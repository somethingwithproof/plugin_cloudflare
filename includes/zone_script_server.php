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

require_once __DIR__ . '/bootstrap.php';

use Somethingwithproof\Cloudflare\Metrics\ZoneReader;
use Somethingwithproof\Cloudflare\Support\SystemClock;

/**
 * Zone SS.
 *
 * @param  string      $host
 * @param  string      $cmd
 * @param  string      $field
 * @param  string      $zone
 * @param  ?ZoneReader $reader
 * @return string
 */
function cloudflare_zone_ss(string $host, string $cmd, string $field, string $zone, ?ZoneReader $reader = null) : string {
	$listing = in_array($cmd, array('index', 'query', 'num_indexes'), true);

	if (preg_match('/^[1-9][0-9]{0,9}\z/', $host) !== 1 || (!$listing && $cmd !== 'get')) {
		return $listing && $cmd !== 'num_indexes' ? '' : 'U';
	}

	try {
		$reader = $reader ?? new ZoneReader(cloudflare_db(), new SystemClock());

		if ($cmd === 'get') {
			return $reader->value((int) $host, trim($zone), $field);
		}

		$index = $reader->index((int) $host);

		if ($cmd === 'num_indexes') {
			return (string) count($index);
		}

		$lines = array();

		foreach ($index as $id => $name) {
			if ($cmd === 'index') {
				$lines[] = $id;
			} elseif ($field === 'zoneId' || $field === 'zoneName') {
				$lines[] = $id . '!' . ($field === 'zoneId' ? $id : $name);
			} elseif (in_array($field, array('requests', 'bytes', 'errorPct', 'sampleInterval', 'age'), true)) {
				$lines[] = $id . '!U';
			}
		}

		return $lines === array() ? '' : implode("\n", $lines) . "\n";
	} catch (Throwable $e) {
		cacti_log('CLOUDFLARE: zone input failed (' . get_class($e) . ')', false, 'CLOUDFLARE');

		return $listing && $cmd !== 'num_indexes' ? '' : 'U';
	}
}
