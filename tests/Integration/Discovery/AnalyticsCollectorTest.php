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

use Somethingwithproof\Cloudflare\Account\AccountRepository;
use Somethingwithproof\Cloudflare\Account\CredentialResolver;
use Somethingwithproof\Cloudflare\Api\CloudflareClient;
use Somethingwithproof\Cloudflare\Api\ZoneAnalytics;
use Somethingwithproof\Cloudflare\Api\RetryPolicy;
use Somethingwithproof\Cloudflare\Discovery\AnalyticsCollector;
use Somethingwithproof\Cloudflare\Metrics\ZoneReader;
use Somethingwithproof\Cloudflare\Http\Response;
use Somethingwithproof\Cloudflare\Storage\Database;
use Somethingwithproof\Cloudflare\Storage\Migrator;
use Somethingwithproof\Cloudflare\Tests\Support\TestDatabase;
use Somethingwithproof\Cloudflare\Tests\Support\FakeTransport;
use Somethingwithproof\Cloudflare\Tests\Support\FrozenClock;
use Somethingwithproof\Cloudflare\Tests\Support\RecordingSleeper;
use Somethingwithproof\Cloudflare\Tests\Integration\IntegrationTestCase;

final class AnalyticsCollectorTest extends IntegrationTestCase {
	public function testCollectsAndServesCachedRatesThenInvalidatesOnOutageAndDisable(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();
		$db = new Database(TestDatabase::pdo());
		(new Migrator($db, Migrator::defaultMigrations()))->migrate();
		$dir = sys_get_temp_dir() . '/cf-zone-test-' . bin2hex(random_bytes(6));
		mkdir($dir, 0700);
		$file = $dir . '/token';
		file_put_contents($file, 'cfut_' . str_repeat('a', 40));
		chmod($file, 0600);
		$account = str_repeat('a', 32);
		$zone = str_repeat('b', 32);

		try {
			$db->execute("INSERT INTO plugin_cloudflare_accounts (id, cf_account_id, name, token_source, token_file, enabled, collect_analytics, created_at, updated_at) VALUES (1, ?, 'account', 'file', ?, 1, 1, 1, 1)", array($account, $file));
			$db->execute("INSERT INTO plugin_cloudflare_resources (account_id, kind, host_id, created_at) VALUES (1, 'device', 10, 1)");
			$transport = new FakeTransport();
			$transport->queue(new Response(200, array(), json_encode(array('success' => true, 'errors' => array(), 'result' => array(array('id' => $zone, 'name' => 'zone.example', 'account' => array('id' => $account)))), JSON_THROW_ON_ERROR)));
			$transport->queue(new Response(200, array(), '{"data":{"viewer":{"zones":[{"settings":{"httpRequestsAdaptiveGroups":{"enabled":true,"maxDuration":86400,"notOlderThan":86400,"maxPageSize":1000,"maxNumberOfFields":40}}}]}},"errors":null}'));
			$transport->queue(new Response(200, array(), '{"data":{"viewer":{"zones":[{"total":[{"count":600,"sum":{"edgeResponseBytes":9000},"avg":{"sampleInterval":1}}],"errors":[{"count":30}]}]}},"errors":null}'));
			$clock = new FrozenClock();
			$repo = new AccountRepository($db);
			$collector = new AnalyticsCollector($db, $repo, new CredentialResolver($repo, null), new ZoneAnalytics(new CloudflareClient($transport, new RetryPolicy($clock, new RecordingSleeper(), 0))), $clock, function (): bool { return true; });
			$collector->run();
			$reader = new ZoneReader($db, $clock);
			$this->assertSame(array($zone => 'zone.example'), $reader->index(10));
			$this->assertSame('2.000000', $reader->value(10, $zone, 'requests'));
			$this->assertSame('30.000000', $reader->value(10, $zone, 'bytes'));
			$this->assertSame('5.000000', $reader->value(10, $zone, 'errorPct'));
			$this->assertSame('U', $reader->value(11, $zone, 'requests'));
			$db->execute("UPDATE plugin_cloudflare_jobs SET next_run_at = 0 WHERE job_type = 'analytics'");
			$transport->queue(new Response(503, array(), '{}'));
			$clock->advance(300);
			$collector->run();
			$this->assertSame('U', $reader->value(10, $zone, 'requests'));
			$this->assertSame('300', $reader->value(10, $zone, 'age'));
			$db->execute('UPDATE plugin_cloudflare_accounts SET collect_analytics = 0');
			$this->assertSame(array(), $reader->index(10));
		} finally {
			unlink($file);
			rmdir($dir);
			TestDatabase::reset();
		}
	}
}
