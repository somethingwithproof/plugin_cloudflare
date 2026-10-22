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
 * The release archive ships no vendor/ tree, so src/ is loaded by this
 * PSR-4 loader. A development checkout uses Composer's autoloader instead.
 */
if (!function_exists('cloudflare_autoload')) {
	function cloudflare_autoload(string $class) : void {
		$prefix = 'Cacti\\PluginCloudflare\\';

		if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
			return;
		}

		$relative = substr($class, strlen($prefix));

		if (preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative) !== 1) {
			return;
		}

		$file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';

		if (is_file($file)) {
			require_once $file;
		}
	}
}

if (is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
	require_once dirname(__DIR__) . '/vendor/autoload.php';
} else {
	/* The test suite always runs from a checkout with vendor/; the install
	 * smoke runs this branch from a copy without it. */
	spl_autoload_register('cloudflare_autoload'); // @codeCoverageIgnore
}
