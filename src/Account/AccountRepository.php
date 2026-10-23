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

namespace Cacti\PluginCloudflare\Account;

use Cacti\PluginCloudflare\Storage\Database;
use InvalidArgumentException;
use OutOfBoundsException;

final class AccountRepository {
	private const TABLE = 'plugin_cloudflare_accounts';

	/* token_ciphertext is deliberately absent; only tokenMaterial() reads it. */
	private const READ_COLUMNS = 'id, cf_account_id, name, token_source, token_file,
		(token_ciphertext IS NOT NULL AND token_ciphertext <> \'\') AS has_ciphertext,
		enabled, status, status_message, permissions, last_checked_at,
		last_verified_at, collect_tunnels, collect_analytics, discovery_interval,
		created_at, updated_at';

	private const WRITABLE = array(
		'cf_account_id',
		'name',
		'token_source',
		'token_ciphertext',
		'token_file',
		'enabled',
		'status',
		'status_message',
		'permissions',
		'last_checked_at',
		'last_verified_at',
		'collect_tunnels',
		'collect_analytics',
		'discovery_interval',
		'created_at',
		'updated_at',
	);

	/** @var Database */
	private $db;

	public function __construct(Database $db) {
		$this->db = $db;
	}

	public function find(int $id): ?Account {
		$row = $this->db->fetchRow('SELECT ' . self::READ_COLUMNS . ' FROM ' . self::TABLE . ' WHERE id = ?', array($id));

		return $row === null ? null : Account::fromRow($row);
	}

	/**
	 * @return array<int, Account>
	 */
	public function all(): array {
		$accounts = array();

		foreach ($this->db->fetchAll(
			'SELECT ' . self::READ_COLUMNS . ' FROM ' . self::TABLE . ' ORDER BY name, id'
		) as $row) {
			$accounts[] = Account::fromRow($row);
		}

		return $accounts;
	}

	/**
	 * @param array<string, scalar|null> $row
	 */
	public function insert(array $row): int {
		$columns = self::columns($row);

		$this->db->execute(
			'INSERT INTO ' .
				self::TABLE .
				' (' .
				implode(', ', $columns) .
				') VALUES (' .
				implode(', ', array_fill(0, count($columns), '?')) .
				')',
			array_values($row)
		);

		return $this->db->lastInsertId();
	}

	/**
	 * @param array<string, scalar|null> $row
	 */
	public function update(int $id, array $row): void {
		$assignments = array();
		foreach (self::columns($row) as $column) {
			$assignments[] = $column . ' = ?';
		}

		$params   = array_values($row);
		$params[] = $id;

		$this->db->execute('UPDATE ' . self::TABLE . ' SET ' . implode(', ', $assignments) . ' WHERE id = ?', $params);
	}

	public function delete(int $id): void {
		$this->db->execute('DELETE FROM ' . self::TABLE . ' WHERE id = ?', array($id));
	}

	/**
	 * For AccountService only: the returned ciphertext must not leave it.
	 *
	 * @return array{source: string, ciphertext: string|null, file: string|null, cf_account_id: string}
	 */
	public function tokenMaterial(int $id): array {
		$row = $this->db->fetchRow(
			'SELECT token_source, token_ciphertext, token_file, cf_account_id FROM ' . self::TABLE . ' WHERE id = ?',
			array($id)
		);

		if ($row === null) {
			throw new OutOfBoundsException('Cloudflare account not found');
		}

		return array(
			'source'        => (string) $row['token_source'],
			'ciphertext'    => $row['token_ciphertext'] === null ? null : (string) $row['token_ciphertext'],
			'file'          => $row['token_file'] === null ? null : (string) $row['token_file'],
			'cf_account_id' => (string) $row['cf_account_id'],
		);
	}

	/**
	 * Column names come from callers in this package, but are checked against
	 * a fixed list so no array key can reach the SQL text unvetted.
	 *
	 * @param array<string, scalar|null> $row
	 * @return array<int, string>
	 */
	private static function columns(array $row): array {
		if ($row === array()) {
			throw new InvalidArgumentException('no columns to write');
		}

		$columns = array_keys($row);
		foreach ($columns as $column) {
			if (!in_array($column, self::WRITABLE, true)) {
				throw new InvalidArgumentException('unknown account column');
			}
		}

		return $columns;
	}
}
