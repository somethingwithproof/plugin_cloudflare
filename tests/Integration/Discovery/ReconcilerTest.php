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

namespace Cacti\PluginCloudflare\Tests\Integration\Discovery;

use Cacti\PluginCloudflare\Api\ConnectorRecord;
use Cacti\PluginCloudflare\Api\TunnelRecord;
use Cacti\PluginCloudflare\Discovery\Reconciler;
use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Storage\DatabaseException;
use Cacti\PluginCloudflare\Storage\Migrator;
use Cacti\PluginCloudflare\Tests\Support\FrozenClock;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use Eris\Generators;
use Eris\TestTrait;
use Cacti\PluginCloudflare\Tests\Integration\IntegrationTestCase;

final class ReconcilerTest extends IntegrationTestCase {
	use TestTrait;

	const T0 = 1800000000;

	/** @var Database */
	private $db;

	/** @var int */
	private $accountId;

	protected function setUp(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();

		$this->db = new Database(TestDatabase::pdo());
		(new Migrator($this->db, Migrator::defaultMigrations()))->migrate();
		$this->accountId = $this->insertAccount('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1');
	}

	protected function tearDown(): void {
		TestDatabase::reset();
	}

	private function insertAccount(string $cfAccountId): int {
		$this->db->execute(
			"INSERT INTO plugin_cloudflare_accounts (cf_account_id, name, token_source, created_at, updated_at)
			VALUES (?, 'acme', 'file', 1, 1)",
			array($cfAccountId)
		);

		return $this->db->lastInsertId();
	}

	private function reconciler(int $now = self::T0): Reconciler {
		return new Reconciler($this->db, new FrozenClock($now));
	}

	private static function uuid(int $n): string {
		return sprintf('%08x-0000-4000-8000-%012x', $n, $n);
	}

	private static function tunnel(int $n, string $name = '', string $status = 'healthy'): TunnelRecord {
		return new TunnelRecord(
			self::uuid($n),
			$name === '' ? 'tunnel-' . $n : $name,
			$status,
			'cfd_tunnel',
			1700000000 + $n,
			1700000100 + $n,
			null
		);
	}

	/**
	 * @param array<int, string> $colos
	 */
	private static function connector(int $n, int $connections, array $colos = array('AMS', 'LHR')): ConnectorRecord {
		return new ConnectorRecord(
			self::uuid(1000 + $n),
			'2026.1.' . $n,
			'linux_amd64',
			1750000000 + $n,
			$connections,
			$colos,
			array('203.0.113.' . $n)
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function tunnels(): array {
		return $this->db->fetchAll('SELECT * FROM plugin_cloudflare_tunnels ORDER BY id');
	}

	/**
	 * last_seen_at moves on every listing; everything else must not.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function tunnelsWithoutLastSeen(): array {
		$rows = $this->tunnels();
		foreach ($rows as $i => $row) {
			unset($rows[$i]['last_seen_at']);
		}

		return $rows;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function tunnelsByUuid(): array {
		$byUuid = array();
		foreach ($this->db->fetchAll(
			'SELECT * FROM plugin_cloudflare_tunnels WHERE account_id = ? ORDER BY id',
			array($this->accountId)
		) as $row) {
			$byUuid[(string) $row['cf_tunnel_id']] = $row;
		}

		return $byUuid;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function connectorRows(int $tunnelId): array {
		return $this->db->fetchAll(
			'SELECT * FROM plugin_cloudflare_connectors WHERE tunnel_id = ? ORDER BY cf_connector_id',
			array($tunnelId)
		);
	}

	private function tunnelId(int $n): int {
		return (int) $this->db->fetchCell(
			'SELECT id FROM plugin_cloudflare_tunnels WHERE account_id = ? AND cf_tunnel_id = ?',
			array($this->accountId, self::uuid($n))
		);
	}

	public function testFirstListingAddsAll(): void {
		$counts = $this->reconciler()->applyListing(
			$this->accountId,
			array(self::tunnel(1), self::tunnel(2, 'edge', 'degraded'), self::tunnel(3))
		);

		$this->assertSame(array('added' => 3, 'updated' => 0, 'deleted' => 0), $counts);

		$rows = $this->tunnelsByUuid();
		$this->assertCount(3, $rows);

		$edge = $rows[self::uuid(2)];
		$this->assertSame((string) $this->accountId, (string) $edge['account_id']);
		$this->assertSame('edge', $edge['name']);
		$this->assertSame('degraded', $edge['cf_status']);
		$this->assertSame('cfd_tunnel', $edge['tun_type']);
		$this->assertSame('active', $edge['lifecycle']);
		$this->assertSame('1700000002', (string) $edge['created_at_cf']);
		$this->assertSame('1700000102', (string) $edge['conns_active_at']);
		$this->assertNull($edge['conns_inactive_at']);
		$this->assertNull($edge['connector_count']);
		$this->assertNull($edge['connection_count']);
		$this->assertSame((string) self::T0, (string) $edge['first_seen_at']);
		$this->assertSame((string) self::T0, (string) $edge['last_seen_at']);
		$this->assertSame((string) self::T0, (string) $edge['lifecycle_changed_at']);
	}

	public function testRepeatListingIsIdempotent(): void {
		$tunnel = Generators::tuple(
			Generators::elements('edge', 'origin', 'db', 'x', 'Tunnel_%!'),
			Generators::elements('healthy', 'degraded', 'down', 'inactive'),
			Generators::elements('cfd_tunnel', 'warp_connector')
		);

		// The size cap bounds the generated listing at 30 tunnels.
		$this->limitTo(25)
			->forAll(Generators::seq($tunnel), Generators::choose(2, 4))
			->withMaxSize(30)
			->then(function (array $specs, int $repeats): void {
				$this->db->execute('DELETE FROM plugin_cloudflare_tunnels');

				$listing = array();
				foreach ($specs as $i => $spec) {
					$listing[] = new TunnelRecord(
						self::uuid((int) $i),
						$spec[0] . '-' . $i,
						$spec[1],
						$spec[2],
						null,
						1700000000 + (int) $i,
						null
					);
				}

				$clock      = new FrozenClock(self::T0);
				$reconciler = new Reconciler($this->db, $clock);
				$first      = $reconciler->applyListing($this->accountId, $listing);
				$this->assertSame(array('added' => count($listing), 'updated' => 0, 'deleted' => 0), $first);

				$snapshot = $this->tunnelsWithoutLastSeen();
				$this->assertCount(count($listing), $snapshot);

				for ($run = 1; $run < $repeats; $run++) {
					$clock->advance(60);
					$again = $reconciler->applyListing($this->accountId, $listing);

					$this->assertSame(array('added' => 0, 'updated' => count($listing), 'deleted' => 0), $again);
					$this->assertSame($snapshot, $this->tunnelsWithoutLastSeen(), 'run ' . $run);
					$this->assertSame(
						array_fill(0, count($listing), (string) $clock->now()),
						array_map('strval', array_column($this->tunnels(), 'last_seen_at'))
					);
				}
			});
	}

	public function testAbsentTunnelBecomesDeletedButRowKept(): void {
		$this->reconciler()->applyListing($this->accountId, array(self::tunnel(1), self::tunnel(2)));
		$this->reconciler()->applyConnections($this->tunnelId(2), array(self::connector(1, 4)));
		$before = $this->tunnelsByUuid();

		$counts = $this->reconciler(self::T0 + 60)->applyListing($this->accountId, array(self::tunnel(1)));
		$this->assertSame(array('added' => 0, 'updated' => 1, 'deleted' => 1), $counts);

		$after = $this->tunnelsByUuid();
		$gone = $after[self::uuid(2)];
		$this->assertSame($before[self::uuid(2)]['id'], $gone['id']);
		$this->assertSame('deleted', $gone['lifecycle']);
		$this->assertSame((string) (self::T0 + 60), (string) $gone['lifecycle_changed_at']);
		$this->assertSame((string) self::T0, (string) $gone['last_seen_at']);
		$this->assertCount(1, $this->connectorRows((int) $gone['id']));

		$kept = $after[self::uuid(1)];
		$this->assertSame('active', $kept['lifecycle']);
		$this->assertSame((string) self::T0, (string) $kept['lifecycle_changed_at']);
		$this->assertSame((string) (self::T0 + 60), (string) $kept['last_seen_at']);

		$again = $this->reconciler(self::T0 + 120)->applyListing($this->accountId, array(self::tunnel(1)));
		$this->assertSame(array('added' => 0, 'updated' => 1, 'deleted' => 0), $again);
		$this->assertSame(
			(string) (self::T0 + 60),
			(string) $this->tunnelsByUuid()[self::uuid(2)]['lifecycle_changed_at']
		);
	}

	public function testEmptyListingDeletesOnlyThatAccount(): void {
		$other = $this->insertAccount('b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2');
		$this->reconciler()->applyListing($this->accountId, array(self::tunnel(1)));
		$this->reconciler()->applyListing($other, array(self::tunnel(1), self::tunnel(2)));

		$counts = $this->reconciler(self::T0 + 60)->applyListing($this->accountId, array());
		$this->assertSame(array('added' => 0, 'updated' => 0, 'deleted' => 1), $counts);

		$lifecycles = $this->db->fetchAll(
			'SELECT account_id, lifecycle FROM plugin_cloudflare_tunnels ORDER BY account_id, cf_tunnel_id'
		);
		$this->assertSame(
			array('deleted', 'active', 'active'),
			array_column($lifecycles, 'lifecycle')
		);
	}

	public function testReappearingTunnelReactivates(): void {
		$this->reconciler()->applyListing($this->accountId, array(self::tunnel(1)));
		$id = $this->tunnelId(1);
		$this->reconciler(self::T0 + 60)->applyListing($this->accountId, array());

		$counts = $this->reconciler(self::T0 + 120)->applyListing(
			$this->accountId,
			array(self::tunnel(1, 'renamed', 'down'))
		);
		$this->assertSame(array('added' => 0, 'updated' => 1, 'deleted' => 0), $counts);

		$row = $this->tunnelsByUuid()[self::uuid(1)];
		$this->assertSame($id, (int) $row['id']);
		$this->assertSame('active', $row['lifecycle']);
		$this->assertSame('renamed', $row['name']);
		$this->assertSame('down', $row['cf_status']);
		$this->assertSame((string) self::T0, (string) $row['first_seen_at']);
		$this->assertSame((string) (self::T0 + 120), (string) $row['last_seen_at']);
		$this->assertSame((string) (self::T0 + 120), (string) $row['lifecycle_changed_at']);
	}

	public function testMarkUnavailableNeverDeletes(): void {
		$other = $this->insertAccount('b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2');
		$this->reconciler()->applyListing($this->accountId, array(self::tunnel(1), self::tunnel(2), self::tunnel(3)));
		$this->reconciler()->applyListing($other, array(self::tunnel(1)));
		$this->reconciler(self::T0 + 60)->applyListing($this->accountId, array(self::tunnel(1), self::tunnel(2)));
		$this->reconciler()->applyConnections($this->tunnelId(1), array(self::connector(1, 4)));
		$before = $this->tunnelsByUuid();

		$changed = $this->reconciler(self::T0 + 120)->markUnavailable($this->accountId);
		$this->assertSame(2, $changed);

		$rows = $this->db->fetchAll(
			'SELECT id, cf_tunnel_id, lifecycle, lifecycle_changed_at FROM plugin_cloudflare_tunnels
			WHERE account_id = ? ORDER BY cf_tunnel_id',
			array($this->accountId)
		);
		$this->assertCount(3, $rows);
		$this->assertSame(array('unavailable', 'unavailable', 'deleted'), array_column($rows, 'lifecycle'));
		$this->assertSame(
			array((string) (self::T0 + 120), (string) (self::T0 + 120), (string) (self::T0 + 60)),
			array_map('strval', array_column($rows, 'lifecycle_changed_at'))
		);
		$this->assertSame($before[self::uuid(1)]['id'], $rows[0]['id']);
		$this->assertSame(
			'active',
			$this->db->fetchCell('SELECT lifecycle FROM plugin_cloudflare_tunnels WHERE account_id = ?', array($other))
		);
		$this->assertCount(1, $this->connectorRows($this->tunnelId(1)));

		$this->assertSame(0, $this->reconciler(self::T0 + 180)->markUnavailable($this->accountId));

		$this->reconciler(self::T0 + 240)->applyListing($this->accountId, array(self::tunnel(1), self::tunnel(2)));
		$this->assertSame('active', $this->tunnelsByUuid()[self::uuid(1)]['lifecycle']);
		$this->assertSame(
			(string) (self::T0 + 240),
			(string) $this->tunnelsByUuid()[self::uuid(1)]['lifecycle_changed_at']
		);
	}

	public function testConnectorsReplacedPerTunnel(): void {
		$this->reconciler()->applyListing($this->accountId, array(self::tunnel(1), self::tunnel(2)));
		$one = $this->tunnelId(1);
		$two = $this->tunnelId(2);

		$this->reconciler()->applyConnections($one, array(self::connector(1, 4), self::connector(2, 2)));
		$this->reconciler()->applyConnections($two, array(self::connector(9, 1)));
		$keptId = $this->connectorRows($one)[0]['id'];

		$this->reconciler(self::T0 + 60)->applyConnections(
			$one,
			array(self::connector(1, 3, array('FRA')), self::connector(3, 1))
		);

		$rows = $this->connectorRows($one);
		$this->assertSame(array(self::uuid(1001), self::uuid(1003)), array_column($rows, 'cf_connector_id'));
		$this->assertSame($keptId, $rows[0]['id']);
		$this->assertSame('2026.1.1', $rows[0]['version']);
		$this->assertSame('linux_amd64', $rows[0]['arch']);
		$this->assertSame('1750000001', (string) $rows[0]['run_at']);
		$this->assertSame('3', (string) $rows[0]['connection_count']);
		$this->assertSame('FRA', $rows[0]['colos']);
		$this->assertSame('203.0.113.1', $rows[0]['origin_ips']);
		$this->assertSame((string) (self::T0 + 60), (string) $rows[0]['last_seen_at']);
		$this->assertCount(1, $this->connectorRows($two));

		$this->reconciler()->applyConnections($one, array());
		$this->assertSame(array(), $this->connectorRows($one));
		$this->assertCount(1, $this->connectorRows($two));
	}

	public function testConnectorListsJoinedWithCommas(): void {
		$this->reconciler()->applyListing($this->accountId, array(self::tunnel(1)));
		$connector = new ConnectorRecord(
			self::uuid(1001),
			'2026.1.0',
			'linux_arm64',
			null,
			4,
			array('AMS', 'LHR'),
			array('192.0.2.1', '2001:db8::1')
		);

		$this->reconciler()->applyConnections($this->tunnelId(1), array($connector));

		$row = $this->connectorRows($this->tunnelId(1))[0];
		$this->assertSame('AMS,LHR', $row['colos']);
		$this->assertSame('192.0.2.1,2001:db8::1', $row['origin_ips']);
		$this->assertNull($row['run_at']);
	}

	public function testCountsUpdated(): void {
		$this->reconciler()->applyListing($this->accountId, array(self::tunnel(1), self::tunnel(2)));
		$one = $this->tunnelId(1);

		$this->reconciler()->applyConnections($one, array(self::connector(1, 4), self::connector(2, 3)));
		$row = $this->tunnelsByUuid()[self::uuid(1)];
		$this->assertSame('2', (string) $row['connector_count']);
		$this->assertSame('7', (string) $row['connection_count']);

		$this->reconciler()->applyConnections($one, array());
		$row = $this->tunnelsByUuid()[self::uuid(1)];
		$this->assertSame('0', (string) $row['connector_count']);
		$this->assertSame('0', (string) $row['connection_count']);

		$this->assertNull($this->tunnelsByUuid()[self::uuid(2)]['connector_count']);
	}

	public function testConnectionsStampRefreshTime(): void {
		$this->reconciler()->applyListing($this->accountId, array(self::tunnel(1), self::tunnel(2)));
		$this->assertNull($this->tunnelsByUuid()[self::uuid(1)]['connections_refreshed_at']);

		$this->reconciler(self::T0 + 60)->applyConnections($this->tunnelId(1), array(self::connector(1, 4)));
		$this->reconciler(self::T0 + 90)->applyListing($this->accountId, array(self::tunnel(1), self::tunnel(2)));

		$rows = $this->tunnelsByUuid();
		$this->assertSame((string) (self::T0 + 60), (string) $rows[self::uuid(1)]['connections_refreshed_at']);
		$this->assertNull($rows[self::uuid(2)]['connections_refreshed_at']);
	}

	public function testDuplicateIdsInListingCountOnce(): void {
		$counts = $this->reconciler()->applyListing(
			$this->accountId,
			array(self::tunnel(1), self::tunnel(1, 'second'))
		);
		$this->assertSame(array('added' => 1, 'updated' => 0, 'deleted' => 0), $counts);
		$this->assertSame('second', $this->tunnelsByUuid()[self::uuid(1)]['name']);

		$this->reconciler()->applyConnections(
			$this->tunnelId(1),
			array(self::connector(1, 4), self::connector(1, 2))
		);
		$row = $this->tunnelsByUuid()[self::uuid(1)];
		$this->assertSame('1', (string) $row['connector_count']);
		$this->assertSame('2', (string) $row['connection_count']);
	}

	public function testTransactionRollsBackOnFailure(): void {
		$this->reconciler()->applyListing($this->accountId, array(self::tunnel(1)));
		$before = $this->tunnels();

		// A real server-side failure on the second upsert, after the first has run.
		$this->db->execute(
			"CREATE TRIGGER cf_test_reject BEFORE INSERT ON plugin_cloudflare_tunnels FOR EACH ROW
			BEGIN
				IF NEW.name = 'reject-me' THEN
					SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rejected';
				END IF;
			END"
		);

		try {
			$this->reconciler(self::T0 + 60)->applyListing(
				$this->accountId,
				array(self::tunnel(2), self::tunnel(3, 'reject-me'), self::tunnel(4))
			);
			$this->fail('Expected DatabaseException');
		} catch (DatabaseException $e) {
			$this->assertSame('45000', $e->getSqlState());
		}

		$this->assertSame($before, $this->tunnels());
	}

	public function testConnectionsRollBackOnFailure(): void {
		$this->reconciler()->applyListing($this->accountId, array(self::tunnel(1)));
		$id = $this->tunnelId(1);
		$this->reconciler()->applyConnections($id, array(self::connector(1, 4)));
		$tunnelsBefore = $this->tunnels();
		$connectorsBefore = $this->connectorRows($id);

		$this->db->execute(
			"CREATE TRIGGER cf_test_reject BEFORE INSERT ON plugin_cloudflare_connectors FOR EACH ROW
			BEGIN
				IF NEW.arch = 'reject-me' THEN
					SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rejected';
				END IF;
			END"
		);

		$bad = new ConnectorRecord(self::uuid(1002), '2026.1.0', 'reject-me', null, 1, array(), array());

		try {
			$this->reconciler(self::T0 + 60)->applyConnections($id, array(self::connector(1, 9), $bad));
			$this->fail('Expected DatabaseException');
		} catch (DatabaseException $e) {
			$this->assertSame('45000', $e->getSqlState());
		}

		$this->assertSame($connectorsBefore, $this->connectorRows($id));
		$this->assertSame($tunnelsBefore, $this->tunnels());
	}
}
