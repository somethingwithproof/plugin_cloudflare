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
require_once __DIR__ . '/templates.php';

use Cacti\PluginCloudflare\Account\Account;
use Cacti\PluginCloudflare\Metrics\ResourceRepository;
use Cacti\PluginCloudflare\Storage\DatabaseException;
use Cacti\PluginCloudflare\Support\SystemClock;

/**
 * A Cacti device is a host.php object, so the Configure realm alone must
 * never create one; both realms are checked on every call.
 */
function cloudflare_device_permitted() : bool {
	return api_user_realm_auth('cloudflare_accounts.php') === true && api_user_realm_auth('host.php') === true;
}

/**
 * Creates the account's Cacti device on the admin's request; nothing else
 * creates one. Returns created, exists, templates_missing, denied or
 * failed. The lock makes a double submit wait and then find the device
 * rather than create a second one.
 *
 * @throws DatabaseException
 */
function cloudflare_create_device(Account $account, int $lock_timeout = 30) : string {
	if (!cloudflare_device_permitted()) {
		return 'denied';
	}

	$templates = cloudflare_templates_ready();

	// A failed install-time import heals here, on an explicit request.
	if ($templates === null) {
		cloudflare_templates_install();

		$templates = cloudflare_templates_ready();
	}

	if ($templates === null) {
		return 'templates_missing';
	}

	$db   = cloudflare_db();
	$lock = 'plugin_cloudflare_device_' . $account->id();

	if (!$db->acquireLock($lock, $lock_timeout)) {
		cloudflare_device_log($account, 'not created; another request holds the device lock');

		return 'failed';
	}

	try {
		return cloudflare_create_device_locked(new ResourceRepository($db, new SystemClock()), $account, $templates);
	} finally {
		$db->releaseLock($lock);
	}
}

/**
 * @param array{host_template_id: int, data_query_id: int} $templates
 */
function cloudflare_create_device_locked(ResourceRepository $resources, Account $account, array $templates) : string {
	$device = $resources->device($account->id());

	// A host the admin deleted falls through, and the new one replaces its row.
	if ($device !== null && cloudflare_host_exists($device['host_id'])) {
		return 'exists';
	}

	cloudflare_device_libraries();

	/* lib/api_device.php:939 in 1.2.25 and 1.2.31. SNMP version 0 with
	 * availability '0' (AVAIL_NONE fails validation, cli/add_device.php:199);
	 * the hostname is reserved and never resolves. */
	$host_id = api_device_save(
		'0',
		$templates['host_template_id'],
		'Cloudflare: ' . $account->name(),
		$account->cfAccountId() . '.cloudflare.invalid',
		'',
		0,
		'',
		'',
		161,
		500,
		'',
		'0',
		1,
		23,
		400,
		1,
		'',
		'[None]',
		'',
		'[None]',
		'',
		'',
		10,
		1,
		1,
		0
	);

	if (is_error_message() || !is_numeric($host_id) || (int) $host_id <= 0) {
		cloudflare_device_log($account, 'not created; Cacti refused the device');

		return 'failed';
	}

	$host_id = (int) $host_id;

	/* The device template has just added the query with the global default
	 * reindex method (lib/api_device.php:1130, :1288), after api_device_save()
	 * set None for SNMP version 0 (:1073-1077). REPLACE INTO sets it back to
	 * None, and the call runs the data query (:552-578). */
	api_device_dq_add($host_id, $templates['data_query_id'], DATA_QUERY_AUTOINDEX_NONE);

	try {
		$resources->recordDevice($account->id(), $host_id);
	} catch (InvalidArgumentException $e) {
		cloudflare_device_log($account, 'not recorded; host ' . $host_id . ' belongs to another account');

		return 'failed';
	}

	cloudflare_device_log($account, 'created as host ' . $host_id);

	return 'created';
}

function cloudflare_host_exists(int $host_id) : bool {
	$id = db_fetch_cell_prepared('SELECT id FROM host WHERE id = ?', array($host_id));

	return is_numeric($id) && (int) $id === $host_id;
}

// host.php loads these before saving a device (host.php:26-38).
function cloudflare_device_libraries() : void {
	global $config;

	$libraries = array(
		'api_data_source_remove' => 'api_data_source.php',
		'api_device_save'        => 'api_device.php',
		'api_graph_remove'       => 'api_graph.php',
		'run_data_query'         => 'data_query.php',
		'cacti_snmp_get'         => 'snmp.php',
		'retemplate_graphs'      => 'template.php',
		'push_out_host'          => 'utility.php',
	);

	foreach ($libraries as $function => $file) {
		if (!function_exists($function)) {
			include_once($config['base_path'] . '/lib/' . $file);
		}
	}
}

function cloudflare_device_log(Account $account, string $what) : void {
	cacti_log('CLOUDFLARE: account ' . $account->id() . ' device ' . $what, false, 'CLOUDFLARE', POLLER_VERBOSITY_LOW);
}
