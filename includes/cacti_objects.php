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
use Cacti\PluginCloudflare\Storage\Database;
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
		$result = cloudflare_create_device_locked(new ResourceRepository($db, new SystemClock()), $account, $templates);
	} finally {
		// GET_LOCK ends with the session anyway, so a failed release must not hide the result.
		try {
			$db->releaseLock($lock);
		} catch (Throwable $e) {
			cloudflare_device_log($account, 'lock release failed (' . get_class($e) . ')');
		}
	}

	return $result;
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
	} catch (Throwable $e) {
		cloudflare_device_log($account, 'not recorded (' . get_class($e) . '); removing host ' . $host_id);

		cloudflare_remove_unrecorded_host($account, $host_id);

		if (!$e instanceof InvalidArgumentException) {
			throw $e;
		}

		return 'failed';
	}

	cloudflare_device_log($account, 'created as host ' . $host_id);

	return 'created';
}

/**
 * Removes a host this request created but could not record, so no device
 * is left that the plugin does not know about. $host_id is only ever the
 * id api_device_save() just returned. api_device_remove() returns nothing
 * (lib/api_device.php:45 in 1.2.25 and 1.2.31), so success is read back
 * from the host table.
 */
function cloudflare_remove_unrecorded_host(Account $account, int $host_id) : void {
	try {
		api_device_remove($host_id);
	} catch (Throwable $e) {
		cloudflare_device_log($account, 'host ' . $host_id . ' removal failed (' . get_class($e) . ')');
	}

	try {
		$left = cloudflare_host_exists($host_id);
	} catch (Throwable $e) {
		$left = true;
	}

	if ($left) {
		cloudflare_device_log($account, 'left orphan host ' . $host_id . '; remove it in Devices');
	}
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

/**
 * Runs in the collector after DiscoveryService::runDue(), under its lock,
 * for each account whose discovery ended ok or partial and that has a
 * device. It reindexes the device and, when the account asks for graphs,
 * creates or adopts the missing ones. It never removes a Cacti object, and
 * a graph the plugin recorded is never made again, so a graph the admin
 * deleted stays deleted. $outcomes is keyed by account id, as runDue()
 * returns it.
 *
 * @param  array<int, array{status: string}>                $outcomes
 * @return array{reindexed: int, graphs: int, skipped: int}
 */
function cloudflare_sync_cacti_objects(array $outcomes, int $budget_seconds = 20, int $max_graphs = 50) : array {
	$totals   = array('reindexed' => 0, 'graphs' => 0, 'skipped' => 0);
	$deadline = time() + $budget_seconds;
	$accounts = array();

	foreach ($outcomes as $account_id => $outcome) {
		if ($outcome['status'] === 'ok' || $outcome['status'] === 'partial') {
			$accounts[] = (int) $account_id;
		}
	}

	if (!cacti_sizeof($accounts)) {
		return $totals;
	}

	$db        = cloudflare_db();
	$resources = new ResourceRepository($db, new SystemClock());
	$templates = null;

	foreach ($accounts as $account_id) {
		if (time() >= $deadline || $totals['graphs'] >= $max_graphs) {
			cacti_log('CLOUDFLARE: graph sync budget reached', false, 'CLOUDFLARE', POLLER_VERBOSITY_MEDIUM);

			break;
		}

		try {
			$device = $resources->device($account_id);

			if ($device === null) {
				continue;
			}

			if (!cloudflare_sync_host_present($account_id, $device['host_id'])) {
				$totals['skipped']++;

				continue;
			}

			if ($templates === null) {
				$templates = cloudflare_templates_ready();

				if ($templates === null) {
					cacti_log('CLOUDFLARE: graph sync skipped; templates missing', false, 'CLOUDFLARE', POLLER_VERBOSITY_LOW);

					break;
				}

				cloudflare_device_libraries();
			}

			/* Picks up new tunnels. $force refreshes titles, which otherwise
			 * follow only index changes (lib/data_query.php:356-368). */
			/* Both releases return false when the reindex fails
			 * (lib/data_query.php:129-131, :149-152). */
			if (run_data_query($device['host_id'], $templates['data_query_id'], false, true) !== false) {
				$totals['reindexed']++;
			}

			$totals['graphs'] += cloudflare_sync_graphs(
				$db,
				$resources,
				$account_id,
				$device['host_id'],
				$templates,
				$deadline,
				$max_graphs - $totals['graphs']
			);
		} catch (Throwable $e) {
			cacti_log(
				'CLOUDFLARE: account ' . $account_id . ' graph sync failed (' . get_class($e) . ')',
				false,
				'CLOUDFLARE',
				POLLER_VERBOSITY_LOW
			);
		}
	}

	return $totals;
}

/**
 * A device the admin deleted is never made again here; only Create Cacti
 * device replaces it. The setting keeps the log to one line per host.
 */
function cloudflare_sync_host_present(int $account_id, int $host_id) : bool {
	$id = db_fetch_cell_prepared('SELECT id FROM host WHERE id = ? AND deleted = \'\'', array($host_id));

	if (is_numeric($id) && (int) $id === $host_id) {
		return true;
	}

	$setting = 'cloudflare_missing_host_' . $account_id;

	if ((string) read_config_option($setting, true) !== (string) $host_id) {
		cacti_log(
			'CLOUDFLARE: account ' . $account_id . ' device host ' . $host_id
				. ' is gone; not synced until Create Cacti device replaces it',
			false,
			'CLOUDFLARE',
			POLLER_VERBOSITY_LOW
		);

		set_config_option($setting, (string) $host_id);
	}

	return false;
}

/**
 * Creates or adopts each missing graph of the account's active tunnels,
 * as cli/add_graphs.php:629-716 does, and returns how many it created.
 * Graph rows recorded against another host are left from a replaced
 * device: they neither block nor cause graphs on the current host.
 *
 * @param array{
 *         data_query_id: int,
 *         graphs: array<string, array{graph_template_id: int, snmp_query_graph_id: int}>
 *         } $templates
 */
function cloudflare_sync_graphs(
	Database $db,
	ResourceRepository $resources,
	int $account_id,
	int $host_id,
	array $templates,
	int $deadline,
	int $graphs_left
) : int {
	$create = $db->fetchCell('SELECT create_graphs FROM plugin_cloudflare_accounts WHERE id = ?', array($account_id));

	if ((int) $create !== 1) {
		return 0;
	}

	$recorded = array();

	foreach ($resources->graphs($account_id) as $graph) {
		if ($graph['host_id'] === $host_id) {
			$recorded[$graph['tunnel_id']][$graph['template_hash']] = true;
		}
	}

	$tunnels = $db->fetchAll(
		'SELECT id, cf_tunnel_id FROM plugin_cloudflare_tunnels WHERE account_id = ? AND lifecycle = \'active\' ORDER BY id',
		array($account_id)
	);

	$data_query_id = $templates['data_query_id'];
	$index_on      = null;
	$created       = 0;
	$adopted       = 0;

	foreach ($tunnels as $tunnel) {
		$tunnel_id = (int) $tunnel['id'];
		$index     = (string) $tunnel['cf_tunnel_id'];
		$cached    = null;

		foreach ($templates['graphs'] as $key => $graph) {
			$hash = cloudflare_template_hash('graph_template/' . $key);

			if (isset($recorded[$tunnel_id][$hash])) {
				continue;
			}

			if ($created >= $graphs_left || time() >= $deadline) {
				break 2;
			}

			$local_graph_id = db_fetch_cell_prepared(
				'SELECT id FROM graph_local
				WHERE host_id = ? AND snmp_query_id = ? AND snmp_index = ? AND graph_template_id = ?
				ORDER BY id LIMIT 1',
				array($host_id, $data_query_id, $index, $graph['graph_template_id'])
			);

			if (is_numeric($local_graph_id) && (int) $local_graph_id > 0) {
				$resources->recordGraph(
					$account_id,
					$tunnel_id,
					$hash,
					$host_id,
					(int) $local_graph_id,
					cloudflare_graph_data_id((int) $local_graph_id)
				);

				$adopted++;

				continue;
			}

			/* A tunnel the reindex did not list (R3 prints an empty listing on
			 * a fault) would get a data source without an index value. */
			if ($index_on === null) {
				$index_on = get_best_data_query_index_type($host_id, $data_query_id);
			}

			if ($cached === null) {
				$cached = db_fetch_cell_prepared(
					'SELECT snmp_index FROM host_snmp_cache
					WHERE host_id = ? AND snmp_query_id = ? AND snmp_index = ? AND field_name = ? LIMIT 1',
					array($host_id, $data_query_id, $index, $index_on)
				) !== false;
			}

			if (!$cached) {
				continue 2;
			}

			$empty = array();

			/* Every graph maps all six fields, so data_source_exists() hands
			 * the tunnel's later graphs its data source (lib/template.php:1613). */
			$made = create_complete_graph_from_template(
				$graph['graph_template_id'],
				$host_id,
				array(
					'snmp_query_id'       => $data_query_id,
					'snmp_index_on'       => $index_on,
					'snmp_query_graph_id' => $graph['snmp_query_graph_id'],
					'snmp_index'          => $index,
				),
				$empty
			);

			if (!is_array($made) || empty($made['local_graph_id'])) {
				cacti_log(
					'CLOUDFLARE: account ' . $account_id . ' graph not created for tunnel ' . $tunnel_id,
					false,
					'CLOUDFLARE',
					POLLER_VERBOSITY_LOW
				);

				break 2;
			}

			$local_data_id = 0;

			foreach ($made['local_data_id'] ?? array() as $id) {
				push_out_host($host_id, (int) $id);

				$local_data_id = (int) $id;
			}

			$resources->recordGraph($account_id, $tunnel_id, $hash, $host_id, (int) $made['local_graph_id'], $local_data_id);

			$created++;
		}
	}

	if ($created > 0 || $adopted > 0) {
		cacti_log(
			'CLOUDFLARE: account ' . $account_id . ' graphs created=' . $created . ' adopted=' . $adopted,
			false,
			'CLOUDFLARE',
			POLLER_VERBOSITY_MEDIUM
		);
	}

	return $created;
}

// The data source an existing graph draws from, as cli/add_graphs.php:680 reads it.
function cloudflare_graph_data_id(int $local_graph_id) : int {
	$id = db_fetch_cell_prepared(
		'SELECT dtr.local_data_id
		FROM graph_templates_item AS gti
		INNER JOIN data_template_rrd AS dtr
		ON gti.task_item_id = dtr.id
		WHERE gti.local_graph_id = ?
		LIMIT 1',
		array($local_graph_id)
	);

	return is_numeric($id) ? (int) $id : 0;
}
