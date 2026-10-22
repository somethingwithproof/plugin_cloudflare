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

namespace Cacti\PluginCloudflare\Metrics;

use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Support\Clock;
use InvalidArgumentException;

/**
 * The Cacti objects the plugin made for each account: at most one device,
 * and one graph per tunnel and graph template. Cacti owns the objects; a
 * row only says the plugin made them, so a deleted host leaves its row
 * behind until the next Create Cacti device replaces it.
 */
final class ResourceRepository {
	private const TABLE = 'plugin_cloudflare_resources';

	/** @var Database */
	private $db;

	/** @var Clock */
	private $clock;

	public function __construct(Database $db, Clock $clock) {
		$this->db    = $db;
		$this->clock = $clock;
	}

	/**
	 * @return array{host_id: int, created_at: int}|null
	 */
	public function device(int $accountId): ?array {
		$row = $this->db->fetchRow(
			'SELECT host_id, created_at FROM ' . self::TABLE . ' WHERE account_id = ? AND kind = \'device\'',
			array($accountId)
		);

		return $row === null ? null : array('host_id' => (int) $row['host_id'], 'created_at' => (int) $row['created_at']);
	}

	/**
	 * Two accounts sharing a host would print each other's tunnels into
	 * one data query, so a host another account holds is refused.
	 *
	 * @throws InvalidArgumentException when another account already owns $hostId
	 */
	public function recordDevice(int $accountId, int $hostId): void {
		$this->db->transactional(function () use ($accountId, $hostId): void {
			$owner = $this->db->fetchCell(
				'SELECT account_id FROM ' . self::TABLE . ' WHERE kind = \'device\' AND host_id = ? AND account_id <> ?',
				array($hostId, $accountId)
			);

			if ($owner !== null) {
				throw new InvalidArgumentException('host already belongs to another Cloudflare account');
			}

			$this->db->execute(
				'DELETE FROM ' . self::TABLE . ' WHERE account_id = ? AND kind = \'device\'',
				array($accountId)
			);
			$this->db->execute(
				'INSERT INTO ' . self::TABLE . ' (account_id, kind, tunnel_id, template_hash, host_id, created_at)
				VALUES (?, \'device\', 0, \'\', ?, ?)',
				array($accountId, $hostId, $this->clock->now())
			);
		});
	}

	/**
	 * @return array<int, array{
	 *         tunnel_id: int,
	 *         template_hash: string,
	 *         host_id: int,
	 *         local_graph_id: int,
	 *         local_data_id: int,
	 *         created_at: int
	 *         }> ordered by tunnel, then template
	 */
	public function graphs(int $accountId): array {
		$graphs = array();

		foreach ($this->db->fetchAll(
			'SELECT tunnel_id, template_hash, host_id, local_graph_id, local_data_id, created_at
			FROM ' . self::TABLE . ' WHERE account_id = ? AND kind = \'graph\' ORDER BY tunnel_id, template_hash',
			array($accountId)
		) as $row) {
			$graphs[] = array(
				'tunnel_id'      => (int) $row['tunnel_id'],
				'template_hash'  => (string) $row['template_hash'],
				'host_id'        => (int) $row['host_id'],
				'local_graph_id' => (int) $row['local_graph_id'],
				'local_data_id'  => (int) $row['local_data_id'],
				'created_at'     => (int) $row['created_at'],
			);
		}

		return $graphs;
	}

	public function recordGraph(
		int $accountId,
		int $tunnelId,
		string $templateHash,
		int $hostId,
		int $localGraphId,
		int $localDataId
	): void {
		$this->db->transactional(function () use ($accountId, $tunnelId, $templateHash, $hostId, $localGraphId, $localDataId): void {
			$this->db->execute(
				'DELETE FROM ' . self::TABLE . ' WHERE account_id = ? AND kind = \'graph\' AND tunnel_id = ? AND template_hash = ?',
				array($accountId, $tunnelId, $templateHash)
			);
			$this->db->execute(
				'INSERT INTO ' . self::TABLE . ' (account_id, kind, tunnel_id, template_hash, host_id, local_graph_id,
				local_data_id, created_at) VALUES (?, \'graph\', ?, ?, ?, ?, ?, ?)',
				array($accountId, $tunnelId, $templateHash, $hostId, $localGraphId, $localDataId, $this->clock->now())
			);
		});
	}
}
