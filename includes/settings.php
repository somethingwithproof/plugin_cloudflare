<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Cacti Group                                      |
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
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

require_once __DIR__ . '/accounts.php';

function cloudflare_config_arrays() : void {
	global $menu;

	if (!cloudflare_plugin_enabled()) {
		return;
	}

	$menu[__('Configuration')]['plugins/cloudflare/cloudflare_accounts.php'] = __('Cloudflare Accounts', 'cloudflare');
}

/**
 * @param array<string, array<string, string>> $nav
 * @return array<string, array<string, string>>
 */
function cloudflare_draw_navigation_text($nav) {
	if (!cloudflare_plugin_enabled()) {
		return $nav;
	}

	$nav['cloudflare_accounts.php:'] = array(
		'title'   => __('Cloudflare Accounts', 'cloudflare'),
		'mapping' => 'index.php:',
		'url'     => 'cloudflare_accounts.php',
		'level'   => '1'
	);

	$nav['cloudflare_accounts.php:edit'] = array(
		'title'   => __('(edit)', 'cloudflare'),
		'mapping' => 'index.php:,cloudflare_accounts.php:',
		'url'     => 'cloudflare_accounts.php',
		'level'   => '2'
	);

	$nav['cloudflare_accounts.php:actions'] = array(
		'title'   => __('Actions', 'cloudflare'),
		'mapping' => 'index.php:,cloudflare_accounts.php:',
		'url'     => 'cloudflare_accounts.php',
		'level'   => '2'
	);

	return $nav;
}

function cloudflare_config_settings() : void {
	global $tabs, $settings, $logfile_verbosity;

	/* The tab stays while disabled: Cacti keeps this hook enabled so the key
	 * path can be set before the plugin is turned on. The schema waits for
	 * check_config, which runs the upgrade on enable. */
	if (cloudflare_plugin_enabled() && function_exists('get_current_page') && in_array(get_current_page(), array('settings.php', 'plugins.php'), true)) {
		include_once(dirname(__DIR__) . '/setup.php');

		plugin_cloudflare_upgrade();
	}

	$tabs['cloudflare'] = __('Cloudflare', 'cloudflare');

	$settings['cloudflare'] = array(
		'cloudflare_header' => array(
			'friendly_name' => __('Cloudflare', 'cloudflare'),
			'method'        => 'spacer',
		),
		'cloudflare_key_file' => array(
			'friendly_name' => __('Token Vault Key File', 'cloudflare'),
			'description'   => __('Absolute path to the 32-byte key used to encrypt stored API tokens. It must be readable by the web server and poller users and not world-readable. When the file is missing, only token files can be used.', 'cloudflare'),
			'method'        => 'textbox',
			'default'       => '/etc/cacti/cloudflare.key',
			'max_length'    => '255',
			'size'          => '60'
		),
		'cloudflare_ui_timeout' => array(
			'friendly_name' => __('Interactive Request Timeout', 'cloudflare'),
			'description'   => __('Total time in seconds allowed for a Cloudflare API request made from the web interface, such as Verify.', 'cloudflare'),
			'method'        => 'textbox',
			'default'       => '10',
			'max_length'    => '2',
			'size'          => '5'
		),
		'cloudflare_poller_timeout' => array(
			'friendly_name' => __('Collector Request Timeout', 'cloudflare'),
			'description'   => __('Total time in seconds allowed for a Cloudflare API request made by the background collector.', 'cloudflare'),
			'method'        => 'textbox',
			'default'       => '20',
			'max_length'    => '3',
			'size'          => '5'
		),
		'cloudflare_log_level' => array(
			'friendly_name' => __('Log Level', 'cloudflare'),
			'description'   => __('The amount of detail the plugin writes to the Cacti log.', 'cloudflare'),
			'method'        => 'drop_array',
			'default'       => POLLER_VERBOSITY_LOW,
			'array'         => is_array($logfile_verbosity) ? $logfile_verbosity : array()
		),
	);
}
