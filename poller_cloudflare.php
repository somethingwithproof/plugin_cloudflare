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

if (php_sapi_name() !== 'cli') {
	http_response_code(404);
	exit;
}

/* Launched by cloudflare_poller_bottom() through exec_background(); runs
 * detached from poller.php, so a slow Cloudflare API never holds a cycle. */
require(__DIR__ . '/../../include/cli_check.php');
require_once(__DIR__ . '/includes/polling.php');

exit(cloudflare_collector_main('cloudflare_discovery_service'));
