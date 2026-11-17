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
use Somethingwithproof\Cloudflare\Storage\Migrator;
use Somethingwithproof\Cloudflare\Tests\Support\TestDatabase;
use Somethingwithproof\Cloudflare\Tests\Integration\IntegrationTestCase;

final class OperatorWorkflowsTest extends IntegrationTestCase {
	/** @var Database */
	private $db;

	protected function setUp(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();
		require_once dirname(__DIR__, 2) . '/Support/CactiGlueStubs.php';
		require_once dirname(__DIR__, 3) . '/includes/accounts_page.php';
		require_once dirname(__DIR__, 3) . '/includes/tunnel_retirement.php';
		require_once dirname(__DIR__, 3) . '/includes/viewer.php';
		$called_by_script_server = true;
		$reporting = error_reporting();
		require_once dirname(__DIR__, 3) . '/ss_cloudflare_zones.php';
		error_reporting($reporting);
		cloudflare_glue_reset();
		$pdo = TestDatabase::pdo();
		$this->db = new Database($pdo);
		(new Migrator($this->db, Migrator::defaultMigrations()))->migrate();
		$GLOBALS['database_hostname'] = 'fixture';
		$GLOBALS['database_port'] = '3306';
		$GLOBALS['database_default'] = 'cacti';
		$GLOBALS['database_sessions'] = array('fixture:3306:cacti' => $pdo);
		$_REQUEST = array();
		$_SESSION = array();
		$this->db->execute("INSERT INTO plugin_cloudflare_accounts (id, cf_account_id, name, token_source, enabled, collect_analytics, discovery_interval, created_at, updated_at) VALUES (1, ?, 'account', 'file', 1, 1, 300, 1, 1)", array(str_repeat('a', 32)));
		$this->db->execute("INSERT INTO plugin_cloudflare_tunnels (id, account_id, cf_tunnel_id, name, lifecycle, cf_status, tun_type, first_seen_at, last_seen_at) VALUES (1, 1, ?, '<tunnel>', 'deleted', 'down', 'cfd_tunnel', 1, 1)", array('11111111-1111-4111-8111-111111111111'));
	}

	protected function tearDown(): void {
		unset($GLOBALS['database_sessions'], $_SERVER['REQUEST_METHOD']);
		$_REQUEST = array();
		$_SESSION = array();
		TestDatabase::reset();
	}

	public function testRetirementRequiresConfirmationAndPostAndRechecksEligibility(): void {
		$_REQUEST = array('tunnel_id' => '1', 'confirmed' => '1');
		$_SERVER['REQUEST_METHOD'] = 'GET';
		ob_start();
		$url = cloudflare_accounts_retire();
		$html = (string) ob_get_clean();
		$this->assertNull($url);
		$this->assertStringContainsString("name='confirmed' value='1'", $html);
		$this->assertStringContainsString('&lt;tunnel&gt;', $html);
		$this->assertNull($this->db->fetchCell('SELECT retired_at FROM plugin_cloudflare_tunnels'));
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assertSame('cloudflare.php?action=tunnels', cloudflare_accounts_retire());
		$this->assertNotNull($this->db->fetchCell('SELECT retired_at FROM plugin_cloudflare_tunnels'));
		$GLOBALS['cloudflare_glue']['realm'] = false;
		$this->assertSame('../../permission_denied.php', cloudflare_accounts_retire());
		$GLOBALS['cloudflare_glue']['realm'] = true;
		$this->db->execute("UPDATE plugin_cloudflare_tunnels SET lifecycle = 'active', retired_at = NULL");
		$this->assertSame('cloudflare.php?action=tunnels', cloudflare_accounts_retire());
		$this->assertNull($this->db->fetchCell('SELECT retired_at FROM plugin_cloudflare_tunnels'));
	}

	public function testZeroBudgetFactoryDefersAnalyticsWithoutReadingCredentials(): void {
		cloudflare_analytics_collector()->run(0);
		$this->assertSame('never', $this->db->fetchCell("SELECT last_status FROM plugin_cloudflare_jobs WHERE job_type = 'analytics'"));
		$this->assertSame(0, (int) $this->db->fetchCell('SELECT COUNT(*) FROM plugin_cloudflare_zones'));
	}

	public function testConnectorCsvKeepsVersionLocationAndUntrustedCells(): void {
		$this->db->execute("INSERT INTO plugin_cloudflare_connectors (tunnel_id, cf_connector_id, version, arch, connection_count, colos, origin_ips, last_seen_at) VALUES (1, ?, '2026.1', 'linux_amd64', 2, 'SJC', '=address', 1)", array('22222222-2222-4222-8222-222222222222'));
		$_REQUEST = array('tunnel_id' => '1');
		$stream = cloudflare_viewer_export_file('connectors', $this->db);
		$header = fgetcsv($stream);
		$row = fgetcsv($stream);
		fclose($stream);
		$this->assertIsArray($header);
		$this->assertIsArray($row);
		$this->assertSame('2026.1', $row[5]);
		$this->assertSame('SJC', $row[7]);
		$this->assertSame("'=address", $row[8]);
		$_REQUEST['tunnel_id'] = '999';
		$stream = cloudflare_viewer_export_file('connectors', $this->db);
		$this->assertIsArray(fgetcsv($stream));
		$this->assertFalse(fgetcsv($stream));
		fclose($stream);
	}

	public function testCachedZoneDisplayAndScriptServerEnforceFreshnessAndHostScope(): void {
		$zone = str_repeat('b', 32);
		$now = time();
		$this->db->execute("INSERT INTO plugin_cloudflare_resources (account_id, kind, host_id, created_at) VALUES (1, 'device', 10, 1)");
		$this->db->execute("INSERT INTO plugin_cloudflare_jobs (account_id, job_type, last_status) VALUES (1, 'analytics', 'ok')");
		$this->db->execute("INSERT INTO plugin_cloudflare_zones (account_id, zone_id, name, status, sampled_at, window_start, window_end, requests, bytes, errors, sample_interval) VALUES (1, ?, '<zone>', 'ok', ?, ?, ?, 600, 9000, 30, 1)", array($zone, $now, $now - 600, $now - 300));
		$this->assertSame($zone . "\n", ss_cloudflare_zones('10', 'index'));
		$this->assertSame('1', ss_cloudflare_zones('10', 'num_indexes'));
		$this->assertSame($zone . '!' . $zone . "\n", ss_cloudflare_zones('10', 'query', 'zoneId'));
		$this->assertSame($zone . "!<zone>\n", ss_cloudflare_zones('10', 'query', 'zoneName'));
		$this->assertSame($zone . "!U\n", ss_cloudflare_zones('10', 'query', 'requests'));
		$this->assertSame('', ss_cloudflare_zones('10', 'query', 'unknown'));
		$this->assertSame('2.000000', ss_cloudflare_zones('10', 'get', 'requests', $zone));
		$this->assertSame('U', ss_cloudflare_zones('11', 'get', 'requests', $zone));
		$this->assertSame('U', ss_cloudflare_zones('bad', 'get', 'requests', $zone));
		$this->assertSame('', ss_cloudflare_zones('bad', 'index'));
		$this->assertSame('U', ss_cloudflare_zones('10', 'bad'));
		$this->assertSame('U', ss_cloudflare_zones('10', 'get', 'requests', 'bad'));
		ob_start();
		cloudflare_viewer_analytics();
		ob_end_clean();
		$cells = array_map(function (array $call): string { return (string) $call[0]; }, cloudflare_glue_calls('form_selectable_cell'));
		$this->assertContains('&lt;zone&gt;', $cells);
		$this->assertContains('5.000000', $cells);
		$this->db->execute('UPDATE plugin_cloudflare_zones SET requests = 0, errors = 0');
		$this->assertSame('0.000000', ss_cloudflare_zones('10', 'get', 'requests', $zone));
		$this->assertSame('U', ss_cloudflare_zones('10', 'get', 'errorPct', $zone));
		$this->db->execute('UPDATE plugin_cloudflare_zones SET requests = NULL');
		$this->assertSame('U', ss_cloudflare_zones('10', 'get', 'requests', $zone));
		$this->db->execute('UPDATE plugin_cloudflare_zones SET requests = 600, sampled_at = ?', array($now - 1000));
		$this->assertSame('U', ss_cloudflare_zones('10', 'get', 'requests', $zone));
		$this->assertGreaterThanOrEqual(1000, (int) ss_cloudflare_zones('10', 'get', 'age', $zone));
		$this->db->execute('DROP TABLE plugin_cloudflare_zones');
		$this->assertSame('U', ss_cloudflare_zones('10', 'get', 'requests', $zone));
		$this->assertSame('', ss_cloudflare_zones('10', 'index'));
	}
}
