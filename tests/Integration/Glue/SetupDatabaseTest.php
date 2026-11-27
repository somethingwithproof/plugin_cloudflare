<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_cloudflare, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_cloudflare
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

namespace Somethingwithproof\Cloudflare\Tests\Integration\Glue;

use Somethingwithproof\Cloudflare\Storage\Database;
use Somethingwithproof\Cloudflare\Storage\DatabaseException;
use Somethingwithproof\Cloudflare\Storage\Migrations\M001Foundation;
use Somethingwithproof\Cloudflare\Storage\Migrations\M002Discovery;
use Somethingwithproof\Cloudflare\Storage\Migrations\M003Metrics;
use Somethingwithproof\Cloudflare\Storage\Migrations\M004Retirement;
use Somethingwithproof\Cloudflare\Storage\Migrations\M005Analytics;
use Somethingwithproof\Cloudflare\Storage\Migrator;
use Somethingwithproof\Cloudflare\Tests\Support\TestDatabase;
use PDO;
use Somethingwithproof\Cloudflare\Tests\Integration\IntegrationTestCase;

/**
 * Runs the lifecycle functions against real MariaDB through the same
 * $database_sessions slot Cacti fills in lib/database.php.
 */
final class SetupDatabaseTest extends IntegrationTestCase {
	private const ROOT    = __DIR__ . '/../../..';
	private const SESSION = 'cacti-db:3306:cacti';

	/** @var PDO */
	private $pdo;

	/** @var string|null */
	private $base;

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
		unset($GLOBALS['config']);
	}

	protected function tearDown(): void {
		unset($GLOBALS['database_sessions'], $GLOBALS['database_hostname'], $GLOBALS['database_port'], $GLOBALS['database_default']);
		unset($GLOBALS['config']);
		TestDatabase::reset();

		if ($this->base !== null) {
			foreach ((array) glob($this->base . '/resource/script_server/*') as $file) {
				unlink((string) $file);
			}
			rmdir($this->base . '/resource/script_server');
			rmdir($this->base . '/resource');
			unlink($this->base . '/lib/template.php');
			unlink($this->base . '/lib/utility.php');
			rmdir($this->base . '/lib');
			rmdir($this->base);
			$this->base = null;
		}
	}

	/**
	 * A Cacti tree with only the directory the plugin writes to, and stub
	 * answers for the Cacti tables the template install reads.
	 */
	private function cactiTree(string $installed): string {
		$this->base = sys_get_temp_dir() . '/cf-base-' . bin2hex(random_bytes(6));
		mkdir($this->base . '/resource/script_server', 0755, true);
		/* Empty stand-ins for the libraries loaded beside import.php. */
		mkdir($this->base . '/lib');
		file_put_contents($this->base . '/lib/template.php', "<?php\n");
		file_put_contents($this->base . '/lib/utility.php', "<?php\n");
		$GLOBALS['config'] = array('base_path' => $this->base);

		$GLOBALS['cloudflare_glue']['import'] = array(
			'data_template' => array(array('type' => 'new', 'title' => 'Cloudflare Tunnel', 'result' => 'success')),
		);
		$GLOBALS['cloudflare_glue']['cell'] = function (string $sql) use ($installed) {
			if (strpos($sql, 'FROM plugin_config') !== false) {
				return $installed;
			}

			if (strpos($sql, 'FROM data_source_profiles') !== false) {
				return '1';
			}

			return cloudflare_glue_calls('import_xml_data') === array() ? false : '7';
		};

		return $this->base . '/resource/script_server/cloudflare_tunnels.xml';
	}

	private function mappingCount(): int {
		return (int) $this->pdo->query('SELECT COUNT(*) FROM plugin_cloudflare_metric_mappings')->fetchColumn();
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
		$GLOBALS['cloudflare_glue']['cell'] = '0.0.9';

		$this->assertTrue(plugin_cloudflare_upgrade());

		$this->assertTrue($this->tableExists('plugin_cloudflare_accounts'));
		$this->assertSame(array(
			array('UPDATE plugin_config SET version = ? WHERE directory = ?', array('0.4.0-dev', 'cloudflare')),
		), cloudflare_glue_calls('db_execute_prepared'));
	}

	public function testUpgradeRunsPendingMigrationsAtTheSameVersion(): void {
		$GLOBALS['cloudflare_glue']['cell'] = static function (string $sql): string {
			return strpos($sql, 'plugin_config') !== false ? '0.4.0-dev' : '1';
		};

		$this->assertTrue(plugin_cloudflare_upgrade());

		$this->assertTrue($this->tableExists('plugin_cloudflare_accounts'));
		$this->assertSame(array(), cloudflare_glue_calls('db_execute_prepared'));
		$this->assertSame(array(), cloudflare_glue_calls('import_xml_data'), 'templates follow the version, not the schema');
		$this->assertSame(array(), cloudflare_glue_calls('api_plugin_register_realm'), 'realm registration must not replicate config on every page');
		$this->assertSame(array(), cloudflare_glue_calls('api_plugin_register_hook'), 'hook registration must not replicate config on every page');
	}

	public function testUpgradeRepairsMissingOperatorRegistrationAtTheSameVersion(): void {
		$GLOBALS['cloudflare_glue']['cell'] = static function (string $sql): string {
			return strpos($sql, 'plugin_config') !== false ? '0.3.0' : '0';
		};

		$this->assertTrue(plugin_cloudflare_upgrade());

		$this->assertSame(array(
			array('cloudflare', 'cloudflare_operator.php', 'Cloudflare: Operator', false),
		), cloudflare_glue_calls('api_plugin_register_realm'));
		$this->assertSame(array(
			array('cloudflare', 'auth_alternate_realms', 'cloudflare_auth_alternate_realms', 'setup.php', true),
			array('cloudflare', 'abilities_register', 'cloudflare_abilities_register', 'includes/ecosystem.php', true),
			array('cloudflare', 'doctor_register_checks', 'cloudflare_doctor_register_checks', 'includes/ecosystem.php', true),
			array('cloudflare', 'doctor_register_debug_info', 'cloudflare_doctor_register_debug_info', 'includes/ecosystem.php', true),
			array('cloudflare', 'casc_register_providers', 'cloudflare_casc_register_providers', 'includes/ecosystem.php', true),
			array('cloudflare', 'api_register_resources', 'cloudflare_api_register_resources', 'includes/ecosystem.php', true),
			array('cloudflare', 'replicate_out', 'cloudflare_replicate_out', 'includes/ecosystem.php', true),
		), cloudflare_glue_calls('api_plugin_register_hook'));
	}

	public function testUpgradeFrom010RunsM002AndRegistersHook(): void {
		(new Migrator(new Database($this->pdo), array(new M001Foundation())))->migrate();
		$this->assertFalse($this->tableExists('plugin_cloudflare_tunnels'));
		$GLOBALS['cloudflare_glue']['cell'] = '0.1.0';

		$this->assertTrue(plugin_cloudflare_upgrade());

		$this->assertTrue($this->tableExists('plugin_cloudflare_tunnels'));
		$this->assertTrue($this->tableExists('plugin_cloudflare_connectors'));
		$this->assertSame(
			array(
				array('cloudflare', 'auth_alternate_realms', 'cloudflare_auth_alternate_realms', 'setup.php', true),
				array('cloudflare', 'abilities_register', 'cloudflare_abilities_register', 'includes/ecosystem.php', true),
				array('cloudflare', 'doctor_register_checks', 'cloudflare_doctor_register_checks', 'includes/ecosystem.php', true),
				array('cloudflare', 'doctor_register_debug_info', 'cloudflare_doctor_register_debug_info', 'includes/ecosystem.php', true),
				array('cloudflare', 'casc_register_providers', 'cloudflare_casc_register_providers', 'includes/ecosystem.php', true),
				array('cloudflare', 'api_register_resources', 'cloudflare_api_register_resources', 'includes/ecosystem.php', true),
				array('cloudflare', 'replicate_out', 'cloudflare_replicate_out', 'includes/ecosystem.php', true),
				array('cloudflare', 'poller_bottom', 'cloudflare_poller_bottom', 'includes/polling.php', true),
			),
			cloudflare_glue_calls('api_plugin_register_hook')
		);
		$this->assertSame(array(
			array('UPDATE plugin_config SET version = ? WHERE directory = ?', array('0.4.0-dev', 'cloudflare')),
		), cloudflare_glue_calls('db_execute_prepared'));
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

	public function testUpgradeFrom020RunsM003AndImportsTemplates(): void {
		(new Migrator(new Database($this->pdo), array(new M001Foundation(), new M002Discovery())))->migrate();
		$this->assertFalse($this->tableExists('plugin_cloudflare_metric_mappings'));
		$resource = $this->cactiTree('0.2.0');

		$this->assertTrue(plugin_cloudflare_upgrade());

		$this->assertTrue($this->tableExists('plugin_cloudflare_resources'));
		$this->assertTrue($this->tableExists('plugin_cloudflare_metric_mappings'));
		$this->assertSame(
			'5',
			(string) $this->pdo->query('SELECT MAX(version) FROM plugin_cloudflare_migrations')->fetchColumn()
		);
		$this->assertFileEquals(self::ROOT . '/templates/resource/cloudflare_tunnels.xml', $resource);
		$this->assertSame(
			array(array(hash_file('sha256', self::ROOT . '/templates/cloudflare_templates.xml'), false, '1')),
			cloudflare_glue_calls('import_xml_data')
		);
		$this->assertSame(6, $this->mappingCount());
		$this->assertSame(
			array(
				array('cloudflare', 'auth_alternate_realms', 'cloudflare_auth_alternate_realms', 'setup.php', true),
				array('cloudflare', 'abilities_register', 'cloudflare_abilities_register', 'includes/ecosystem.php', true),
				array('cloudflare', 'doctor_register_checks', 'cloudflare_doctor_register_checks', 'includes/ecosystem.php', true),
				array('cloudflare', 'doctor_register_debug_info', 'cloudflare_doctor_register_debug_info', 'includes/ecosystem.php', true),
				array('cloudflare', 'casc_register_providers', 'cloudflare_casc_register_providers', 'includes/ecosystem.php', true),
				array('cloudflare', 'api_register_resources', 'cloudflare_api_register_resources', 'includes/ecosystem.php', true),
				array('cloudflare', 'replicate_out', 'cloudflare_replicate_out', 'includes/ecosystem.php', true),
			),
			cloudflare_glue_calls('api_plugin_register_hook'),
			'0.2.0 keeps its poller hook and gains role inheritance and ecosystem hooks'
		);
		$this->assertSame(array(
			array('UPDATE plugin_config SET version = ? WHERE directory = ?', array('0.4.0-dev', 'cloudflare')),
		), cloudflare_glue_calls('db_execute_prepared'));
		$this->assertSame(array(), $this->loggedLines());
	}

	public function testFailedImportOnUpgradeStillRecordsTheVersion(): void {
		(new Migrator(new Database($this->pdo), array(new M001Foundation(), new M002Discovery())))->migrate();
		$this->cactiTree('0.2.0');
		$GLOBALS['cloudflare_glue']['import'] = array(
			'data_query' => array(array('type' => 'new', 'title' => 'Cloudflare Tunnels', 'result' => 'fail')),
		);

		$this->assertTrue(plugin_cloudflare_upgrade());

		$this->assertTrue($this->tableExists('plugin_cloudflare_metric_mappings'));
		$this->assertCount(1, cloudflare_glue_calls('import_xml_data'));
		$this->assertSame(array(), cloudflare_glue_calls('set_config_option'));
		$this->assertArrayNotHasKey('cloudflare_templates_sha256', $GLOBALS['cloudflare_glue']['settings']);
		$this->assertSame(array('CLOUDFLARE: template import failed (data_query)'), $this->loggedLines());
		$this->assertSame(array(
			array('UPDATE plugin_config SET version = ? WHERE directory = ?', array('0.4.0-dev', 'cloudflare')),
		), cloudflare_glue_calls('db_execute_prepared'));
	}

	public function testInstallImportsTemplatesAfterTheSchema(): void {
		$resource = $this->cactiTree('');

		plugin_cloudflare_install();

		$this->assertFileExists($resource);
		$this->assertCount(1, cloudflare_glue_calls('import_xml_data'));
		$this->assertSame(6, $this->mappingCount());
		$this->assertSame(array(), $this->loggedLines());
	}

	public function testUninstallKeepsResourceXml(): void {
		$resource = $this->cactiTree('');
		plugin_cloudflare_install();
		$this->assertFileExists($resource);
		cloudflare_glue_reset();

		plugin_cloudflare_uninstall();

		$this->assertFileEquals(self::ROOT . '/templates/resource/cloudflare_tunnels.xml', $resource);
		$this->assertFalse($this->tableExists('plugin_cloudflare_metric_mappings'));
		/* The only write to Cacti's own tables is the settings cleanup. */
		$this->assertSame(array(
			array('DELETE FROM settings WHERE name LIKE ? ESCAPE \'!\'', array('cloudflare!_%')),
		), cloudflare_glue_calls('db_execute_prepared'));
	}

	public function testUpgradeFromPriorMainSchemaIsCleanAndIdempotent(): void {
		$db = new Database($this->pdo);

		// Simulate prior 0.3.0 schema (M001..M004) with existing production data.
		(new Migrator($db, array(
			new M001Foundation(),
			new M002Discovery(),
			new M003Metrics(),
			new M004Retirement(),
		)))->migrate();

		$db->execute(
			"INSERT INTO plugin_cloudflare_accounts (cf_account_id, name, token_source, enabled, discovery_interval, created_at, updated_at)
			VALUES (?, 'Edge Primary', 'file', 1, 300, 1700000000, 1700000000)",
			array('0123456789abcdef0123456789abcdef')
		);
		$accountId = $db->lastInsertId();
		$db->execute(
			"INSERT INTO plugin_cloudflare_tunnels (account_id, cf_tunnel_id, name, lifecycle, cf_status, last_seen_at)
			VALUES (?, '0b0e3f43-63a8-4f0c-bb4c-0d6b2b5a2f10', 'edge-nyc', 'active', 'healthy', 1700000100)",
			array($accountId)
		);
		$this->assertFalse($this->tableExists('plugin_cloudflare_zones'));

		// First upgrade from 0.3.0 to 0.4.0-dev: runs M005 and registers ecosystem hooks.
		/** @var array<string, bool> $registeredHooks */
		$registeredHooks = array();
		$GLOBALS['cloudflare_glue']['cell'] = static function (string $sql, array $params) use (&$registeredHooks): string {
			if (strpos($sql, 'FROM plugin_config') !== false) {
				return '0.3.0';
			}

			if (strpos($sql, 'FROM plugin_hooks') !== false && isset($params[1])) {
				return isset($registeredHooks[(string) $params[1]]) ? '1' : '0';
			}

			return '1';
		};

		$this->assertTrue(plugin_cloudflare_upgrade());
		$this->assertTrue($this->tableExists('plugin_cloudflare_zones'));
		$this->assertSame('5', (string) $this->pdo->query('SELECT MAX(version) FROM plugin_cloudflare_migrations')->fetchColumn());
		$this->assertSame('Edge Primary', (string) $db->fetchCell('SELECT name FROM plugin_cloudflare_accounts WHERE id = ?', array($accountId)));
		$this->assertSame('edge-nyc', (string) $db->fetchCell('SELECT name FROM plugin_cloudflare_tunnels WHERE account_id = ?', array($accountId)));

		foreach (cloudflare_glue_calls('api_plugin_register_hook') as $call) {
			$registeredHooks[(string) $call[1]] = true;
		}
		foreach (array('auth_alternate_realms', 'abilities_register', 'doctor_register_checks', 'doctor_register_debug_info', 'casc_register_providers', 'api_register_resources', 'replicate_out') as $expectedHook) {
			$this->assertArrayHasKey($expectedHook, $registeredHooks);
		}

		// Second upgrade at 0.4.0-dev (idempotent no-op with all rows preserved).
		cloudflare_glue_reset();
		$GLOBALS['cloudflare_glue']['cell'] = static function (string $sql): string {
			return strpos($sql, 'FROM plugin_config') !== false ? '0.4.0-dev' : '1';
		};

		$this->assertTrue(plugin_cloudflare_upgrade());
		$this->assertSame(array(), cloudflare_glue_calls('api_plugin_register_hook'));
		$this->assertSame(array(), cloudflare_glue_calls('db_execute_prepared'));
		$this->assertSame(1, (int) $db->fetchCell('SELECT COUNT(*) FROM plugin_cloudflare_accounts'));
		$this->assertSame(1, (int) $db->fetchCell('SELECT COUNT(*) FROM plugin_cloudflare_tunnels'));
		$this->assertSame(array(), $this->loggedLines());

		// Also verify prior main schema (M001..M005 already applied, missing new ecosystem hooks) repairs cleanly.
		cloudflare_glue_reset();
		$GLOBALS['cloudflare_glue']['cell'] = static function (string $sql, array $params): string {
			if (strpos($sql, 'FROM plugin_config') !== false) {
				return '0.4.0-dev';
			}

			if (strpos($sql, 'FROM plugin_hooks') !== false && isset($params[1]) && in_array($params[1], array('doctor_register_debug_info', 'api_register_resources'), true)) {
				return '0';
			}

			return '1';
		};

		$this->assertTrue(plugin_cloudflare_upgrade());
		$this->assertSame(
			array(
				array('cloudflare', 'doctor_register_debug_info', 'cloudflare_doctor_register_debug_info', 'includes/ecosystem.php', true),
				array('cloudflare', 'api_register_resources', 'cloudflare_api_register_resources', 'includes/ecosystem.php', true),
			),
			cloudflare_glue_calls('api_plugin_register_hook')
		);
		$this->assertSame(array(), (new Migrator($db, array(
			new M001Foundation(),
			new M002Discovery(),
			new M003Metrics(),
			new M004Retirement(),
			new M005Analytics(),
		)))->pending());
	}
}
