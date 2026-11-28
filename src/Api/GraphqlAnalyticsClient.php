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

namespace Somethingwithproof\Cloudflare\Api;

use Somethingwithproof\Cloudflare\Metrics\EnterpriseMetrics;
use Somethingwithproof\Cloudflare\Support\Ids;

/**
 * Strictly read-only, allowlisted Cloudflare GraphQL analytics client.
 *
 * All queries are fixed compile-time templates executed over CloudflareClient
 * (which enforces TLS verification, pinned API host, response size limits, and
 * rate-limit backoff via RetryPolicy). Missing or malformed fields throw
 * ApiException instead of fabricating zero metrics.
 */
final class GraphqlAnalyticsClient {
	/** @var string Query zone settings. */
	public const QUERY_ZONE_SETTINGS      = 'zone_settings';
	/** @var string Query zone HTTP metrics (requests, bytes, cache hits, 4xx/5xx split, edge & origin latency). */
	public const QUERY_ZONE_HTTP_METRICS  = 'zone_http_metrics';
	/** @var string Query zone edge and origin latency. */
	public const QUERY_ZONE_EDGE_LATENCY  = 'zone_edge_latency';
	/** @var string Query tunnel analytics. */
	public const QUERY_TUNNEL_ANALYTICS   = 'tunnel_analytics';
	/** @var string Query zone WAF / firewall mitigation events. */
	public const QUERY_ZONE_WAF_EVENTS    = 'zone_waf_events';
	/** @var string Query zone DNS analytics (total, NXDOMAIN, SERVFAIL). */
	public const QUERY_ZONE_DNS_ANALYTICS = 'zone_dns_analytics';

	/** @var array<string, string> Allowlisted read-only GraphQL query templates. */
	private const ALLOWLIST = array(
		self::QUERY_ZONE_SETTINGS      => 'query($zone: string!) { viewer { zones(filter: {zoneTag: $zone}) { settings { httpRequestsAdaptiveGroups { enabled maxDuration notOlderThan maxPageSize maxNumberOfFields } } } } }',
		self::QUERY_ZONE_HTTP_METRICS  => 'query($zone: string!, $start: Time!, $end: Time!) { viewer { zones(filter: {zoneTag: $zone}) { total: httpRequestsAdaptiveGroups(limit: 1, filter: {datetime_geq: $start, datetime_lt: $end, requestSource: "eyeball"}) { count sum { edgeResponseBytes } avg { sampleInterval edgeTimeToFirstByteMs originResponseDurationMs } quantiles { edgeTimeToFirstByteMsP95 originResponseDurationMsP95 } } cached: httpRequestsAdaptiveGroups(limit: 1, filter: {datetime_geq: $start, datetime_lt: $end, requestSource: "eyeball", cacheStatus: "hit"}) { count sum { edgeResponseBytes } } errors4xx: httpRequestsAdaptiveGroups(limit: 1, filter: {datetime_geq: $start, datetime_lt: $end, requestSource: "eyeball", edgeResponseStatus_geq: 400, edgeResponseStatus_lt: 500}) { count } errors: httpRequestsAdaptiveGroups(limit: 1, filter: {datetime_geq: $start, datetime_lt: $end, requestSource: "eyeball", edgeResponseStatus_geq: 500, edgeResponseStatus_lt: 600}) { count } } } }',
		self::QUERY_ZONE_EDGE_LATENCY  => 'query($zone: string!, $start: Time!, $end: Time!) { viewer { zones(filter: {zoneTag: $zone}) { latency: httpRequestsAdaptiveGroups(limit: 1, filter: {datetime_geq: $start, datetime_lt: $end, requestSource: "eyeball"}) { count avg { edgeTimeToFirstByteMs originResponseDurationMs } quantiles { edgeTimeToFirstByteMsP95 originResponseDurationMsP95 } } } } }',
		self::QUERY_TUNNEL_ANALYTICS   => 'query($account: string!, $tunnel: string!, $start: Time!, $end: Time!) { viewer { accounts(filter: {accountTag: $account}) { total: httpRequestsAdaptiveGroups(limit: 1, filter: {datetime_geq: $start, datetime_lt: $end, edgeResponseStatus_geq: 100}) { count sum { edgeResponseBytes } avg { edgeTimeToFirstByteMs } } errors: httpRequestsAdaptiveGroups(limit: 1, filter: {datetime_geq: $start, datetime_lt: $end, edgeResponseStatus_geq: 500, edgeResponseStatus_lt: 600}) { count } } } }',
		self::QUERY_ZONE_WAF_EVENTS    => 'query($zone: string!, $start: Time!, $end: Time!) { viewer { zones(filter: {zoneTag: $zone}) { blocked: firewallEventsAdaptiveGroups(limit: 1, filter: {datetime_geq: $start, datetime_lt: $end, action: "block"}) { count } challenged: firewallEventsAdaptiveGroups(limit: 1, filter: {datetime_geq: $start, datetime_lt: $end, action_in: ["challenge", "js_challenge", "managed_challenge"]}) { count } } } }',
		self::QUERY_ZONE_DNS_ANALYTICS => 'query($zone: string!, $start: Time!, $end: Time!) { viewer { zones(filter: {zoneTag: $zone}) { total: dnsAnalyticsAdaptiveGroups(limit: 1, filter: {datetime_geq: $start, datetime_lt: $end}) { count } nxdomain: dnsAnalyticsAdaptiveGroups(limit: 1, filter: {datetime_geq: $start, datetime_lt: $end, responseCode: "NXDOMAIN"}) { count } servfail: dnsAnalyticsAdaptiveGroups(limit: 1, filter: {datetime_geq: $start, datetime_lt: $end, responseCode: "SERVFAIL"}) { count } } } }',
	);

	/** @var CloudflareClient */
	private $client;

	/**
	 * Initialize GraphqlAnalyticsClient.
	 *
	 * @param CloudflareClient $client
	 */
	public function __construct(CloudflareClient $client) {
		$this->client = $client;
	}

	/**
	 * @return array<int, string>
	 */
	public static function allowedQueries(): array {
		return array_keys(self::ALLOWLIST);
	}

	/**
	 * Execute one allowlisted read-only GraphQL query.
	 *
	 * @param array<string, scalar> $variables
	 * @return array<string, mixed>
	 * @throws ApiException
	 */
	public function executeAllowlisted(
		string $queryName,
		array $variables,
		#[\SensitiveParameter]
		string $token
	): array {
		if (!isset(self::ALLOWLIST[$queryName])) {
			throw new ApiException(ErrorClass::CONFIG, null, array(), 'GraphQL query is not in the allowlist');
		}

		return $this->client->graphql(self::ALLOWLIST[$queryName], $variables, $token);
	}

	/**
	 * Collect zone HTTP request, cache hit ratio, 4xx/5xx error split, response byte,
	 * edge latency, and origin TTFB metrics.
	 * Returns null when the zone's dataset capabilities cannot serve the window.
	 *
	 * @param callable(): void $budget
	 * @return array{
	 *     requests: float,
	 *     bytes: float,
	 *     errors: float,
	 *     error_rate_pct: ?float,
	 *     sample_interval: float,
	 *     edge_latency_avg_ms: ?float,
	 *     edge_latency_p95_ms: ?float,
	 *     cached_requests: ?float,
	 *     cached_bytes: ?float,
	 *     cache_hit_requests_pct: ?float,
	 *     cache_hit_bytes_pct: ?float,
	 *     errors_4xx: ?float,
	 *     errors_5xx: float,
	 *     error_4xx_pct: ?float,
	 *     error_5xx_pct: ?float,
	 *     origin_ttfb_avg_ms: ?float,
	 *     origin_ttfb_p95_ms: ?float
	 * }|null
	 */
	public function collectZoneMetrics(
		string $zoneTag,
		#[\SensitiveParameter]
		string $token,
		int $start,
		int $end,
		callable $budget
	): ?array {
		if (!Ids::isAccountId($zoneTag) || $start < 0 || $end <= $start) {
			throw new ApiException(ErrorClass::CONFIG, null, array(), 'invalid zone analytics window');
		}

		$budget();
		$settingsData = $this->executeAllowlisted(self::QUERY_ZONE_SETTINGS, array('zone' => $zoneTag), $token);
		$zones        = $settingsData['viewer']['zones'] ?? null;
		$settings     = is_array($zones) && count($zones) === 1
			? ($zones[0]['settings']['httpRequestsAdaptiveGroups'] ?? null)
			: null;

		if (
			!is_array($settings)
			|| ($settings['enabled'] ?? null) !== true
			|| !is_int($settings['maxDuration'] ?? null)
			|| $settings['maxDuration'] < ($end - $start)
			|| !is_int($settings['notOlderThan'] ?? null)
			|| $settings['notOlderThan'] < ($end - $start + 120)
			|| ($settings['maxPageSize'] ?? 0) < 1
			|| ($settings['maxNumberOfFields'] ?? 0) < 4
		) {
			return null;
		}

		$budget();
		$data = $this->executeAllowlisted(
			self::QUERY_ZONE_HTTP_METRICS,
			array(
				'zone'  => $zoneTag,
				'start' => gmdate('Y-m-d\TH:i:s\Z', $start),
				'end'   => gmdate('Y-m-d\TH:i:s\Z', $end),
			),
			$token
		);
		$rows = $data['viewer']['zones'] ?? null;

		if (
			!is_array($rows)
			|| count($rows) !== 1
			|| !is_array($rows[0]['total'] ?? null)
			|| !is_array($rows[0]['errors'] ?? null)
			|| count($rows[0]['total']) > 1
			|| count($rows[0]['errors']) > 1
		) {
			throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'unexpected zone GraphQL analytics shape');
		}

		$zoneRow = $rows[0];
		$total   = $zoneRow['total'];
		$errors  = $zoneRow['errors'];

		$requests = $total === array() ? 0.0 : self::nonNegativeFloat($total[0]['count'] ?? null);
		$bytes    = $total === array() ? 0.0 : self::nonNegativeFloat($total[0]['sum']['edgeResponseBytes'] ?? null);
		$errCount = $errors === array() ? 0.0 : self::nonNegativeFloat($errors[0]['count'] ?? null);
		$interval = $total === array() ? 1.0 : self::nonNegativeFloat($total[0]['avg']['sampleInterval'] ?? null);

		if ($errCount > $requests || $interval < 1.0) {
			throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'inconsistent zone analytics totals');
		}

		$cachedRequests = null;
		$cachedBytes    = null;
		if (array_key_exists('cached', $zoneRow)) {
			if (!is_array($zoneRow['cached']) || count($zoneRow['cached']) > 1) {
				throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'unexpected cached analytics shape');
			}

			$cachedRequests = $zoneRow['cached'] === array() ? 0.0 : self::nonNegativeFloat($zoneRow['cached'][0]['count'] ?? null);
			$cachedBytes    = $zoneRow['cached'] === array() ? 0.0 : self::nonNegativeFloat($zoneRow['cached'][0]['sum']['edgeResponseBytes'] ?? null);

			if ($cachedRequests > $requests || $cachedBytes > $bytes) {
				throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'inconsistent zone cache totals');
			}
		}

		$err4xx = null;
		if (array_key_exists('errors4xx', $zoneRow)) {
			if (!is_array($zoneRow['errors4xx']) || count($zoneRow['errors4xx']) > 1) {
				throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'unexpected 4xx analytics shape');
			}

			$err4xx = $zoneRow['errors4xx'] === array() ? 0.0 : self::nonNegativeFloat($zoneRow['errors4xx'][0]['count'] ?? null);

			if ($err4xx > $requests || ($err4xx + $errCount) > $requests) {
				throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'inconsistent zone 4xx/5xx error totals');
			}
		}

		$avgLatency      = null;
		$p95Latency      = null;
		$originTtfbAvgMs = null;
		$originTtfbP95Ms = null;

		if ($total !== array()) {
			$avg       = is_array($total[0]['avg'] ?? null) ? $total[0]['avg'] : array();
			$quantiles = is_array($total[0]['quantiles'] ?? null) ? $total[0]['quantiles'] : array();

			if (array_key_exists('edgeTimeToFirstByteMs', $avg) && $avg['edgeTimeToFirstByteMs'] !== null) {
				$avgLatency = self::nonNegativeFloat($avg['edgeTimeToFirstByteMs']);
			}

			if (array_key_exists('edgeTimeToFirstByteMsP95', $quantiles) && $quantiles['edgeTimeToFirstByteMsP95'] !== null) {
				$p95Latency = self::nonNegativeFloat($quantiles['edgeTimeToFirstByteMsP95']);
			}

			if (array_key_exists('originResponseDurationMs', $avg) && $avg['originResponseDurationMs'] !== null) {
				$originTtfbAvgMs = self::nonNegativeFloat($avg['originResponseDurationMs']);
			}

			if (array_key_exists('originResponseDurationMsP95', $quantiles) && $quantiles['originResponseDurationMsP95'] !== null) {
				$originTtfbP95Ms = self::nonNegativeFloat($quantiles['originResponseDurationMsP95']);
			}
		}

		$error5xxPct = EnterpriseMetrics::ratioPercent($errCount, $requests);

		return array(
			'requests'               => $requests,
			'bytes'                  => $bytes,
			'errors'                 => $errCount,
			'error_rate_pct'         => $error5xxPct,
			'sample_interval'        => $interval,
			'edge_latency_avg_ms'    => $avgLatency,
			'edge_latency_p95_ms'    => $p95Latency,
			'cached_requests'        => $cachedRequests,
			'cached_bytes'           => $cachedBytes,
			'cache_hit_requests_pct' => EnterpriseMetrics::ratioPercent($cachedRequests, $requests),
			'cache_hit_bytes_pct'    => EnterpriseMetrics::ratioPercent($cachedBytes, $bytes),
			'errors_4xx'             => $err4xx,
			'errors_5xx'             => $errCount,
			'error_4xx_pct'          => EnterpriseMetrics::ratioPercent($err4xx, $requests),
			'error_5xx_pct'          => $error5xxPct,
			'origin_ttfb_avg_ms'     => $originTtfbAvgMs,
			'origin_ttfb_p95_ms'     => $originTtfbP95Ms,
		);
	}

	/**
	 * Collect zone WAF / firewall mitigation rates (blocked and challenged events/sec).
	 *
	 * @param callable(): void $budget
	 * @return array{
	 *     blocked_count: float,
	 *     challenged_count: float,
	 *     waf_blocked_rate: float,
	 *     waf_challenged_rate: float
	 * }
	 */
	public function collectZoneWafMetrics(
		string $zoneTag,
		#[\SensitiveParameter]
		string $token,
		int $start,
		int $end,
		callable $budget
	): array {
		if (!Ids::isAccountId($zoneTag) || $start < 0 || $end <= $start) {
			throw new ApiException(ErrorClass::CONFIG, null, array(), 'invalid zone WAF analytics window');
		}

		$window = $end - $start;
		$budget();
		$data = $this->executeAllowlisted(
			self::QUERY_ZONE_WAF_EVENTS,
			array(
				'zone'  => $zoneTag,
				'start' => gmdate('Y-m-d\TH:i:s\Z', $start),
				'end'   => gmdate('Y-m-d\TH:i:s\Z', $end),
			),
			$token
		);
		$rows = $data['viewer']['zones'] ?? null;

		if (
			!is_array($rows)
			|| count($rows) !== 1
			|| !is_array($rows[0]['blocked'] ?? null)
			|| !is_array($rows[0]['challenged'] ?? null)
			|| count($rows[0]['blocked']) > 1
			|| count($rows[0]['challenged']) > 1
		) {
			throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'unexpected zone WAF GraphQL analytics shape');
		}

		$blocked    = $rows[0]['blocked'] === array() ? 0.0 : self::nonNegativeFloat($rows[0]['blocked'][0]['count'] ?? null);
		$challenged = $rows[0]['challenged'] === array() ? 0.0 : self::nonNegativeFloat($rows[0]['challenged'][0]['count'] ?? null);

		return array(
			'blocked_count'       => $blocked,
			'challenged_count'    => $challenged,
			'waf_blocked_rate'    => round($blocked / (float) $window, 4),
			'waf_challenged_rate' => round($challenged / (float) $window, 4),
		);
	}

	/**
	 * Collect zone DNS analytics (query rate, NXDOMAIN rate, SERVFAIL rate).
	 *
	 * @param callable(): void $budget
	 * @return array{
	 *     dns_queries: float,
	 *     dns_nxdomain: float,
	 *     dns_servfail: float,
	 *     dns_queries_rate: float,
	 *     dns_nxdomain_rate: float,
	 *     dns_servfail_rate: float,
	 *     dns_nxdomain_pct: ?float,
	 *     dns_servfail_pct: ?float
	 * }
	 */
	public function collectZoneDnsMetrics(
		string $zoneTag,
		#[\SensitiveParameter]
		string $token,
		int $start,
		int $end,
		callable $budget
	): array {
		if (!Ids::isAccountId($zoneTag) || $start < 0 || $end <= $start) {
			throw new ApiException(ErrorClass::CONFIG, null, array(), 'invalid zone DNS analytics window');
		}

		$window = $end - $start;
		$budget();
		$data = $this->executeAllowlisted(
			self::QUERY_ZONE_DNS_ANALYTICS,
			array(
				'zone'  => $zoneTag,
				'start' => gmdate('Y-m-d\TH:i:s\Z', $start),
				'end'   => gmdate('Y-m-d\TH:i:s\Z', $end),
			),
			$token
		);
		$rows = $data['viewer']['zones'] ?? null;

		if (
			!is_array($rows)
			|| count($rows) !== 1
			|| !is_array($rows[0]['total'] ?? null)
			|| !is_array($rows[0]['nxdomain'] ?? null)
			|| !is_array($rows[0]['servfail'] ?? null)
			|| count($rows[0]['total']) > 1
			|| count($rows[0]['nxdomain']) > 1
			|| count($rows[0]['servfail']) > 1
		) {
			throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'unexpected zone DNS GraphQL analytics shape');
		}

		$total    = $rows[0]['total'] === array() ? 0.0 : self::nonNegativeFloat($rows[0]['total'][0]['count'] ?? null);
		$nxdomain = $rows[0]['nxdomain'] === array() ? 0.0 : self::nonNegativeFloat($rows[0]['nxdomain'][0]['count'] ?? null);
		$servfail = $rows[0]['servfail'] === array() ? 0.0 : self::nonNegativeFloat($rows[0]['servfail'][0]['count'] ?? null);

		if ($nxdomain > $total || $servfail > $total || ($nxdomain + $servfail) > $total) {
			throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'inconsistent zone DNS analytics totals');
		}

		return array(
			'dns_queries'       => $total,
			'dns_nxdomain'      => $nxdomain,
			'dns_servfail'      => $servfail,
			'dns_queries_rate'  => round($total / (float) $window, 4),
			'dns_nxdomain_rate' => round($nxdomain / (float) $window, 4),
			'dns_servfail_rate' => round($servfail / (float) $window, 4),
			'dns_nxdomain_pct'  => EnterpriseMetrics::ratioPercent($nxdomain, $total),
			'dns_servfail_pct'  => EnterpriseMetrics::ratioPercent($servfail, $total),
		);
	}

	/**
	 * Collect soonest active TLS certificate expiry for a zone via the read-only
	 * Cloudflare SSL certificate packs endpoint.
	 *
	 * Returns null for tls_cert_expiry_days when no active certificates exist
	 * so absent certificate metadata is never misrepresented as 0 days remaining.
	 *
	 * @param callable(): void $budget
	 * @return array{
	 *     tls_cert_expiry_days: ?float,
	 *     soonest_expires_at: ?int,
	 *     active_certificate_count: int
	 * }
	 */
	public function collectZoneTlsExpiry(
		string $zoneTag,
		#[\SensitiveParameter]
		string $token,
		int $now,
		callable $budget
	): array {
		if (!Ids::isAccountId($zoneTag) || $now < 0) {
			throw new ApiException(ErrorClass::CONFIG, null, array(), 'invalid zone TLS expiry parameters');
		}

		$budget();
		$body  = $this->client->request('GET', '/zones/' . $zoneTag . '/ssl/certificate_packs', $token, array('status' => 'all'));
		$packs = $body['result'] ?? null;

		if (!is_array($packs)) {
			throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'unexpected TLS certificate packs shape');
		}

		$soonest = null;
		$active  = 0;

		foreach ($packs as $pack) {
			if (!is_array($pack)) {
				throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'invalid TLS certificate pack entry');
			}

			if (($pack['status'] ?? '') !== 'active') {
				continue;
			}

			$certs = $pack['certificates'] ?? null;
			if (!is_array($certs)) {
				continue;
			}

			foreach ($certs as $cert) {
				if (!is_array($cert) || !isset($cert['expires_on']) || !is_string($cert['expires_on'])) {
					continue;
				}

				$ts = strtotime($cert['expires_on']);
				if ($ts === false) {
					throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'unparseable TLS certificate expiry timestamp');
				}

				$active++;
				if ($soonest === null || $ts < $soonest) {
					$soonest = $ts;
				}
			}
		}

		return array(
			'tls_cert_expiry_days'     => EnterpriseMetrics::daysUntilExpiry($soonest, $now),
			'soonest_expires_at'       => $soonest,
			'active_certificate_count' => $active,
		);
	}

	/**
	 * Collect a unified EnterpriseMetrics snapshot for a zone by combining HTTP
	 * cache/error/origin metrics, WAF mitigation rates, DNS analytics, and TLS
	 * certificate expiry under a single caller-supplied request budget.
	 *
	 * @param callable(): void $budget
	 * @return EnterpriseMetrics
	 */
	public function collectZoneEnterpriseMetrics(
		string $zoneTag,
		#[\SensitiveParameter]
		string $token,
		int $start,
		int $end,
		int $now,
		callable $budget
	): EnterpriseMetrics {
		$http = $this->collectZoneMetrics($zoneTag, $token, $start, $end, $budget);
		$waf  = $this->collectZoneWafMetrics($zoneTag, $token, $start, $end, $budget);
		$dns  = $this->collectZoneDnsMetrics($zoneTag, $token, $start, $end, $budget);
		$tls  = $this->collectZoneTlsExpiry($zoneTag, $token, $now, $budget);

		return new EnterpriseMetrics(
			$http !== null ? $http['cache_hit_requests_pct'] : null,
			$http !== null ? $http['cache_hit_bytes_pct'] : null,
			$http !== null ? $http['error_4xx_pct'] : null,
			$http !== null ? $http['error_5xx_pct'] : null,
			$waf['waf_blocked_rate'],
			$waf['waf_challenged_rate'],
			$http !== null ? $http['origin_ttfb_avg_ms'] : null,
			$http !== null ? $http['origin_ttfb_p95_ms'] : null,
			$dns['dns_queries_rate'],
			$dns['dns_nxdomain_rate'],
			$dns['dns_servfail_rate'],
			$tls['tls_cert_expiry_days']
		);
	}

	/**
	 * Collect account-level tunnel analytics window (requests, error rate, edge latency).
	 *
	 * @param callable(): void $budget
	 * @return array{
	 *     requests: float,
	 *     bytes: float,
	 *     errors: float,
	 *     error_rate_pct: ?float,
	 *     edge_latency_avg_ms: ?float
	 * }
	 */
	public function collectTunnelMetrics(
		string $cfAccountId,
		string $tunnelUuid,
		#[\SensitiveParameter]
		string $token,
		int $start,
		int $end,
		callable $budget
	): array {
		if (!Ids::isAccountId($cfAccountId) || !Ids::isUuid($tunnelUuid) || $start < 0 || $end <= $start) {
			throw new ApiException(ErrorClass::CONFIG, null, array(), 'invalid tunnel analytics parameters');
		}

		$budget();
		$data = $this->executeAllowlisted(
			self::QUERY_TUNNEL_ANALYTICS,
			array(
				'account' => $cfAccountId,
				'tunnel'  => $tunnelUuid,
				'start'   => gmdate('Y-m-d\TH:i:s\Z', $start),
				'end'     => gmdate('Y-m-d\TH:i:s\Z', $end),
			),
			$token
		);
		$accounts = $data['viewer']['accounts'] ?? null;

		if (
			!is_array($accounts)
			|| count($accounts) !== 1
			|| !is_array($accounts[0]['total'] ?? null)
			|| !is_array($accounts[0]['errors'] ?? null)
			|| count($accounts[0]['total']) > 1
			|| count($accounts[0]['errors']) > 1
		) {
			throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'unexpected tunnel GraphQL analytics shape');
		}

		$total    = $accounts[0]['total'];
		$errors   = $accounts[0]['errors'];
		$requests = $total === array() ? 0.0 : self::nonNegativeFloat($total[0]['count'] ?? null);
		$bytes    = $total === array() ? 0.0 : self::nonNegativeFloat($total[0]['sum']['edgeResponseBytes'] ?? null);
		$errCount = $errors === array() ? 0.0 : self::nonNegativeFloat($errors[0]['count'] ?? null);

		if ($errCount > $requests) {
			throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'inconsistent tunnel analytics totals');
		}

		$avgLatency = null;
		if ($total !== array() && isset($total[0]['avg']['edgeTimeToFirstByteMs'])) {
			$avgLatency = self::nonNegativeFloat($total[0]['avg']['edgeTimeToFirstByteMs']);
		}

		return array(
			'requests'            => $requests,
			'bytes'               => $bytes,
			'errors'              => $errCount,
			'error_rate_pct'      => $requests > 0.0 ? round(100.0 * $errCount / $requests, 4) : null,
			'edge_latency_avg_ms' => $avgLatency,
		);
	}

	/**
	 * @param mixed $value
	 */
	private static function nonNegativeFloat($value): float {
		if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || (float) $value < 0.0) {
			throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'invalid numeric metric in GraphQL response');
		}

		return (float) $value;
	}
}
