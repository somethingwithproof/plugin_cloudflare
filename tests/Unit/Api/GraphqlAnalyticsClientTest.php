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

namespace Somethingwithproof\Cloudflare\Tests\Unit\Api;

use Somethingwithproof\Cloudflare\Api\ApiException;
use Somethingwithproof\Cloudflare\Api\CloudflareClient;
use Somethingwithproof\Cloudflare\Api\ErrorClass;
use Somethingwithproof\Cloudflare\Api\GraphqlAnalyticsClient;
use Somethingwithproof\Cloudflare\Api\RetryPolicy;
use Somethingwithproof\Cloudflare\Http\Response;
use Somethingwithproof\Cloudflare\Metrics\AnalyticsSummary;
use Somethingwithproof\Cloudflare\Storage\Database;
use Somethingwithproof\Cloudflare\Tests\Support\FakeTransport;
use Somethingwithproof\Cloudflare\Tests\Support\FrozenClock;
use Somethingwithproof\Cloudflare\Tests\Support\RecordingSleeper;
use PDO;
use PHPUnit\Framework\TestCase;

final class GraphqlAnalyticsClientTest extends TestCase {
	/** @var FakeTransport */
	private $transport;

	/** @var GraphqlAnalyticsClient */
	private $client;

	protected function setUp(): void {
		$this->transport = new FakeTransport();
		$this->client    = new GraphqlAnalyticsClient(
			new CloudflareClient($this->transport, new RetryPolicy(new FrozenClock(), new RecordingSleeper(), 1, 2))
		);
	}

	public function testRefusesArbitraryUnallowlistedQueries(): void {
		$this->expectException(ApiException::class);
		$this->expectExceptionMessage('allowlist');

		$this->client->executeAllowlisted('arbitrary_mutation', array(), 'cfut_' . str_repeat('a', 40));
	}

	public function testCollectsZoneRequestErrorRateAndEdgeLatencyMetrics(): void {
		$this->transport->queue(new Response(200, array(), '{"data":{"viewer":{"zones":[{"settings":{"httpRequestsAdaptiveGroups":{"enabled":true,"maxDuration":86400,"notOlderThan":86400,"maxPageSize":100,"maxNumberOfFields":40}}}]}},"errors":null}'));
		$this->transport->queue(new Response(200, array(), '{"data":{"viewer":{"zones":[{"total":[{"count":1000,"sum":{"edgeResponseBytes":250000},"avg":{"sampleInterval":1,"edgeTimeToFirstByteMs":42.5},"quantiles":{"edgeTimeToFirstByteMsP95":185.0}}],"errors":[{"count":25}]}]}},"errors":null}'));

		$metrics = $this->client->collectZoneMetrics(str_repeat('a', 32), 'cfut_' . str_repeat('a', 40), 1000, 1300, static function (): void {
		});

		$this->assertNotNull($metrics);
		$this->assertSame(1000.0, $metrics['requests']);
		$this->assertSame(250000.0, $metrics['bytes']);
		$this->assertSame(25.0, $metrics['errors']);
		$this->assertSame(2.5, $metrics['error_rate_pct']);
		$this->assertSame(42.5, $metrics['edge_latency_avg_ms']);
		$this->assertSame(185.0, $metrics['edge_latency_p95_ms']);
	}

	public function testRateLimitBackoffRetriesAndSucceeds(): void {
		$this->transport->queue(new Response(429, array('retry-after' => '1'), '{}'));
		$this->transport->queue(new Response(200, array(), '{"data":{"viewer":{"accounts":[{"total":[{"count":200,"sum":{"edgeResponseBytes":4096},"avg":{"edgeTimeToFirstByteMs":31.2}}],"errors":[{"count":4}]}]}},"errors":null}'));

		$metrics = $this->client->collectTunnelMetrics(
			str_repeat('a', 32),
			'0b0e3f43-63a8-4f0c-bb4c-0d6b2b5a2f10',
			'cfut_' . str_repeat('b', 40),
			1000,
			1300,
			static function (): void {
			}
		);

		$this->assertSame(200.0, $metrics['requests']);
		$this->assertSame(2.0, $metrics['error_rate_pct']);
		$this->assertSame(31.2, $metrics['edge_latency_avg_ms']);
		$this->assertCount(2, $this->transport->requests());
	}

	public function testRejectsInvalidZoneOrTunnelIdentifiers(): void {
		try {
			$this->client->collectTunnelMetrics('not-hex', 'not-a-uuid', 'cfut_' . str_repeat('a', 40), 1000, 1300, static function (): void {
			});
			$this->fail('expected ApiException');
		} catch (ApiException $e) {
			$this->assertSame(ErrorClass::CONFIG, $e->getErrorClass());
		}
	}

	public function testAnalyticsSummaryEvaluatesTholdReadyThresholds(): void {
		$templates = AnalyticsSummary::recommendedThresholds();
		$this->assertCount(4, $templates);
		$this->assertSame('health', $templates[0]['data_source_name']);
		$this->assertSame('error_pct', $templates[2]['data_source_name']);

		$ok = AnalyticsSummary::evaluateSample(array('requests' => 500.0, 'error_rate_pct' => 0.4, 'edge_latency_p95_ms' => 120.0));
		$this->assertSame(AnalyticsSummary::STATUS_OK, $ok['status']);
		$this->assertSame(array(), $ok['breaches']);

		$degraded = AnalyticsSummary::evaluateSample(array('requests' => 500.0, 'error_rate_pct' => 0.4, 'edge_latency_p95_ms' => 750.0));
		$this->assertSame(AnalyticsSummary::STATUS_DEGRADED, $degraded['status']);
		$this->assertCount(1, $degraded['breaches']);

		$critical = AnalyticsSummary::evaluateSample(array('requests' => 500.0, 'error_rate_pct' => 8.5, 'edge_latency_p95_ms' => 120.0));
		$this->assertSame(AnalyticsSummary::STATUS_CRITICAL, $critical['status']);

		$unknown = AnalyticsSummary::evaluateSample(null);
		$this->assertSame(AnalyticsSummary::STATUS_UNKNOWN, $unknown['status']);

		if (extension_loaded('pdo_sqlite')) {
			$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
			$pdo->exec('CREATE TABLE plugin_cloudflare_accounts (id INTEGER PRIMARY KEY, enabled INTEGER NOT NULL, discovery_interval INTEGER NOT NULL)');
			$pdo->exec('CREATE TABLE plugin_cloudflare_zones (
				account_id INTEGER NOT NULL, zone_id TEXT NOT NULL, name TEXT NOT NULL, listed INTEGER NOT NULL,
				status TEXT NOT NULL, sampled_at INTEGER NULL, window_start INTEGER NULL, window_end INTEGER NULL,
				requests REAL NULL, bytes REAL NULL, errors REAL NULL, sample_interval REAL NULL
			)');
			$pdo->exec('INSERT INTO plugin_cloudflare_accounts (id, enabled, discovery_interval) VALUES (1, 1, 300)');
			$pdo->exec("INSERT INTO plugin_cloudflare_zones VALUES (1, '" . str_repeat('a', 32) . "', 'ok.example', 1, 'ok', 1700000900, 1700000600, 1700000900, 1000, 50000, 2, 1)");
			$pdo->exec("INSERT INTO plugin_cloudflare_zones VALUES (1, '" . str_repeat('b', 32) . "', 'deg.example', 1, 'ok', 1700000900, 1700000600, 1700000900, 1000, 50000, 25, 1)");
			$pdo->exec("INSERT INTO plugin_cloudflare_zones VALUES (1, '" . str_repeat('c', 32) . "', 'crit.example', 1, 'ok', 1700000900, 1700000600, 1700000900, 1000, 50000, 80, 1)");
			$pdo->exec("INSERT INTO plugin_cloudflare_zones VALUES (1, '" . str_repeat('d', 32) . "', 'stale.example', 1, 'ok', 1699000000, 1698999700, 1699000000, 1000, 50000, 0, 1)");

			$summary = (new AnalyticsSummary(new Database($pdo), new FrozenClock(1700001000)))->summarizeZones(1);
			$byName  = array();
			foreach ($summary as $item) {
				$byName[$item['name']] = $item;
			}
			$this->assertSame(AnalyticsSummary::STATUS_OK, $byName['ok.example']['status']);
			$this->assertSame(AnalyticsSummary::STATUS_DEGRADED, $byName['deg.example']['status']);
			$this->assertSame(AnalyticsSummary::STATUS_CRITICAL, $byName['crit.example']['status']);
			$this->assertSame(AnalyticsSummary::STATUS_UNKNOWN, $byName['stale.example']['status']);
		}
	}

	public function testCollectZoneMetricsReturnsNullWhenAdaptiveGroupsDisabledAndHandlesErrors(): void {
		$this->transport->queue(new Response(200, array(), '{"data":{"viewer":{"zones":[{"settings":{"httpRequestsAdaptiveGroups":{"enabled":false}}}]}},"errors":null}'));
		$this->assertNull($this->client->collectZoneMetrics(str_repeat('a', 32), 'cfut_' . str_repeat('a', 40), 1000, 1300, static function (): void {
		}));

		try {
			$this->client->collectZoneMetrics('invalid-zone-id', 'cfut_' . str_repeat('a', 40), 1000, 1300, static function (): void {
			});
			$this->fail('expected ApiException');
		} catch (ApiException $e) {
			$this->assertSame(ErrorClass::CONFIG, $e->getErrorClass());
		}

		$this->transport->queue(new Response(200, array(), '{"data":null,"errors":[{"message":"query rejected"}]}'));
		$this->expectException(ApiException::class);
		$this->client->executeAllowlisted(GraphqlAnalyticsClient::QUERY_ZONE_SETTINGS, array('zoneTag' => str_repeat('a', 32)), 'cfut_' . str_repeat('a', 40));
	}
}
