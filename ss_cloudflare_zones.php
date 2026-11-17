<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_cloudflare, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_cloudflare
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

error_reporting(0);

/* Cacti runs this file two ways. The script server includes it once per
 * process and calls ss_cloudflare() for each poller item; a data query
 * reindex runs it as a CLI script (lib/data_query.php:656 in 1.2.31). */
if (!isset($called_by_script_server)) {
	include_once(__DIR__ . '/../../include/cli_check.php');
	include_once(__DIR__ . '/includes/zone_script_server.php');

	array_shift($_SERVER['argv']);

	print call_user_func_array('ss_cloudflare_zones', $_SERVER['argv']);
} else {
	include_once(__DIR__ . '/includes/zone_script_server.php');
}

/**
 * Script server 1.2.31 calls only a function whose source file resolves
 * under base_path (script_server.php:358). Defining it in the file the
 * poller item names keeps that check tied to the path Cacti validated.
 * The parameters stay untyped like Cacti's own ss_ functions.
 *
 * @param mixed $host_id
 * @param mixed $cmd
 * @param mixed $arg1
 * @param mixed $arg2
 */
function ss_cloudflare_zones($host_id = '', $cmd = '', $arg1 = '', $arg2 = '') : string {
	return cloudflare_zone_ss((string) $host_id, (string) $cmd, (string) $arg1, (string) $arg2);
}
