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

chdir('../../');
include('./include/auth.php');
include_once(__DIR__ . '/setup.php');
include_once(__DIR__ . '/includes/accounts_page.php');

/* include/auth.php passes a disabled plugin's page because realms load
 * regardless of status; answer the way permission_denied.php does. */
if (!cloudflare_plugin_enabled()) {
	raise_ajax_permission_denied();
	header('Location: ../../permission_denied.php');
	exit;
}

plugin_cloudflare_upgrade();

set_default_action();

$redirect = null;

switch (get_request_var('action')) {
	case 'save':
		$redirect = cloudflare_accounts_save();

		break;
	case 'actions':
		$redirect = cloudflare_accounts_actions();

		break;
	case 'edit':
		$redirect = cloudflare_accounts_edit();

		break;
	default:
		top_header();
		cloudflare_accounts_list();
		bottom_footer();

		break;
}

if ($redirect !== null) {
	header('Location: cloudflare_accounts.php?header=false' . $redirect);
	exit;
}
