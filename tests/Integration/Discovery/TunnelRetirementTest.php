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

namespace Somethingwithproof\Cloudflare\Tests\Integration\Discovery;

use Somethingwithproof\Cloudflare\Storage\Database;
use Somethingwithproof\Cloudflare\Storage\Migrator;
use Somethingwithproof\Cloudflare\Api\TunnelRecord;
use Somethingwithproof\Cloudflare\Discovery\Reconciler;
use Somethingwithproof\Cloudflare\Metrics\ObservationReader;
use Somethingwithproof\Cloudflare\Tests\Support\FrozenClock;
use Somethingwithproof\Cloudflare\Tests\Support\TestDatabase;
use Somethingwithproof\Cloudflare\Tests\Integration\IntegrationTestCase;

final class TunnelRetirementTest extends IntegrationTestCase {
	/** @var Database */
	private $db;

	protected function setUp(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();
		require_once dirname(__DIR__, 2) . '/Support/CactiGlueStubs.php';
		require_once dirname(__DIR__, 3) . '/includes/tunnel_retirement.php';
		cloudflare_glue_reset();
		$this->db = new Database(TestDatabase::pdo());
		(new Migrator($this->db, Migrator::defaultMigrations()))->migrate();
		$this->db->execute("INSERT INTO plugin_cloudflare_accounts (id, cf_account_id, name, token_source, created_at, updated_at) VALUES (1, ?, 'account', 'file', 1, 1)", array(str_repeat('a', 32)));
		(new Reconciler($this->db, new FrozenClock(100)))->applyListing(1, array(self::tunnel()));
	}

	private static function tunnel(): TunnelRecord {
		return new TunnelRecord('11111111-1111-4111-8111-111111111111', 'tunnel', 'healthy', 'cfd_tunnel', null, null, null);
	}

	public function testOnlyDeletedTunnelsRetireAndRediscoveryRestoresIndex(): void {
		$id = (int) $this->db->fetchCell('SELECT id FROM plugin_cloudflare_tunnels');
		$this->assertFalse(cloudflare_retire_tunnel($id, $this->db));
		$this->db->execute("UPDATE plugin_cloudflare_tunnels SET lifecycle = 'unavailable'");
		$this->assertFalse(cloudflare_retire_tunnel($id, $this->db));
		$this->db->execute("UPDATE plugin_cloudflare_tunnels SET lifecycle = 'deleted'");
		$this->assertTrue(cloudflare_retire_tunnel($id, $this->db));
		$this->assertTrue(cloudflare_retire_tunnel($id, $this->db));
		$reader = new ObservationReader($this->db, new FrozenClock(200));
		$this->assertSame(array(), $reader->tunnelIndex(1));
		$this->assertSame('deleted', $this->db->fetchCell('SELECT lifecycle FROM plugin_cloudflare_tunnels WHERE id = ?', array($id)));
		(new Reconciler($this->db, new FrozenClock(200)))->applyListing(1, array(self::tunnel()));
		$this->assertCount(1, $reader->tunnelIndex(1));
		$this->assertNull($this->db->fetchCell('SELECT retired_at FROM plugin_cloudflare_tunnels WHERE id = ?', array($id)));
	}

	public function testRemovesOwnedObjectsButPreservesSharedDataAndRejectsReusedIds(): void {
		foreach (array(
			'CREATE TABLE IF NOT EXISTS graph_templates (id INT PRIMARY KEY, hash CHAR(32))',
			'CREATE TABLE IF NOT EXISTS graph_local (id INT PRIMARY KEY, host_id INT, snmp_index VARCHAR(36), graph_template_id INT)',
			'CREATE TABLE IF NOT EXISTS data_local (id INT PRIMARY KEY, host_id INT, snmp_index VARCHAR(36))',
			'CREATE TABLE IF NOT EXISTS data_template_rrd (id INT PRIMARY KEY, local_data_id INT)',
			'CREATE TABLE IF NOT EXISTS graph_templates_item (local_graph_id INT, task_item_id INT)',
		) as $sql) {
			$this->db->execute($sql);
		}

		foreach (array('graph_templates_item', 'data_template_rrd', 'data_local', 'graph_local', 'graph_templates') as $table) {
			$this->db->execute('DELETE FROM ' . $table);
		}

		$id = (int) $this->db->fetchCell('SELECT id FROM plugin_cloudflare_tunnels');
		$uuid = self::tunnel()->id;
		$this->db->execute("UPDATE plugin_cloudflare_tunnels SET lifecycle = 'deleted'");
		$this->db->execute('INSERT INTO graph_templates VALUES (1, ?)', array(str_repeat('b', 32)));
		$this->db->execute('INSERT INTO graph_local VALUES (10, 1, ?, 1)', array($uuid));
		$this->db->execute('INSERT INTO data_local VALUES (20, 1, ?)', array($uuid));
		$this->db->execute('INSERT INTO data_template_rrd VALUES (30, 20)');
		$this->db->execute('INSERT INTO graph_templates_item VALUES (10, 30), (99, 30)');
		$this->db->execute("INSERT INTO plugin_cloudflare_resources (account_id, kind, tunnel_id, template_hash, host_id, local_graph_id, local_data_id, created_at) VALUES (1, 'graph', ?, ?, 1, 10, 20, 1)", array($id, str_repeat('a', 32)));

		$this->db->execute('INSERT INTO graph_templates VALUES (2, ?)', array(str_repeat('c', 32)));
		$this->db->execute('INSERT INTO graph_local VALUES (11, 1, ?, 2)', array($uuid));
		$this->db->execute('INSERT INTO data_local VALUES (21, 1, ?)', array($uuid));
		$this->db->execute('INSERT INTO data_template_rrd VALUES (31, 21)');
		$this->db->execute('INSERT INTO graph_templates_item VALUES (11, 31)');
		$this->db->execute("INSERT INTO plugin_cloudflare_resources (account_id, kind, tunnel_id, template_hash, host_id, local_graph_id, local_data_id, created_at) VALUES (1, 'graph', ?, ?, 1, 11, 21, 1)", array($id, str_repeat('c', 32)));
		$this->assertFalse(cloudflare_retire_tunnel($id, $this->db), 'A graph id reused by another template is never removed');
		$this->assertSame(2, (int) $this->db->fetchCell('SELECT COUNT(*) FROM graph_local'));
		$this->db->execute('DELETE FROM graph_templates WHERE id = 1');
		$this->assertFalse(cloudflare_retire_tunnel($id, $this->db), 'A graph whose template row is gone is never removed');
		$this->assertSame(2, (int) $this->db->fetchCell('SELECT COUNT(*) FROM graph_local'));
		$this->db->execute('INSERT INTO graph_templates VALUES (1, ?)', array(str_repeat('a', 32)));
		$this->assertFalse(cloudflare_retire_tunnel($id, $this->db), 'An API removal that did not succeed leaves retirement retryable');
		$this->assertNull($this->db->fetchCell('SELECT retired_at FROM plugin_cloudflare_tunnels'));
		$db = $this->db;
		$GLOBALS['cloudflare_glue']['remove_graph'] = function (int $graphId) use ($db): void {
			$db->execute('DELETE FROM graph_local WHERE id = ?', array($graphId));
			$db->execute('DELETE FROM graph_templates_item WHERE local_graph_id = ?', array($graphId));
		};
		$GLOBALS['cloudflare_glue']['remove_data'] = function (int $dataId) use ($db): void {
			$db->execute('DELETE FROM data_local WHERE id = ?', array($dataId));
		};
		$this->assertTrue(cloudflare_retire_tunnel($id, $this->db));
		$this->assertSame(1, (int) $this->db->fetchCell('SELECT COUNT(*) FROM data_local'), 'An unrelated graph still uses this source');
		$this->assertSame(0, (int) $this->db->fetchCell("SELECT COUNT(*) FROM plugin_cloudflare_resources WHERE kind = 'graph'"));

		foreach (array('graph_templates_item', 'data_template_rrd', 'data_local', 'graph_local', 'graph_templates') as $table) {
			$this->db->execute('DROP TABLE ' . $table);
		}

		cloudflare_glue_reset();
	}

	public function testPermissionAndConcurrentCollectorRefuseBeforeMutation(): void {
		$this->db->execute("UPDATE plugin_cloudflare_tunnels SET lifecycle = 'deleted'");
		$id = (int) $this->db->fetchCell('SELECT id FROM plugin_cloudflare_tunnels');
		$GLOBALS['cloudflare_glue']['realm'] = false;
		$this->assertFalse(cloudflare_retire_tunnel($id, $this->db));
		$GLOBALS['cloudflare_glue']['realm'] = true;
		$other = new Database(TestDatabase::pdo());
		$this->assertTrue($other->acquireLock('plugin_cloudflare_collector', 0));
		$this->assertFalse(cloudflare_retire_tunnel($id, $this->db));
		$other->releaseLock('plugin_cloudflare_collector');
		$this->assertNull($this->db->fetchCell('SELECT retired_at FROM plugin_cloudflare_tunnels'));
	}
}
