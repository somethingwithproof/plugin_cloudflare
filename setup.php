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

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/accounts.php';
require_once __DIR__ . '/includes/templates.php';

use Cacti\PluginCloudflare\Storage\DatabaseException;
use Cacti\PluginCloudflare\Storage\Migrator;

/*
 * api_plugin_valid_entrypoint() (lib/plugins.php:1124) rejects hook and
 * realm registration unless the direct caller's name matches
 * /(install|upgrade|setup)/i, so registration stays inline in install()
 * and plugin_cloudflare_upgrade().
 */
function plugin_cloudflare_install() : void {
	api_plugin_register_hook('cloudflare', 'config_arrays', 'cloudflare_config_arrays', 'includes/settings.php');
	api_plugin_register_hook('cloudflare', 'config_settings', 'cloudflare_config_settings', 'includes/settings.php');
	api_plugin_register_hook(
		'cloudflare',
		'draw_navigation_text',
		'cloudflare_draw_navigation_text',
		'includes/settings.php'
	);
	api_plugin_register_hook('cloudflare', 'poller_bottom', 'cloudflare_poller_bottom', 'includes/polling.php');

	api_plugin_register_realm('cloudflare', 'cloudflare.php', 'Cloudflare: View', false);
	api_plugin_register_realm('cloudflare', 'cloudflare_accounts.php', 'Cloudflare: Configure', true);

	try {
		plugin_cloudflare_setup_database();
	} catch (DatabaseException $e) {
		cloudflare_log_schema_failure('install', $e);

		return;
	}

	cloudflare_templates_install();
}

/**
 * Cacti removes hooks, realms and plugin_config itself after this returns
 * (lib/plugins.php:801). Devices, graphs, RRD files, the imported templates
 * and resource/script_server/cloudflare_tunnels.xml are left alone. The old
 * device keeps its history, but no account owns it after a reinstall, so its
 * data sources print U, and Create Cacti Device makes a new device.
 */
function plugin_cloudflare_uninstall() : void {
	try {
		(new Migrator(cloudflare_db(), Migrator::defaultMigrations()))->dropAll();
	} catch (DatabaseException $e) {
		cacti_log('CLOUDFLARE: dropping plugin tables failed: ' . $e->getMessage(), false, 'CLOUDFLARE');
	}

	// '!' as the escape keeps the pattern independent of NO_BACKSLASH_ESCAPES.
	db_execute_prepared('DELETE FROM settings WHERE name LIKE ? ESCAPE \'!\'', array('cloudflare!_%'));
}

/**
 * @return array<string, string>
 */
function plugin_cloudflare_version() : array {
	$info = parse_ini_file(__DIR__ . '/INFO', true);

	return (is_array($info) && isset($info['info']) && is_array($info['info'])) ? $info['info'] : array();
}

function plugin_cloudflare_check_config() : bool {
	$loaded = isset($GLOBALS['cloudflare_extension_loaded']) && is_callable($GLOBALS['cloudflare_extension_loaded'])
		? $GLOBALS['cloudflare_extension_loaded']
		: 'extension_loaded';

	foreach (array('curl', 'openssl', 'pdo_mysql') as $extension) {
		if (!$loaded($extension)) {
			cacti_log('CLOUDFLARE: the ' . $extension . ' PHP extension is required', false, 'CLOUDFLARE');

			return false;
		}
	}

	plugin_cloudflare_upgrade();

	return true;
}

/**
 * Core Cacti never calls an upgrade function, so this runs from
 * check_config, the config_settings hook and the top of each plugin page.
 * Returns false when nothing could be upgraded.
 */
function plugin_cloudflare_upgrade(int $lock_timeout = 10) : bool {
	$installed = db_fetch_cell_prepared('SELECT version FROM plugin_config WHERE directory = ?', array('cloudflare'));

	if ($installed === false || $installed === null || $installed === '') {
		return false;
	}

	$info    = plugin_cloudflare_version();
	$current = isset($info['version']) ? (string) $info['version'] : '';

	try {
		$migrator = new Migrator(cloudflare_db(), Migrator::defaultMigrations(), $lock_timeout);

		if ($migrator->pending() !== array()) {
			$migrator->migrate();
		}
	} catch (DatabaseException $e) {
		cloudflare_log_schema_failure('upgrade', $e);

		return false;
	}

	/* 0.2.0 added the hook. Cacti inserts a new hook disabled unless asked
	 * (lib/plugins.php:1046), and an already enabled plugin is not enabled
	 * again, so the hook takes the plugin's current state. This stays inline:
	 * api_plugin_valid_entrypoint() checks the direct caller's name. */
	if (version_compare((string) $installed, '0.2.0', '<')) {
		api_plugin_register_hook(
			'cloudflare',
			'poller_bottom',
			'cloudflare_poller_bottom',
			'includes/polling.php',
			cloudflare_plugin_enabled()
		);
	}

	if ($current !== '' && $current !== (string) $installed) {
		/* A failed template install is logged and does not hold back the
		 * version: retrying a failing import on every page load would cost
		 * more than it could fix. */
		cloudflare_templates_install($lock_timeout);

		db_execute_prepared('UPDATE plugin_config SET version = ? WHERE directory = ?', array($current, 'cloudflare'));
	}

	return true;
}

function plugin_cloudflare_setup_database() : void {
	(new Migrator(cloudflare_db(), Migrator::defaultMigrations()))->migrate();
}

/**
 * Migrator::migrate() reports a lock wait timeout as SQLSTATE HY000 with
 * driver code 0; any other code is a real failure. Only getMessage() is
 * logged: it carries no bound values or driver text.
 */
function cloudflare_log_schema_failure(string $stage, DatabaseException $e) : void {
	if ($e->getSqlState() === 'HY000' && $e->getDriverCode() === 0) {
		cacti_log(
			'CLOUDFLARE: schema ' . $stage . ' deferred; another process holds the migration lock',
			false,
			'CLOUDFLARE'
		);

		return;
	}

	cacti_log('CLOUDFLARE: schema ' . $stage . ' failed: ' . $e->getMessage(), false, 'CLOUDFLARE');
}
