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

namespace Cacti\PluginCloudflare\Tests\Unit\Glue;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PollingContractTest extends TestCase {
	private const ROOT = __DIR__ . '/../../..';

	public static function setUpBeforeClass(): void {
		require_once self::ROOT . '/tests/Support/CactiGlueStubs.php';
		require_once self::ROOT . '/setup.php';
		require_once self::ROOT . '/includes/polling.php';
	}

	protected function setUp(): void {
		cloudflare_glue_reset();
		unset($GLOBALS['config'], $GLOBALS['database_sessions']);
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['config'],
			$GLOBALS['database_sessions'],
			$GLOBALS['database_hostname'],
			$GLOBALS['database_port'],
			$GLOBALS['database_default']
		);
	}

	/**
	 * @return array<int, string>
	 */
	private static function loggedLines(): array {
		return array_map(function (array $call): string {
			return (string) $call[0];
		}, cloudflare_glue_calls('cacti_log'));
	}

	public function testInstallRegistersPollerBottom(): void {
		plugin_cloudflare_install();

		$this->assertContains(
			array('cloudflare', 'poller_bottom', 'cloudflare_poller_bottom', 'includes/polling.php', false),
			cloudflare_glue_calls('api_plugin_register_hook')
		);
		$this->assertTrue(function_exists('cloudflare_poller_bottom'));

		foreach (self::loggedLines() as $line) {
			$this->assertStringNotContainsString('improperly', $line);
		}
	}

	public function testPollerBottomSkipsWhenDisabledOrRemotePoller(): void {
		$GLOBALS['cloudflare_glue']['enabled'] = false;
		$GLOBALS['config']                     = array('poller_id' => 1);
		cloudflare_poller_bottom();

		cloudflare_glue_reset();
		foreach (array(2, '3') as $remote) {
			$GLOBALS['config'] = array('poller_id' => $remote);
			cloudflare_poller_bottom();
		}

		$this->assertSame(array(), cloudflare_glue_calls('exec_background'));
		$this->assertSame(array(), cloudflare_glue_calls('db_connect_real'), 'no query before the cheap checks pass');
		$this->assertSame(array(), self::loggedLines());
	}

	/* poller.php runs this hook in its own process; a database fault must not reach it. */
	public function testPollerBottomStaysSilentWhenTheDatabaseFails(): void {
		$GLOBALS['config'] = array('poller_id' => 1);

		cloudflare_poller_bottom();

		$this->assertCount(1, cloudflare_glue_calls('db_connect_real'));
		$this->assertSame(array(), cloudflare_glue_calls('exec_background'));
		$this->assertSame(array(), self::loggedLines());
	}

	public function testCollectorRefusesWebInvocation(): void {
		$source = (string) file_get_contents(self::ROOT . '/poller_cloudflare.php');
		$code   = substr($source, (int) strpos($source, "*/\n") + 3);

		$this->assertMatchesRegularExpression(
			'#^\s*if \(php_sapi_name\(\) !== \'cli\'\) \{\s*http_response_code\(404\);\s*exit;\s*\}#',
			$code,
			'the SAPI check is the first statement'
		);

		$guard = (int) strpos($code, 'php_sapi_name()');
		$this->assertLessThan((int) strpos($code, 'cli_check.php'), $guard);
		$this->assertMatchesRegularExpression('#require\(__DIR__ \. \'/\.\./\.\./include/cli_check\.php\'\);#', $code);
		$this->assertStringContainsString("exit(cloudflare_collector_main('cloudflare_discovery_service'));", $code);
		$this->assertDoesNotMatchRegularExpression('#\b(print|echo|printf|var_dump|print_r)\b#', $code);
	}

	public function testCollectorLogsOnlyTheExceptionClass(): void {
		$called = false;

		$status = cloudflare_collector_main(function () use (&$called) {
			$called = true;
		});

		$this->assertSame(1, $status);
		$this->assertFalse($called);
		$this->assertSame(
			array('CLOUDFLARE: collector failed (Cacti\PluginCloudflare\Storage\DatabaseException)'),
			self::loggedLines()
		);
	}

	/* Enough of Cacti's handle for the collector to see a current schema, take the lock and find no accounts. */
	private static function lockingSqlite(): PDO {
		$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
		$pdo->sqliteCreateFunction('GET_LOCK', function (): int {
			return 1;
		}, 2);
		$pdo->sqliteCreateFunction('RELEASE_LOCK', function (): int {
			return 1;
		}, 1);
		$pdo->sqliteCreateFunction('DATABASE', function (): string {
			return 'cacti';
		}, 0);
		$pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
		$pdo->exec('CREATE TABLE information_schema.tables (table_schema TEXT, table_name TEXT)');
		$pdo->exec("INSERT INTO information_schema.tables VALUES ('cacti', 'plugin_cloudflare_migrations')");
		$pdo->exec('CREATE TABLE plugin_cloudflare_migrations (version INTEGER)');
		$pdo->exec('INSERT INTO plugin_cloudflare_migrations VALUES (1), (2), (3)');
		$pdo->exec('CREATE TABLE plugin_cloudflare_accounts (id INTEGER, enabled INTEGER, collect_tunnels INTEGER)');

		return $pdo;
	}

	public function testCollectorFailureLogOmitsTheExceptionMessage(): void {
		if (!extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is not loaded');
		}

		$secret = 'cfut_' . str_repeat('s', 40);
		$GLOBALS['database_hostname'] = 'cacti-db';
		$GLOBALS['database_port']     = '3306';
		$GLOBALS['database_default']  = 'cacti';
		$GLOBALS['database_sessions'] = array('cacti-db:3306:cacti' => self::lockingSqlite());

		$status = cloudflare_collector_main(function () use ($secret) {
			throw new RuntimeException('token ' . $secret . ' rejected');
		});

		$this->assertSame(1, $status);
		$this->assertSame(array('CLOUDFLARE: collector failed (RuntimeException)'), self::loggedLines());
		$this->assertStringNotContainsString($secret, implode("\n", self::loggedLines()));
	}

	public function testCollectorExitsQuietlyWhenDisabled(): void {
		$GLOBALS['cloudflare_glue']['enabled'] = false;

		$this->assertSame(0, cloudflare_collector_main(function () {
			$this->fail('no service is built while the plugin is disabled');
		}));
		$this->assertSame(array(), cloudflare_glue_calls('db_connect_real'));
		$this->assertSame(array(), self::loggedLines());
	}
}
