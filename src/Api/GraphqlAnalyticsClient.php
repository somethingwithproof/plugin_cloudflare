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
	public const QUERY_ZONE_SETTINGS     = 'zone_settings';
	public const QUERY_ZONE_HTTP_METRICS = 'zone_http_metrics';
	public const QUERY_ZONE_EDGE_LATENCY = 'zone_edge_latency';
	public const QUERY_TUNNEL_ANALYTICS  = 'tunnel_analytics';

	private const ALLOWLIST = array(
		self::QUERY_ZONE_SETTINGS => 'query($zone: string!) { viewer { zones(filter: {zoneTag: $zone}) { settings { httpRequestsAdaptiveGroups { enabled maxDuration notOlderThan maxPageSize maxNumberOfFields } } } } }',
		self::QUERY_ZONE_HTTP_METRICS => 'query($zone: string!, $start: Time!, $end: Time!) { viewer { zones(filter: {zoneTag: $zone}) { total: httpRequestsAdaptiveGroups(limit: 1, filter: {datetime_geq: $start, datetime_lt: $end, requestSource: "eyeball"}) { count sum { edgeResponseBytes } avg { sampleInterval edgeTimeToFirstByteMs } quantiles { edgeTimeToFirstByteMsP95 } } errors: httpRequestsAdaptiveGroups(limit: 1, filter: {datetime_geq: $start, datetime_lt: $end, requestSource: "eyeball", edgeResponseStatus_geq: 500, edgeResponseStatus_lt: 600}) { count } } } }',
		self::QUERY_ZONE_EDGE_LATENCY => 'query($zone: string!, $start: Time!, $end: Time!) { viewer { zones(filter: {zoneTag: $zone}) { latency: httpRequestsAdaptiveGroups(limit: 1, filter: {datetime_geq: $start, datetime_lt: $end, requestSource: "eyeball"}) { count avg { edgeTimeToFirstByteMs originResponseDurationMs } quantiles { edgeTimeToFirstByteMsP95 } } } } }',
		self::QUERY_TUNNEL_ANALYTICS => 'query($account: string!, $tunnel: string!, $start: Time!, $end: Time!) { viewer { accounts(filter: {accountTag: $account}) { total: httpRequestsAdaptiveGroups(limit: 1, filter: {datetime_geq: $start, datetime_lt: $end, edgeResponseStatus_geq: 100}) { count sum { edgeResponseBytes } avg { edgeTimeToFirstByteMs } } errors: httpRequestsAdaptiveGroups(limit: 1, filter: {datetime_geq: $start, datetime_lt: $end, edgeResponseStatus_geq: 500, edgeResponseStatus_lt: 600}) { count } } } }',
	);

	/** @var CloudflareClient */
	private $client;

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
	 * Collect zone HTTP request, 5xx error-rate, response byte, and edge latency metrics.
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
	 *     edge_latency_p95_ms: ?float
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

		$total  = $rows[0]['total'];
		$errors = $rows[0]['errors'];

		$requests = $total === array() ? 0.0 : self::nonNegativeFloat($total[0]['count'] ?? null);
		$bytes    = $total === array() ? 0.0 : self::nonNegativeFloat($total[0]['sum']['edgeResponseBytes'] ?? null);
		$errCount = $errors === array() ? 0.0 : self::nonNegativeFloat($errors[0]['count'] ?? null);
		$interval = $total === array() ? 1.0 : self::nonNegativeFloat($total[0]['avg']['sampleInterval'] ?? null);

		if ($errCount > $requests || $interval < 1.0) {
			throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'inconsistent zone analytics totals');
		}

		$avgLatency = null;
		$p95Latency = null;

		if ($total !== array()) {
			if (array_key_exists('edgeTimeToFirstByteMs', $total[0]['avg'] ?? array()) && $total[0]['avg']['edgeTimeToFirstByteMs'] !== null) {
				$avgLatency = self::nonNegativeFloat($total[0]['avg']['edgeTimeToFirstByteMs']);
			}

			if (array_key_exists('edgeTimeToFirstByteMsP95', $total[0]['quantiles'] ?? array()) && $total[0]['quantiles']['edgeTimeToFirstByteMsP95'] !== null) {
				$p95Latency = self::nonNegativeFloat($total[0]['quantiles']['edgeTimeToFirstByteMsP95']);
			}
		}

		return array(
			'requests'            => $requests,
			'bytes'               => $bytes,
			'errors'              => $errCount,
			'error_rate_pct'      => $requests > 0.0 ? round(100.0 * $errCount / $requests, 4) : null,
			'sample_interval'     => $interval,
			'edge_latency_avg_ms' => $avgLatency,
			'edge_latency_p95_ms' => $p95Latency,
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
