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

namespace Cacti\PluginCloudflare\Tests\Integration\Storage;

use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Storage\DatabaseException;
use Cacti\PluginCloudflare\Storage\Migrations\M001Foundation;
use Cacti\PluginCloudflare\Storage\Migrations\M002Discovery;
use Cacti\PluginCloudflare\Storage\Migrations\M003Metrics;
use Cacti\PluginCloudflare\Storage\Migrator;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use Cacti\PluginCloudflare\Tests\Integration\IntegrationTestCase;

final class M003MetricsTest extends IntegrationTestCase {
	/** @var Database */
	private $db;

	protected function setUp(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();

		$this->db = new Database(TestDatabase::pdo());
	}

	protected function tearDown(): void {
		TestDatabase::reset();
	}

	private function migrator(): Migrator {
		return new Migrator($this->db, Migrator::defaultMigrations());
	}

	private function tableExists(string $table): bool {
		return (int) $this->db->fetchCell(
			'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
			array($table)
		) === 1;
	}

	private function columnCount(string $table, string $column): int {
		return (int) $this->db->fetchCell(
			'SELECT COUNT(*) FROM information_schema.columns
			WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
			array($table, $column)
		);
	}

	private function foreignKeyCount(string $table): int {
		return (int) $this->db->fetchCell(
			"SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE()
			AND table_name = ? AND constraint_type = 'FOREIGN KEY'",
			array($table)
		);
	}

	private function rows(string $table): int {
		return (int) $this->db->fetchCell('SELECT COUNT(*) FROM ' . $table);
	}

	private function insertAccount(): int {
		$this->db->execute(
			"INSERT INTO plugin_cloudflare_accounts (cf_account_id, name, token_source, created_at, updated_at)
			VALUES ('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1', 'acme', 'file', 1, 1)"
		);

		return $this->db->lastInsertId();
	}

	private function insertDevice(int $accountId): void {
		$this->db->execute(
			"INSERT INTO plugin_cloudflare_resources (account_id, kind, host_id, created_at)
			VALUES (?, 'device', 10, 1)",
			array($accountId)
		);
	}

	private function insertGraph(int $accountId, int $tunnelId, int $localGraphId): void {
		$this->db->execute(
			"INSERT INTO plugin_cloudflare_resources
			(account_id, kind, tunnel_id, template_hash, host_id, local_graph_id, local_data_id, created_at)
			VALUES (?, 'graph', ?, ?, 10, ?, 5, 1)",
			array($accountId, $tunnelId, str_repeat('a', 32), $localGraphId)
		);
	}

	public function testUpgradeFromV2AddsMetricsTables(): void {
		(new Migrator($this->db, array(new M001Foundation(), new M002Discovery())))->migrate();
		$this->assertFalse($this->tableExists('plugin_cloudflare_resources'));
		$this->assertSame(0, $this->columnCount('plugin_cloudflare_tunnels', 'connections_error_class'));
		$this->assertSame(0, $this->columnCount('plugin_cloudflare_accounts', 'create_graphs'));

		$accountId = $this->insertAccount();

		$this->assertSame(array(3), $this->migrator()->migrate());
		$this->assertTrue($this->tableExists('plugin_cloudflare_resources'));
		$this->assertTrue($this->tableExists('plugin_cloudflare_metric_mappings'));
		$this->assertSame(1, $this->columnCount('plugin_cloudflare_tunnels', 'connections_error_class'));
		$this->assertSame(1, $this->columnCount('plugin_cloudflare_accounts', 'create_graphs'));
		$this->assertSame(3, $this->migrator()->currentVersion());
		$this->assertSame('0', (string) $this->db->fetchCell(
			'SELECT create_graphs FROM plugin_cloudflare_accounts WHERE id = ?',
			array($accountId)
		));
	}

	public function testM003Idempotent(): void {
		$this->migrator()->migrate();
		$this->db->execute('DELETE FROM plugin_cloudflare_migrations WHERE version = 3');

		$this->assertSame(array(3), $this->migrator()->migrate());
		$this->assertSame(1, $this->foreignKeyCount('plugin_cloudflare_resources'));

		foreach ((new M003Metrics())->statements($this->db) as $sql) {
			$this->db->execute($sql);
		}

		$this->assertSame(1, $this->foreignKeyCount('plugin_cloudflare_resources'));
		$this->assertSame(1, $this->columnCount('plugin_cloudflare_tunnels', 'connections_error_class'));
		$this->assertSame(1, $this->columnCount('plugin_cloudflare_accounts', 'create_graphs'));
	}

	public function testOneDeviceRowPerAccount(): void {
		$this->migrator()->migrate();
		$accountId = $this->insertAccount();
		$this->insertDevice($accountId);

		try {
			$this->insertDevice($accountId);
			$this->fail('Expected DatabaseException');
		} catch (DatabaseException $e) {
			$this->assertSame('23000', $e->getSqlState());
		}

		$this->assertSame(1, $this->rows('plugin_cloudflare_resources'));
	}

	public function testGraphIdUnique(): void {
		$this->migrator()->migrate();
		$accountId = $this->insertAccount();
		$this->insertGraph($accountId, 1, 77);
		$this->insertGraph($accountId, 2, 78);

		try {
			$this->insertGraph($accountId, 3, 77);
			$this->fail('Expected DatabaseException');
		} catch (DatabaseException $e) {
			$this->assertSame('23000', $e->getSqlState());
		}

		$this->assertSame(2, $this->rows('plugin_cloudflare_resources'));
	}

	public function testResourcesCascadeOnAccountDelete(): void {
		$this->migrator()->migrate();
		$accountId = $this->insertAccount();
		$this->insertDevice($accountId);
		$this->insertGraph($accountId, 1, 77);

		$this->db->execute('DELETE FROM plugin_cloudflare_accounts WHERE id = ?', array($accountId));
		$this->assertSame(0, $this->rows('plugin_cloudflare_resources'));
	}

	public function testDropAllRemovesMetricsTables(): void {
		$this->migrator()->migrate();
		$this->insertDevice($this->insertAccount());

		$this->migrator()->dropAll();

		foreach (array('resources', 'metric_mappings', 'accounts', 'migrations') as $suffix) {
			$this->assertFalse($this->tableExists('plugin_cloudflare_' . $suffix), $suffix);
		}
	}
}
