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

namespace Somethingwithproof\Cloudflare\Metrics;

use Somethingwithproof\Cloudflare\Storage\Database;
use Somethingwithproof\Cloudflare\Support\Clock;

/**
 * Thold-ready summary helper for Cloudflare zone analytics and tunnel health.
 *
 * Produces deterministic status classifications and threshold templates aligned
 * with the plugin's RRD data source names (never substituting zero for unknown).
 */
final class AnalyticsSummary {
	/** @var string Status ok. */
	public const STATUS_OK       = 'ok';
	/** @var string Status degraded. */
	public const STATUS_DEGRADED = 'degraded';
	/** @var string Status critical. */
	public const STATUS_CRITICAL = 'critical';
	/** @var string Status unknown. */
	public const STATUS_UNKNOWN  = 'unknown';

	/** @var Database */
	private $db;

	/** @var Clock */
	private $clock;

	/**
	 * Initialize AnalyticsSummary.
	 *
	 * @param Database $db
	 * @param Clock $clock
	 */
	public function __construct(Database $db, Clock $clock) {
		$this->db    = $db;
		$this->clock = $clock;
	}

	/**
	 * Recommended Thold threshold definitions mapped to Cloudflare RRD DS names.
	 *
	 * @return array<int, array{
	 *     metric: string,
	 *     data_source_name: string,
	 *     direction: string,
	 *     thold_hi: ?float,
	 *     thold_low: ?float,
	 *     fail_trigger: int,
	 *     unit: string,
	 *     description: string
	 * }>
	 */
	public static function recommendedThresholds(): array {
		return array(
			array(
				'metric'           => 'cloudflare_tunnel_health',
				'data_source_name' => 'health',
				'direction'        => 'low',
				'thold_hi'         => null,
				'thold_low'        => 2.0,
				'fail_trigger'     => 2,
				'unit'             => 'state',
				'description'      => 'Alert when tunnel health drops below degraded (down or inactive)',
			),
			array(
				'metric'           => 'cloudflare_collection_age',
				'data_source_name' => 'collect_age',
				'direction'        => 'high',
				'thold_hi'         => 660.0,
				'thold_low'        => null,
				'fail_trigger'     => 2,
				'unit'             => 'seconds',
				'description'      => 'Alert when tunnel collection exceeds two discovery intervals plus slack',
			),
			array(
				'metric'           => 'cloudflare_zone_errors',
				'data_source_name' => 'error_pct',
				'direction'        => 'high',
				'thold_hi'         => 5.0,
				'thold_low'        => null,
				'fail_trigger'     => 2,
				'unit'             => 'percent',
				'description'      => 'Alert when zone HTTP 5xx error rate exceeds 5%',
			),
			array(
				'metric'           => 'cloudflare_zone_age',
				'data_source_name' => 'age',
				'direction'        => 'high',
				'thold_hi'         => 660.0,
				'thold_low'        => null,
				'fail_trigger'     => 2,
				'unit'             => 'seconds',
				'description'      => 'Alert when zone analytics sample age exceeds staleness budget',
			),
			array(
				'metric'           => 'cloudflare_zone_cache_hit_requests',
				'data_source_name' => 'cache_hit_req_pct',
				'direction'        => 'low',
				'thold_hi'         => null,
				'thold_low'        => 70.0,
				'fail_trigger'     => 3,
				'unit'             => 'percent',
				'description'      => 'Alert when zone cache hit ratio by requests drops below 70%',
			),
			array(
				'metric'           => 'cloudflare_zone_cache_hit_bytes',
				'data_source_name' => 'cache_hit_bytes_pct',
				'direction'        => 'low',
				'thold_hi'         => null,
				'thold_low'        => 70.0,
				'fail_trigger'     => 3,
				'unit'             => 'percent',
				'description'      => 'Alert when zone cache hit ratio by bytes drops below 70%',
			),
			array(
				'metric'           => 'cloudflare_zone_errors_4xx',
				'data_source_name' => 'error_4xx_pct',
				'direction'        => 'high',
				'thold_hi'         => 10.0,
				'thold_low'        => null,
				'fail_trigger'     => 2,
				'unit'             => 'percent',
				'description'      => 'Alert when zone HTTP 4xx client error rate exceeds 10%',
			),
			array(
				'metric'           => 'cloudflare_zone_errors_5xx',
				'data_source_name' => 'error_5xx_pct',
				'direction'        => 'high',
				'thold_hi'         => 5.0,
				'thold_low'        => null,
				'fail_trigger'     => 2,
				'unit'             => 'percent',
				'description'      => 'Alert when zone HTTP 5xx server error rate exceeds 5%',
			),
			array(
				'metric'           => 'cloudflare_zone_waf_blocked',
				'data_source_name' => 'waf_blocked_rate',
				'direction'        => 'high',
				'thold_hi'         => 50.0,
				'thold_low'        => null,
				'fail_trigger'     => 2,
				'unit'             => 'events/s',
				'description'      => 'Alert when WAF blocked request rate exceeds 50 events/s',
			),
			array(
				'metric'           => 'cloudflare_zone_waf_challenged',
				'data_source_name' => 'waf_challenged_rate',
				'direction'        => 'high',
				'thold_hi'         => 100.0,
				'thold_low'        => null,
				'fail_trigger'     => 2,
				'unit'             => 'events/s',
				'description'      => 'Alert when WAF challenged request rate exceeds 100 events/s',
			),
			array(
				'metric'           => 'cloudflare_zone_origin_ttfb_p95',
				'data_source_name' => 'origin_ttfb_p95_ms',
				'direction'        => 'high',
				'thold_hi'         => 1000.0,
				'thold_low'        => null,
				'fail_trigger'     => 2,
				'unit'             => 'milliseconds',
				'description'      => 'Alert when origin TTFB p95 latency exceeds 1000 ms',
			),
			array(
				'metric'           => 'cloudflare_zone_dns_servfail',
				'data_source_name' => 'dns_servfail_rate',
				'direction'        => 'high',
				'thold_hi'         => 5.0,
				'thold_low'        => null,
				'fail_trigger'     => 2,
				'unit'             => 'responses/s',
				'description'      => 'Alert when zone DNS SERVFAIL rate exceeds 5 responses/s',
			),
			array(
				'metric'           => 'cloudflare_zone_tls_cert_expiry',
				'data_source_name' => 'tls_cert_expiry_days',
				'direction'        => 'low',
				'thold_hi'         => null,
				'thold_low'        => 14.0,
				'fail_trigger'     => 1,
				'unit'             => 'days',
				'description'      => 'Alert when soonest active TLS certificate expires in fewer than 14 days',
			),
		);
	}

	/**
	 * Build a Thold-ready summary for all listed zones (optionally scoped to one account).
	 *
	 * @return array<int, array{
	 *     account_id: int,
	 *     zone_id: string,
	 *     name: string,
	 *     requests_per_sec: ?float,
	 *     bytes_per_sec: ?float,
	 *     error_rate_pct: ?float,
	 *     sample_interval: ?float,
	 *     age_seconds: ?int,
	 *     status: string
	 * }>
	 */
	public function summarizeZones(int $accountId = 0, float $warnErrorPct = 1.0, float $critErrorPct = 5.0): array {
		$sql    = 'SELECT z.account_id, z.zone_id, z.name, z.status, z.sampled_at, z.window_start, z.window_end,
			z.requests, z.bytes, z.errors, z.sample_interval, a.discovery_interval
			FROM plugin_cloudflare_zones z
			JOIN plugin_cloudflare_accounts a ON a.id = z.account_id
			WHERE z.listed = 1 AND a.enabled = 1';
		$params = array();

		if ($accountId > 0) {
			$sql     .= ' AND z.account_id = ?';
			$params[] = $accountId;
		}

		$sql .= ' ORDER BY z.account_id, z.name, z.zone_id';

		$now     = $this->clock->now();
		$summary = array();

		foreach ($this->db->fetchAll($sql, $params) as $row) {
			$sampledAt = $row['sampled_at'] === null ? null : (int) $row['sampled_at'];
			$age       = ($sampledAt !== null && $sampledAt <= $now) ? ($now - $sampledAt) : null;
			$window    = ($row['window_start'] !== null && $row['window_end'] !== null)
				? ((int) $row['window_end'] - (int) $row['window_start'])
				: 0;
			$maxAge    = 2 * max(300, (int) $row['discovery_interval']) + 60;

			$valid = (string) $row['status'] === 'ok'
				&& $age !== null
				&& $age <= $maxAge
				&& $window > 0
				&& is_numeric($row['requests'])
				&& is_numeric($row['bytes'])
				&& is_numeric($row['errors'])
				&& is_numeric($row['sample_interval']);

			$reqRate   = null;
			$byteRate  = null;
			$errorPct  = null;
			$interval  = null;
			$alertState = self::STATUS_UNKNOWN;

			if ($valid) {
				$requests = (float) $row['requests'];
				$bytes    = (float) $row['bytes'];
				$errors   = (float) $row['errors'];
				$reqRate  = round($requests / $window, 4);
				$byteRate = round($bytes / $window, 4);
				$interval = (float) $row['sample_interval'];
				$errorPct = $requests > 0.0 ? round(100.0 * $errors / $requests, 4) : 0.0;

				if ($errorPct >= $critErrorPct) {
					$alertState = self::STATUS_CRITICAL;
				} elseif ($errorPct >= $warnErrorPct) {
					$alertState = self::STATUS_DEGRADED;
				} else {
					$alertState = self::STATUS_OK;
				}
			}

			$summary[] = array(
				'account_id'       => (int) $row['account_id'],
				'zone_id'          => (string) $row['zone_id'],
				'name'             => (string) $row['name'],
				'requests_per_sec' => $reqRate,
				'bytes_per_sec'    => $byteRate,
				'error_rate_pct'   => $errorPct,
				'sample_interval'  => $interval,
				'age_seconds'      => $age,
				'status'           => $alertState,
			);
		}

		return $summary;
	}

	/**
	 * Evaluate a single GraphQL or enterprise analytics sample against error-rate,
	 * latency, cache-hit, WAF, DNS, and TLS expiry thresholds.
	 *
	 * Only metrics present and non-null in $sample are evaluated so minimal samples
	 * remain backwards-compatible and unknown fields never trigger false breaches.
	 *
	 * @param array{
	 *     requests?: float,
	 *     error_rate_pct?: ?float,
	 *     error_5xx_pct?: ?float,
	 *     error_4xx_pct?: ?float,
	 *     edge_latency_avg_ms?: ?float,
	 *     edge_latency_p95_ms?: ?float,
	 *     origin_ttfb_avg_ms?: ?float,
	 *     origin_ttfb_p95_ms?: ?float,
	 *     cache_hit_requests_pct?: ?float,
	 *     cache_hit_bytes_pct?: ?float,
	 *     waf_blocked_rate?: ?float,
	 *     waf_challenged_rate?: ?float,
	 *     dns_servfail_rate?: ?float,
	 *     tls_cert_expiry_days?: ?float
	 * }|null $sample
	 * @param float $maxErrorPct        Maximum allowed 5xx error rate (%)
	 * @param float $maxLatencyMs       Maximum allowed edge latency (ms)
	 * @param float $max4xxPct          Maximum allowed 4xx error rate (%)
	 * @param float $minCacheHitPct     Minimum allowed cache hit ratio (%)
	 * @param float $maxOriginTtfbMs    Maximum allowed origin TTFB latency (ms)
	 * @param float $maxWafBlockedRate  Maximum allowed WAF blocked rate (events/s)
	 * @param float $maxDnsServfailRate Maximum allowed DNS SERVFAIL rate (responses/s)
	 * @param float $minTlsExpiryDays   Minimum days before TLS cert expiry warning
	 * @return array{status: string, breaches: array<int, string>}
	 */
	public static function evaluateSample(
		?array $sample,
		float $maxErrorPct = 5.0,
		float $maxLatencyMs = 500.0,
		float $max4xxPct = 10.0,
		float $minCacheHitPct = 70.0,
		float $maxOriginTtfbMs = 1000.0,
		float $maxWafBlockedRate = 50.0,
		float $maxDnsServfailRate = 5.0,
		float $minTlsExpiryDays = 14.0
	): array {
		if ($sample === null) {
			return array('status' => self::STATUS_UNKNOWN, 'breaches' => array('no_sample'));
		}

		$breaches   = array();
		$isCritical = false;

		$err5xx = $sample['error_5xx_pct'] ?? ($sample['error_rate_pct'] ?? null);
		if ($err5xx !== null && $err5xx > $maxErrorPct) {
			$breaches[] = sprintf('error_rate_pct %.2f%% > %.2f%%', $err5xx, $maxErrorPct);
			$isCritical = true;
		}

		if (isset($sample['error_4xx_pct']) && $sample['error_4xx_pct'] > $max4xxPct) {
			$breaches[] = sprintf('error_4xx_pct %.2f%% > %.2f%%', $sample['error_4xx_pct'], $max4xxPct);
		}

		$latency = $sample['edge_latency_p95_ms'] ?? ($sample['edge_latency_avg_ms'] ?? null);
		if ($latency !== null && $latency > $maxLatencyMs) {
			$breaches[] = sprintf('edge_latency_ms %.1f ms > %.1f ms', $latency, $maxLatencyMs);
		}

		$originTtfb = $sample['origin_ttfb_p95_ms'] ?? ($sample['origin_ttfb_avg_ms'] ?? null);
		if ($originTtfb !== null && $originTtfb > $maxOriginTtfbMs) {
			$breaches[] = sprintf('origin_ttfb_ms %.1f ms > %.1f ms', $originTtfb, $maxOriginTtfbMs);
		}

		if (isset($sample['cache_hit_requests_pct']) && $sample['cache_hit_requests_pct'] < $minCacheHitPct) {
			$breaches[] = sprintf('cache_hit_requests_pct %.2f%% < %.2f%%', $sample['cache_hit_requests_pct'], $minCacheHitPct);
		}

		if (isset($sample['cache_hit_bytes_pct']) && $sample['cache_hit_bytes_pct'] < $minCacheHitPct) {
			$breaches[] = sprintf('cache_hit_bytes_pct %.2f%% < %.2f%%', $sample['cache_hit_bytes_pct'], $minCacheHitPct);
		}

		if (isset($sample['waf_blocked_rate']) && $sample['waf_blocked_rate'] > $maxWafBlockedRate) {
			$breaches[] = sprintf('waf_blocked_rate %.2f/s > %.2f/s', $sample['waf_blocked_rate'], $maxWafBlockedRate);
		}

		if (isset($sample['dns_servfail_rate']) && $sample['dns_servfail_rate'] > $maxDnsServfailRate) {
			$breaches[] = sprintf('dns_servfail_rate %.2f/s > %.2f/s', $sample['dns_servfail_rate'], $maxDnsServfailRate);
			$isCritical = true;
		}

		if (isset($sample['tls_cert_expiry_days']) && $sample['tls_cert_expiry_days'] < $minTlsExpiryDays) {
			$breaches[] = sprintf('tls_cert_expiry_days %.1f d < %.1f d', $sample['tls_cert_expiry_days'], $minTlsExpiryDays);
			if ($sample['tls_cert_expiry_days'] < 7.0) {
				$isCritical = true;
			}
		}

		if ($breaches === array()) {
			return array('status' => self::STATUS_OK, 'breaches' => array());
		}

		$status = ($isCritical || count($breaches) > 1)
			? self::STATUS_CRITICAL
			: self::STATUS_DEGRADED;

		return array('status' => $status, 'breaches' => $breaches);
	}
}
