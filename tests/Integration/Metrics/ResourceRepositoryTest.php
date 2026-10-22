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

use Cacti\PluginCloudflare\Account\AccountRepository;
use Cacti\PluginCloudflare\Metrics\ResourceRepository;
use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Storage\DatabaseException;
use Cacti\PluginCloudflare\Storage\Migrator;
use Cacti\PluginCloudflare\Tests\Integration\IntegrationTestCase;
use Cacti\PluginCloudflare\Tests\Support\FrozenClock;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use InvalidArgumentException;

final class ResourceRepositoryTest extends IntegrationTestCase {
	private const NOW = 1800000000;

	/** @var Database */
	private $db;

	/** @var FrozenClock */
	private $clock;

	/** @var ResourceRepository */
	private $repository;

	protected function setUp(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();

		$this->db = new Database(TestDatabase::pdo());
		(new Migrator($this->db, Migrator::defaultMigrations()))->migrate();

		$this->clock      = new FrozenClock(self::NOW);
		$this->repository = new ResourceRepository($this->db, $this->clock);
	}

	protected function tearDown(): void {
		TestDatabase::reset();
	}

	private function account(string $cfAccountId): int {
		return (new AccountRepository($this->db))->insert(array(
			'cf_account_id' => $cfAccountId,
			'name'          => 'Account ' . $cfAccountId,
			'token_source'  => 'file',
			'token_file'    => '/etc/cacti/cf.token',
			'created_at'    => self::NOW,
			'updated_at'    => self::NOW,
		));
	}

	private function rows(): int {
		return (int) $this->db->fetchCell('SELECT COUNT(*) FROM plugin_cloudflare_resources');
	}

	public function testRecordDeviceReplacesRowAndRejectsSharedHost(): void {
		$first  = $this->account(str_repeat('a', 32));
		$second = $this->account(str_repeat('b', 32));

		$this->assertNull($this->repository->device($first));

		$this->repository->recordDevice($first, 41);
		$this->assertSame(array('host_id' => 41, 'created_at' => self::NOW), $this->repository->device($first));

		$this->clock->advance(60);
		$this->repository->recordDevice($first, 42);
		$this->assertSame(array('host_id' => 42, 'created_at' => self::NOW + 60), $this->repository->device($first));
		$this->assertSame(1, $this->rows());

		try {
			$this->repository->recordDevice($second, 42);
			$this->fail('a host owned by another account was recorded');
		} catch (InvalidArgumentException $e) {
			$this->assertNull($this->repository->device($second));
			$this->assertSame(42, $this->repository->device($first)['host_id'] ?? null);
		}

		// Recording the same host again for its own account is not a conflict.
		$this->repository->recordDevice($first, 42);
		$this->assertSame(1, $this->rows());
	}

	public function testRecordGraphReplacesTheRowForATunnelAndTemplate(): void {
		$account = $this->account(str_repeat('a', 32));
		$hash    = md5('plugin_cloudflare/graph_template/health');

		$this->repository->recordDevice($account, 41);
		$this->repository->recordGraph($account, 7, $hash, 41, 100, 200);
		$this->repository->recordGraph($account, 7, $hash, 41, 101, 200);
		$this->repository->recordGraph($account, 8, $hash, 41, 102, 201);

		$this->assertSame(array(
			array('tunnel_id' => 7, 'template_hash' => $hash, 'host_id' => 41, 'local_graph_id' => 101, 'local_data_id' => 200, 'created_at' => self::NOW),
			array('tunnel_id' => 8, 'template_hash' => $hash, 'host_id' => 41, 'local_graph_id' => 102, 'local_data_id' => 201, 'created_at' => self::NOW),
		), $this->repository->graphs($account));

		// The device row is not a graph.
		$this->assertSame(41, $this->repository->device($account)['host_id'] ?? null);
		$this->assertSame(array(), $this->repository->graphs($this->account(str_repeat('b', 32))));
	}

	/**
	 * An admin-deleted graph leaves its row behind, and MySQL 5.7 and older
	 * MariaDB hand the same graph_local.id out again after a restart.
	 */
	public function testRecordGraphDropsAStaleRowForAReusedGraphId(): void {
		$first  = $this->account(str_repeat('a', 32));
		$second = $this->account(str_repeat('b', 32));
		$health = md5('plugin_cloudflare/graph_template/health');
		$conns  = md5('plugin_cloudflare/graph_template/connections');

		$this->repository->recordGraph($first, 7, $health, 41, 100, 200);
		$this->repository->recordGraph($first, 7, $conns, 41, 100, 201);
		$this->repository->recordGraph($second, 9, $health, 42, 100, 202);

		$this->assertSame(array(), $this->repository->graphs($first));
		$this->assertSame(array(
			array('tunnel_id' => 9, 'template_hash' => $health, 'host_id' => 42, 'local_graph_id' => 100, 'local_data_id' => 202, 'created_at' => self::NOW),
		), $this->repository->graphs($second));
	}

	/**
	 * A plain read would see the owner at once and throw
	 * InvalidArgumentException; the locking read waits for the other
	 * transaction instead.
	 */
	public function testSharedHostCheckLocksTheRowsItReads(): void {
		$pdo        = TestDatabase::pdo();
		$repository = new ResourceRepository(new Database($pdo), $this->clock);
		$first      = $this->account(str_repeat('a', 32));
		$second     = $this->account(str_repeat('b', 32));
		$this->repository->recordDevice($first, 41);

		$pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');

		$other = TestDatabase::pdo();
		$other->beginTransaction();
		$other->query('SELECT host_id FROM plugin_cloudflare_resources WHERE host_id = 41 FOR UPDATE');

		try {
			$repository->recordDevice($second, 41);
			$this->fail('the shared-host check did not wait for the locked row');
		} catch (DatabaseException $e) {
			$this->assertSame(1205, $e->getDriverCode(), 'lock wait timeout');
		} finally {
			$other->rollBack();
			$other      = null;
			$repository = null;
			$pdo        = null;
		}

		$this->assertNull($this->repository->device($second));
	}

	public function testDeletingTheAccountRemovesItsResources(): void {
		$account = $this->account(str_repeat('a', 32));

		$this->repository->recordDevice($account, 41);
		(new AccountRepository($this->db))->delete($account);

		$this->assertSame(0, $this->rows());
	}
}
