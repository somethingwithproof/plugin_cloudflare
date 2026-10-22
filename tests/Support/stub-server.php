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

/*
 * Router for `php -S` used by the curl transport tests. Every request path
 * is appended to CF_STUB_LOG so a test can prove a route was never hit.
 */

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$log  = getenv('CF_STUB_LOG');

if (is_string($log) && $log !== '') {
	file_put_contents($log, $path . "\n", FILE_APPEND | LOCK_EX);
}

header('Content-Type: application/json');

switch ($path) {
	case '/ok':
		header('X-Stub: ok');
		print json_encode(array(
			'success' => true,
			'method'  => $_SERVER['REQUEST_METHOD'],
			'auth'    => isset($_SERVER['HTTP_AUTHORIZATION']),
		));
		break;
	case '/redirect':
		header('Location: /target', true, 302);
		break;
	case '/target':
		print '{"target":true}';
		break;
	case '/slow':
		sleep(max(0, min(10, (int) ($_GET['s'] ?? 0))));
		print '{"slow":true}';
		break;
	case '/big':
		$chunk = str_repeat('a', 65536);
		for ($i = 0; $i < 96; $i++) {
			print $chunk;
			flush();
		}
		break;
	case '/status':
		http_response_code(max(100, min(599, (int) ($_GET['code'] ?? 500))));
		print '{"success":false}';
		break;
	default:
		http_response_code(404);
		print '{"success":false}';
}

return true;
