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

namespace Somethingwithproof\Cloudflare\Tests\Unit\Ecosystem;

use Somethingwithproof\Cloudflare\Account\AccountRepository;
use Somethingwithproof\Cloudflare\Config\ConfigCodec;
use Somethingwithproof\Cloudflare\Config\ConfigService;
use Somethingwithproof\Cloudflare\Discovery\RemoteReplication;
use Somethingwithproof\Cloudflare\Discovery\TunnelRepository;
use Somethingwithproof\Cloudflare\Ecosystem\AbilitiesProvider;
use Somethingwithproof\Cloudflare\Ecosystem\CascProvider;
use Somethingwithproof\Cloudflare\Ecosystem\DoctorChecks;
use Somethingwithproof\Cloudflare\Metrics\MetricMappingRepository;
use Somethingwithproof\Cloudflare\Storage\Database;
use Somethingwithproof\Cloudflare\Tests\Support\FrozenClock;
use PDO;
use PHPUnit\Framework\TestCase;
use stdClass;

final class EcosystemIntegrationTest extends TestCase {
	/** @var string|null */
	private $tmpDir;

	protected function tearDown(): void {
		if ($this->tmpDir !== null && is_dir($this->tmpDir)) {
			foreach ((array) glob($this->tmpDir . '/*') as $file) {
				@unlink((string) $file);
			}
			@rmdir($this->tmpDir);
			$this->tmpDir = null;
		}
	}

	private static function sqliteDb(): Database {
		$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
		$pdo->exec('CREATE TABLE plugin_cloudflare_accounts (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			cf_account_id TEXT NOT NULL,
			name TEXT NOT NULL,
			token_source TEXT NOT NULL DEFAULT "vault",
			token_ciphertext TEXT NULL,
			token_file TEXT NULL,
			enabled INTEGER NOT NULL DEFAULT 1,
			status TEXT NOT NULL DEFAULT "ok",
			status_message TEXT NOT NULL DEFAULT "",
			permissions TEXT NULL,
			last_checked_at INTEGER NULL,
			last_verified_at INTEGER NULL,
			collect_tunnels INTEGER NOT NULL DEFAULT 1,
			collect_analytics INTEGER NOT NULL DEFAULT 1,
			create_graphs INTEGER NOT NULL DEFAULT 0,
			discovery_interval INTEGER NOT NULL DEFAULT 300,
			created_at INTEGER NOT NULL DEFAULT 1,
			updated_at INTEGER NOT NULL DEFAULT 1
		)');
		$pdo->exec('CREATE TABLE plugin_cloudflare_tunnels (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			account_id INTEGER NOT NULL,
			cf_tunnel_id TEXT NOT NULL,
			name TEXT NOT NULL,
			lifecycle TEXT NOT NULL DEFAULT "active",
			cf_status TEXT NOT NULL DEFAULT "healthy",
			tun_type TEXT NOT NULL DEFAULT "cfd_tunnel",
			connector_count INTEGER NULL,
			connection_count INTEGER NULL,
			last_seen_at INTEGER NULL
		)');
		$pdo->exec('CREATE TABLE plugin_cloudflare_connectors (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			tunnel_id INTEGER NOT NULL,
			cf_connector_id TEXT NOT NULL
		)');
		$pdo->exec('CREATE TABLE plugin_cloudflare_zones (
			account_id INTEGER NOT NULL,
			zone_id TEXT NOT NULL,
			name TEXT NOT NULL,
			listed INTEGER NOT NULL DEFAULT 1
		)');
		$pdo->exec('CREATE TABLE plugin_cloudflare_jobs (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			account_id INTEGER NOT NULL,
			job_type TEXT NOT NULL,
			last_status TEXT NOT NULL DEFAULT "ok",
			last_error_class TEXT NULL,
			consecutive_failures INTEGER NOT NULL DEFAULT 0
		)');
		$pdo->exec('CREATE TABLE plugin_cloudflare_metric_mappings (
			metric_name TEXT PRIMARY KEY,
			schema_version INTEGER NOT NULL,
			ds_type TEXT NOT NULL,
			unit TEXT NOT NULL,
			query_field TEXT NOT NULL,
			data_template_hash TEXT NOT NULL,
			rrd_ds_name TEXT NOT NULL
		)');
		$pdo->exec('CREATE TABLE plugin_cloudflare_resources (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			account_id INTEGER NOT NULL,
			kind TEXT NOT NULL,
			host_id INTEGER NOT NULL
		)');

		return new Database($pdo);
	}

	public function testAbilitiesRegisterIsReadOnlyRealmGatedAndExposesZeroTokens(): void {
		if (!extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is not loaded');
		}

		$db     = self::sqliteDb();
		$secret = 'v1:' . base64_encode(str_repeat('s', 40));
		$db->execute(
			'INSERT INTO plugin_cloudflare_accounts (cf_account_id, name, token_source, token_ciphertext, enabled, discovery_interval) VALUES (?, ?, ?, ?, 1, 300)',
			array(str_repeat('a', 32), 'Edge', 'vault', $secret)
		);
		$db->execute(
			'INSERT INTO plugin_cloudflare_tunnels (account_id, cf_tunnel_id, name, lifecycle, cf_status, connector_count, connection_count, last_seen_at)
			VALUES (1, ?, ?, ?, ?, 2, 8, 1700000900)',
			array('0b0e3f43-63a8-4f0c-bb4c-0d6b2b5a2f10', 'edge-nyc', 'active', 'healthy')
		);

		$provider = new AbilitiesProvider(
			$db,
			new AccountRepository($db),
			new TunnelRepository($db),
			new FrozenClock(1700001000),
			static function (int $userId): bool {
				return $userId === 7;
			}
		);

		$registrar = new class() {
			/** @var array<string, string> */
			public $categories = array();
			/** @var array<string, array<string, mixed>> */
			public $abilities = array();

			public function registerCategory(string $id, string $label): void {
				$this->categories[$id] = $label;
			}

			/** @param array<string, mixed> $def */
			public function register(string $id, array $def): void {
				$this->abilities[$id] = $def;
			}
		};

		$provider->register($registrar);

		$this->assertSame(array('cloudflare' => 'Cloudflare'), $registrar->categories);
		$this->assertSame(array('cloudflare-list-tunnels', 'cloudflare-get-health'), array_keys($registrar->abilities));

		foreach ($registrar->abilities as $id => $def) {
			$this->assertSame(array('readOnly' => true, 'destructive' => false, 'idempotent' => true), $def['annotations'], $id);
			$this->assertFalse($def['input_schema']['additionalProperties'], $id);
			$this->assertFalse($def['output_schema']['additionalProperties'], $id);
			$this->assertTrue(($def['permission'])(7), $id);
			$this->assertFalse(($def['permission'])(8), $id);
			$this->assertFalse(($def['permission'])(0), $id);
		}

		$list = ($registrar->abilities['cloudflare-list-tunnels']['execute'])(7, new stdClass());
		$this->assertSame(1, $list['total']);
		$this->assertSame('edge-nyc', $list['tunnels'][0]['name']);
		$this->assertStringNotContainsString($secret, (string) json_encode($list));

		$denied = ($registrar->abilities['cloudflare-list-tunnels']['execute'])(8, new stdClass());
		$this->assertSame(array('total' => 0, 'tunnels' => array()), $denied);

		$health = ($registrar->abilities['cloudflare-get-health']['execute'])(7, new stdClass());
		$this->assertSame('ok', $health['status']);
		$this->assertSame(1, $health['tunnels_active']);
		$this->assertSame(0, $health['tunnels_stale']);
		$this->assertStringNotContainsString($secret, (string) json_encode($health));
	}

	public function testDoctorChecksReportKeyPermissionsTokenDirFreshnessAndApiFailures(): void {
		if (!extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is not loaded');
		}

		$this->tmpDir = sys_get_temp_dir() . '/cf-doctor-' . bin2hex(random_bytes(6));
		mkdir($this->tmpDir, 0700);
		$keyFile = $this->tmpDir . '/cloudflare.key';
		file_put_contents($keyFile, bin2hex(random_bytes(32)));
		chmod($keyFile, 0644); // Intentionally world-readable -> must fail

		$db = self::sqliteDb();
		$db->execute(
			'INSERT INTO plugin_cloudflare_accounts (cf_account_id, name, enabled, discovery_interval) VALUES (?, ?, 1, 300)',
			array(str_repeat('a', 32), 'Edge')
		);
		$db->execute(
			'INSERT INTO plugin_cloudflare_tunnels (account_id, cf_tunnel_id, name, lifecycle, cf_status, last_seen_at) VALUES (1, ?, ?, ?, ?, 1700000000)',
			array('0b0e3f43-63a8-4f0c-bb4c-0d6b2b5a2f10', 'stale-edge', 'active', 'healthy')
		);
		$db->execute("INSERT INTO plugin_cloudflare_jobs (account_id, job_type, last_status, last_error_class, consecutive_failures) VALUES (1, 'discovery', 'failed', 'rate_limited', 5)");

		$doctor = new DoctorChecks($db, new AccountRepository($db), new FrozenClock(1700005000), $keyFile, $this->tmpDir);
		$checks = $doctor->register(array());
		$byId   = array();
		foreach ($checks as $entry) {
			$byId[$entry['id']] = $entry;
		}

		$this->assertSame(array('cloudflare_vault_key', 'cloudflare_token_dir', 'cloudflare_tunnel_freshness', 'cloudflare_api_failure_rate'), array_keys($byId));
		$this->assertSame('fail', $byId['cloudflare_vault_key']['status']);
		$this->assertSame('ok', $byId['cloudflare_token_dir']['status']);
		$this->assertSame('warn', $byId['cloudflare_tunnel_freshness']['status']);
		$this->assertSame('fail', $byId['cloudflare_api_failure_rate']['status']);

		chmod($keyFile, 0600);
		$cleanChecks = $doctor->run();
		$this->assertSame('ok', $cleanChecks[0]['status']);
	}

	public function testCascProviderAndRemoteReplicationExcludeRawSecrets(): void {
		if (!extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is not loaded');
		}

		$db     = self::sqliteDb();
		$secret = 'v1:' . base64_encode(str_repeat('k', 40));
		$db->execute(
			'INSERT INTO plugin_cloudflare_accounts (cf_account_id, name, token_source, token_ciphertext, enabled, discovery_interval) VALUES (?, ?, ?, ?, 1, 300)',
			array(str_repeat('d', 32), 'Core', 'vault', $secret)
		);

		$casc      = new CascProvider(new ConfigService($db, new AccountRepository($db), new TunnelRepository($db), new MetricMappingRepository($db), new FrozenClock(1700000000)));
		$providers = $casc->register(array());
		$this->assertArrayHasKey('cloudflare', $providers);
		$yaml = ($providers['cloudflare']['export'])(ConfigCodec::FORMAT_YAML);
		$this->assertStringNotContainsString($secret, $yaml);
		$this->assertTrue(($providers['cloudflare']['validate'])($yaml, ConfigCodec::FORMAT_YAML)['valid']);

		$replication = new RemoteReplication($db);
		$sink        = new class() {
			/** @var array<string, array{poller_id: int, rows: array<int, array<string, mixed>>}> */
			public $tables = array();

			/**
			 * @param array<int, array<string, mixed>> $rows
			 */
			public function record(string $table, int $pollerId, array $rows): void {
				$this->tables[$table] = array('poller_id' => $pollerId, 'rows' => $rows);
			}
		};
		$capture = static function ($rcnnId, array $rows, string $table, int $pollerId) use ($sink): void {
			$sink->record($table, $pollerId, $rows);
		};

		// Poller 1 skipped
		$replication->handle(array('remote_poller_id' => 1, 'rcnn_id' => 'c1', 'class' => 'all'), $capture);
		$this->assertSame(array(), $sink->tables);
		$this->assertSame(array(), $replication->payloadForPoller(1));

		// Unrelated replication class skipped
		$replication->handle(array('poller_id' => 2, 'rcnn_id' => 'c2', 'class' => 'hosts'), $capture);
		$this->assertSame(array(), $sink->tables);

		// Poller 2 replicates metadata with token_ciphertext stripped to null
		$replication->handle(array('remote_poller_id' => 2, 'rcnn_id' => 'c2', 'class' => 'all'), $capture);
		$tables = $sink->tables;
		$this->assertArrayHasKey('plugin_cloudflare_accounts', $tables);
		$this->assertNull($tables['plugin_cloudflare_accounts']['rows'][0]['token_ciphertext']);
		$this->assertStringNotContainsString($secret, (string) json_encode($tables));

		// Object registrar coverage on CascProvider and invalid YAML error branches
		$cascObj = new class() {
			/** @var array<string, array<string, mixed>> */
			public $items = array();

			/** @param array<string, mixed> $desc */
			public function register(string $slug, array $desc): void {
				$this->items[$slug] = $desc;
			}
		};
		$casc->register($cascObj);
		$this->assertArrayHasKey('cloudflare', $cascObj->items);
		$this->assertFalse(($providers['cloudflare']['validate'])("schema_version: 999\n", ConfigCodec::FORMAT_YAML)['valid']);
		$this->assertFalse(($providers['cloudflare']['plan'])("schema_version: 999\n", ConfigCodec::FORMAT_YAML)['valid']);
		$this->assertFalse(($providers['cloudflare']['apply'])("schema_version: 999\n", 'bad', ConfigCodec::FORMAT_YAML)['applied']);
	}

	public function testEcosystemGlueHooksAndViewRealmResolution(): void {
		if (!extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is not loaded');
		}

		require_once dirname(__DIR__, 3) . '/tests/Support/CactiGlueStubs.php';
		require_once dirname(__DIR__, 3) . '/includes/ecosystem.php';

		cloudflare_glue_reset();

		$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
		$pdo->exec('CREATE TABLE user_auth (id INTEGER PRIMARY KEY, enabled TEXT NOT NULL)');
		$pdo->exec('CREATE TABLE plugin_realms (id INTEGER PRIMARY KEY, plugin TEXT NOT NULL, file TEXT NOT NULL)');
		$pdo->exec('CREATE TABLE user_auth_realm (user_id INTEGER NOT NULL, realm_id INTEGER NOT NULL)');
		$pdo->exec('CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY, enabled TEXT NOT NULL)');
		$pdo->exec('CREATE TABLE user_auth_group_members (group_id INTEGER NOT NULL, user_id INTEGER NOT NULL)');
		$pdo->exec('CREATE TABLE user_auth_group_realm (group_id INTEGER NOT NULL, realm_id INTEGER NOT NULL)');
		$pdo->exec('CREATE TABLE plugin_cloudflare_accounts (
			id INTEGER PRIMARY KEY AUTOINCREMENT, cf_account_id TEXT NOT NULL, name TEXT NOT NULL,
			token_source TEXT NOT NULL DEFAULT "vault", token_ciphertext TEXT NULL, token_file TEXT NULL,
			enabled INTEGER NOT NULL DEFAULT 1, status TEXT NOT NULL DEFAULT "ok", status_message TEXT NOT NULL DEFAULT "",
			permissions TEXT NULL, last_checked_at INTEGER NULL, last_verified_at INTEGER NULL,
			collect_tunnels INTEGER NOT NULL DEFAULT 1, collect_analytics INTEGER NOT NULL DEFAULT 1,
			create_graphs INTEGER NOT NULL DEFAULT 0, discovery_interval INTEGER NOT NULL DEFAULT 300,
			created_at INTEGER NOT NULL DEFAULT 1, updated_at INTEGER NOT NULL DEFAULT 1
		)');
		$pdo->exec('CREATE TABLE plugin_cloudflare_tunnels (
			id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER NOT NULL, cf_tunnel_id TEXT NOT NULL,
			name TEXT NOT NULL, lifecycle TEXT NOT NULL DEFAULT "active", cf_status TEXT NOT NULL DEFAULT "healthy",
			tun_type TEXT NOT NULL DEFAULT "cfd_tunnel", connector_count INTEGER NULL, connection_count INTEGER NULL, last_seen_at INTEGER NULL
		)');
		$pdo->exec('CREATE TABLE plugin_cloudflare_connectors (id INTEGER PRIMARY KEY AUTOINCREMENT, tunnel_id INTEGER NOT NULL, cf_connector_id TEXT NOT NULL)');
		$pdo->exec('CREATE TABLE plugin_cloudflare_zones (account_id INTEGER NOT NULL, zone_id TEXT NOT NULL, name TEXT NOT NULL, listed INTEGER NOT NULL DEFAULT 1)');
		$pdo->exec('CREATE TABLE plugin_cloudflare_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER NOT NULL, job_type TEXT NOT NULL, last_status TEXT NOT NULL DEFAULT "ok", last_error_class TEXT NULL, consecutive_failures INTEGER NOT NULL DEFAULT 0)');
		$pdo->exec('CREATE TABLE plugin_cloudflare_metric_mappings (metric_name TEXT PRIMARY KEY, schema_version INTEGER NOT NULL, ds_type TEXT NOT NULL, unit TEXT NOT NULL, query_field TEXT NOT NULL, data_template_hash TEXT NOT NULL, rrd_ds_name TEXT NOT NULL)');
		$pdo->exec('CREATE TABLE plugin_cloudflare_resources (id INTEGER PRIMARY KEY AUTOINCREMENT, account_id INTEGER NOT NULL, kind TEXT NOT NULL, host_id INTEGER NOT NULL)');

		$pdo->exec("INSERT INTO user_auth (id, enabled) VALUES (10, 'on'), (11, 'on'), (12, 'off')");
		$pdo->exec("INSERT INTO plugin_realms (id, plugin, file) VALUES (5, 'cloudflare', 'cloudflare.php')");
		$pdo->exec('INSERT INTO user_auth_realm (user_id, realm_id) VALUES (10, 105)');
		$pdo->exec("INSERT INTO user_auth_group (id, enabled) VALUES (2, 'on')");
		$pdo->exec('INSERT INTO user_auth_group_members (group_id, user_id) VALUES (2, 11)');
		$pdo->exec('INSERT INTO user_auth_group_realm (group_id, realm_id) VALUES (2, 105)');

		$db = new Database($pdo);
		$this->assertFalse(cloudflare_user_has_view_realm($db, 0));
		$this->assertTrue(cloudflare_user_has_view_realm($db, 10));
		$this->assertTrue(cloudflare_user_has_view_realm($db, 11));
		$this->assertFalse(cloudflare_user_has_view_realm($db, 12));

		$GLOBALS['database_hostname'] = 'sqlite';
		$GLOBALS['database_port']     = '0';
		$GLOBALS['database_default']  = 'memory';
		$GLOBALS['database_sessions'] = array('sqlite:0:memory' => $pdo);

		$registrar = new class() {
			/** @var array<string, array<string, mixed>> */
			public $abilities = array();

			public function registerCategory(string $id, string $label): void {
			}

			/** @param array<string, mixed> $def */
			public function register(string $id, array $def): void {
				$this->abilities[$id] = $def;
			}
		};

		cloudflare_abilities_register($registrar);
		$this->assertArrayHasKey('cloudflare-list-tunnels', $registrar->abilities);
		$this->assertTrue(($registrar->abilities['cloudflare-list-tunnels']['permission'])(10));
		$this->assertFalse(($registrar->abilities['cloudflare-list-tunnels']['permission'])(12));

		$checks = cloudflare_doctor_register_checks(array());
		$this->assertCount(4, $checks);

		$providers = cloudflare_casc_register_providers(array());
		$this->assertArrayHasKey('cloudflare', $providers);

		$rep = cloudflare_replicate_out(array('remote_poller_id' => 2, 'rcnn_id' => new stdClass(), 'class' => 'all'));
		$this->assertSame(2, $rep['remote_poller_id']);

		// Disabled plugin returns early on every hook
		cloudflare_glue_reset();
		$GLOBALS['cloudflare_glue']['enabled'] = false;
		$this->assertSame(array('keep'), cloudflare_doctor_register_checks(array('keep')));
		$this->assertSame(array('keep'), cloudflare_casc_register_providers(array('keep')));
		$this->assertSame('raw', cloudflare_replicate_out('raw'));
	}
}
