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

use Cacti\PluginCloudflare\Storage\DatabaseException;
use Cacti\PluginCloudflare\Storage\Migrator;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs the lifecycle functions against real MariaDB through the same
 * $database_sessions slot Cacti fills in lib/database.php.
 */
final class SetupDatabaseTest extends TestCase {
	private const ROOT    = __DIR__ . '/../../..';
	private const SESSION = 'cacti-db:3306:cacti';

	/** @var PDO */
	private $pdo;

	public static function setUpBeforeClass(): void {
		require_once self::ROOT . '/tests/Support/CactiGlueStubs.php';
		require_once self::ROOT . '/setup.php';
	}

	protected function setUp(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();
		cloudflare_glue_reset();

		$this->pdo = TestDatabase::pdo();

		$GLOBALS['database_hostname'] = 'cacti-db';
		$GLOBALS['database_port']     = '3306';
		$GLOBALS['database_default']  = 'cacti';
		$GLOBALS['database_sessions'] = array(self::SESSION => $this->pdo);
	}

	protected function tearDown(): void {
		unset($GLOBALS['database_sessions'], $GLOBALS['database_hostname'], $GLOBALS['database_port'], $GLOBALS['database_default']);
		TestDatabase::reset();
	}

	private function tableExists(string $table): bool {
		$stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
		$stmt->execute(array($table));

		return (int) $stmt->fetchColumn() === 1;
	}

	/**
	 * @return array<int, string>
	 */
	private function loggedLines(): array {
		return array_map(function (array $call): string {
			return (string) $call[0];
		}, cloudflare_glue_calls('cacti_log'));
	}

	public function testInstallCreatesSchemaOnCactisConnection(): void {
		plugin_cloudflare_install();

		$this->assertTrue($this->tableExists('plugin_cloudflare_accounts'));
		$this->assertTrue($this->tableExists('plugin_cloudflare_jobs'));
		$this->assertSame(array(), cloudflare_glue_calls('db_connect_real'));
	}

	public function testDbConnectsThroughCactiWhenNoSessionExists(): void {
		$GLOBALS['database_sessions']           = array();
		$GLOBALS['cloudflare_glue']['connect']  = $this->pdo;

		cloudflare_db()->fetchCell('SELECT 1');

		$calls = cloudflare_glue_calls('db_connect_real');
		$this->assertCount(1, $calls);
		$this->assertSame(array('cacti-db', 'cacti', '3306'), $calls[0]);
	}

	public function testDbFailsWhenCactiCannotConnect(): void {
		$GLOBALS['database_sessions'] = array();

		$this->expectException(DatabaseException::class);

		cloudflare_db();
	}

	public function testUpgradeMigratesAndRecordsTheVersion(): void {
		$GLOBALS['cloudflare_glue']['cell'] = '0.9.0';

		$this->assertTrue(plugin_cloudflare_upgrade());

		$this->assertTrue($this->tableExists('plugin_cloudflare_accounts'));
		$this->assertSame(array(
			array('UPDATE plugin_config SET version = ? WHERE directory = ?', array('1.0.0', 'cloudflare')),
		), cloudflare_glue_calls('db_execute_prepared'));
	}

	public function testUpgradeRunsPendingMigrationsAtTheSameVersion(): void {
		$GLOBALS['cloudflare_glue']['cell'] = '1.0.0';

		$this->assertTrue(plugin_cloudflare_upgrade());

		$this->assertTrue($this->tableExists('plugin_cloudflare_accounts'));
		$this->assertSame(array(), cloudflare_glue_calls('db_execute_prepared'));
	}

	public function testUpgradeDefersWhileAnotherProcessHoldsTheLock(): void {
		$holder = TestDatabase::pdo();
		$holder->query("SELECT GET_LOCK('" . Migrator::LOCK_NAME . "', 0)");
		$GLOBALS['cloudflare_glue']['cell'] = '0.9.0';

		try {
			$this->assertFalse(plugin_cloudflare_upgrade(1));
		} finally {
			$holder->query("SELECT RELEASE_LOCK('" . Migrator::LOCK_NAME . "')");
		}

		$this->assertSame(array('CLOUDFLARE: schema upgrade deferred; another process holds the migration lock'), $this->loggedLines());
		$this->assertFalse($this->tableExists('plugin_cloudflare_accounts'));
		$this->assertSame(array(), cloudflare_glue_calls('db_execute_prepared'));
	}

	public function testSettingsPatternMatchesOnlyPluginSettings(): void {
		$this->pdo->exec('CREATE TEMPORARY TABLE settings (name VARCHAR(50) NOT NULL, value VARCHAR(10) NOT NULL)');
		$this->pdo->exec("INSERT INTO settings VALUES ('cloudflare_key_file', ''), ('cloudflarex_other', ''), ('cloudflare', ''), ('thold_x', '')");

		$stmt = $this->pdo->prepare('DELETE FROM settings WHERE name LIKE ? ESCAPE \'!\'');
		$stmt->execute(array('cloudflare!_%'));

		$left = $this->pdo->query('SELECT name FROM settings ORDER BY name');
		$this->assertNotFalse($left);
		$this->assertSame(array('cloudflare', 'cloudflarex_other', 'thold_x'), $left->fetchAll(PDO::FETCH_COLUMN));
	}

	public function testUninstallDropsTablesAndPluginSettings(): void {
		plugin_cloudflare_setup_database();
		cloudflare_glue_reset();

		plugin_cloudflare_uninstall();

		$this->assertFalse($this->tableExists('plugin_cloudflare_accounts'));
		$this->assertFalse($this->tableExists('plugin_cloudflare_jobs'));
		$this->assertFalse($this->tableExists('plugin_cloudflare_migrations'));
		$this->assertSame(array(
			array('DELETE FROM settings WHERE name LIKE ? ESCAPE \'!\'', array('cloudflare!_%')),
		), cloudflare_glue_calls('db_execute_prepared'));
	}
}
