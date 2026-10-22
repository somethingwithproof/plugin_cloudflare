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

namespace Cacti\PluginCloudflare\Tests\Integration\Jobs;

use Cacti\PluginCloudflare\Api\ErrorClass;
use Cacti\PluginCloudflare\Jobs\JobRepository;
use Cacti\PluginCloudflare\Jobs\Lease;
use Cacti\PluginCloudflare\Jobs\LeaseLost;
use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Storage\DatabaseException;
use Cacti\PluginCloudflare\Storage\Migrator;
use Cacti\PluginCloudflare\Tests\Integration\IntegrationTestCase;
use Cacti\PluginCloudflare\Tests\Support\FrozenClock;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use InvalidArgumentException;

final class JobRepositoryTest extends IntegrationTestCase {
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

	private function insertAccount(string $cfAccountId, int $enabled = 1, int $collectTunnels = 1): int {
		$this->db->execute(
			"INSERT INTO plugin_cloudflare_accounts
			(cf_account_id, name, token_source, enabled, collect_tunnels, created_at, updated_at)
			VALUES (?, 'acme', 'file', ?, ?, 1, 1)",
			array($cfAccountId, $enabled, $collectTunnels)
		);

		return $this->db->lastInsertId();
	}

	private function repo(int $now = self::T0, ?Database $db = null): JobRepository {
		return new JobRepository($db ?? $this->db, new FrozenClock($now));
	}

	private function jobId(int $accountId, string $type = 'discovery'): int {
		return (int) $this->db->fetchCell(
			'SELECT id FROM plugin_cloudflare_jobs WHERE job_type = ? AND account_id = ?',
			array($type, $accountId)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function job(int $id): array {
		$row = $this->db->fetchRow('SELECT * FROM plugin_cloudflare_jobs WHERE id = ?', array($id));
		$this->assertIsArray($row);

		return $row;
	}

	/* Lease expiry follows the database clock, not the FrozenClock the repository is given. */
	private function dbNow(): int {
		return (int) $this->db->fetchCell('SELECT UNIX_TIMESTAMP()');
	}

	private function setLeaseUntil(int $id, int $offset): void {
		$this->db->execute(
			'UPDATE plugin_cloudflare_jobs SET lease_until = UNIX_TIMESTAMP() + ? WHERE id = ?',
			array($offset, $id)
		);
	}

	private function leased(JobRepository $repo, int $jobId, string $owner, int $ttl = 60): Lease {
		$lease = $repo->lease($jobId, $owner, $ttl);
		$this->assertInstanceOf(Lease::class, $lease);

		return $lease;
	}

	public function testEnsureIdempotent(): void {
		$repo = $this->repo();

		$repo->ensure('discovery', $this->accountId);
		$id = $this->jobId($this->accountId);
		$this->db->execute('UPDATE plugin_cloudflare_jobs SET next_run_at = 42 WHERE id = ?', array($id));
		$repo->ensure('discovery', $this->accountId);

		$this->assertSame(1, (int) $this->db->fetchCell('SELECT COUNT(*) FROM plugin_cloudflare_jobs'));
		$this->assertSame(42, (int) $this->job($id)['next_run_at']);
		$this->assertSame('never', $this->job($id)['last_status']);
	}

	public function testEnsureMissingAccountThrows(): void {
		$this->expectException(DatabaseException::class);
		$this->repo()->ensure('discovery', $this->accountId + 1000);
	}

	/**
	 * @dataProvider invalidTypes
	 */
	public function testEnsureRejectsOverlongType(string $type): void {
		try {
			$this->repo()->ensure($type, $this->accountId);
			$this->fail('ensure() accepted ' . $type);
		} catch (InvalidArgumentException $e) {
			$this->assertSame(0, (int) $this->db->fetchCell('SELECT COUNT(*) FROM plugin_cloudflare_jobs'));
		}
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function invalidTypes(): array {
		return array(
			'33 characters' => array(str_repeat('a', 33)),
			'40 characters' => array(str_repeat('discover', 5)),
			'empty'         => array(''),
			'upper case'    => array('Discovery'),
			'hyphen'        => array('tunnel-discovery'),
			'digit'         => array('discovery2'),
		);
	}

	public function testEnsureAcceptsLongestType(): void {
		$this->repo()->ensure(str_repeat('a', 32), $this->accountId);

		$this->assertNotSame(0, $this->jobId($this->accountId, str_repeat('a', 32)));
	}

	public function testLeaseIsExclusive(): void {
		$this->repo()->ensure('discovery', $this->accountId);
		$id = $this->jobId($this->accountId);

		$first = $this->repo(self::T0, new Database(TestDatabase::pdo()));
		$second = $this->repo(self::T0, new Database(TestDatabase::pdo()));

		$before = $this->dbNow();
		$lease = $this->leased($first, $id, 'host-a:1', 60);
		$after = $this->dbNow();

		$this->assertNull($second->lease($id, 'host-b:2', 60));
		$this->assertNull($first->lease($id, 'host-a:1', 60));
		$this->assertSame($id, $lease->jobId());
		$this->assertSame('host-a:1', $lease->owner());
		$this->assertGreaterThanOrEqual($before + 60, $lease->leaseUntil());
		$this->assertLessThanOrEqual($after + 60, $lease->leaseUntil());

		$row = $this->job($id);
		$this->assertSame('host-a:1', $row['lease_owner']);
		$this->assertSame($lease->leaseUntil(), (int) $row['lease_until']);
		$this->assertSame(self::T0, (int) $row['last_started_at']);
	}

	public function testLeaseOfMissingJobIsNull(): void {
		$this->assertNull($this->repo()->lease(999, 'host-a:1', 60));
	}

	public function testLeaseRejectsNonPositiveTtl(): void {
		$this->repo()->ensure('discovery', $this->accountId);

		$this->expectException(InvalidArgumentException::class);
		$this->repo()->lease($this->jobId($this->accountId), 'host-a:1', 0);
	}

	public function testExpiredLeaseCanBeTaken(): void {
		$this->repo()->ensure('discovery', $this->accountId);
		$id = $this->jobId($this->accountId);
		$this->leased($this->repo(self::T0), $id, 'host-a:1', 60);

		$this->setLeaseUntil($id, 5);
		$this->assertNull($this->repo(self::T0 + 61)->lease($id, 'host-b:2', 60));

		$this->setLeaseUntil($id, -1);
		$this->leased($this->repo(self::T0 + 61), $id, 'host-b:2', 60);

		$this->assertSame('host-b:2', $this->job($id)['lease_owner']);
		$this->assertSame(self::T0 + 61, (int) $this->job($id)['last_started_at']);
	}

	public function testLeaseExpiryIgnoresTheHostClock(): void {
		$this->repo()->ensure('discovery', $this->accountId);
		$id = $this->jobId($this->accountId);
		$this->leased($this->repo(self::T0), $id, 'host-a:1', 60);

		/* A poller whose clock runs a day ahead of the leaseholder's must not see the lease as expired. */
		$skewed = $this->repo(self::T0 + 86400);

		$this->assertNull($skewed->lease($id, 'host-b:2', 60));
		$this->assertSame(array(), $skewed->due('discovery', 10));
	}

	public function testStaleLeaseCannotOverwrite(): void {
		$this->repo()->ensure('discovery', $this->accountId);
		$id = $this->jobId($this->accountId);
		$stale = $this->leased($this->repo(self::T0), $id, 'host-a:1', 1);
		$this->setLeaseUntil($id, -1);
		$this->leased($this->repo(self::T0 + 61), $id, 'host-b:2', 60);
		$before = $this->job($id);

		$late = $this->repo(self::T0 + 90);
		$attempts = array(
			function () use ($late, $stale): void {
				$late->succeed($stale, self::T0 + 900);
			},
			function () use ($late, $stale): void {
				$late->fail($stale, ErrorClass::TRANSIENT, self::T0 + 900);
			},
			function () use ($late, $stale): void {
				$late->release($stale);
			},
			function () use ($late, $stale): void {
				$late->touch($stale);
			},
		);

		foreach ($attempts as $attempt) {
			try {
				$attempt();
				$this->fail('a stale lease wrote to the job');
			} catch (LeaseLost $e) {
				$this->assertSame($before, $this->job($id));
			}
		}
	}

	public function testSameOwnerCannotReuseAnEarlierLease(): void {
		$this->repo()->ensure('discovery', $this->accountId);
		$id = $this->jobId($this->accountId);
		$stale = $this->leased($this->repo(self::T0), $id, 'host-a:1', 1);
		$this->setLeaseUntil($id, -1);
		$this->leased($this->repo(self::T0 + 61), $id, 'host-a:1', 60);

		$this->expectException(LeaseLost::class);
		$this->repo(self::T0 + 90)->succeed($stale, self::T0 + 900);
	}

	public function testTouchConfirmsAHeldLease(): void {
		$this->repo()->ensure('discovery', $this->accountId);
		$id = $this->jobId($this->accountId);
		$lease = $this->leased($this->repo(self::T0), $id, 'host-a:1');
		$before = $this->job($id);

		$this->repo(self::T0 + 5)->touch($lease);

		$this->assertSame($before, $this->job($id));
	}

	public function testLostLeaseRollsBackWritesInTheSameTransaction(): void {
		$this->repo()->ensure('discovery', $this->accountId);
		$id = $this->jobId($this->accountId);
		$stale = $this->leased($this->repo(self::T0), $id, 'host-a:1', 1);
		$this->setLeaseUntil($id, -1);
		$this->leased($this->repo(self::T0 + 61), $id, 'host-b:2', 60);
		$repo = $this->repo(self::T0 + 90);

		try {
			$this->db->transactional(function () use ($repo, $stale): void {
				$this->db->execute(
					"UPDATE plugin_cloudflare_accounts SET name = 'changed' WHERE id = ?",
					array($this->accountId)
				);
				$repo->touch($stale);
			});
			$this->fail('touch() accepted a stale lease');
		} catch (LeaseLost $e) {
			$this->assertSame('acme', $this->db->fetchCell(
				'SELECT name FROM plugin_cloudflare_accounts WHERE id = ?',
				array($this->accountId)
			));
		}
	}

	public function testOutageStreakCountsOnlyOutagesAndResetsOnListing(): void {
		$this->repo()->ensure('discovery', $this->accountId);
		$id = $this->jobId($this->accountId);

		$lease = $this->leased($this->repo(), $id, 'host-a:1');
		$this->assertSame(1, $this->repo()->fail($lease, ErrorClass::TRANSIENT, self::T0, true));
		$lease = $this->leased($this->repo(), $id, 'host-a:1');
		$this->repo()->fail($lease, ErrorClass::TRANSIENT, self::T0, true);
		$lease = $this->leased($this->repo(), $id, 'host-a:1');
		$this->assertSame(3, $this->repo()->fail($lease, ErrorClass::RATE_LIMITED, self::T0));

		$this->assertSame(2, (int) $this->job($id)['outage_failures']);
		$this->assertSame(3, (int) $this->job($id)['consecutive_failures']);

		$lease = $this->leased($this->repo(), $id, 'host-a:1');
		$this->assertSame(2, $this->repo()->outageStreak($lease));
		$this->repo()->listed($lease);
		$this->assertSame(0, (int) $this->job($id)['outage_failures']);
		$this->assertSame(3, (int) $this->job($id)['consecutive_failures'], 'backoff still sees every failure');
		$this->assertSame('host-a:1', $this->job($id)['lease_owner'], 'listed() keeps the lease');

		$this->stealAndExpectLeaseLost($id, $lease);
	}

	private function stealAndExpectLeaseLost(int $id, Lease $lease): void {
		$this->db->execute("UPDATE plugin_cloudflare_jobs SET lease_owner = 'other' WHERE id = ?", array($id));
		$this->expectException(LeaseLost::class);
		$this->repo()->listed($lease);
	}

	public function testPartialResetsFailuresAndReleases(): void {
		$this->repo()->ensure('discovery', $this->accountId);
		$id = $this->jobId($this->accountId);

		$lease = $this->leased($this->repo(self::T0), $id, 'host-a:1');
		$this->repo(self::T0)->fail($lease, ErrorClass::TRANSIENT, self::T0 + 600);

		$lease = $this->leased($this->repo(self::T0 + 10), $id, 'host-a:1');
		$this->repo(self::T0 + 15)->partial($lease, self::T0 + 315);

		$row = $this->job($id);
		$this->assertSame('partial', $row['last_status']);
		$this->assertNull($row['last_error_class']);
		$this->assertSame(0, (int) $row['consecutive_failures']);
		$this->assertSame(self::T0 + 15, (int) $row['last_finished_at']);
		$this->assertSame(self::T0 + 315, (int) $row['next_run_at']);
		$this->assertNull($row['lease_owner']);
		$this->assertNull($row['lease_until']);

		$this->expectException(LeaseLost::class);
		$this->repo()->partial($lease, self::T0 + 900);
	}

	public function testFailIncrementsAndSucceedResets(): void {
		$this->repo()->ensure('discovery', $this->accountId);
		$id = $this->jobId($this->accountId);

		$lease = $this->leased($this->repo(self::T0), $id, 'host-a:1');
		$this->assertSame(1, $this->repo(self::T0 + 5)->fail($lease, ErrorClass::TRANSIENT, self::T0 + 600));

		$row = $this->job($id);
		$this->assertSame('failed', $row['last_status']);
		$this->assertSame('transient', $row['last_error_class']);
		$this->assertSame(self::T0 + 600, (int) $row['next_run_at']);
		$this->assertSame(self::T0 + 5, (int) $row['last_finished_at']);
		$this->assertNull($row['lease_owner']);
		$this->assertNull($row['lease_until']);

		$lease = $this->leased($this->repo(self::T0 + 10), $id, 'host-a:1');
		$this->assertSame(2, $this->repo(self::T0 + 15)->fail($lease, ErrorClass::RATE_LIMITED, self::T0 + 700));
		$this->assertSame('rate_limited', $this->job($id)['last_error_class']);

		$lease = $this->leased($this->repo(self::T0 + 20), $id, 'host-a:1');
		$this->repo(self::T0 + 25)->succeed($lease, self::T0 + 325);

		$row = $this->job($id);
		$this->assertSame('ok', $row['last_status']);
		$this->assertNull($row['last_error_class']);
		$this->assertSame(0, (int) $row['consecutive_failures']);
		$this->assertSame(self::T0 + 20, (int) $row['last_started_at']);
		$this->assertSame(self::T0 + 25, (int) $row['last_finished_at']);
		$this->assertSame(self::T0 + 325, (int) $row['next_run_at']);
		$this->assertNull($row['lease_owner']);
		$this->assertNull($row['lease_until']);
	}

	public function testReleaseFreesTheJobWithoutRecordingARun(): void {
		$this->repo()->ensure('discovery', $this->accountId);
		$id = $this->jobId($this->accountId);
		$lease = $this->leased($this->repo(self::T0), $id, 'host-a:1');

		$this->repo(self::T0 + 5)->release($lease);

		$row = $this->job($id);
		$this->assertNull($row['lease_owner']);
		$this->assertNull($row['lease_until']);
		$this->assertSame('never', $row['last_status']);
		$this->assertNull($row['last_finished_at']);
		$this->assertNotNull($this->repo(self::T0 + 6)->lease($id, 'host-b:2', 60));
	}

	public function testDueSkipsDisabledAccounts(): void {
		$disabled = $this->insertAccount('b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2', 0, 1);
		$noTunnels = $this->insertAccount('c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3', 1, 0);
		$repo = $this->repo();

		foreach (array($this->accountId, $disabled, $noTunnels) as $accountId) {
			$repo->ensure('discovery', $accountId);
		}

		$due = $repo->due('discovery', 10);

		$this->assertSame(array($this->accountId), array_map('intval', array_column($due, 'account_id')));
		$this->assertSame(array(), $repo->due('analytics', 10));
	}

	public function testDueSkipsFutureAndLeasedJobsInRunOrder(): void {
		$ids = array();
		foreach (array('b2', 'c3', 'd4', 'e5') as $prefix) {
			$account = $this->insertAccount(str_repeat($prefix, 16));
			$this->repo()->ensure('discovery', $account);
			$ids[$prefix] = $this->jobId($account);
		}
		$this->repo()->ensure('discovery', $this->accountId);
		$first = $this->jobId($this->accountId);

		$set = 'UPDATE plugin_cloudflare_jobs SET next_run_at = ? WHERE id = ?';
		$this->db->execute($set, array(self::T0, $ids['b2']));
		$this->db->execute($set, array(self::T0 + 1, $ids['c3']));
		$this->db->execute($set, array(self::T0 - 100, $ids['d4']));
		$this->db->execute($set, array(self::T0 - 100, $ids['e5']));
		/* e5's lease is still live by the database clock; the first job's ended a second ago. */
		$this->leased($this->repo(), $ids['e5'], 'host-a:1', 60);
		$this->leased($this->repo(), $first, 'host-a:1', 60);
		$this->setLeaseUntil($first, -1);

		$due = $this->repo()->due('discovery', 10);

		$this->assertSame(
			array($first, $ids['d4'], $ids['b2']),
			array_map('intval', array_column($due, 'id'))
		);
		$this->assertSame(array($first), array_map('intval', array_column($this->repo()->due('discovery', 1), 'id')));
	}

	public function testDueRejectsNonPositiveLimit(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->repo()->due('discovery', 0);
	}

	public function testDefaultOwnerIsHostAndPid(): void {
		$owner = JobRepository::defaultOwner();

		$this->assertSame(substr(gethostname() . ':' . getmypid(), -64), $owner);
		$this->assertLessThanOrEqual(64, strlen($owner));
	}

	/**
	 * @dataProvider nextRunRules
	 */
	public function testNextRunTable(
		?string $errorClass,
		?int $retryAfter,
		int $failures,
		int $interval,
		int $delay
	): void {
		$this->assertSame(
			self::T0 + $delay,
			JobRepository::nextRun(self::T0, $interval, $errorClass, $retryAfter, $failures)
		);
	}

	/**
	 * @return array<string, array{0: ?string, 1: ?int, 2: int, 3: int, 4: int}>
	 */
	public function nextRunRules(): array {
		return array(
			'success'                        => array(null, null, 0, 300, 300),
			'success ignores failures'       => array(null, 120, 4, 300, 300),
			'rate limited, no retry-after'   => array(ErrorClass::RATE_LIMITED, null, 1, 300, 60),
			'rate limited, short retry'      => array(ErrorClass::RATE_LIMITED, 5, 1, 300, 60),
			'rate limited, long retry'       => array(ErrorClass::RATE_LIMITED, 900, 1, 300, 900),
			'transient, no failures'         => array(ErrorClass::TRANSIENT, null, 0, 300, 300),
			'transient, one failure'         => array(ErrorClass::TRANSIENT, null, 1, 300, 600),
			'transient, three failures'      => array(ErrorClass::TRANSIENT, null, 3, 300, 2400),
			'transient, capped'              => array(ErrorClass::TRANSIENT, null, 4, 300, 3600),
			'transient, many failures'       => array(ErrorClass::TRANSIENT, null, 200, 300, 3600),
			'transient ignores retry-after'  => array(ErrorClass::TRANSIENT, 30, 1, 60, 120),
			'auth'                           => array(ErrorClass::AUTH, null, 1, 300, 3600),
			'permission'                     => array(ErrorClass::PERMISSION, 60, 2, 300, 3600),
			'config'                         => array(ErrorClass::CONFIG, null, 5, 300, 300),
			'malformed'                      => array(ErrorClass::MALFORMED, null, 1, 600, 600),
			'not found uses the interval'    => array(ErrorClass::NOT_FOUND, null, 1, 300, 300),
			'unknown class uses the interval' => array('something_new', null, 3, 300, 300),
		);
	}
}
