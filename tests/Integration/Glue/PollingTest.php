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

namespace Cacti\PluginCloudflare\Tests\Integration\Glue;

use Cacti\PluginCloudflare\Account\AccountRepository;
use Cacti\PluginCloudflare\Discovery\DiscoveryService;
use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Storage\Migrator;
use Cacti\PluginCloudflare\Tests\Integration\IntegrationTestCase;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use PDO;
use RuntimeException;

/**
 * Drives the poller hook and the collector's main logic against real
 * MariaDB through the $database_sessions slot Cacti fills.
 */
final class PollingTest extends IntegrationTestCase {
	private const ROOT    = __DIR__ . '/../../..';
	private const SESSION = 'cacti-db:3306:cacti';
	private const LOCK    = 'plugin_cloudflare_collector';

	/** @var PDO|null */
	private $pdo;

	/** @var Database|null */
	private $db;

	/** @var PDO|null */
	private $holder;

	public static function setUpBeforeClass(): void {
		require_once self::ROOT . '/tests/Support/CactiGlueStubs.php';
		require_once self::ROOT . '/setup.php';
		require_once self::ROOT . '/includes/polling.php';
	}

	protected function setUp(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();
		cloudflare_glue_reset();

		$this->pdo = TestDatabase::pdo();
		$this->db  = new Database($this->pdo);

		/* The collector reads Cacti's plugin_config itself; temporary, so it lives on this handle only. */
		$this->pdo->exec('CREATE TEMPORARY TABLE plugin_config (directory VARCHAR(32) NOT NULL, status TINYINT NOT NULL)');
		$this->pdo->exec("INSERT INTO plugin_config VALUES ('cloudflare', 1), ('thold', 1)");

		$GLOBALS['database_hostname'] = 'cacti-db';
		$GLOBALS['database_port']     = '3306';
		$GLOBALS['database_default']  = 'cacti';
		$GLOBALS['database_sessions'] = array(self::SESSION => $this->pdo);
		$GLOBALS['config']            = array('poller_id' => 1);

		$GLOBALS['cloudflare_glue']['settings']['cloudflare_key_file'] = sys_get_temp_dir() . '/cf-absent-'
			. bin2hex(random_bytes(6));
		$GLOBALS['cloudflare_glue']['settings']['path_php_binary']     = '/usr/bin/php';
	}

	protected function tearDown(): void {
		if ($this->holder !== null) {
			$this->holder->query("SELECT RELEASE_LOCK('" . self::LOCK . "')");
		}

		unset(
			$GLOBALS['database_sessions'],
			$GLOBALS['database_hostname'],
			$GLOBALS['database_port'],
			$GLOBALS['database_default'],
			$GLOBALS['config']
		);
		TestDatabase::reset();
	}

	private function db(): Database {
		$this->assertNotNull($this->db);

		return $this->db;
	}

	private function migrate(): void {
		(new Migrator($this->db(), Migrator::defaultMigrations()))->migrate();
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function account(string $cfAccountId, array $row = array()): int {
		return (new AccountRepository($this->db()))->insert($row + array(
			'cf_account_id'      => $cfAccountId,
			'name'               => 'acme',
			'token_source'       => 'file',
			'token_file'         => sys_get_temp_dir() . '/cf-absent-token-' . $cfAccountId,
			'discovery_interval' => 300,
			'created_at'         => 1,
			'updated_at'         => 1,
		));
	}

	private function holdLock(): void {
		$this->holder = TestDatabase::pdo();
		$got          = $this->holder->query("SELECT GET_LOCK('" . self::LOCK . "', 0)");
		$this->assertNotFalse($got);
		$this->assertSame(1, (int) $got->fetchColumn());
	}

	private function lockIsFree(): bool {
		return (int) $this->db()->fetchCell('SELECT IS_FREE_LOCK(?)', array(self::LOCK)) === 1;
	}

	private function jobCount(): int {
		return (int) $this->db()->fetchCell('SELECT COUNT(*) FROM plugin_cloudflare_jobs');
	}

	/**
	 * @return array<int, string>
	 */
	private static function loggedLines(): array {
		return array_map(function (array $call): string {
			return (string) $call[0];
		}, cloudflare_glue_calls('cacti_log'));
	}

	/* In-process by design (R9); the real script's subprocess path is covered by the Task 10 smoke stack. */
	public function testSecondCollectorExitsWhenLockHeld(): void {
		$this->migrate();
		$this->account('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1');
		$this->holdLock();
		$built = false;

		$status = cloudflare_collector_main(function () use (&$built) {
			$built = true;

			return cloudflare_discovery_service();
		});

		$this->assertSame(0, $status);
		$this->assertFalse($built, 'no discovery runs without the lock');
		$this->assertSame(0, $this->jobCount());
		$this->assertSame(array(), self::loggedLines());
	}

	public function testCollectorEnsuresJobsRunsDueAndLogsSummary(): void {
		$this->migrate();
		$collecting = $this->account('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1');
		$this->account('b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2', array('collect_tunnels' => 0));
		$this->account('c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3', array('enabled' => 0));

		$this->assertSame(0, cloudflare_collector_main('cloudflare_discovery_service'));

		$jobs = $this->db()->fetchAll('SELECT account_id, job_type FROM plugin_cloudflare_jobs');
		$this->assertSame(array(array('account_id' => $collecting, 'job_type' => 'discovery')), array_map(
			function (array $row): array {
				return array('account_id' => (int) $row['account_id'], 'job_type' => (string) $row['job_type']);
			},
			$jobs
		));

		/* The absent token file fails the account before any request is made. */
		$this->assertContains(
			'CLOUDFLARE: collector ok=0 partial=0 failed=1 skipped=0 lease_lost=0',
			self::loggedLines()
		);
		$levels = array();
		foreach (cloudflare_glue_calls('cacti_log') as $call) {
			$this->assertSame('CLOUDFLARE', $call[2]);
			$levels[(string) $call[0]] = $call[3];
		}
		$failure = 'CLOUDFLARE: discovery token unavailable account=' . $collecting . ' reason=file_unavailable';
		$this->assertSame(array(
			$failure                                                               => POLLER_VERBOSITY_LOW,
			'CLOUDFLARE: collector ok=0 partial=0 failed=1 skipped=0 lease_lost=0' => POLLER_VERBOSITY_MEDIUM,
		), $levels);

		$this->assertTrue($this->lockIsFree());
		$this->assertSame(array(), cloudflare_glue_calls('db_connect_real'));
	}

	/*
	 * api_plugin_is_enabled() caches its first answer for the process, so a
	 * disable made while the collector runs is only visible in plugin_config.
	 */
	public function testDisableBetweenAccountsStopsTheRun(): void {
		$this->migrate();
		$pdo = $this->pdo;
		$this->assertNotNull($pdo);

		$first  = $this->account('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1');
		$second = $this->account('b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2');
		cloudflare_collector_ensure_jobs($this->db());

		/* The first account fails on its absent token file; disable the plugin right after. */
		$GLOBALS['cloudflare_glue']['on_log'] = function (string $line) use ($pdo): void {
			if (strpos($line, 'token unavailable') !== false) {
				$pdo->exec("UPDATE plugin_config SET status = 0 WHERE directory = 'cloudflare'");
			}
		};

		$outcomes = cloudflare_discovery_service()->runDue(50);

		$this->assertSame(array($first), array_keys($outcomes));
		$this->assertContains('CLOUDFLARE: discovery plugin disabled, stopping accounts=1', self::loggedLines());
		$this->assertSame('never', $this->db()->fetchCell(
			'SELECT last_status FROM plugin_cloudflare_jobs WHERE account_id = ?',
			array($second)
		));
	}

	public function testMissingPluginRowStopsTheRunBeforeAnyAccount(): void {
		$this->migrate();
		$this->account('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1');
		cloudflare_collector_ensure_jobs($this->db());
		$this->db()->execute('DELETE FROM plugin_config WHERE directory = ?', array('cloudflare'));

		$this->assertSame(array(), cloudflare_discovery_service()->runDue(50));
	}

	public function testCollectorReleasesTheLockWhenDiscoveryFails(): void {
		$this->migrate();

		$status = cloudflare_collector_main(function (): DiscoveryService {
			throw new RuntimeException('boom with secret detail');
		});

		$this->assertSame(1, $status);
		$this->assertSame(array('CLOUDFLARE: collector failed (RuntimeException)'), self::loggedLines());
		$this->assertSame(POLLER_VERBOSITY_LOW, cloudflare_glue_calls('cacti_log')[0][3]);
		$this->assertTrue($this->lockIsFree());
	}

	public function testPollerBottomLaunchesCollectorWhenAnAccountCollects(): void {
		$this->migrate();
		$this->account('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1');

		cloudflare_poller_bottom();

		$script = (string) realpath(self::ROOT) . '/poller_cloudflare.php';
		$this->assertSame(
			array(array('/usr/bin/php', '-q ' . escapeshellarg($script), '')),
			cloudflare_glue_calls('exec_background')
		);
		$this->assertFileExists($script);
	}

	public function testPollerBottomSkipsWithoutACollectingAccount(): void {
		$this->migrate();
		$this->account('b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2', array('collect_tunnels' => 0));
		$this->account('c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3', array('enabled' => 0));

		cloudflare_poller_bottom();
		unset($GLOBALS['config']['poller_id']);
		cloudflare_poller_bottom();

		$this->assertSame(array(), cloudflare_glue_calls('exec_background'));
	}

	public function testPollerBottomTreatsAnUnsetPollerIdAsTheMainPoller(): void {
		$this->migrate();
		$this->account('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1');
		$GLOBALS['config'] = array();

		cloudflare_poller_bottom();

		$this->assertCount(1, cloudflare_glue_calls('exec_background'));
	}

	public function testPollerBottomStaysSilentBeforeTheSchemaExists(): void {
		cloudflare_poller_bottom();

		$this->assertSame(array(), cloudflare_glue_calls('exec_background'));
		$this->assertSame(array(), self::loggedLines());
	}

	public function testUpgradeRegistersPollerBottom(): void {
		$GLOBALS['cloudflare_glue']['cell'] = '0.1.0';

		$this->assertTrue(plugin_cloudflare_upgrade());

		/* Cacti inserts a new non-settings hook disabled (lib/plugins.php:1046),
		 * so an enabled plugin must ask for it enabled. */
		$this->assertSame(
			array(array('cloudflare', 'poller_bottom', 'cloudflare_poller_bottom', 'includes/polling.php', true)),
			cloudflare_glue_calls('api_plugin_register_hook')
		);

		cloudflare_glue_reset();
		$GLOBALS['cloudflare_glue']['cell']    = '0.1.0';
		$GLOBALS['cloudflare_glue']['enabled'] = false;
		$this->assertTrue(plugin_cloudflare_upgrade());
		$this->assertSame(
			array(array('cloudflare', 'poller_bottom', 'cloudflare_poller_bottom', 'includes/polling.php', false)),
			cloudflare_glue_calls('api_plugin_register_hook')
		);

		cloudflare_glue_reset();
		$GLOBALS['cloudflare_glue']['cell'] = '0.2.0';
		$this->assertTrue(plugin_cloudflare_upgrade());
		$this->assertSame(array(), cloudflare_glue_calls('api_plugin_register_hook'));
	}
}
