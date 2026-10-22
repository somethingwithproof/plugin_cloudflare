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

/* bootstrap.php only declares functions and constants, and the script
 * server has already loaded Cacti's $config and $database_sessions through
 * include/cli_check.php, so cloudflare_db() finds the poller's handle. */
require_once __DIR__ . '/bootstrap.php';

use Cacti\PluginCloudflare\Metrics\Catalog;
use Cacti\PluginCloudflare\Metrics\ObservationReader;
use Cacti\PluginCloudflare\Support\Ids;
use Cacti\PluginCloudflare\Support\SystemClock;

/**
 * Answers one script server call from the plugin tables. Arguments arrive
 * as <arg_prepend>|host_id|</arg_prepend> followed by the data query verb
 * (lib/utility.php:544). Anything that cannot be vouched for prints U, and
 * a listing that cannot be read is empty rather than a line Cacti would
 * take for an index.
 */
function cloudflare_ss(
	string $hostId,
	string $cmd,
	string $arg1,
	string $arg2,
	?ObservationReader $reader = null
) : string {
	$listing = in_array($cmd, array('index', 'num_indexes', 'query'), true);

	if (!$listing && $cmd !== 'get') {
		return 'U';
	}

	try {
		$accountId = null;

		if (preg_match('/^[1-9][0-9]{0,9}\z/', $hostId) === 1) {
			$reader    = $reader ?? new ObservationReader(cloudflare_db(), new SystemClock());
			$accountId = $reader->accountForHost((int) $hostId);
		}

		if ($cmd === 'get') {
			$index = trim($arg2);

			if ($accountId === null || Catalog::byField($arg1) === null || !Ids::isUuid($index)) {
				return 'U';
			}

			return $reader->observe($accountId, $index)[$arg1]->rrdValue();
		}

		$tunnels = $accountId === null ? array() : cloudflare_ss_tunnels($reader, $accountId);

		if ($cmd === 'num_indexes') {
			return (string) count($tunnels);
		}

		if ($cmd === 'index') {
			return cloudflare_ss_lines(array_keys($tunnels));
		}

		return cloudflare_ss_query($tunnels, $arg1);
	} catch (Throwable $e) {
		cacti_log('CLOUDFLARE: script server failed (' . get_class($e) . ')', false, 'CLOUDFLARE', POLLER_VERBOSITY_HIGH);

		return $listing && $cmd !== 'num_indexes' ? '' : 'U';
	}
}

/**
 * Every tunnel of the account, deleted ones included, so reindex never
 * orphans a graph (lib/data_query.php:317). An id that is not a lowercase
 * UUID cannot be fetched with get, so it is not offered as an index.
 *
 * @return array<string, string> cf_tunnel_id => name
 */
function cloudflare_ss_tunnels(ObservationReader $reader, int $accountId) : array {
	$tunnels = array();

	foreach ($reader->tunnelIndex($accountId) as $id => $name) {
		if (Ids::isUuid((string) $id)) {
			$tunnels[(string) $id] = $name;
		}
	}

	return $tunnels;
}

/**
 * Cacti splits each line at the first delimiter (lib/data_query.php:748),
 * so a name may contain '!' but not a line break.
 *
 * @param array<string, string> $tunnels
 */
function cloudflare_ss_query(array $tunnels, string $field) : string {
	$lines = array();

	foreach ($tunnels as $id => $name) {
		if ($field === 'tunnelId') {
			$lines[] = $id . '!' . $id;
		} elseif ($field === 'tunnelName') {
			$lines[] = $id . '!' . str_replace(array("\r", "\n"), ' ', $name);
		} elseif (Catalog::byField($field) !== null) {
			/* Output fields are read per poll through get; reindex only needs
			 * to see that they exist. */
			$lines[] = $id . '!U';
		} else {
			return '';
		}
	}

	return cloudflare_ss_lines($lines);
}

/**
 * @param array<int, string> $lines
 */
function cloudflare_ss_lines(array $lines) : string {
	return count($lines) === 0 ? '' : implode("\n", $lines) . "\n";
}
