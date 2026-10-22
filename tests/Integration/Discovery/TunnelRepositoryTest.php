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
use Cacti\PluginCloudflare\Discovery\TunnelRepository;
use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Storage\Migrator;
use Cacti\PluginCloudflare\Tests\Support\FrozenClock;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use InvalidArgumentException;
use Cacti\PluginCloudflare\Tests\Integration\IntegrationTestCase;

final class TunnelRepositoryTest extends IntegrationTestCase {
	const T0 = 1800000000;

	/** @var Database */
	private $db;

	/** @var TunnelRepository */
	private $repo;

	/** @var int */
	private $accountId;

	/** @var int */
	private $otherId;

	protected function setUp(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();

		$this->db = new Database(TestDatabase::pdo());
		(new Migrator($this->db, Migrator::defaultMigrations()))->migrate();
		$this->repo = new TunnelRepository($this->db);

		$this->accountId = $this->insertAccount('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1');
		$this->otherId = $this->insertAccount('b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2');

		$reconciler = new Reconciler($this->db, new FrozenClock(self::T0));
		$reconciler->applyListing($this->accountId, array(
			self::tunnel(1, 'web', 'healthy'),
			self::tunnel(2, 'api', 'degraded'),
			self::tunnel(3, '50%_off', 'down'),
			self::tunnel(4, '50x_off', 'healthy'),
			self::tunnel(5, 'api', 'inactive'),
			self::tunnel(6, 'old!', 'healthy'),
		));
		$reconciler->applyListing($this->otherId, array(self::tunnel(7, 'web', 'healthy')));

		$later = new Reconciler($this->db, new FrozenClock(self::T0 + 60));
		$later->applyListing($this->accountId, array(
			self::tunnel(1, 'web', 'healthy'),
			self::tunnel(2, 'api', 'degraded'),
			self::tunnel(3, '50%_off', 'down'),
			self::tunnel(4, '50x_off', 'healthy'),
			self::tunnel(5, 'api', 'inactive'),
		));
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

	private static function uuid(int $n): string {
		return sprintf('%08x-0000-4000-8000-%012x', $n, $n);
	}

	private static function tunnel(int $n, string $name, string $status): TunnelRecord {
		return new TunnelRecord(self::uuid($n), $name, $status, 'cfd_tunnel', null, null, null);
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, string>
	 */
	private static function uuids(array $rows): array {
		return array_column($rows, 'cf_tunnel_id');
	}

	public function testForAccountSortsByNameThenId(): void {
		$rows = $this->repo->forAccount($this->accountId);

		$this->assertSame(
			array(self::uuid(3), self::uuid(4), self::uuid(2), self::uuid(5), self::uuid(6), self::uuid(1)),
			self::uuids($rows)
		);
		$this->assertSame('50%_off', $rows[0]['name']);
		$this->assertSame('down', $rows[0]['cf_status']);
		$this->assertSame('active', $rows[0]['lifecycle']);
	}

	public function testForAccountPages(): void {
		$this->assertSame(
			array(self::uuid(2), self::uuid(5)),
			self::uuids($this->repo->forAccount($this->accountId, array(), 2, 2))
		);
		$this->assertSame(array(), $this->repo->forAccount($this->accountId, array(), 50, 6));
	}

	public function testRejectsBadPaging(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->repo->forAccount($this->accountId, array(), 0);
	}

	public function testRejectsNegativeOffset(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->repo->forAccount($this->accountId, array(), 10, -1);
	}

	public function testFilterByLifecycleAndStatus(): void {
		$this->assertSame(
			array(self::uuid(6)),
			self::uuids($this->repo->forAccount($this->accountId, array('lifecycle' => 'deleted')))
		);
		$this->assertSame(
			array(self::uuid(4), self::uuid(1)),
			self::uuids($this->repo->forAccount($this->accountId, array('lifecycle' => 'active', 'cf_status' => 'healthy')))
		);
		$this->assertSame(0, $this->repo->count($this->accountId, array('lifecycle' => 'unavailable')));
	}

	public function testSearchEscapesLikeWildcards(): void {
		$this->assertSame(
			array(self::uuid(3)),
			self::uuids($this->repo->forAccount($this->accountId, array('search' => '%_')))
		);
		$this->assertSame(
			array(self::uuid(6)),
			self::uuids($this->repo->forAccount($this->accountId, array('search' => 'd!')))
		);
		$this->assertSame(
			array(self::uuid(2), self::uuid(5)),
			self::uuids($this->repo->forAccount($this->accountId, array('search' => 'AP')))
		);
		$this->assertSame(2, $this->repo->count($this->accountId, array('search' => '50')));
		$this->assertSame(6, $this->repo->count($this->accountId, array('search' => '')));
	}

	public function testCountAcrossAccounts(): void {
		$this->assertSame(6, $this->repo->count($this->accountId));
		$this->assertSame(1, $this->repo->count($this->otherId));
		$this->assertSame(7, $this->repo->count());
		$this->assertSame(2, $this->repo->count(0, array('search' => 'web')));
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function invalidFilters(): array {
		return array(
			'unknown key'        => array(array('name' => 'web')),
			'unknown lifecycle'  => array(array('lifecycle' => 'gone')),
			'unknown status'     => array(array('cf_status' => 'HEALTHY')),
			'non-string status'  => array(array('cf_status' => 1)),
			'non-string search'  => array(array('search' => array('web'))),
			'lifecycle as array' => array(array('lifecycle' => array('active'))),
		);
	}

	/**
	 * @dataProvider invalidFilters
	 * @param array<string, mixed> $filters
	 */
	public function testInvalidFiltersThrow(array $filters): void {
		$this->expectException(InvalidArgumentException::class);
		$this->repo->forAccount($this->accountId, $filters);
	}

	/**
	 * @dataProvider invalidFilters
	 * @param array<string, mixed> $filters
	 */
	public function testInvalidFiltersThrowOnCount(array $filters): void {
		$this->expectException(InvalidArgumentException::class);
		$this->repo->count($this->accountId, $filters);
	}

	public function testConnectorsForTunnel(): void {
		$tunnelId = (int) $this->repo->forAccount($this->accountId, array('search' => 'web'))[0]['id'];
		$reconciler = new Reconciler($this->db, new FrozenClock(self::T0));
		$reconciler->applyConnections($tunnelId, array(
			new ConnectorRecord(self::uuid(1002), '2026.1.0', 'linux_amd64', null, 2, array('LHR'), array()),
			new ConnectorRecord(self::uuid(1001), '2026.1.0', 'linux_arm64', null, 4, array('AMS'), array()),
		));

		$rows = $this->repo->connectors($tunnelId);
		$this->assertSame(array(self::uuid(1001), self::uuid(1002)), array_column($rows, 'cf_connector_id'));
		$this->assertSame('AMS', $rows[0]['colos']);
		$this->assertSame(array(), $this->repo->connectors($tunnelId + 1000));
	}

	public function testMarkAccountUnavailable(): void {
		$this->assertSame(5, $this->repo->markAccountUnavailable($this->accountId, self::T0 + 99));

		$this->assertSame(5, $this->repo->count($this->accountId, array('lifecycle' => 'unavailable')));
		$this->assertSame(1, $this->repo->count($this->accountId, array('lifecycle' => 'deleted')));
		$this->assertSame(1, $this->repo->count($this->otherId, array('lifecycle' => 'active')));
		$this->assertSame(0, $this->repo->markAccountUnavailable($this->accountId, self::T0 + 120));
	}
}
