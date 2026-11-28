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

namespace Somethingwithproof\Cloudflare\Tests\Unit\Config;

use Somethingwithproof\Cloudflare\Account\AccountRepository;
use Somethingwithproof\Cloudflare\Config\ConfigCodec;
use Somethingwithproof\Cloudflare\Config\ConfigService;
use Somethingwithproof\Cloudflare\Discovery\TunnelRepository;
use Somethingwithproof\Cloudflare\Metrics\MetricMappingRepository;
use Somethingwithproof\Cloudflare\Storage\Database;
use Somethingwithproof\Cloudflare\Tests\Support\FrozenClock;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class ConfigServiceTest extends TestCase {
	private static function sqliteDb(): Database {
		$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
		$pdo->exec('CREATE TABLE plugin_cloudflare_accounts (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			cf_account_id TEXT NOT NULL UNIQUE,
			name TEXT NOT NULL,
			token_source TEXT NOT NULL DEFAULT "vault",
			token_ciphertext TEXT NULL,
			token_file TEXT NULL,
			enabled INTEGER NOT NULL DEFAULT 1,
			status TEXT NOT NULL DEFAULT "unverified",
			status_message TEXT NOT NULL DEFAULT "",
			permissions TEXT NULL,
			last_checked_at INTEGER NULL,
			last_verified_at INTEGER NULL,
			collect_tunnels INTEGER NOT NULL DEFAULT 1,
			collect_analytics INTEGER NOT NULL DEFAULT 0,
			create_graphs INTEGER NOT NULL DEFAULT 0,
			discovery_interval INTEGER NOT NULL DEFAULT 300,
			created_at INTEGER NOT NULL DEFAULT 1,
			updated_at INTEGER NOT NULL DEFAULT 1
		)');
		$pdo->exec('CREATE TABLE plugin_cloudflare_zones (
			account_id INTEGER NOT NULL,
			zone_id TEXT NOT NULL,
			name TEXT NOT NULL,
			listed INTEGER NOT NULL DEFAULT 1
		)');
		$pdo->exec('CREATE TABLE plugin_cloudflare_tunnels (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			account_id INTEGER NOT NULL,
			cf_tunnel_id TEXT NOT NULL,
			name TEXT NOT NULL,
			lifecycle TEXT NOT NULL DEFAULT "active",
			cf_status TEXT NOT NULL DEFAULT "healthy"
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

		return new Database($pdo);
	}

	private function service(Database $db): ConfigService {
		return new ConfigService(
			$db,
			new AccountRepository($db),
			new TunnelRepository($db),
			new MetricMappingRepository($db),
			new FrozenClock(1700000000)
		);
	}

	public function testExportsSecretReferencesInYamlAndXmlAndNeverExposesRawTokens(): void {
		if (!extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is not loaded');
		}

		$db     = self::sqliteDb();
		$cfId   = str_repeat('a', 32);
		$secret = 'v1:' . base64_encode(str_repeat('x', 48));
		$db->execute(
			'INSERT INTO plugin_cloudflare_accounts (cf_account_id, name, token_source, token_ciphertext, enabled, collect_tunnels, collect_analytics, create_graphs, discovery_interval)
			VALUES (?, ?, ?, ?, 1, 1, 1, 0, 300)',
			array($cfId, 'Prod Edge', 'vault', $secret)
		);
		$db->execute('INSERT INTO plugin_cloudflare_zones (account_id, zone_id, name, listed) VALUES (1, ?, ?, 1)', array(str_repeat('b', 32), 'edge.example'));

		$service = $this->service($db);

		foreach (array(ConfigCodec::FORMAT_YAML, ConfigCodec::FORMAT_XML) as $format) {
			$exported = $service->export($format);
			$this->assertStringContainsString('vault:' . $cfId, $exported);
			$this->assertStringNotContainsString($secret, $exported);
			$this->assertStringNotContainsString('token_ciphertext', $exported);

			$validated = $service->validate($exported, $format);
			$this->assertTrue($validated['valid'], implode('; ', $validated['errors']));

			$plan = $service->plan($exported, $format);
			$this->assertTrue($plan['valid']);
			$this->assertTrue($plan['empty']);
			$this->assertNotSame('', $plan['plan_hash']);
		}
	}

	public function testRejectsRawTokensVaultCiphertextsAndXmlDoctype(): void {
		$rawTokenYaml = "schema_version: 1\naccounts:\n  - cf_account_id: " . str_repeat('a', 32) . "\n    name: Bad\n    discovery_interval: 300\n    token_ref: cfut_" . str_repeat('z', 40) . "\n";

		try {
			ConfigCodec::decode(ConfigCodec::FORMAT_YAML, $rawTokenYaml);
			$this->fail('raw token should be rejected');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('secret reference', $e->getMessage());
		}

		$xxe = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><cloudflare_config/>';
		$this->expectException(InvalidArgumentException::class);
		ConfigCodec::decode(ConfigCodec::FORMAT_XML, $xxe);
	}

	public function testPlanAndConfirmedApplyRequiresExactPlanHash(): void {
		if (!extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is not loaded');
		}

		$db      = self::sqliteDb();
		$service = $this->service($db);
		$cfId    = str_repeat('c', 32);

		$yaml = ConfigCodec::encode(ConfigCodec::FORMAT_YAML, array(
			'schema_version' => 1,
			'accounts'       => array(
				array(
					'cf_account_id'      => $cfId,
					'name'               => 'New Account',
					'enabled'            => true,
					'collect_tunnels'    => true,
					'collect_analytics'  => true,
					'create_graphs'      => false,
					'discovery_interval' => 600,
					'token_ref'          => 'vault:' . $cfId,
				),
			),
		));

		$plan = $service->plan($yaml, ConfigCodec::FORMAT_YAML);
		$this->assertTrue($plan['valid']);
		$this->assertFalse($plan['empty']);
		$this->assertCount(1, $plan['changes']);

		$rejected = $service->apply($yaml, 'wrong-hash', ConfigCodec::FORMAT_YAML);
		$this->assertFalse($rejected['applied']);
		$this->assertSame(0, (int) $db->fetchCell('SELECT COUNT(*) FROM plugin_cloudflare_accounts'));

		$applied = $service->apply($yaml, $plan['plan_hash'], ConfigCodec::FORMAT_YAML);
		$this->assertTrue($applied['applied']);
		$this->assertSame(1, (int) $db->fetchCell('SELECT COUNT(*) FROM plugin_cloudflare_accounts'));

		$secondPlan = $service->plan($yaml, ConfigCodec::FORMAT_YAML);
		$this->assertTrue($secondPlan['empty']);

		// Update existing account and add a metric mapping, then verify XML round-trip apply.
		$xml = ConfigCodec::encode(ConfigCodec::FORMAT_XML, array(
			'schema_version'  => 1,
			'accounts'        => array(
				array(
					'cf_account_id'      => $cfId,
					'name'               => 'Renamed Account',
					'enabled'            => false,
					'collect_tunnels'    => true,
					'collect_analytics'  => false,
					'create_graphs'      => true,
					'discovery_interval' => 900,
					'token_ref'          => 'file:/etc/cacti/cloudflare/prod.token',
				),
			),
			'metric_mappings' => array(
				array(
					'metric_name'        => 'zone_requests',
					'schema_version'     => 1,
					'ds_type'            => 'GAUGE',
					'unit'               => 'requests',
					'query_field'        => 'requests',
					'data_template_hash' => str_repeat('e', 32),
					'rrd_ds_name'        => 'requests',
				),
			),
		));

		$updatePlan = $service->plan($xml, ConfigCodec::FORMAT_XML);
		$this->assertTrue($updatePlan['valid']);
		$this->assertFalse($updatePlan['empty']);
		$this->assertCount(1, $updatePlan['changes']);

		$updateApplied = $service->apply($xml, $updatePlan['plan_hash'], ConfigCodec::FORMAT_XML);
		$this->assertTrue($updateApplied['applied']);
		$this->assertSame('Renamed Account', $db->fetchCell('SELECT name FROM plugin_cloudflare_accounts WHERE cf_account_id = ?', array($cfId)));
	}
}
