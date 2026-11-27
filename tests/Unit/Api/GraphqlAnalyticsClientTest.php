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
use Somethingwithproof\Cloudflare\Metrics\EnterpriseMetrics;
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
		$this->assertCount(13, $templates);
		$this->assertSame('health', $templates[0]['data_source_name']);
		$this->assertSame('error_pct', $templates[2]['data_source_name']);

		$byDs = array();
		foreach ($templates as $tpl) {
			$byDs[$tpl['data_source_name']] = $tpl;
		}
		$this->assertArrayHasKey('cache_hit_req_pct', $byDs);
		$this->assertSame('low', $byDs['cache_hit_req_pct']['direction']);
		$this->assertArrayHasKey('cache_hit_bytes_pct', $byDs);
		$this->assertArrayHasKey('error_4xx_pct', $byDs);
		$this->assertArrayHasKey('error_5xx_pct', $byDs);
		$this->assertArrayHasKey('waf_blocked_rate', $byDs);
		$this->assertArrayHasKey('waf_challenged_rate', $byDs);
		$this->assertArrayHasKey('origin_ttfb_p95_ms', $byDs);
		$this->assertArrayHasKey('dns_servfail_rate', $byDs);
		$this->assertArrayHasKey('tls_cert_expiry_days', $byDs);
		$this->assertSame(14.0, $byDs['tls_cert_expiry_days']['thold_low']);

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

	public function testCollectsFullEnterpriseHttpWafDnsAndTlsMetrics(): void {
		$zone  = str_repeat('a', 32);
		$token = 'cfut_' . str_repeat('a', 40);
		$nop   = static function (): void {
		};

		// 1. Settings + HTTP metrics with cache hit, 4xx/5xx split, and origin TTFB
		$this->transport->queue(new Response(200, array(), '{"data":{"viewer":{"zones":[{"settings":{"httpRequestsAdaptiveGroups":{"enabled":true,"maxDuration":86400,"notOlderThan":86400,"maxPageSize":100,"maxNumberOfFields":40}}}]}},"errors":null}'));
		$this->transport->queue(new Response(200, array(), '{"data":{"viewer":{"zones":[{"total":[{"count":2000,"sum":{"edgeResponseBytes":500000},"avg":{"sampleInterval":1,"edgeTimeToFirstByteMs":25.0,"originResponseDurationMs":110.5},"quantiles":{"edgeTimeToFirstByteMsP95":90.0,"originResponseDurationMsP95":420.25}}],"cached":[{"count":1500,"sum":{"edgeResponseBytes":400000}}],"errors4xx":[{"count":80}],"errors":[{"count":20}]}]}},"errors":null}'));

		// 2. WAF events over 300s window: 90 blocked (0.3/s), 150 challenged (0.5/s)
		$this->transport->queue(new Response(200, array(), '{"data":{"viewer":{"zones":[{"blocked":[{"count":90}],"challenged":[{"count":150}]}]}},"errors":null}'));

		// 3. DNS analytics over 300s window: 3000 queries (10/s), 300 nxdomain (1/s), 15 servfail (0.05/s)
		$this->transport->queue(new Response(200, array(), '{"data":{"viewer":{"zones":[{"total":[{"count":3000}],"nxdomain":[{"count":300}],"servfail":[{"count":15}]}]}},"errors":null}'));

		// 4. TLS certificate packs: soonest active cert expires in exactly 20.5 days from now=1700000000
		$now        = 1700000000;
		$soonIso    = gmdate('Y-m-d\TH:i:s\Z', $now + (int) (20.5 * 86400));
		$laterIso   = gmdate('Y-m-d\TH:i:s\Z', $now + 60 * 86400);
		$expiredIso = gmdate('Y-m-d\TH:i:s\Z', $now - 10 * 86400);
		$tlsBody    = json_encode(array(
			'success' => true,
			'errors'  => array(),
			'result'  => array(
				array(
					'status'       => 'pending_validation',
					'certificates' => array(array('expires_on' => $expiredIso)),
				),
				array(
					'status'       => 'active',
					'certificates' => array(
						array('expires_on' => $laterIso),
						array('expires_on' => $soonIso),
					),
				),
			),
		));
		$this->transport->queue(new Response(200, array(), (string) $tlsBody));

		$enterprise = $this->client->collectZoneEnterpriseMetrics($zone, $token, 1000, 1300, $now, $nop);

		$this->assertSame(75.0, $enterprise->cacheHitRequestsPct());
		$this->assertSame(80.0, $enterprise->cacheHitBytesPct());
		$this->assertSame(4.0, $enterprise->error4xxPct());
		$this->assertSame(1.0, $enterprise->error5xxPct());
		$this->assertSame(0.3, $enterprise->wafBlockedRate());
		$this->assertSame(0.5, $enterprise->wafChallengedRate());
		$this->assertSame(110.5, $enterprise->originTtfbAvgMs());
		$this->assertSame(420.25, $enterprise->originTtfbP95Ms());
		$this->assertSame(10.0, $enterprise->dnsQueriesRate());
		$this->assertSame(1.0, $enterprise->dnsNxdomainRate());
		$this->assertSame(0.05, $enterprise->dnsServfailRate());
		$this->assertSame(20.5, $enterprise->tlsCertExpiryDays());

		$asArray = $enterprise->toArray();
		$this->assertCount(12, $asArray);
		$this->assertSame(75.0, $asArray['cache_hit_requests_pct']);
		$this->assertSame(20.5, $asArray['tls_cert_expiry_days']);
		$this->assertContains(GraphqlAnalyticsClient::QUERY_ZONE_WAF_EVENTS, GraphqlAnalyticsClient::allowedQueries());
		$this->assertContains(GraphqlAnalyticsClient::QUERY_ZONE_DNS_ANALYTICS, GraphqlAnalyticsClient::allowedQueries());
	}

	public function testZeroDenominatorAndEmptyEnterpriseResponsesReturnNullRatios(): void {
		$zone  = str_repeat('b', 32);
		$token = 'cfut_' . str_repeat('b', 40);
		$nop   = static function (): void {
		};

		// Zero HTTP requests and zero bytes
		$this->transport->queue(new Response(200, array(), '{"data":{"viewer":{"zones":[{"settings":{"httpRequestsAdaptiveGroups":{"enabled":true,"maxDuration":86400,"notOlderThan":86400,"maxPageSize":100,"maxNumberOfFields":40}}}]}},"errors":null}'));
		$this->transport->queue(new Response(200, array(), '{"data":{"viewer":{"zones":[{"total":[],"cached":[],"errors4xx":[],"errors":[]}]}},"errors":null}'));

		$http = $this->client->collectZoneMetrics($zone, $token, 1000, 1300, $nop);
		$this->assertNotNull($http);
		$this->assertNull($http['cache_hit_requests_pct']);
		$this->assertNull($http['cache_hit_bytes_pct']);
		$this->assertNull($http['error_4xx_pct']);
		$this->assertNull($http['error_5xx_pct']);
		$this->assertNull($http['origin_ttfb_avg_ms']);
		$this->assertNull($http['origin_ttfb_p95_ms']);

		// Zero DNS queries
		$this->transport->queue(new Response(200, array(), '{"data":{"viewer":{"zones":[{"total":[],"nxdomain":[],"servfail":[]}]}},"errors":null}'));
		$dns = $this->client->collectZoneDnsMetrics($zone, $token, 1000, 1300, $nop);
		$this->assertSame(0.0, $dns['dns_queries_rate']);
		$this->assertNull($dns['dns_nxdomain_pct']);
		$this->assertNull($dns['dns_servfail_pct']);

		// No active TLS certificates
		$this->transport->queue(new Response(200, array(), '{"success":true,"errors":[],"result":[]}'));
		$tls = $this->client->collectZoneTlsExpiry($zone, $token, 1700000000, $nop);
		$this->assertNull($tls['tls_cert_expiry_days']);
		$this->assertNull($tls['soonest_expires_at']);
		$this->assertSame(0, $tls['active_certificate_count']);

		// EnterpriseMetrics::fromRawCounters zero-denominator and invalid-window safety
		$zero = EnterpriseMetrics::fromRawCounters(0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 5.0, 5.0, null, null, 10.0, 1.0, 0.0, null, 1700000000);
		$this->assertNull($zero->cacheHitRequestsPct());
		$this->assertNull($zero->cacheHitBytesPct());
		$this->assertNull($zero->error4xxPct());
		$this->assertNull($zero->error5xxPct());
		$this->assertNull($zero->wafBlockedRate());
		$this->assertNull($zero->dnsQueriesRate());
		$this->assertNull($zero->tlsCertExpiryDays());
	}

	public function testRejectsInconsistentEnterprisePayloadsAndEvaluatesBreaches(): void {
		$zone  = str_repeat('c', 32);
		$token = 'cfut_' . str_repeat('c', 40);
		$nop   = static function (): void {
		};

		// Cached requests > total requests must throw MALFORMED
		$this->transport->queue(new Response(200, array(), '{"data":{"viewer":{"zones":[{"settings":{"httpRequestsAdaptiveGroups":{"enabled":true,"maxDuration":86400,"notOlderThan":86400,"maxPageSize":100,"maxNumberOfFields":40}}}]}},"errors":null}'));
		$this->transport->queue(new Response(200, array(), '{"data":{"viewer":{"zones":[{"total":[{"count":100,"sum":{"edgeResponseBytes":1000},"avg":{"sampleInterval":1}}],"cached":[{"count":150,"sum":{"edgeResponseBytes":500}}],"errors":[]}]}},"errors":null}'));
		try {
			$this->client->collectZoneMetrics($zone, $token, 1000, 1300, $nop);
			$this->fail('expected ApiException for cached > requests');
		} catch (ApiException $e) {
			$this->assertSame(ErrorClass::MALFORMED, $e->getErrorClass());
		}

		// 4xx + 5xx > total requests must throw MALFORMED
		$this->transport->queue(new Response(200, array(), '{"data":{"viewer":{"zones":[{"settings":{"httpRequestsAdaptiveGroups":{"enabled":true,"maxDuration":86400,"notOlderThan":86400,"maxPageSize":100,"maxNumberOfFields":40}}}]}},"errors":null}'));
		$this->transport->queue(new Response(200, array(), '{"data":{"viewer":{"zones":[{"total":[{"count":100,"sum":{"edgeResponseBytes":1000},"avg":{"sampleInterval":1}}],"errors4xx":[{"count":60}],"errors":[{"count":50}]}]}},"errors":null}'));
		try {
			$this->client->collectZoneMetrics($zone, $token, 1000, 1300, $nop);
			$this->fail('expected ApiException for 4xx + 5xx > requests');
		} catch (ApiException $e) {
			$this->assertSame(ErrorClass::MALFORMED, $e->getErrorClass());
		}

		// DNS NXDOMAIN + SERVFAIL > total must throw MALFORMED
		$this->transport->queue(new Response(200, array(), '{"data":{"viewer":{"zones":[{"total":[{"count":10}],"nxdomain":[{"count":8}],"servfail":[{"count":5}]}]}},"errors":null}'));
		try {
			$this->client->collectZoneDnsMetrics($zone, $token, 1000, 1300, $nop);
			$this->fail('expected ApiException for inconsistent DNS totals');
		} catch (ApiException $e) {
			$this->assertSame(ErrorClass::MALFORMED, $e->getErrorClass());
		}

		// Unparseable TLS expiry timestamp must throw MALFORMED
		$this->transport->queue(new Response(200, array(), '{"success":true,"errors":[],"result":[{"status":"active","certificates":[{"expires_on":"not-a-timestamp"}]}]}'));
		try {
			$this->client->collectZoneTlsExpiry($zone, $token, 1700000000, $nop);
			$this->fail('expected ApiException for bad TLS timestamp');
		} catch (ApiException $e) {
			$this->assertSame(ErrorClass::MALFORMED, $e->getErrorClass());
		}

		// Evaluate enterprise threshold breaches
		$lowCache = AnalyticsSummary::evaluateSample(array('cache_hit_requests_pct' => 55.0));
		$this->assertSame(AnalyticsSummary::STATUS_DEGRADED, $lowCache['status']);
		$this->assertCount(1, $lowCache['breaches']);

		$tlsWarn = AnalyticsSummary::evaluateSample(array('tls_cert_expiry_days' => 10.0));
		$this->assertSame(AnalyticsSummary::STATUS_DEGRADED, $tlsWarn['status']);

		$tlsCrit = AnalyticsSummary::evaluateSample(array('tls_cert_expiry_days' => 3.0));
		$this->assertSame(AnalyticsSummary::STATUS_CRITICAL, $tlsCrit['status']);

		$dnsCrit = AnalyticsSummary::evaluateSample(array('dns_servfail_rate' => 12.5));
		$this->assertSame(AnalyticsSummary::STATUS_CRITICAL, $dnsCrit['status']);

		$multi = AnalyticsSummary::evaluateSample(array(
			'error_4xx_pct'       => 15.0,
			'origin_ttfb_p95_ms'  => 1400.0,
			'cache_hit_bytes_pct' => 40.0,
			'waf_blocked_rate'    => 75.0,
		));
		$this->assertSame(AnalyticsSummary::STATUS_CRITICAL, $multi['status']);
		$this->assertCount(4, $multi['breaches']);
	}
}
