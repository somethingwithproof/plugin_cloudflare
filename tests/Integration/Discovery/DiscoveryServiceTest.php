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

use Cacti\PluginCloudflare\Account\AccountRepository;
use Cacti\PluginCloudflare\Account\CredentialResolver;
use Cacti\PluginCloudflare\Api\CloudflareClient;
use Cacti\PluginCloudflare\Api\ErrorClass;
use Cacti\PluginCloudflare\Api\RetryPolicy;
use Cacti\PluginCloudflare\Discovery\DiscoveryService;
use Cacti\PluginCloudflare\Discovery\Reconciler;
use Cacti\PluginCloudflare\Http\Request;
use Cacti\PluginCloudflare\Http\Response;
use Cacti\PluginCloudflare\Http\TransportException;
use Cacti\PluginCloudflare\Jobs\JobRepository;
use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Storage\DatabaseException;
use Cacti\PluginCloudflare\Storage\Migrator;
use Cacti\PluginCloudflare\Tests\Integration\IntegrationTestCase;
use Cacti\PluginCloudflare\Tests\Support\FakeTransport;
use Cacti\PluginCloudflare\Tests\Support\FrozenClock;
use Cacti\PluginCloudflare\Tests\Support\RecordingSleeper;
use Cacti\PluginCloudflare\Tests\Support\SecretAssertions;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use OutOfBoundsException;

final class DiscoveryServiceTest extends IntegrationTestCase {
	use SecretAssertions;

	private const T0        = 1800000000;
	private const CF_A      = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
	private const CF_B      = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';
	private const BLOG      = 'f70ff985-a4ef-4643-bbbc-4a0ed4fc8415';
	private const STAGING   = '0b6a8e4c-6c3b-4f64-9d6e-2f6c1f8a1d11';
	private const OLDOFFICE = '7d1c3f52-91a4-4a39-8a55-3e1b0c6f2b77';
	private const NAMES     = array('blog', 'staging', 'old-office');

	/** @var Database|null */
	private $db;

	/** @var FakeTransport|null */
	private $transport;

	/** @var FrozenClock|null */
	private $clock;

	/** @var string */
	private $dir;

	/** @var array<int, string> */
	private $logs = array();

	/** @var bool */
	private $enabled = true;

	/** @var callable|null */
	private $beforeAccount;

	/** @var Database|null */
	private $resolverDb;

	protected function setUp(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();

		$this->db = new Database(TestDatabase::pdo());
		(new Migrator($this->db, Migrator::defaultMigrations()))->migrate();

		$this->transport = new FakeTransport();
		$this->clock     = new FrozenClock(self::T0);
		$this->dir       = sys_get_temp_dir() . '/cf-discovery-' . bin2hex(random_bytes(6));
		mkdir($this->dir, 0700);
	}

	protected function tearDown(): void {
		if (is_string($this->dir) && is_dir($this->dir)) {
			foreach ((array) scandir($this->dir) as $name) {
				if ($name !== '.' && $name !== '..') {
					unlink($this->dir . '/' . $name);
				}
			}
			rmdir($this->dir);
		}

		TestDatabase::reset();
	}

	private function db(): Database {
		$this->assertNotNull($this->db);

		return $this->db;
	}

	private function transport(): FakeTransport {
		$this->assertNotNull($this->transport);

		return $this->transport;
	}

	private function clock(): FrozenClock {
		$this->assertNotNull($this->clock);

		return $this->clock;
	}

	private static function token(string $cfAccountId): string {
		return 'cfut_' . substr(hash('sha256', $cfAccountId), 0, 40);
	}

	/* Creates the account with a token file and a due discovery job. */
	private function account(string $cfAccountId, bool $tokenFile = true): int {
		$path = $this->dir . '/' . $cfAccountId;
		if ($tokenFile) {
			file_put_contents($path, self::token($cfAccountId));
			chmod($path, 0600);
		}

		$id = (new AccountRepository($this->db()))->insert(array(
			'cf_account_id'      => $cfAccountId,
			'name'               => 'acme',
			'token_source'       => 'file',
			'token_file'         => $path,
			'discovery_interval' => 300,
			'created_at'         => 1,
			'updated_at'         => 1,
		));
		(new JobRepository($this->db(), $this->clock()))->ensure(JobRepository::TYPE_DISCOVERY, $id);

		return $id;
	}

	private function service(): DiscoveryService {
		$db       = $this->db();
		$clock    = $this->clock();
		$accounts = new AccountRepository($db);

		return new DiscoveryService(
			$accounts,
			new CredentialResolver(new AccountRepository($this->resolverDb ?? $db), null),
			new CloudflareClient($this->transport(), new RetryPolicy($clock, new RecordingSleeper())),
			new Reconciler($db, $clock),
			new JobRepository($db, $clock),
			$db,
			$clock,
			function (): bool {
				if ($this->beforeAccount !== null) {
					($this->beforeAccount)();
				}

				return $this->enabled;
			},
			function (string $line): void {
				$this->logs[] = $line;
			}
		);
	}

	/**
	 * @return array<int, array{status: string, error_class: string|null, added: int, updated: int, deleted: int}>
	 */
	private function runDue(int $budget = 50): array {
		return $this->service()->runDue($budget);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function job(int $accountId): array {
		$row = $this->db()->fetchRow('SELECT * FROM plugin_cloudflare_jobs WHERE account_id = ?', array($accountId));
		$this->assertIsArray($row);

		return $row;
	}

	private function makeDue(int $accountId): void {
		$this->db()->execute('UPDATE plugin_cloudflare_jobs SET next_run_at = 0 WHERE account_id = ?', array($accountId));
	}

	private function stealLease(int $accountId): void {
		$this->db()->execute(
			"UPDATE plugin_cloudflare_jobs SET lease_owner = 'other', lease_until = UNIX_TIMESTAMP() + 600
			WHERE account_id = ?",
			array($accountId)
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function tunnels(int $accountId): array {
		$rows = array();
		foreach ($this->db()->fetchAll(
			'SELECT * FROM plugin_cloudflare_tunnels WHERE account_id = ? ORDER BY id',
			array($accountId)
		) as $row) {
			$rows[(string) $row['cf_tunnel_id']] = $row;
		}

		return $rows;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function connectors(): array {
		return $this->db()->fetchAll('SELECT * FROM plugin_cloudflare_connectors ORDER BY id');
	}

	/**
	 * @return array{status: string, error_class: string|null, added: int, updated: int, deleted: int}
	 */
	private static function outcome(
		string $status,
		?string $class,
		int $added = 0,
		int $updated = 0,
		int $deleted = 0
	): array {
		return array(
			'status'      => $status,
			'error_class' => $class,
			'added'       => $added,
			'updated'     => $updated,
			'deleted'     => $deleted,
		);
	}

	private static function emptyListing(): Response {
		return new Response(200, array(), (string) json_encode(array(
			'success'     => true,
			'errors'      => array(),
			'result'      => array(),
			'result_info' => array('total_count' => 0),
		)));
	}

	private static function noConnectors(): Response {
		return new Response(200, array(), '{"success":true,"errors":[],"result":[]}');
	}

	private function queueTransient(int $attempts = 3): void {
		for ($i = 0; $i < $attempts; $i++) {
			$this->transport()->queue(new TransportException(TransportException::TRANSIENT, 'timeout'));
		}
	}

	/* The listing, then connections for each tunnel in name order: blog, old-office, staging. */
	private function queueFullDiscovery(): void {
		$this->transport()->queue(FakeTransport::fixture('tunnel-list'));
		for ($i = 0; $i < 3; $i++) {
			$this->transport()->queue(FakeTransport::fixture('tunnel-connections'));
		}
	}

	private function discoverOnce(int $accountId): void {
		$this->queueFullDiscovery();
		$this->assertSame('ok', $this->runDue()[$accountId]['status']);
	}

	/**
	 * @return array<int, string>
	 */
	private function urls(): array {
		return array_map(function (Request $r): string {
			return $r->url();
		}, $this->transport()->requests());
	}

	public function testDiscoversThreeTunnels(): void {
		$id = $this->account(self::CF_A);
		$this->queueFullDiscovery();

		$this->assertSame(array($id => self::outcome('ok', null, 3)), $this->runDue());

		$tunnels = $this->tunnels($id);
		$this->assertCount(3, $tunnels);
		foreach ($tunnels as $row) {
			$this->assertSame('active', $row['lifecycle']);
			$this->assertSame('1', (string) $row['connector_count']);
			$this->assertSame('1', (string) $row['connection_count']);
		}
		$this->assertCount(3, $this->connectors());

		$job = $this->job($id);
		$this->assertSame('ok', $job['last_status']);
		$this->assertSame('0', (string) $job['consecutive_failures']);
		$this->assertSame((string) (self::T0 + 300), (string) $job['next_run_at']);
		$this->assertNull($job['lease_owner']);
		$this->assertNull($job['lease_until']);

		$base = 'https://api.cloudflare.com/client/v4/accounts/' . self::CF_A . '/cfd_tunnel';
		$this->assertSame(array(
			$base . '?is_deleted=false&per_page=1000&page=1',
			$base . '/' . self::BLOG . '/connections',
			$base . '/' . self::OLDOFFICE . '/connections',
			$base . '/' . self::STAGING . '/connections',
		), $this->urls());

		$this->assertSame(array(), $this->runDue(), 'a job that is not due runs nothing');
	}

	public function testOutageLeavesTunnelsIntact(): void {
		$id = $this->account(self::CF_A);
		$this->discoverOnce($id);
		$tunnels    = $this->tunnels($id);
		$connectors = $this->connectors();

		$this->makeDue($id);
		$this->queueTransient();

		$this->assertSame(array($id => self::outcome('failed', ErrorClass::TRANSIENT)), $this->runDue());
		$this->assertSame($tunnels, $this->tunnels($id));
		$this->assertSame($connectors, $this->connectors());

		$job = $this->job($id);
		$this->assertSame('failed', $job['last_status']);
		$this->assertSame(ErrorClass::TRANSIENT, $job['last_error_class']);
		$this->assertSame('1', (string) $job['consecutive_failures']);
		$this->assertSame((string) (self::T0 + 600), (string) $job['next_run_at'], 'one failure doubles the interval');
		$this->assertNull($job['lease_owner']);
	}

	/* A repeated id means the pages shifted under us; the next cycle reads a fresh listing. */
	public function testDuplicateTunnelIdRetriesNextCycle(): void {
		$id = $this->account(self::CF_A);
		$this->discoverOnce($id);
		$tunnels = $this->tunnels($id);

		$item = array('id' => self::BLOG, 'name' => 'blog', 'status' => 'healthy');
		$this->makeDue($id);
		$this->transport()->queue(new Response(200, array(), (string) json_encode(array(
			'success'     => true,
			'errors'      => array(),
			'result'      => array($item, $item),
			'result_info' => array('total_count' => 2),
		))));

		$this->assertSame(array($id => self::outcome('failed', ErrorClass::MALFORMED)), $this->runDue());
		$this->assertSame($tunnels, $this->tunnels($id));
		$this->assertSame((string) (self::T0 + 300), (string) $this->job($id)['next_run_at']);
	}

	public function testThreeFailuresMarkUnavailable(): void {
		$id = $this->account(self::CF_A);
		$this->discoverOnce($id);

		for ($run = 1; $run <= 3; $run++) {
			$this->makeDue($id);
			$this->queueTransient();
			$this->assertSame('failed', $this->runDue()[$id]['status']);

			$expected = $run < 3 ? 'active' : 'unavailable';
			$this->assertSame(
				array($expected, $expected, $expected),
				array_values(array_column($this->tunnels($id), 'lifecycle')),
				'after failure ' . $run
			);
		}

		$this->assertSame('3', (string) $this->job($id)['consecutive_failures']);
		$this->assertCount(3, $this->connectors());

		$this->makeDue($id);
		$this->queueFullDiscovery();
		$this->assertSame(self::outcome('ok', null, 0, 3), $this->runDue()[$id]);
		$this->assertSame(
			array('active', 'active', 'active'),
			array_values(array_column($this->tunnels($id), 'lifecycle'))
		);
		$this->assertSame('0', (string) $this->job($id)['consecutive_failures']);
	}

	/* A response we cannot parse says nothing about whether Cloudflare is up. */
	public function testMalformedFailuresNeverMarkUnavailable(): void {
		$id = $this->account(self::CF_A);
		$this->discoverOnce($id);

		for ($run = 1; $run <= 3; $run++) {
			$this->makeDue($id);
			$this->transport()->queue(new Response(200, array(), 'not json'));
			$this->assertSame(ErrorClass::MALFORMED, $this->runDue()[$id]['error_class']);
		}

		$this->assertSame('3', (string) $this->job($id)['consecutive_failures']);
		$this->assertSame(
			array('active', 'active', 'active'),
			array_values(array_column($this->tunnels($id), 'lifecycle'))
		);
	}

	public function testRateLimitDefersByRetryAfter(): void {
		$id = $this->account(self::CF_A);
		$this->transport()->queue(FakeTransport::fixture('error-envelope', 429, array('Retry-After' => '600')));

		$this->assertSame(array($id => self::outcome('failed', ErrorClass::RATE_LIMITED)), $this->runDue());

		$job = $this->job($id);
		$this->assertSame((string) (self::T0 + 600), (string) $job['next_run_at']);
		$this->assertSame(ErrorClass::RATE_LIMITED, $job['last_error_class']);
		$this->assertCount(1, $this->transport()->requests());
	}

	public function testConnectionsRateLimitStopsTheAccount(): void {
		$id = $this->account(self::CF_A);
		$this->transport()->queue(FakeTransport::fixture('tunnel-list'));
		$this->transport()->queue(FakeTransport::fixture('error-envelope', 429, array('Retry-After' => '900')));

		$this->assertSame(array($id => self::outcome('failed', ErrorClass::RATE_LIMITED, 3)), $this->runDue());
		$this->assertCount(2, $this->transport()->requests(), 'no further tunnel is tried');
		$this->assertSame((string) (self::T0 + 900), (string) $this->job($id)['next_run_at']);
		$this->assertCount(3, $this->tunnels($id), 'the complete listing is kept');
	}

	public function testConnectionsAuthFailsTheAccount(): void {
		$id = $this->account(self::CF_A);
		$this->transport()->queue(FakeTransport::fixture('tunnel-list'));
		$this->transport()->queue(FakeTransport::fixture('auth-error', 401));

		$this->assertSame(array($id => self::outcome('failed', ErrorClass::AUTH, 3)), $this->runDue());
		$this->assertCount(2, $this->transport()->requests());
		$this->assertSame((string) (self::T0 + 3600), (string) $this->job($id)['next_run_at']);
	}

	public function testConnections1001DoesNotFailAccount(): void {
		$id = $this->account(self::CF_A);
		$this->transport()->queue(FakeTransport::fixture('tunnel-list'));
		$this->transport()->queue(FakeTransport::fixture('connections-1001', 401));
		$this->transport()->queue(FakeTransport::fixture('tunnel-connections'));
		$this->transport()->queue(FakeTransport::fixture('tunnel-connections'));

		$this->assertSame(array($id => self::outcome('ok', null, 3)), $this->runDue());

		$tunnels = $this->tunnels($id);
		$this->assertNull($tunnels[self::BLOG]['connector_count']);
		$this->assertSame('active', $tunnels[self::BLOG]['lifecycle']);
		$this->assertSame('1', (string) $tunnels[self::OLDOFFICE]['connector_count']);
		$this->assertSame('1', (string) $tunnels[self::STAGING]['connector_count']);
		$this->assertSame('ok', $this->job($id)['last_status']);
		$this->assertSame(
			'unknown',
			$this->db()->fetchCell('SELECT status FROM plugin_cloudflare_accounts WHERE id = ?', array($id))
		);
	}

	public function testConnectionsFailureKeepsThatTunnelsConnectors(): void {
		$id = $this->account(self::CF_A);
		$this->discoverOnce($id);

		$this->makeDue($id);
		$this->transport()->queue(FakeTransport::fixture('tunnel-list'));
		$this->queueTransient();
		$this->transport()->queue(self::noConnectors());
		$this->transport()->queue(self::noConnectors());

		$this->assertSame(array($id => self::outcome('ok', null, 0, 3)), $this->runDue());

		$tunnels = $this->tunnels($id);
		$this->assertSame('1', (string) $tunnels[self::BLOG]['connector_count']);
		$this->assertSame('0', (string) $tunnels[self::OLDOFFICE]['connector_count']);
		$this->assertSame('0', (string) $tunnels[self::STAGING]['connector_count']);
		$this->assertCount(1, $this->connectors());
	}

	public function testDisableMidRunStopsBeforeNextAccount(): void {
		$a = $this->account(self::CF_A);
		$b = $this->account(self::CF_B);
		$this->transport()->queue(function (): Response {
			$this->enabled = false;

			return self::emptyListing();
		});

		$this->assertSame(array($a => self::outcome('ok', null)), $this->runDue());
		$this->assertCount(1, $this->transport()->requests());
		$this->assertSame('never', $this->job($b)['last_status']);
		$this->assertNull($this->job($b)['lease_owner']);
	}

	public function testDisabledPluginStartsNothing(): void {
		$a = $this->account(self::CF_A);
		$this->enabled = false;

		$this->assertSame(array(), $this->runDue());
		$this->assertCount(0, $this->transport()->requests());
		$this->assertNull($this->job($a)['lease_owner']);
	}

	public function testBudgetStopsNewAccounts(): void {
		$a = $this->account(self::CF_A);
		$b = $this->account(self::CF_B);
		$this->transport()->queue(function (): Response {
			$this->clock()->advance(50);

			return self::emptyListing();
		});

		$this->assertSame(array($a => self::outcome('ok', null)), $this->runDue(50));
		$this->assertSame('never', $this->job($b)['last_status']);
	}

	public function testAccountStartedWithinBudgetRuns(): void {
		$a = $this->account(self::CF_A);
		$b = $this->account(self::CF_B);
		$this->transport()->queue(function (): Response {
			$this->clock()->advance(49);

			return self::emptyListing();
		});
		$this->transport()->queue(self::emptyListing());

		$this->assertSame(array($a => self::outcome('ok', null), $b => self::outcome('ok', null)), $this->runDue(50));
	}

	public function testLostLeaseRollsBackListing(): void {
		$id = $this->account(self::CF_A);
		$this->transport()->queue(function () use ($id): Response {
			$this->stealLease($id);

			return FakeTransport::fixture('tunnel-list');
		});

		$this->assertSame(array($id => self::outcome('lease_lost', null)), $this->runDue());
		$this->assertSame(array(), $this->tunnels($id));
		$this->assertCount(1, $this->transport()->requests());

		$job = $this->job($id);
		$this->assertSame('other', $job['lease_owner'], 'the new holder keeps its lease');
		$this->assertSame('never', $job['last_status']);
	}

	public function testLostLeaseStopsConnections(): void {
		$id = $this->account(self::CF_A);
		$this->transport()->queue(FakeTransport::fixture('tunnel-list'));
		$this->transport()->queue(function () use ($id): Response {
			$this->stealLease($id);

			return FakeTransport::fixture('tunnel-connections');
		});

		$this->assertSame(array($id => self::outcome('lease_lost', null, 3)), $this->runDue());
		$this->assertCount(2, $this->transport()->requests());
		$this->assertSame(array(), $this->connectors());
		$this->assertSame('never', $this->job($id)['last_status']);
	}

	public function testLeaseTakenElsewhereSkipsAccount(): void {
		$id                  = $this->account(self::CF_A);
		$this->beforeAccount = function () use ($id): void {
			$this->stealLease($id);
		};

		$this->assertSame(array($id => self::outcome('skipped', null)), $this->runDue());
		$this->assertCount(0, $this->transport()->requests());
	}

	public function testUnreadableTokenIsConfigFailure(): void {
		$id = $this->account(self::CF_A, false);

		$this->assertSame(array($id => self::outcome('failed', ErrorClass::CONFIG)), $this->runDue());
		$this->assertCount(0, $this->transport()->requests());
		$this->assertSame((string) (self::T0 + 300), (string) $this->job($id)['next_run_at']);
	}

	/* Fails every tunnel upsert for one account, as a deadlock or a dropped statement would. */
	private function failTunnelWritesFor(int $accountId): void {
		$this->db()->execute(
			'CREATE TRIGGER cf_test_reject BEFORE INSERT ON plugin_cloudflare_tunnels FOR EACH ROW
			BEGIN
				IF NEW.account_id = ' . $accountId . " THEN
					SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rejected';
				END IF;
			END"
		);
	}

	public function testDatabaseErrorOnOneAccountDoesNotStopOthers(): void {
		$a = $this->account(self::CF_A);
		$b = $this->account(self::CF_B);
		$this->failTunnelWritesFor($a);
		$this->transport()->queue(FakeTransport::fixture('tunnel-list'));
		$this->transport()->queue(self::emptyListing());

		$this->assertSame(
			array($a => self::outcome('failed', ErrorClass::TRANSIENT), $b => self::outcome('ok', null)),
			$this->runDue()
		);
		$this->assertSame(array(), $this->tunnels($a));

		$job = $this->job($a);
		$this->assertSame('failed', $job['last_status']);
		$this->assertSame(ErrorClass::TRANSIENT, $job['last_error_class']);
		$this->assertSame('1', (string) $job['consecutive_failures']);
		$this->assertSame((string) (self::T0 + 600), (string) $job['next_run_at']);
		$this->assertNull($job['lease_owner']);
		$this->assertSame('ok', $this->job($b)['last_status']);

		$logs = implode("\n", $this->logs);
		$this->assertStringContainsString('account=' . $a . ' error=' . DatabaseException::class, $logs);
		$this->assertStringNotContainsString('rejected', $logs);
	}

	/*
	 * The resolver reads through a snapshot taken before account A existed,
	 * which is what it sees if A is deleted after find() and before resolve().
	 */
	public function testDeletedAccountBetweenFindAndResolve(): void {
		$b                = $this->account(self::CF_B);
		$this->resolverDb = new Database(TestDatabase::pdo());
		$this->resolverDb->execute('START TRANSACTION WITH CONSISTENT SNAPSHOT');

		try {
			$a = $this->account(self::CF_A);
			$this->transport()->queue(self::emptyListing());

			$outcomes = $this->runDue();
		} finally {
			$this->resolverDb->execute('ROLLBACK');
		}

		$this->assertSame(
			array($b => self::outcome('ok', null), $a => self::outcome('failed', ErrorClass::TRANSIENT)),
			$outcomes
		);
		$this->assertSame('failed', $this->job($a)['last_status']);
		$this->assertStringContainsString('error=' . OutOfBoundsException::class, implode("\n", $this->logs));
	}

	/**
	 * @dataProvider jobWriteFaults
	 */
	public function testFaultThatCannotBeRecordedReleasesOrStops(string $rejectWhen, bool $released): void {
		$a = $this->account(self::CF_A);
		$b = $this->account(self::CF_B);
		$this->failTunnelWritesFor($a);
		$this->db()->execute(
			'CREATE TRIGGER cf_test_reject_job BEFORE UPDATE ON plugin_cloudflare_jobs FOR EACH ROW
			BEGIN
				IF NEW.account_id = ' . $a . ' AND ' . $rejectWhen . " THEN
					SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rejected';
				END IF;
			END"
		);
		$this->transport()->queue(FakeTransport::fixture('tunnel-list'));
		$this->transport()->queue(self::emptyListing());

		$outcomes = $this->runDue();

		$job = $this->job($a);
		$this->assertSame('never', $job['last_status']);
		if ($released) {
			$this->assertSame(
				array($a => self::outcome('failed', ErrorClass::TRANSIENT), $b => self::outcome('ok', null)),
				$outcomes
			);
			$this->assertNull($job['lease_owner']);
		} else {
			$this->assertSame(array(), $outcomes, 'the run stops before account B');
			$this->assertNotNull($job['lease_owner']);
			$this->assertSame('never', $this->job($b)['last_status']);
		}
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function jobWriteFaults(): array {
		return array(
			'fail rejected, release works' => array("NEW.last_status = 'failed'", true),
			'fail and release rejected'    => array('NEW.lease_owner IS NULL', false),
		);
	}

	public function testLocalFaultDoesNotMarkUnavailable(): void {
		$id = $this->account(self::CF_A);
		$this->discoverOnce($id);
		$this->failTunnelWritesFor($id);

		for ($run = 1; $run <= 3; $run++) {
			$this->makeDue($id);
			$this->transport()->queue(FakeTransport::fixture('tunnel-list'));
			$this->assertSame(ErrorClass::TRANSIENT, $this->runDue()[$id]['error_class']);
		}

		$this->assertSame('3', (string) $this->job($id)['consecutive_failures']);
		$this->assertSame(
			array('active', 'active', 'active'),
			array_values(array_column($this->tunnels($id), 'lifecycle'))
		);
	}

	public function testLogsNeverContainToken(): void {
		$a = $this->account(self::CF_A);
		$b = $this->account(self::CF_B);

		$this->transport()->queue(FakeTransport::fixture('tunnel-list'));
		$this->transport()->queue(FakeTransport::fixture('connections-1001', 401));
		$this->queueTransient();
		$leaky = new Response(500, array(), (string) json_encode(array(
			'success' => false,
			'errors'  => array(array('code' => 9999, 'message' => 'blog staging old-office ' . self::token(self::CF_A))),
		)));
		for ($i = 0; $i < 3; $i++) {
			$this->transport()->queue($leaky);
		}
		$this->transport()->queue(new Response(403, array(), (string) json_encode(array(
			'success' => false,
			'errors'  => array(array('code' => 9109, 'message' => 'denied for ' . self::token(self::CF_B) . ' on blog')),
		))));

		$outcomes = $this->runDue();
		$this->assertSame('ok', $outcomes[$a]['status']);
		$this->assertSame(ErrorClass::PERMISSION, $outcomes[$b]['error_class']);

		$logs = implode("\n", $this->logs);
		$this->assertStringContainsString('account=' . $b, $logs);
		$this->assertStringContainsString('class=' . ErrorClass::PERMISSION, $logs);
		$this->assertStringContainsString('class=' . ErrorClass::TRANSIENT, $logs);
		$this->assertNotLeaked(self::token(self::CF_A), $logs, 'discovery log');
		$this->assertNotLeaked(self::token(self::CF_B), $logs, 'discovery log');
		foreach (self::NAMES as $name) {
			$this->assertStringNotContainsString($name, $logs);
		}
		$this->assertStringNotContainsString(self::CF_A, $logs);
	}
}
