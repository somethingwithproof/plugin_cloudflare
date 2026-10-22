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

use Cacti\PluginCloudflare\Metrics\Catalog;
use Cacti\PluginCloudflare\Metrics\ObservationReader;
use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Storage\Migrator;
use Cacti\PluginCloudflare\Tests\Integration\IntegrationTestCase;
use Cacti\PluginCloudflare\Tests\Support\FrozenClock;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use PDO;

/**
 * Drives cloudflare_ss() against the test database in the forms Cacti's
 * poller and reindex send it.
 */
final class ScriptServerHandlersTest extends IntegrationTestCase {
	private const ROOT    = __DIR__ . '/../../..';
	private const SESSION = 'cacti-db:3306:cacti';
	private const T0      = 1800000000;
	private const HOST    = 41;

	/** @var Database|null */
	private $db;

	/** @var ObservationReader|null */
	private $reader;

	/** @var int|null */
	private $accountId;

	public static function setUpBeforeClass(): void {
		require_once self::ROOT . '/tests/Support/CactiGlueStubs.php';
		require_once self::ROOT . '/includes/script_server.php';
	}

	protected function setUp(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();
		cloudflare_glue_reset();

		$this->db = new Database(TestDatabase::pdo());
		(new Migrator($this->db, Migrator::defaultMigrations()))->migrate();

		$this->reader    = new ObservationReader($this->db, new FrozenClock(self::T0 + 30));
		$this->accountId = $this->account('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1', self::HOST);
		$this->job($this->accountId);
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['database_sessions'],
			$GLOBALS['database_hostname'],
			$GLOBALS['database_port'],
			$GLOBALS['database_default']
		);

		if ($this->db !== null) {
			TestDatabase::reset();
		}
	}

	private static function uuid(int $n): string {
		return sprintf('%08x-0000-4000-8000-%012x', $n, $n);
	}

	private function account(string $cfAccountId, int $hostId): int {
		$this->db->execute(
			"INSERT INTO plugin_cloudflare_accounts
			(cf_account_id, name, token_source, discovery_interval, created_at, updated_at)
			VALUES (?, 'acme', 'file', 300, 1, 1)",
			array($cfAccountId)
		);
		$id = $this->db->lastInsertId();

		$this->db->execute(
			"INSERT INTO plugin_cloudflare_resources (account_id, kind, host_id, created_at) VALUES (?, 'device', ?, 1)",
			array($id, $hostId)
		);

		return $id;
	}

	private function job(int $accountId): void {
		$this->db->execute(
			"INSERT INTO plugin_cloudflare_jobs
			(job_type, account_id, last_status, last_error_class, consecutive_failures, last_finished_at)
			VALUES ('discovery', ?, 'ok', NULL, 0, ?)",
			array($accountId, self::T0)
		);
	}

	/**
	 * @param array<string, int|string|null> $overrides
	 */
	private function tunnel(int $n, array $overrides = array(), ?int $accountId = null): void {
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
			'first_seen_at'            => self::T0,
			'last_seen_at'             => self::T0,
			'lifecycle_changed_at'     => self::T0,
		), $overrides);

		$this->db->execute(
			'INSERT INTO plugin_cloudflare_tunnels (' . implode(', ', array_keys($row)) . ')
			VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')',
			array_values($row)
		);
	}

	private function ss(string $cmd, string $arg1 = '', string $arg2 = ''): string {
		return cloudflare_ss((string) self::HOST, $cmd, $arg1, $arg2, $this->reader);
	}

	/**
	 * Splits a listing the way lib/data_query.php:748 does in 1.2.31.
	 *
	 * @return array<string, string> index => value
	 */
	private static function parsed(string $listing): array {
		$out = array();

		foreach (explode("\n", rtrim($listing, "\n")) as $line) {
			if (preg_match('/(.*?)' . preg_quote('!') . '(.*)/', $line, $matches)) {
				$out[$matches[1]] = $matches[2];
			}
		}

		return $out;
	}

	public function testIndexListsAllLifecyclesSorted(): void {
		$this->tunnel(3, array('lifecycle' => 'deleted'));
		$this->tunnel(1);
		$this->tunnel(2, array('lifecycle' => 'unavailable'));
		$other = $this->account('b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2', 42);
		$this->tunnel(4, array(), $other);

		$expected = self::uuid(1) . "\n" . self::uuid(2) . "\n" . self::uuid(3) . "\n";

		$this->assertSame($expected, $this->ss('index'));
		$this->assertSame('3', $this->ss('num_indexes'));
		$this->assertSame(
			array(self::uuid(1) => self::uuid(1), self::uuid(2) => self::uuid(2), self::uuid(3) => self::uuid(3)),
			self::parsed($this->ss('query', 'tunnelId'))
		);
	}

	public function testHostWithoutDeviceListsNothing(): void {
		$this->tunnel(1);

		$this->assertSame('', cloudflare_ss('99', 'index', '', '', $this->reader));
		$this->assertSame('0', cloudflare_ss('99', 'num_indexes', '', '', $this->reader));
		$this->assertSame('', cloudflare_ss('99', 'query', 'tunnelName', '', $this->reader));
		$this->assertSame('U', cloudflare_ss('99', 'get', 'health', self::uuid(1), $this->reader));
	}

	public function testQueryNamesUseDelimiter(): void {
		$this->tunnel(1, array('name' => 'edge!lon!1'));
		$this->tunnel(2, array('name' => "two\r\nlines"));

		$listing = $this->ss('query', 'tunnelName');

		$this->assertSame(2, substr_count($listing, "\n"), 'one line per tunnel');
		$this->assertSame(
			array(self::uuid(1) => 'edge!lon!1', self::uuid(2) => 'two  lines'),
			self::parsed($listing)
		);
	}

	public function testOutputFieldQueryPrintsU(): void {
		$this->tunnel(1);
		$this->tunnel(2);

		foreach (Catalog::all() as $metric) {
			$this->assertSame(
				self::uuid(1) . "!U\n" . self::uuid(2) . "!U\n",
				$this->ss('query', $metric->queryField()),
				$metric->queryField()
			);
		}
	}

	public function testGetReturnsReaderValue(): void {
		$this->tunnel(1, array('cf_status' => 'degraded'));

		$observed = $this->reader->observe($this->accountId, self::uuid(1));

		foreach (Catalog::all() as $metric) {
			$field = $metric->queryField();

			$this->assertSame($observed[$field]->rrdValue(), $this->ss('get', $field, self::uuid(1)), $field);
		}

		$this->assertSame('2', $this->ss('get', 'health', self::uuid(1)));
		$this->assertSame('4', $this->ss('get', 'connections', self::uuid(1)));
		$this->assertSame('30', $this->ss('get', 'collectAge', self::uuid(1)));
	}

	/* Reindex keeps the index so Cacti does not orphan the data source (lib/data_query.php:317). */
	public function testDeletedTunnelStaysIndexedAndPrintsU(): void {
		$this->tunnel(1, array('lifecycle' => 'deleted'));

		$this->assertSame(self::uuid(1) . "\n", $this->ss('index'));
		$this->assertSame(array(self::uuid(1) => 'tunnel-1'), self::parsed($this->ss('query', 'tunnelName')));

		foreach (Catalog::all() as $metric) {
			$this->assertSame('U', $this->ss('get', $metric->queryField(), self::uuid(1)), $metric->queryField());
		}
	}

	public function testUnknownIndexAndBadInputPrintU(): void {
		/* Hex letters, so the uppercase form differs from the stored id. */
		$known = self::uuid(0xabcdef);
		$this->tunnel(0xabcdef);
		$other = $this->account('b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2', 42);
		$this->tunnel(2, array(), $other);

		$this->assertSame('3', $this->ss('get', 'health', $known), 'the fixture itself has a value');

		$indexes = array(
			'another account' => self::uuid(2),
			'unknown'         => self::uuid(9),
			'uppercase'       => strtoupper($known),
			'injection'       => '1;DROP',
			'quoted'          => "'" . $known . "' OR 1=1",
			'empty'           => '',
		);
		foreach ($indexes as $label => $index) {
			$this->assertSame('U', $this->ss('get', 'health', $index), $label);
		}

		foreach (array('', 'tunnelId', 'tunnelName', '1;DROP', 'HEALTH', 'cf_status') as $field) {
			$this->assertSame('U', $this->ss('get', $field, $known), var_export($field, true));
		}

		foreach (array('', '1;DROP', 'HEALTH', 'name') as $field) {
			$this->assertSame('', $this->ss('query', $field), var_export($field, true));
		}

		foreach (array('', '0', '1;DROP', '-41', '41.0') as $host) {
			$this->assertSame('U', cloudflare_ss($host, 'get', 'health', $known, $this->reader), $host);
			$this->assertSame('', cloudflare_ss($host, 'index', '', '', $this->reader), $host);
		}

		$this->assertSame(array(), cloudflare_glue_calls('cacti_log'), 'bad input is not a failure');
	}

	/* Cacti's handle can drop between polls; the next call must print U, not throw. */
	public function testClosedConnectionPrintsU(): void {
		$this->tunnel(1);

		$dead = TestDatabase::pdo();
		$id   = (int) $dead->query('SELECT CONNECTION_ID()')->fetchColumn();
		$this->db->execute('KILL CONNECTION ' . $id);
		$dead->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		$GLOBALS['database_hostname'] = 'cacti-db';
		$GLOBALS['database_port']     = '3306';
		$GLOBALS['database_default']  = 'cacti';
		$GLOBALS['database_sessions'] = array(self::SESSION => $dead);

		$this->assertSame('U', cloudflare_ss((string) self::HOST, 'get', 'health', self::uuid(1)));
		$this->assertSame('U', cloudflare_ss((string) self::HOST, 'num_indexes', '', ''));
		$this->assertSame('', cloudflare_ss((string) self::HOST, 'index', '', ''));

		$logged = cloudflare_glue_calls('cacti_log');
		$this->assertCount(3, $logged);
		foreach ($logged as $call) {
			$this->assertMatchesRegularExpression('/^CLOUDFLARE: script server failed \([A-Za-z\\\\]+\)$/', (string) $call[0]);
			$this->assertSame(POLLER_VERBOSITY_HIGH, $call[3]);
		}
	}

	public function testLiveSessionServesValuesWithoutAReader(): void {
		$this->tunnel(1, array('connections_refreshed_at' => time(), 'last_seen_at' => time()));

		$GLOBALS['database_hostname'] = 'cacti-db';
		$GLOBALS['database_port']     = '3306';
		$GLOBALS['database_default']  = 'cacti';
		$GLOBALS['database_sessions'] = array(self::SESSION => TestDatabase::pdo());

		$this->assertSame('3', cloudflare_ss((string) self::HOST, 'get', 'health', self::uuid(1)));
		$this->assertSame(self::uuid(1) . "\n", cloudflare_ss((string) self::HOST, 'index', '', ''));
	}
}
