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

namespace Cacti\PluginCloudflare\Discovery;

use Cacti\PluginCloudflare\Api\ConnectorRecord;
use Cacti\PluginCloudflare\Api\TunnelRecord;
use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Support\Clock;

/**
 * Applies a complete listing to the stored inventory. Callers pass only a
 * listing that was fetched in full; a failed fetch must not reach here, or
 * the absent tunnels would be marked deleted.
 *
 * Rows are upserted on their unique keys and never deleted and re-inserted,
 * so tunnel row ids stay stable for the data sources that reference them.
 */
final class Reconciler {
	/** @var Database */
	private $db;

	/** @var Clock */
	private $clock;

	public function __construct(Database $db, Clock $clock) {
		$this->db    = $db;
		$this->clock = $clock;
	}

	/**
	 * @param array<int, TunnelRecord> $tunnels
	 * @return array{added: int, updated: int, deleted: int}
	 */
	public function applyListing(int $accountId, array $tunnels): array {
		return $this->db->transactional(function () use ($accountId, $tunnels): array {
			$now = $this->clock->now();

			$stored = array();
			foreach ($this->db->fetchAll(
				'SELECT id, cf_tunnel_id, lifecycle FROM plugin_cloudflare_tunnels WHERE account_id = ? FOR UPDATE',
				array($accountId)
			) as $row) {
				$stored[strtolower((string) $row['cf_tunnel_id'])] = $row;
			}

			$seen    = array();
			$added   = 0;
			$updated = 0;

			foreach ($tunnels as $tunnel) {
				$key = strtolower($tunnel->id);

				if (!isset($seen[$key])) {
					$seen[$key] = true;
					if (isset($stored[$key])) {
						$updated++;
					} else {
						$added++;
					}
				}

				// lifecycle_changed_at is assigned before lifecycle so it reads the old value.
				$this->db->execute(
					"INSERT INTO plugin_cloudflare_tunnels
					(account_id, cf_tunnel_id, name, cf_status, tun_type, lifecycle, created_at_cf,
						conns_active_at, conns_inactive_at, first_seen_at, last_seen_at, lifecycle_changed_at)
					VALUES (?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?)
					ON DUPLICATE KEY UPDATE
						lifecycle_changed_at = IF(lifecycle = 'active', lifecycle_changed_at, VALUES(lifecycle_changed_at)),
						lifecycle = 'active',
						name = VALUES(name),
						cf_status = VALUES(cf_status),
						tun_type = VALUES(tun_type),
						created_at_cf = VALUES(created_at_cf),
						conns_active_at = VALUES(conns_active_at),
						conns_inactive_at = VALUES(conns_inactive_at),
						last_seen_at = VALUES(last_seen_at)",
					array(
						$accountId,
						$tunnel->id,
						$tunnel->name,
						$tunnel->status,
						$tunnel->tunType,
						$tunnel->createdAt,
						$tunnel->connsActiveAt,
						$tunnel->connsInactiveAt,
						$now,
						$now,
						$now,
					)
				);
			}

			$absent = array();
			foreach ($stored as $key => $row) {
				if (!isset($seen[$key]) && $row['lifecycle'] !== 'deleted') {
					$absent[] = (int) $row['id'];
				}
			}

			$deleted = 0;
			if ($absent !== array()) {
				$params = $absent;
				array_unshift($params, $now);

				$deleted = $this->db->execute(
					"UPDATE plugin_cloudflare_tunnels SET lifecycle = 'deleted', lifecycle_changed_at = ?
					WHERE id IN (" . implode(', ', array_fill(0, count($absent), '?')) . ')',
					$params
				);
			}

			return array('added' => $added, 'updated' => $updated, 'deleted' => $deleted);
		});
	}

	/**
	 * Connectors are cloudflared processes that come and go, so rows absent
	 * from the listing are removed rather than kept with a lifecycle.
	 *
	 * @param array<int, ConnectorRecord> $connectors
	 */
	public function applyConnections(int $tunnelRowId, array $connectors): void {
		$this->db->transactional(function () use ($tunnelRowId, $connectors): void {
			$now = $this->clock->now();

			/* A repeated id keeps its last entry, matching what the upsert leaves stored. */
			$byId = array();
			foreach ($connectors as $connector) {
				$byId[strtolower($connector->id)] = $connector;
			}

			$connections = 0;
			foreach ($byId as $connector) {
				$connections += $connector->connectionCount;

				$this->db->execute(
					'INSERT INTO plugin_cloudflare_connectors
					(tunnel_id, cf_connector_id, version, arch, run_at, connection_count, colos, origin_ips, last_seen_at)
					VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
					ON DUPLICATE KEY UPDATE
						version = VALUES(version),
						arch = VALUES(arch),
						run_at = VALUES(run_at),
						connection_count = VALUES(connection_count),
						colos = VALUES(colos),
						origin_ips = VALUES(origin_ips),
						last_seen_at = VALUES(last_seen_at)',
					array(
						$tunnelRowId,
						$connector->id,
						$connector->version,
						$connector->arch,
						$connector->runAt,
						$connector->connectionCount,
						implode(',', $connector->colos),
						implode(',', $connector->originIps),
						$now,
					)
				);
			}

			$sql    = 'DELETE FROM plugin_cloudflare_connectors WHERE tunnel_id = ?';
			$params = array($tunnelRowId);
			if ($byId !== array()) {
				$sql .= ' AND cf_connector_id NOT IN (' . implode(', ', array_fill(0, count($byId), '?')) . ')';
				foreach ($byId as $connector) {
					$params[] = $connector->id;
				}
			}
			$this->db->execute($sql, $params);

			$this->db->execute(
				'UPDATE plugin_cloudflare_tunnels SET connector_count = ?, connection_count = ? WHERE id = ?',
				array(count($byId), $connections, $tunnelRowId)
			);
		});
	}

	/**
	 * Called after repeated collection failures. It records that the
	 * account could not be read and never deletes or marks anything deleted.
	 *
	 * @return int Tunnels changed from active to unavailable.
	 */
	public function markUnavailable(int $accountId): int {
		return (new TunnelRepository($this->db))->markAccountUnavailable($accountId, $this->clock->now());
	}
}
