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

require_once __DIR__ . '/cacti_objects.php';

use Somethingwithproof\Cloudflare\Storage\Database;
use Somethingwithproof\Cloudflare\Metrics\ResourceRepository;
use Somethingwithproof\Cloudflare\Support\SystemClock;

/**
 * Serialize against discovery and graph sync. Cacti APIs own graph, data
 * source, remote-poller and RRD cleanup. A failed removal leaves the tunnel
 * indexed and its resource records available for a safe retry.
 */
function cloudflare_retire_tunnel(int $id, ?Database $db = null) : bool {
	if (!cloudflare_device_permitted()) {
		return false;
	}

	$db = $db ?? cloudflare_db();

	if (!$db->acquireLock('plugin_cloudflare_collector', 0)) {
		return false;
	}

	try {
		$tunnel = $db->fetchRow('SELECT * FROM plugin_cloudflare_tunnels WHERE id = ?', array($id));

		if ($tunnel === null || $tunnel['lifecycle'] !== 'deleted') {
			return false;
		}

		$resources = new ResourceRepository($db, new SystemClock());
		$device    = $resources->device((int) $tunnel['account_id']);

		if ($device !== null && !is_device_allowed($device['host_id'])) {
			return false;
		}

		global $config;

		foreach (array('api_graph_remove' => 'api_graph.php', 'api_data_source_remove' => 'api_data_source.php', 'run_data_query' => 'data_query.php') as $function => $file) {
			if (!function_exists($function)) {
				require_once $config['base_path'] . '/lib/' . $file;
			}
		}
		$graphs = array_filter($resources->graphs((int) $tunnel['account_id']), function (array $graph) use ($id) : bool {
			return $graph['tunnel_id'] === $id;
		});
		$data = array();

		// Validate the entire removal set before deleting its first object.
		foreach ($graphs as $graph) {
			$actual = $db->fetchRow('SELECT g.host_id, g.snmp_index, t.hash FROM graph_local g LEFT JOIN graph_templates t ON t.id = g.graph_template_id WHERE g.id = ?', array($graph['local_graph_id']));

			if ($actual !== null && ((int) $actual['host_id'] !== $graph['host_id'] || $actual['snmp_index'] !== $tunnel['cf_tunnel_id'] || $actual['hash'] !== $graph['template_hash'] || !is_graph_allowed($graph['local_graph_id']))) {
				return false;
			}

			if ($graph['local_data_id'] > 0) {
				$row = $db->fetchRow('SELECT host_id, snmp_index FROM data_local WHERE id = ?', array($graph['local_data_id']));

				if ($row !== null && ((int) $row['host_id'] !== $graph['host_id'] || $row['snmp_index'] !== $tunnel['cf_tunnel_id'])) {
					return false;
				}

				$data[$graph['local_data_id']] = true;
			}
		}

		foreach ($graphs as $graph) {
			api_graph_remove($graph['local_graph_id']);

			if ($db->fetchCell('SELECT id FROM graph_local WHERE id = ?', array($graph['local_graph_id'])) !== null) {
				return false;
			}
		}

		foreach (array_keys($data) as $data_id) {
			$references = (int) $db->fetchCell('SELECT COUNT(*) FROM graph_templates_item g JOIN data_template_rrd r ON r.id = g.task_item_id WHERE r.local_data_id = ?', array($data_id));

			// Other graphs can share the source. Keep their history intact.
			if ($references === 0) {
				api_data_source_remove($data_id);

				if ($db->fetchCell('SELECT id FROM data_local WHERE id = ?', array($data_id)) !== null) {
					return false;
				}
			}
		}

		$db->execute("DELETE FROM plugin_cloudflare_resources WHERE kind = 'graph' AND account_id = ? AND tunnel_id = ?", array($tunnel['account_id'], $id));
		$db->execute("UPDATE plugin_cloudflare_tunnels SET retired_at = COALESCE(retired_at, ?) WHERE id = ? AND lifecycle = 'deleted'", array(time(), $id));
		$templates = $device === null ? null : cloudflare_templates_ready();

		// The index must already exclude the tunnel. A failed reindex undoes this retirement's mark so the action stays visible.
		if ($device !== null && $templates !== null && run_data_query($device['host_id'], $templates['data_query_id'], false, true) === false) {
			if ($tunnel['retired_at'] === null) {
				$db->execute('UPDATE plugin_cloudflare_tunnels SET retired_at = NULL WHERE id = ?', array($id));
			}

			return false;
		}

		cacti_log('CLOUDFLARE: tunnel ' . $id . ' retired by user ' . (int) ($_SESSION['sess_user_id'] ?? 0), false, 'CLOUDFLARE');

		return true;
	} finally {
		$db->releaseLock('plugin_cloudflare_collector');
	}
}

/**
 * Accounts retire.
 *
 * @return ?string
 */
function cloudflare_accounts_retire() : ?string {
	if (!cloudflare_device_permitted()) {
		raise_ajax_permission_denied();

		return '../../permission_denied.php';
	}

	get_filter_request_var('tunnel_id');
	$id     = (int) get_request_var('tunnel_id');
	$tunnel = cloudflare_db()->fetchRow('SELECT name, lifecycle FROM plugin_cloudflare_tunnels WHERE id = ?', array($id));

	if ($tunnel === null || $tunnel['lifecycle'] !== 'deleted') {
		return 'cloudflare.php?action=tunnels';
	}

	if (cloudflare_request_is_post() && get_filter_request_var('confirmed')) {
		if (!cloudflare_retire_tunnel($id)) {
			raise_message('cloudflare_retire_failed', __esc('The tunnel could not be retired. Check permissions and retry after collection finishes.', 'cloudflare'), MESSAGE_LEVEL_ERROR);
		}

		return 'cloudflare.php?action=tunnels';
	}

	top_header();
	form_start('cloudflare_accounts.php');
	html_start_box(__esc('Retire tunnel', 'cloudflare'), '100%', '', '3', 'center', '');
	print '<tr><td>' . __esc('Retiring removes the graphs and unshared data sources created for this deleted tunnel. Cacti controls RRD cleanup. The inventory record is kept.', 'cloudflare') . '<p>' . html_escape((string) $tunnel['name']) . '</p>';
	print "<input type='hidden' name='action' value='retire'><input type='hidden' name='tunnel_id' value='" . $id . "'><input type='hidden' name='confirmed' value='1'>";
	print "<a class='ui-button' href='cloudflare.php?action=tunnels'>" . __esc('Cancel', 'cloudflare') . "</a> <input class='ui-button' type='submit' value='" . __esc('Continue', 'cloudflare') . "'></td></tr>";
	html_end_box();
	form_end(false);
	bottom_footer();

	return null;
}
