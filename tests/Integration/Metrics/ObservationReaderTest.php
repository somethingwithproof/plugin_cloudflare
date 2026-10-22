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

namespace Cacti\PluginCloudflare\Tests\Integration\Metrics;

use Cacti\PluginCloudflare\Api\ConnectorRecord;
use Cacti\PluginCloudflare\Discovery\Reconciler;
use Cacti\PluginCloudflare\Metrics\Catalog;
use Cacti\PluginCloudflare\Metrics\Observation;
use Cacti\PluginCloudflare\Metrics\ObservationReader;
use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Storage\Migrator;
use Cacti\PluginCloudflare\Tests\Integration\IntegrationTestCase;
use Cacti\PluginCloudflare\Tests\Support\FrozenClock;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use Cacti\PluginCloudflare\Version;

final class ObservationReaderTest extends IntegrationTestCase {
	const T0 = 1800000000;

	const STATE = array('health', 'connectors', 'connections', 'versions');

	const COLLECTION = array('collectAge', 'collectFailures');

	/** @var Database */
	private $db;

	/** @var FrozenClock */
	private $clock;

	/** @var ObservationReader */
	private $reader;

	/** @var int */
	private $accountId;

	protected function setUp(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();

		$this->db = new Database(TestDatabase::pdo());
		(new Migrator($this->db, Migrator::defaultMigrations()))->migrate();

		$this->clock     = new FrozenClock(self::T0);
		$this->reader    = new ObservationReader($this->db, $this->clock);
		$this->accountId = $this->insertAccount('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1', 300);
		$this->insertJob($this->accountId, 'ok', null, 0);
	}

	protected function tearDown(): void {
		TestDatabase::reset();
	}

	private static function uuid(int $n): string {
		return sprintf('%08x-0000-4000-8000-%012x', $n, $n);
	}

	private function insertAccount(string $cfAccountId, int $interval): int {
		$this->db->execute(
			"INSERT INTO plugin_cloudflare_accounts
			(cf_account_id, name, token_source, discovery_interval, created_at, updated_at)
			VALUES (?, 'acme', 'file', ?, 1, 1)",
			array($cfAccountId, $interval)
		);

		return $this->db->lastInsertId();
	}

	private function insertJob(int $accountId, string $status, ?string $errorClass, int $failures): void {
		$this->db->execute(
			"INSERT INTO plugin_cloudflare_jobs
			(job_type, account_id, last_status, last_error_class, consecutive_failures, last_finished_at)
			VALUES ('discovery', ?, ?, ?, ?, ?)",
			array($accountId, $status, $errorClass, $failures, self::T0)
		);
	}

	/**
	 * A fresh, fully collected tunnel; $overrides replaces columns.
	 *
	 * @param array<string, int|string|null> $overrides
	 */
	private function insertTunnel(int $n, array $overrides = array(), ?int $accountId = null): int {
		$row = array_merge(array(
			'account_id'               => $accountId ?? $this->accountId,
			'cf_tunnel_id'             => self::uuid($n),
			'name'                     => 'tunnel-' . $n,
			'cf_status'                => 'healthy',
			'tun_type'                 => 'cfd_tunnel',
			'lifecycle'                => 'active',
			'connector_count'          => 2,
			'connection_count'         => 4,
			'connections_refreshed_at' => self::T0,
			'connections_attempted_at' => self::T0,
			'connections_error_class'  => null,
			'first_seen_at'            => self::T0,
			'last_seen_at'             => self::T0,
			'lifecycle_changed_at'     => self::T0,
		), $overrides);

		$this->db->execute(
			'INSERT INTO plugin_cloudflare_tunnels (' . implode(', ', array_keys($row)) . ')
			VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')',
			array_values($row)
		);

		return $this->db->lastInsertId();
	}

	private function insertConnector(int $tunnelRowId, string $connectorId, string $version, int $connections): void {
		$this->db->execute(
			"INSERT INTO plugin_cloudflare_connectors
			(tunnel_id, cf_connector_id, version, arch, run_at, connection_count, colos, origin_ips, last_seen_at)
			VALUES (?, ?, ?, 'linux_amd64', NULL, ?, 'lhr', '', ?)",
			array($tunnelRowId, $connectorId, $version, $connections, self::T0)
		);
	}

	private static function connector(string $id, int $connections): ConnectorRecord {
		return new ConnectorRecord($id, '2026.9.1', 'linux_amd64', null, $connections, array('lhr'), array());
	}

	/**
	 * @param array<string, Observation> $obs
	 * @return array<string, string>
	 */
	private static function printed(array $obs): array {
		return array_map(static function (Observation $o): string {
			return $o->rrdValue();
		}, $obs);
	}

	/**
	 * @param array<string, Observation> $obs
	 * @param array<int, string>         $fields
	 */
	private function assertUnknown(array $obs, array $fields, string $reason): void {
		foreach ($fields as $field) {
			$this->assertNull($obs[$field]->value(), $field);
			$this->assertSame('U', $obs[$field]->rrdValue(), $field);
			$this->assertSame($reason, $obs[$field]->reason(), $field);
		}
	}

	public function testFreshSuccessfulTunnelHasValues(): void {
		$row = $this->insertTunnel(1);
		$this->insertConnector($row, self::uuid(101), '2026.9.1', 2);
		$this->insertConnector($row, self::uuid(102), '2026.9.1', 2);
		$this->clock->advance(30);

		$obs = $this->reader->observe($this->accountId, self::uuid(1));

		$this->assertSame(array_map(static function ($m): string {
			return $m->queryField();
		}, Catalog::all()), array_keys($obs));
		$this->assertSame(array(
			'health'          => '3',
			'connectors'      => '2',
			'connections'     => '4',
			'versions'        => '1',
			'collectAge'      => '30',
			'collectFailures' => '0',
		), self::printed($obs));

		foreach ($obs as $field => $o) {
			$this->assertNull($o->reason(), $field);
			$this->assertSame(Version::METRIC_SCHEMA, $o->schemaVersion(), $field);
			$this->assertSame(Catalog::byField($field)->name(), $o->metric(), $field);
		}
		$this->assertSame(self::T0, $obs['health']->observedAt());
		$this->assertSame(self::T0, $obs['connections']->observedAt());
	}

	public function testFailedJobMakesStateUnknown(): void {
		$this->db->execute(
			"UPDATE plugin_cloudflare_jobs SET last_status = 'failed', last_error_class = 'transient',
			consecutive_failures = 2 WHERE account_id = ?",
			array($this->accountId)
		);
		$this->insertTunnel(1);

		$obs = $this->reader->observe($this->accountId, self::uuid(1));

		$this->assertUnknown($obs, self::STATE, 'transient');
		$this->assertSame('2', $obs['collectFailures']->rrdValue());
		$this->assertNull($obs['collectFailures']->reason());
		$this->assertSame('0', $obs['collectAge']->rrdValue());
	}

	public function testFailedJobReasonIsTheStoredClass(): void {
		$this->db->execute(
			"UPDATE plugin_cloudflare_jobs SET last_status = 'failed', last_error_class = 'auth' WHERE account_id = ?",
			array($this->accountId)
		);
		$this->insertTunnel(1);

		$this->assertUnknown($this->reader->observe($this->accountId, self::uuid(1)), self::STATE, 'auth');
	}

	public function testNeverRunJobIsNeverCollected(): void {
		$this->db->execute(
			"UPDATE plugin_cloudflare_jobs SET last_status = 'never' WHERE account_id = ?",
			array($this->accountId)
		);
		$this->insertTunnel(1);

		$this->assertUnknown($this->reader->observe($this->accountId, self::uuid(1)), self::STATE, 'never_collected');
	}

	public function testPartialJobStillPrints(): void {
		$this->db->execute(
			"UPDATE plugin_cloudflare_jobs SET last_status = 'partial' WHERE account_id = ?",
			array($this->accountId)
		);
		$this->insertTunnel(1);

		$this->assertSame('4', $this->reader->observe($this->accountId, self::uuid(1))['connections']->rrdValue());
	}

	public function testFailedConnectionsAttemptIsUnknown(): void {
		$this->insertTunnel(1, array(
			'connections_refreshed_at' => self::T0 - 100,
			'connections_attempted_at' => self::T0,
			'connections_error_class'  => 'transient',
		));

		$obs = $this->reader->observe($this->accountId, self::uuid(1));

		$this->assertUnknown($obs, array('connectors', 'connections', 'versions'), 'transient');
		$this->assertSame('3', $obs['health']->rrdValue());
		$this->assertSame('100', $obs['collectAge']->rrdValue());
	}

	public function testFailedConnectionsAttemptWithoutClassFallsBackToTransient(): void {
		$this->insertTunnel(1, array(
			'connections_refreshed_at' => self::T0 - 100,
			'connections_attempted_at' => self::T0,
		));

		$obs = $this->reader->observe($this->accountId, self::uuid(1));

		$this->assertUnknown($obs, array('connectors', 'connections', 'versions'), 'transient');
	}

	public function testCollectorStoppedGoesUnknownAfterWindow(): void {
		$this->insertTunnel(1);

		$this->clock->advance(660);
		$obs = $this->reader->observe($this->accountId, self::uuid(1));
		foreach (self::STATE as $field) {
			$this->assertNotSame('U', $obs[$field]->rrdValue(), $field);
			$this->assertNull($obs[$field]->reason(), $field);
		}

		$this->clock->advance(1);
		$obs = $this->reader->observe($this->accountId, self::uuid(1));
		$this->assertUnknown($obs, self::STATE, 'stale');
		$this->assertSame('661', $obs['collectAge']->rrdValue());
		$this->assertSame('0', $obs['collectFailures']->rrdValue());
	}

	public function testWindowFollowsTheAccountInterval(): void {
		$this->db->execute(
			'UPDATE plugin_cloudflare_accounts SET discovery_interval = 60 WHERE id = ?',
			array($this->accountId)
		);
		$this->insertTunnel(1);
		$this->clock->advance(181);

		$this->assertUnknown($this->reader->observe($this->accountId, self::uuid(1)), self::STATE, 'stale');
	}

	public function testFutureObservationBeyondToleranceIsUnknown(): void {
		$this->insertTunnel(1, array(
			'connections_refreshed_at' => self::T0 + 61,
			'connections_attempted_at' => self::T0 + 61,
			'last_seen_at'             => self::T0 + 61,
		));
		$this->insertTunnel(2, array(
			'connections_refreshed_at' => self::T0 + 60,
			'connections_attempted_at' => self::T0 + 60,
			'last_seen_at'             => self::T0 + 60,
		));

		$skewed = $this->reader->observe($this->accountId, self::uuid(1));
		$this->assertUnknown($skewed, self::STATE, 'clock_skew');
		$this->assertUnknown($skewed, array('collectAge'), 'clock_skew');

		$tolerated = $this->reader->observe($this->accountId, self::uuid(2));
		$this->assertSame('0', $tolerated['collectAge']->rrdValue());
		$this->assertSame('3', $tolerated['health']->rrdValue());
		$this->assertSame('4', $tolerated['connections']->rrdValue());
	}

	public function testUnavailableKeepsCollectionMetricsOnly(): void {
		$this->db->execute(
			"UPDATE plugin_cloudflare_jobs SET last_status = 'failed', last_error_class = 'transient',
			consecutive_failures = 3 WHERE account_id = ?",
			array($this->accountId)
		);
		$this->insertTunnel(1, array('lifecycle' => 'unavailable'));
		$this->clock->advance(900);

		$obs = $this->reader->observe($this->accountId, self::uuid(1));

		$this->assertUnknown($obs, self::STATE, 'transient');
		$this->assertSame('900', $obs['collectAge']->rrdValue());
		$this->assertSame('3', $obs['collectFailures']->rrdValue());
	}

	public function testUnavailableWithHealthyJobIsNotActive(): void {
		$this->insertTunnel(1, array('lifecycle' => 'unavailable'));

		$obs = $this->reader->observe($this->accountId, self::uuid(1));

		$this->assertUnknown($obs, self::STATE, 'not_active');
		$this->assertSame('0', $obs['collectAge']->rrdValue());
	}

	public function testDeletedTunnelAllUnknown(): void {
		$this->insertTunnel(1, array('lifecycle' => 'deleted'));

		$this->assertUnknown(
			$this->reader->observe($this->accountId, self::uuid(1)),
			array_merge(self::STATE, self::COLLECTION),
			'not_active'
		);
	}

	public function testNeverRefreshedTunnelHasNoConnectionOrCollectionValues(): void {
		$this->insertTunnel(1, array(
			'connector_count'          => null,
			'connection_count'         => null,
			'connections_refreshed_at' => null,
			'connections_attempted_at' => null,
		));

		$obs = $this->reader->observe($this->accountId, self::uuid(1));

		$this->assertSame('3', $obs['health']->rrdValue());
		$this->assertUnknown(
			$obs,
			array('connectors', 'connections', 'versions', 'collectAge', 'collectFailures'),
			'never_collected'
		);
	}

	public function testZeroConnectorsAfterSuccessfulReadIsRealZero(): void {
		$this->insertTunnel(1, array('cf_status' => 'inactive', 'connector_count' => 0, 'connection_count' => 0));

		$obs = $this->reader->observe($this->accountId, self::uuid(1));

		foreach (self::STATE as $field) {
			$this->assertSame(0, $obs[$field]->value(), $field);
			$this->assertSame('0', $obs[$field]->rrdValue(), $field);
			$this->assertNull($obs[$field]->reason(), $field);
		}
	}

	public function testConnectorRestartKeepsGauge(): void {
		$row = $this->insertTunnel(1);

		(new Reconciler($this->db, new FrozenClock(self::T0)))->applyConnections(
			$row,
			array(self::connector(self::uuid(101), 2), self::connector(self::uuid(102), 2))
		);
		$before = self::printed($this->reader->observe($this->accountId, self::uuid(1)));

		(new Reconciler($this->db, new FrozenClock(self::T0)))->applyConnections(
			$row,
			array(self::connector(self::uuid(101), 2), self::connector(self::uuid(103), 2))
		);
		$after = self::printed($this->reader->observe($this->accountId, self::uuid(1)));

		$this->assertSame($before, $after);
		$this->assertSame(array('3', '2', '4', '1', '0', '0'), array_values($after));
		foreach ($after as $field => $value) {
			$this->assertGreaterThanOrEqual(0, (int) $value, $field);
		}
	}

	public function testVersionsCountsDistinctVersions(): void {
		$row = $this->insertTunnel(1, array('connector_count' => 3, 'connection_count' => 6));
		$this->insertConnector($row, self::uuid(101), '2026.9.1', 2);
		$this->insertConnector($row, self::uuid(102), '2026.9.1', 2);
		$this->insertConnector($row, self::uuid(103), '2026.8.0', 2);

		$this->assertSame(2, $this->reader->observe($this->accountId, self::uuid(1))['versions']->value());
	}

	public function testUnknownTunnelIsUnknown(): void {
		$other = $this->insertAccount('b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2', 300);
		$this->insertJob($other, 'ok', null, 0);
		$this->insertTunnel(0xabc, array(), $other);

		$all = array_merge(self::STATE, self::COLLECTION);
		$this->assertUnknown($this->reader->observe($this->accountId, self::uuid(9)), $all, 'unknown_tunnel');
		$this->assertUnknown($this->reader->observe($this->accountId, self::uuid(0xabc)), $all, 'unknown_tunnel');
		$this->assertUnknown($this->reader->observe($this->accountId, ''), $all, 'unknown_tunnel');
		$this->assertUnknown($this->reader->observe($other, strtoupper(self::uuid(0xabc))), $all, 'unknown_tunnel');
		$this->assertUnknown($this->reader->observe($other, self::uuid(0xabc) . ';DROP'), $all, 'unknown_tunnel');
		$this->assertSame('3', $this->reader->observe($other, self::uuid(0xabc))['health']->rrdValue());
		$this->assertUnknown($this->reader->observe(0, self::uuid(0xabc)), $all, 'unknown_tunnel');
	}

	public function testTunnelIndexListsEveryLifecycleById(): void {
		$other = $this->insertAccount('b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2', 300);
		$this->insertTunnel(3, array('name' => 'gone', 'lifecycle' => 'deleted'));
		$this->insertTunnel(1, array('name' => 'web'));
		$this->insertTunnel(2, array('name' => 'api', 'lifecycle' => 'unavailable'));
		$this->insertTunnel(4, array('name' => 'theirs'), $other);

		$this->assertSame(array(
			self::uuid(1) => 'web',
			self::uuid(2) => 'api',
			self::uuid(3) => 'gone',
		), $this->reader->tunnelIndex($this->accountId));
		$this->assertSame(array(), $this->reader->tunnelIndex(0));
	}

	public function testAccountForHostReadsTheDeviceRow(): void {
		$this->db->execute(
			"INSERT INTO plugin_cloudflare_resources (account_id, kind, tunnel_id, template_hash, host_id,
			local_graph_id, created_at) VALUES (?, 'device', 0, '', 42, NULL, 1),
			(?, 'graph', 5, 'abcdefabcdefabcdefabcdefabcdefab', 43, 9, 1)",
			array($this->accountId, $this->accountId)
		);

		$this->assertSame($this->accountId, $this->reader->accountForHost(42));
		$this->assertNull($this->reader->accountForHost(43));
		$this->assertNull($this->reader->accountForHost(44));
	}
}
