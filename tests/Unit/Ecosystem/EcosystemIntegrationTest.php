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
use Somethingwithproof\Cloudflare\Ecosystem\ApiResourcesProvider;
use Somethingwithproof\Cloudflare\Ecosystem\CascProvider;
use Somethingwithproof\Cloudflare\Ecosystem\DoctorChecks;
use Somethingwithproof\Cloudflare\Ecosystem\DoctorDebugInfo;
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

		$registrar = new class () {
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
		$checks = $doctor->register(array('mode' => 'run', 'plugin' => 'cloudflare'));
		$byId   = array();
		foreach ($checks as $entry) {
			$this->assertSame('cloudflare', $entry['plugin']);
			$this->assertStringStartsWith('cloudflare.', $entry['id']);
			$this->assertContains($entry['status'], array('ok', 'warn', 'fail', 'info'));
			$this->assertNotSame('', $entry['message']);
			$this->assertArrayHasKey('details', $entry);
			$this->assertArrayHasKey('remediation', $entry);
			$byId[$entry['id']] = $entry;
		}

		$this->assertSame(array('cloudflare.vault_key', 'cloudflare.token_dir', 'cloudflare.tunnel_freshness', 'cloudflare.api_failure_rate'), array_keys($byId));
		$this->assertSame('fail', $byId['cloudflare.vault_key']['status']);
		$this->assertSame('ok', $byId['cloudflare.token_dir']['status']);
		$this->assertSame('warn', $byId['cloudflare.tunnel_freshness']['status']);
		$this->assertSame('fail', $byId['cloudflare.api_failure_rate']['status']);

		$listed = $doctor->register(array('mode' => 'list', 'plugin' => 'cloudflare'));
		$this->assertCount(4, $listed);
		foreach ($listed as $entry) {
			$this->assertSame('info', $entry['status']);
			$this->assertStringStartsWith('cloudflare.', $entry['id']);
		}

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

		$casc = new CascProvider(new ConfigService($db, new AccountRepository($db), new TunnelRepository($db), new MetricMappingRepository($db), new FrozenClock(1700000000)));

		// 1-argument ProviderRegistrar support
		$cascObj = new class () {
			/** @var array<int, array<string, mixed>> */
			public $items = array();

			/** @param array<string, mixed> $desc */
			public function register(array $desc): void {
				$this->items[] = $desc;
			}
		};
		$descriptor = $casc->register($cascObj);
		$this->assertCount(1, $cascObj->items);
		$this->assertSame('cloudflare', $descriptor['slug']);
		$this->assertSame('1.0', $descriptor['schemaVersion']);

		$providers = $casc->register(array());
		$this->assertArrayHasKey('cloudflare', $providers);
		$this->assertSame('cloudflare', $casc->register()['slug']);

		$exported = ($descriptor['export'])(array());
		$this->assertSame('1.0', $exported['schemaVersion']);
		$this->assertStringNotContainsString($secret, (string) json_encode($exported));
		$this->assertSame(array(), ($descriptor['validate'])($exported));
		$this->assertSame(array(), ($descriptor['plan'])($exported, array()));

		// Mutated desired account produces plan item and confirmed apply succeeds
		$changed                            = $exported['config'];
		$changed['accounts'][0]['name']     = 'Core Updated';
		$plan                               = ($descriptor['plan'])($changed, array());
		$this->assertCount(1, $plan);
		$this->assertSame('update', $plan[0]['operation']);
		$this->assertSame(str_repeat('d', 32), $plan[0]['key']);
		$this->assertNotSame('', $plan[0]['plan_hash']);

		$applied = ($descriptor['apply'])($changed, $plan[0]['plan_hash'], array());
		$this->assertTrue($applied['ok']);
		$this->assertTrue($applied['applied']);
		$this->assertSame(array(), $applied['errors']);

		// Invalid payloads and raw secrets return non-empty list<string> and fail closed
		$invalidErrors = ($descriptor['validate'])(array('schema_version' => 999));
		$this->assertNotSame(array(), $invalidErrors);
		$secretErrors = ($descriptor['validate'])(array('schema_version' => 1, 'token_ciphertext' => $secret));
		$this->assertNotSame(array(), $secretErrors);

		$invalidPlan = ($descriptor['plan'])(array('schema_version' => 999), array());
		$this->assertSame('error', $invalidPlan[0]['operation']);
		$secretPlan = ($descriptor['plan'])(array('schema_version' => 1, 'token_ciphertext' => $secret), array());
		$this->assertSame('error', $secretPlan[0]['operation']);

		$invalidApply = ($descriptor['apply'])(array('schema_version' => 999), 'bad', array());
		$this->assertFalse($invalidApply['ok']);
		$this->assertFalse($invalidApply['applied']);
		$secretApply = ($descriptor['apply'])(array('schema_version' => 1, 'token_ciphertext' => $secret), 'bad', array());
		$this->assertFalse($secretApply['ok']);
		$this->assertFalse($secretApply['applied']);

		$replication = new RemoteReplication($db);
		$sink        = new class () {
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

		$registrar = new class () {
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

		$checks = cloudflare_doctor_register_checks(array('mode' => 'run', 'plugin' => 'cloudflare'));
		$this->assertCount(4, $checks);
		$this->assertSame('cloudflare.vault_key', $checks[0]['id']);

		$debugSections = cloudflare_doctor_register_debug_info(array('plugin' => 'cloudflare'));
		$this->assertCount(1, $debugSections);
		$this->assertSame('cloudflare.overview', $debugSections[0]['id']);
		$this->assertSame('cloudflare', $debugSections[0]['plugin']);
		$this->assertArrayHasKey('plugin_version', $debugSections[0]['fields']);

		$cascRegistrar = new class () {
			/** @var array<int, array<string, mixed>> */
			public $registered = array();

			/** @param array<string, mixed> $desc */
			public function register(array $desc): void {
				$this->registered[] = $desc;
			}
		};
		$provider = cloudflare_casc_register_providers($cascRegistrar);
		$this->assertCount(1, $cascRegistrar->registered);
		$this->assertSame('cloudflare', $provider['slug']);
		$this->assertSame('1.0', $provider['schemaVersion']);

		$resources = cloudflare_api_register_resources(array());
		$this->assertSame(array('cloudflareAccounts', 'cloudflareTunnels'), array_column($resources, 'collection'));

		$rep = cloudflare_replicate_out(array('remote_poller_id' => 2, 'rcnn_id' => new stdClass(), 'class' => 'all'));
		$this->assertSame(2, $rep['remote_poller_id']);

		// Disabled plugin returns early without echoing Doctor context
		cloudflare_glue_reset();
		$GLOBALS['cloudflare_glue']['enabled'] = false;
		$this->assertSame(array(), cloudflare_doctor_register_checks(array('mode' => 'run', 'plugin' => 'cloudflare')));
		$this->assertSame(array(), cloudflare_doctor_register_debug_info(array('plugin' => 'cloudflare')));
		$this->assertSame(array('keep'), cloudflare_casc_register_providers(array('keep')));
		$this->assertSame(array(array('collection' => 'keep')), cloudflare_api_register_resources(array(array('collection' => 'keep'))));
		$this->assertSame('raw', cloudflare_replicate_out('raw'));
	}

	public function testDoctorDebugInfoRedactsSecretsAndReportsCounts(): void {
		if (!extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is not loaded');
		}

		$this->tmpDir = sys_get_temp_dir() . '/cf-debug-' . bin2hex(random_bytes(6));
		mkdir($this->tmpDir, 0700);
		$keyFile   = $this->tmpDir . '/secret-vault.key';
		$tokenFile = $this->tmpDir . '/secret-token.txt';
		$secret    = 'v1:' . base64_encode(str_repeat('z', 40));
		file_put_contents($keyFile, bin2hex(random_bytes(32)));
		chmod($keyFile, 0600);

		$db = self::sqliteDb();
		$db->execute('CREATE TABLE plugin_cloudflare_migrations (version INTEGER PRIMARY KEY, applied_at INTEGER NOT NULL)');
		$db->execute('INSERT INTO plugin_cloudflare_migrations (version, applied_at) VALUES (5, 1700000000)');
		$db->execute(
			'INSERT INTO plugin_cloudflare_accounts (cf_account_id, name, token_source, token_ciphertext, enabled, discovery_interval) VALUES (?, ?, ?, ?, 1, 300)',
			array(str_repeat('a', 32), 'Vault Account', 'vault', $secret)
		);
		$db->execute(
			'INSERT INTO plugin_cloudflare_accounts (cf_account_id, name, token_source, token_file, enabled, discovery_interval) VALUES (?, ?, ?, ?, 0, 300)',
			array(str_repeat('b', 32), 'File Account', 'file', $tokenFile)
		);
		$db->execute(
			'INSERT INTO plugin_cloudflare_tunnels (account_id, cf_tunnel_id, name, lifecycle, cf_status) VALUES (1, ?, ?, ?, ?), (1, ?, ?, ?, ?)',
			array('0b0e3f43-63a8-4f0c-bb4c-0d6b2b5a2f10', 'active-1', 'active', 'healthy', '1b0e3f43-63a8-4f0c-bb4c-0d6b2b5a2f11', 'old-1', 'retired', 'down')
		);
		$db->execute('INSERT INTO plugin_cloudflare_zones (account_id, zone_id, name, listed) VALUES (1, ?, ?, 1)', array(str_repeat('c', 32), 'example.com'));
		$db->execute("INSERT INTO plugin_cloudflare_jobs (account_id, job_type, last_status, consecutive_failures) VALUES (1, 'discovery', 'ok', 0), (1, 'analytics', 'failed', 2)");

		$info     = new DoctorDebugInfo($db, new AccountRepository($db), $keyFile, $this->tmpDir);
		$sections = $info->register(array('plugin' => 'cloudflare'));
		$encoded  = (string) json_encode($sections);

		$this->assertCount(1, $sections);
		$this->assertSame('cloudflare.overview', $sections[0]['id']);
		$this->assertSame('cloudflare', $sections[0]['plugin']);
		$fields = $sections[0]['fields'];
		$this->assertSame('5', $fields['schema_version']['value']);
		$this->assertSame('1 / 2', $fields['accounts_summary']['value']);
		$this->assertSame('vault=1, file=1', $fields['token_sources']['value']);
		$this->assertSame('present (mode 0600)', $fields['vault_key_status']['value']);
		$this->assertSame('yes', $fields['token_dir_restricted']['value']);
		$this->assertSame('1 / 1', $fields['tunnels_summary']['value']);
		$this->assertSame('1', $fields['zones_listed']['value']);
		$this->assertSame('1 / 2', $fields['collector_jobs']['value']);

		// Zero secrets or secret paths leaked
		$this->assertStringNotContainsString($secret, $encoded);
		$this->assertStringNotContainsString($keyFile, $encoded);
		$this->assertStringNotContainsString($tokenFile, $encoded);

		$unconfigured = new DoctorDebugInfo($db, new AccountRepository($db), '', '');
		$this->assertSame('unconfigured', $unconfigured->section()['fields']['vault_key_status']['value']);
		$this->assertSame('no', $unconfigured->section()['fields']['token_dir_restricted']['value']);

		$absent = new DoctorDebugInfo($db, new AccountRepository($db), $this->tmpDir . '/missing.key', '');
		$this->assertSame('absent', $absent->section()['fields']['vault_key_status']['value']);

		$dirAsKey = new DoctorDebugInfo($db, new AccountRepository($db), $this->tmpDir, '');
		$this->assertSame('not_regular_file', $dirAsKey->section()['fields']['vault_key_status']['value']);

		$linkKey = $this->tmpDir . '/link.key';
		symlink($keyFile, $linkKey);
		$symlinkKey = new DoctorDebugInfo($db, new AccountRepository($db), $linkKey, '');
		$this->assertSame('symlink_refused', $symlinkKey->section()['fields']['vault_key_status']['value']);

		// Missing migrations table falls back safely to schema_version 0
		$dbNoMig = self::sqliteDb();
		$noMig   = new DoctorDebugInfo($dbNoMig, new AccountRepository($dbNoMig), '', '');
		$this->assertSame('0', $noMig->section()['fields']['schema_version']['value']);
	}

	public function testApiResourcesAreReadOnlyRedactedAndSchemaSeparatesConfigFromState(): void {
		if (!extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is not loaded');
		}

		$db     = self::sqliteDb();
		$secret = 'v1:' . base64_encode(str_repeat('q', 40));
		$db->execute(
			'INSERT INTO plugin_cloudflare_accounts (cf_account_id, name, token_source, token_ciphertext, enabled, discovery_interval) VALUES (?, ?, ?, ?, 1, 300)',
			array(str_repeat('a', 32), 'Edge', 'vault', $secret)
		);
		$db->execute(
			'INSERT INTO plugin_cloudflare_tunnels (account_id, cf_tunnel_id, name, lifecycle, cf_status, connector_count, connection_count, last_seen_at) VALUES (1, ?, ?, ?, ?, 2, 4, 1700000900)',
			array('0b0e3f43-63a8-4f0c-bb4c-0d6b2b5a2f10', 'edge-nyc', 'active', 'healthy')
		);

		$provider  = new ApiResourcesProvider($db);
		$resources = $provider->register(array(array('plugin' => 'other', 'collection' => 'otherItems')));

		$this->assertCount(3, $resources);
		$cfResources = array_slice($resources, 1);
		$this->assertSame(array('cloudflareAccounts', 'cloudflareTunnels'), array_column($cfResources, 'collection'));

		foreach ($cfResources as $descriptor) {
			$this->assertSame('cloudflare', $descriptor['plugin']);
			$this->assertMatchesRegularExpression('/^[a-z][A-Za-z0-9]{2,63}$/', $descriptor['collection']);
			$this->assertSame('cloudflare.php', $descriptor['realm_read']);
			$this->assertSame('schema/cloudflare.schema.yaml', $descriptor['schema']);
			$this->assertIsCallable($descriptor['handlers']['list']);
			$this->assertIsCallable($descriptor['handlers']['get']);
		}

		$accounts = ($cfResources[0]['handlers']['list'])(array('user_id' => 1));
		$this->assertCount(1, $accounts);
		$this->assertSame('cloudflareAccounts/1', $accounts[0]['name']);
		$this->assertStringNotContainsString($secret, (string) json_encode($accounts));
		$this->assertArrayNotHasKey('token_ciphertext', $accounts[0]);
		$this->assertArrayNotHasKey('token_file', $accounts[0]);

		$accountOne = ($cfResources[0]['handlers']['get'])('1', array('user_id' => 1));
		$this->assertIsArray($accountOne);
		$this->assertSame('Edge', $accountOne['display_name']);
		$this->assertNull(($cfResources[0]['handlers']['get'])('999', array('user_id' => 1)));
		$this->assertNull(($cfResources[0]['handlers']['get'])('bad', array('user_id' => 1)));

		$tunnels = ($cfResources[1]['handlers']['list'])(array('user_id' => 1));
		$this->assertCount(1, $tunnels);
		$this->assertSame('cloudflareTunnels/1', $tunnels[0]['name']);

		$tunnelOne = ($cfResources[1]['handlers']['get'])('1', array('user_id' => 1));
		$this->assertIsArray($tunnelOne);
		$this->assertSame('edge-nyc', $tunnelOne['display_name']);
		$this->assertNull(($cfResources[1]['handlers']['get'])('999', array('user_id' => 1)));
		$this->assertNull(($cfResources[1]['handlers']['get'])('bad', array('user_id' => 1)));

		// Null-DB fallback returns empty lists and null lookups safely
		$noDb = new ApiResourcesProvider();
		$this->assertSame(array(), $noDb->listAccounts());
		$this->assertNull($noDb->getAccount('1'));
		$this->assertSame(array(), $noDb->listTunnels());
		$this->assertNull($noDb->getTunnel('1'));

		// Static Drupal-style schema exists and enforces Config vs. State separation
		$root       = dirname(__DIR__, 3);
		$schemaFile = $root . '/schema/cloudflare.schema.yaml';
		$this->assertFileExists($schemaFile);
		$yaml = (string) file_get_contents($schemaFile);
		$this->assertStringContainsString('SPDX-FileCopyrightText:', $yaml);
		$this->assertStringContainsString('cloudflare.settings:', $yaml);
		$this->assertStringContainsString('cloudflare.casc:', $yaml);
		$this->assertStringContainsString('cloudflare.state:', $yaml);
		$this->assertStringContainsString('exportable: true', $yaml);
		$this->assertStringContainsString('exportable: false', $yaml);

		// Zero cross-plugin PHP imports in src/ and includes/
		foreach (array($root . '/src/Ecosystem/DoctorDebugInfo.php', $root . '/src/Ecosystem/ApiResourcesProvider.php', $root . '/includes/ecosystem.php', $root . '/setup.php') as $file) {
			$src = (string) file_get_contents($file);
			$this->assertDoesNotMatchRegularExpression('/\b(use|require|include)\b[^\n;]*(plugin_doctor|plugin_api|CactiDoctor|CactiApi)/i', $src, $file);
		}
	}
}
