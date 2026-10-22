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

use Cacti\PluginCloudflare\Storage\Database;
use InvalidArgumentException;

final class TunnelRepository {
	private const TABLE = 'plugin_cloudflare_tunnels';

	private const LIFECYCLES = array('active', 'unavailable', 'deleted');

	private const CF_STATUSES = array('healthy', 'degraded', 'down', 'inactive');

	/** @var Database */
	private $db;

	public function __construct(Database $db) {
		$this->db = $db;
	}

	/**
	 * @param array<string, mixed> $filters lifecycle, cf_status, search
	 * @return array<int, array<string, mixed>>
	 */
	public function forAccount(int $accountId, array $filters = array(), int $limit = 50, int $offset = 0): array {
		if ($limit < 1 || $offset < 0) {
			throw new InvalidArgumentException('invalid page');
		}

		list($where, $params) = $this->where($accountId, $filters);

		/* Both are checked ints. pdo_mysql emulates prepares by default and would quote a bound LIMIT. */
		return $this->db->fetchAll(
			'SELECT * FROM ' . self::TABLE . $where . ' ORDER BY name, id LIMIT ' . $limit . ' OFFSET ' . $offset,
			$params
		);
	}

	/**
	 * @param int                  $accountId 0 counts every account
	 * @param array<string, mixed> $filters   as for forAccount()
	 */
	public function count(int $accountId = 0, array $filters = array()): int {
		list($where, $params) = $this->where($accountId, $filters);

		return (int) $this->db->fetchCell('SELECT COUNT(*) FROM ' . self::TABLE . $where, $params);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function connectors(int $tunnelId): array {
		return $this->db->fetchAll(
			'SELECT * FROM plugin_cloudflare_connectors WHERE tunnel_id = ? ORDER BY cf_connector_id, id',
			array($tunnelId)
		);
	}

	/**
	 * A run that cannot refresh every tunnel starts from these, so
	 * successive runs work through the whole account. Ordering by the last
	 * attempt, not the last success, keeps a tunnel that always fails from
	 * holding the head of the queue. MySQL sorts NULL first, so tunnels
	 * never tried lead.
	 *
	 * @return array<int, array<string, mixed>> id and cf_tunnel_id
	 */
	public function activeForRefresh(int $accountId, int $limit): array {
		if ($accountId < 1 || $limit < 1) {
			throw new InvalidArgumentException('invalid refresh query');
		}

		/* $limit is a checked int. pdo_mysql emulates prepares by default and would quote a bound LIMIT. */
		return $this->db->fetchAll(
			'SELECT id, cf_tunnel_id FROM ' . self::TABLE . "
			WHERE account_id = ? AND lifecycle = 'active'
			ORDER BY connections_attempted_at, id LIMIT " . $limit,
			array($accountId)
		);
	}

	/**
	 * For an attempt that failed; a successful one is stamped by
	 * Reconciler::applyConnections() with the refresh.
	 *
	 * @return int Rows changed.
	 */
	public function markConnectionsAttempted(int $tunnelId, int $now): int {
		return $this->db->execute(
			'UPDATE ' . self::TABLE . ' SET connections_attempted_at = ? WHERE id = ?',
			array($now, $tunnelId)
		);
	}

	/**
	 * Deleted rows stay deleted: the account being unreachable says nothing
	 * about a tunnel that was already gone.
	 *
	 * @return int Rows changed.
	 */
	public function markAccountUnavailable(int $accountId, int $now): int {
		return $this->db->execute(
			'UPDATE ' . self::TABLE . " SET lifecycle = 'unavailable', lifecycle_changed_at = ?
			WHERE account_id = ? AND lifecycle = 'active'",
			array($now, $accountId)
		);
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array{0: string, 1: array<int, int|string>}
	 */
	private function where(int $accountId, array $filters): array {
		if ($accountId < 0) {
			throw new InvalidArgumentException('invalid account id');
		}

		$clauses = array();
		$params  = array();

		if ($accountId > 0) {
			$clauses[] = 'account_id = ?';
			$params[]  = $accountId;
		}

		foreach ($filters as $key => $value) {
			switch ($key) {
				case 'lifecycle':
				case 'cf_status':
					$allowed = $key === 'lifecycle' ? self::LIFECYCLES : self::CF_STATUSES;
					if (!is_string($value) || !in_array($value, $allowed, true)) {
						throw new InvalidArgumentException('invalid ' . $key . ' filter');
					}

					$clauses[] = $key . ' = ?';
					$params[]  = $value;

					break;
				case 'search':
					if (!is_string($value)) {
						throw new InvalidArgumentException('invalid search filter');
					}

					if ($value !== '') {
						$clauses[] = "name LIKE ? ESCAPE '!'";
						$params[]  = '%' . strtr($value, array('!' => '!!', '%' => '!%', '_' => '!_')) . '%';
					}

					break;
				default:
					throw new InvalidArgumentException('unknown tunnel filter');
			}
		}

		return array($clauses === array() ? '' : ' WHERE ' . implode(' AND ', $clauses), $params);
	}
}
