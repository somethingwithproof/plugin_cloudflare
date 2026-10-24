<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 Thomas Vincent                                       |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cloudflare plugin for Cacti                                             |
 | https://github.com/somethingwithproof/plugin_cloudflare                 |
 +-------------------------------------------------------------------------+
*/

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/cacti_objects.php';

use Cacti\PluginCloudflare\Jobs\JobRepository;
use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Storage\DatabaseException;
use Cacti\PluginCloudflare\Storage\Migrator;
use Cacti\PluginCloudflare\Support\SystemClock;

if (!defined('CLOUDFLARE_COLLECTOR_LOCK')) {
	define('CLOUDFLARE_COLLECTOR_LOCK', 'plugin_cloudflare_collector');
	define('CLOUDFLARE_COLLECTOR_BUDGET', 50);
}

/**
 * Runs inside poller.php after every cycle, so it only decides whether to
 * launch the collector and never waits on it or lets a fault escape.
 * Remote pollers fire this hook too (poller.php:956); collection belongs
 * to the main poller alone.
 */
function cloudflare_poller_bottom() : void {
	global $config;

	if (!cloudflare_plugin_enabled()) {
		return;
	}

	if (isset($config['poller_id']) && (int) $config['poller_id'] !== 1) {
		return;
	}

	try {
		$collecting = (int) cloudflare_db()->fetchCell(
			'SELECT COUNT(*) FROM plugin_cloudflare_accounts WHERE enabled = 1 AND collect_tunnels = 1'
		);
	} catch (DatabaseException $e) {
		return;
	}

	if ($collecting < 1) {
		return;
	}

	// 1.2.25 interpolates $args as a string (lib/poller.php:132); arrays are 1.2.31 only.
	// nosemgrep: php.lang.security.exec-use.exec-use -- admin-set PHP binary, fixed escaped script path
	exec_background(
		read_config_option('path_php_binary'),
		'-q ' . escapeshellarg(dirname(__DIR__) . '/poller_cloudflare.php')
	);
}

/**
 * Body of poller_cloudflare.php. A lock held by another collector is the
 * normal overlap of a slow run with the next cycle, so it exits 0 quietly.
 * A pending upgrade also exits 0, with one LOW line per run so the admin
 * learns why nothing is collected.
 * Only the exception class is logged: messages can carry Cloudflare text.
 *
 * @param callable $service_factory returns the DiscoveryService to run
 */
function cloudflare_collector_main(callable $service_factory) : int {
	if (!cloudflare_plugin_enabled()) {
		return 0;
	}

	try {
		$db = cloudflare_db();

		/* The upgrade runs only from a page view, and the account read needs
		 * the new columns, so until then every account would fault. */
		if ((new Migrator($db, Migrator::defaultMigrations()))->pending() !== array()) {
			cacti_log(
				'CLOUDFLARE: collector skipped; plugin upgrade pending, open Console > Plugins',
				false,
				'CLOUDFLARE',
				POLLER_VERBOSITY_LOW
			);

			return 0;
		}

		if (!$db->acquireLock(CLOUDFLARE_COLLECTOR_LOCK, 0)) {
			return 0;
		}

		try {
			cloudflare_collector_ensure_jobs($db);

			$outcomes = $service_factory()->runDue(CLOUDFLARE_COLLECTOR_BUDGET);

			cacti_log(cloudflare_collector_summary($outcomes), false, 'CLOUDFLARE', POLLER_VERBOSITY_MEDIUM);

			cloudflare_collector_sync($outcomes);
		} finally {
			$db->releaseLock(CLOUDFLARE_COLLECTOR_LOCK);
		}
	} catch (Throwable $e) {
		cacti_log('CLOUDFLARE: collector failed (' . get_class($e) . ')', false, 'CLOUDFLARE', POLLER_VERBOSITY_LOW);

		return 1;
	}

	return 0;
}

/**
 * Discovery has already recorded its results, so a failed sync is logged
 * and leaves the collector's exit status alone.
 *
 * @param array<int, array{status: string}> $outcomes from DiscoveryService::runDue()
 */
function cloudflare_collector_sync(array $outcomes) : void {
	try {
		cloudflare_sync_cacti_objects($outcomes);
	} catch (Throwable $e) {
		cacti_log('CLOUDFLARE: graph sync failed (' . get_class($e) . ')', false, 'CLOUDFLARE', POLLER_VERBOSITY_LOW);
	}
}

// Nothing else creates discovery jobs, so an account added since the last run gets one here.
function cloudflare_collector_ensure_jobs(Database $db) : void {
	$jobs = new JobRepository($db, new SystemClock());
	$rows = $db->fetchAll(
		'SELECT id FROM plugin_cloudflare_accounts WHERE enabled = 1 AND collect_tunnels = 1 ORDER BY id'
	);

	foreach ($rows as $row) {
		$jobs->ensure(JobRepository::TYPE_DISCOVERY, (int) $row['id']);
	}
}

/**
 * @param array<int, array{status: string}> $outcomes from DiscoveryService::runDue()
 */
function cloudflare_collector_summary(array $outcomes) : string {
	$counts = array('ok' => 0, 'partial' => 0, 'failed' => 0, 'skipped' => 0, 'lease_lost' => 0);

	foreach ($outcomes as $outcome) {
		if (isset($counts[$outcome['status']])) {
			$counts[$outcome['status']]++;
		}
	}

	$parts = array();

	foreach ($counts as $status => $count) {
		$parts[] = $status . '=' . $count;
	}

	return 'CLOUDFLARE: collector ' . implode(' ', $parts);
}
