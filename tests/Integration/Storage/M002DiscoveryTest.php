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
use Cacti\PluginCloudflare\Storage\Migrator;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use Cacti\PluginCloudflare\Tests\Integration\IntegrationTestCase;

final class M002DiscoveryTest extends IntegrationTestCase {
	const TUNNEL_UUID = '11111111-2222-3333-4444-555555555555';
	const CONNECTOR_UUID = '66666666-7777-8888-9999-000000000000';

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

	private function foreignKeyCount(string $table): int {
		return (int) $this->db->fetchCell(
			"SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE()
			AND table_name = ? AND constraint_type = 'FOREIGN KEY'",
			array($table)
		);
	}

	private function insertAccount(): int {
		$this->db->execute(
			"INSERT INTO plugin_cloudflare_accounts (cf_account_id, name, token_source, created_at, updated_at)
			VALUES ('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1', 'acme', 'file', 1, 1)"
		);

		return $this->db->lastInsertId();
	}

	private function insertTunnel(int $accountId, string $uuid = self::TUNNEL_UUID): int {
		$this->db->execute(
			"INSERT INTO plugin_cloudflare_tunnels
			(account_id, cf_tunnel_id, name, cf_status, tun_type, first_seen_at, last_seen_at, lifecycle_changed_at)
			VALUES (?, ?, 'edge', 'healthy', 'cfd_tunnel', 1, 1, 1)",
			array($accountId, $uuid)
		);

		return $this->db->lastInsertId();
	}

	private function insertConnector(int $tunnelId, string $uuid = self::CONNECTOR_UUID): void {
		$this->db->execute(
			"INSERT INTO plugin_cloudflare_connectors
			(tunnel_id, cf_connector_id, version, arch, connection_count, colos, origin_ips, last_seen_at)
			VALUES (?, ?, '2026.1.0', 'linux_amd64', 4, 'AMS,LHR', '203.0.113.7', 1)",
			array($tunnelId, $uuid)
		);
	}

	private function rows(string $table): int {
		return (int) $this->db->fetchCell('SELECT COUNT(*) FROM ' . $table);
	}

	public function testUpgradeFromV1AddsDiscoveryTables(): void {
		$v1 = new Migrator($this->db, array(new M001Foundation()));
		$this->assertSame(array(1), $v1->migrate());
		$this->assertFalse($this->tableExists('plugin_cloudflare_tunnels'));

		$accountId = $this->insertAccount();

		$this->assertSame(array(2), $this->migrator()->migrate());
		$this->assertTrue($this->tableExists('plugin_cloudflare_tunnels'));
		$this->assertTrue($this->tableExists('plugin_cloudflare_connectors'));
		$this->assertSame(2, $this->migrator()->currentVersion());
		$this->assertSame(1, $this->rows('plugin_cloudflare_accounts'));
		$this->assertGreaterThan(0, $accountId);
	}

	/* plugin_cloudflare_jobs shipped in M001, so its new column arrives by a guarded ALTER. */
	public function testUpgradeFromV1AddsOutageFailuresToJobs(): void {
		(new Migrator($this->db, array(new M001Foundation())))->migrate();
		$this->assertSame(0, $this->columnCount('plugin_cloudflare_jobs', 'outage_failures'));

		$this->migrator()->migrate();
		$this->assertSame(1, $this->columnCount('plugin_cloudflare_jobs', 'outage_failures'));

		foreach ((new M002Discovery())->statements($this->db) as $sql) {
			$this->db->execute($sql);
		}
		$this->assertSame(1, $this->columnCount('plugin_cloudflare_jobs', 'outage_failures'));
	}

	private function columnCount(string $table, string $column): int {
		return (int) $this->db->fetchCell(
			'SELECT COUNT(*) FROM information_schema.columns
			WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
			array($table, $column)
		);
	}

	public function testM002Idempotent(): void {
		$this->migrator()->migrate();
		$this->db->execute('DELETE FROM plugin_cloudflare_migrations WHERE version = 2');

		$this->assertSame(array(2), $this->migrator()->migrate());
		$this->assertSame(1, $this->foreignKeyCount('plugin_cloudflare_tunnels'));
		$this->assertSame(1, $this->foreignKeyCount('plugin_cloudflare_connectors'));

		foreach ((new M002Discovery())->statements($this->db) as $sql) {
			$this->db->execute($sql);
		}

		$this->assertSame(1, $this->foreignKeyCount('plugin_cloudflare_tunnels'));
	}

	public function testTunnelUniquePerAccount(): void {
		$this->migrator()->migrate();
		$accountId = $this->insertAccount();
		$this->insertTunnel($accountId);

		try {
			$this->insertTunnel($accountId);
			$this->fail('Expected DatabaseException');
		} catch (DatabaseException $e) {
			$this->assertSame('23000', $e->getSqlState());
		}

		$this->insertTunnel($accountId, 'aaaaaaaa-2222-3333-4444-555555555555');
		$this->assertSame(2, $this->rows('plugin_cloudflare_tunnels'));
	}

	public function testTunnelDefaultsToActiveLifecycle(): void {
		$this->migrator()->migrate();
		$this->insertTunnel($this->insertAccount());

		$this->assertSame(
			'active',
			$this->db->fetchCell('SELECT lifecycle FROM plugin_cloudflare_tunnels')
		);
	}

	public function testConnectorUniquePerTunnel(): void {
		$this->migrator()->migrate();
		$tunnelId = $this->insertTunnel($this->insertAccount());
		$this->insertConnector($tunnelId);

		$this->expectException(DatabaseException::class);
		$this->insertConnector($tunnelId);
	}

	public function testConnectorCascadeOnTunnelDelete(): void {
		$this->migrator()->migrate();
		$accountId = $this->insertAccount();
		$tunnelId = $this->insertTunnel($accountId);
		$this->insertConnector($tunnelId);

		$this->db->execute('DELETE FROM plugin_cloudflare_tunnels WHERE id = ?', array($tunnelId));
		$this->assertSame(0, $this->rows('plugin_cloudflare_connectors'));

		$tunnelId = $this->insertTunnel($accountId);
		$this->insertConnector($tunnelId);
		$this->db->execute('DELETE FROM plugin_cloudflare_accounts WHERE id = ?', array($accountId));
		$this->assertSame(0, $this->rows('plugin_cloudflare_tunnels'));
		$this->assertSame(0, $this->rows('plugin_cloudflare_connectors'));
	}

	public function testDropAllRemovesDiscoveryTables(): void {
		$this->migrator()->migrate();
		$this->insertConnector($this->insertTunnel($this->insertAccount()));

		$this->migrator()->dropAll();

		foreach (array('connectors', 'tunnels', 'jobs', 'accounts', 'migrations') as $suffix) {
			$this->assertFalse($this->tableExists('plugin_cloudflare_' . $suffix), $suffix);
		}
	}
}
