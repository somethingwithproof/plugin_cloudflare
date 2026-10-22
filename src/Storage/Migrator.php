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

namespace Cacti\PluginCloudflare\Storage;

use Cacti\PluginCloudflare\Storage\Migrations\M001Foundation;
use Cacti\PluginCloudflare\Storage\Migrations\M002Discovery;
use InvalidArgumentException;

final class Migrator {
	const LOCK_NAME = 'plugin_cloudflare_migrate';
	const TABLE = 'plugin_cloudflare_migrations';

	/** @var Database */
	private $db;

	/** @var Migration[] keyed by version */
	private $migrations = array();

	/** @var int */
	private $lockTimeout;

	/**
	 * @param Migration[] $migrations
	 */
	public function __construct(Database $db, array $migrations, int $lockTimeout = 10) {
		$this->db = $db;
		$this->lockTimeout = $lockTimeout;

		foreach ($migrations as $migration) {
			if (isset($this->migrations[$migration->version()])) {
				throw new InvalidArgumentException('Duplicate migration version ' . $migration->version());
			}

			$this->migrations[$migration->version()] = $migration;
		}

		ksort($this->migrations);
	}

	/**
	 * @return Migration[]
	 */
	public static function defaultMigrations(): array {
		return array(new M001Foundation(), new M002Discovery());
	}

	/**
	 * @return int[]
	 */
	public function pending(): array {
		return array_values(array_diff(array_keys($this->migrations), $this->appliedVersions()));
	}

	public function currentVersion(): int {
		$applied = $this->appliedVersions();

		return $applied === array() ? 0 : max($applied);
	}

	/**
	 * @return int[] Versions applied by this call.
	 */
	public function migrate(): array {
		if (!$this->db->acquireLock(self::LOCK_NAME, $this->lockTimeout)) {
			throw new DatabaseException('HY000', 0);
		}

		$failed = true;

		try {
			$this->db->execute(
				'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
					version INT UNSIGNED NOT NULL,
					applied_at INT UNSIGNED NOT NULL,
					PRIMARY KEY (version)
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
			);

			$applied = array();

			// Re-read under the lock: another process may have finished first.
			foreach ($this->pending() as $version) {
				foreach ($this->migrations[$version]->statements($this->db) as $sql) {
					$this->db->execute($sql);
				}

				$this->db->execute(
					'INSERT INTO ' . self::TABLE . ' (version, applied_at) VALUES (?, ?)',
					array($version, time())
				);
				$applied[] = $version;
			}

			$failed = false;

			return $applied;
		} finally {
			try {
				$this->db->releaseLock(self::LOCK_NAME);
			} catch (DatabaseException $e) {
				// The migration error is the one worth reporting, and a lock
				// we could not release dies with the session anyway.
				if (!$failed) {
					throw $e;
				}
			}
		}
	}

	/**
	 * Removes every plugin table and nothing else. Child tables go first so
	 * the foreign keys never block a drop, then the version record, so an
	 * interrupted uninstall never leaves a version row over an empty schema.
	 * Tables added by later migrations are found by prefix and go last.
	 */
	public function dropAll(): void {
		$rows = $this->db->fetchAll(
			'SELECT table_name AS name FROM information_schema.tables
			WHERE table_schema = DATABASE() AND table_name LIKE ? ESCAPE \'!\'',
			array('plugin!_cloudflare!_%')
		);

		$present = array();

		foreach ($rows as $row) {
			$present[] = (string) $row['name'];
		}

		$first = array(
			'plugin_cloudflare_connectors',
			'plugin_cloudflare_tunnels',
			'plugin_cloudflare_jobs',
			'plugin_cloudflare_accounts',
		);
		$core = array_merge($first, array(self::TABLE));
		$others = array_diff($present, $core);
		$ordered = array_merge($core, $others);

		foreach ($ordered as $table) {
			if (in_array($table, $present, true)) {
				// The name comes from the catalog, not from input.
				$this->db->execute('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
			}
		}
	}

	/**
	 * @return int[]
	 */
	private function appliedVersions(): array {
		$exists = (int) $this->db->fetchCell(
			'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
			array(self::TABLE)
		);

		if ($exists === 0) {
			return array();
		}

		$versions = array();

		foreach ($this->db->fetchAll('SELECT version FROM ' . self::TABLE . ' ORDER BY version') as $row) {
			$versions[] = (int) $row['version'];
		}

		return $versions;
	}
}
