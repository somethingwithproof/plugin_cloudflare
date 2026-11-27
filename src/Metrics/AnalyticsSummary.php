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
	 * Evaluate a single GraphQL analytics sample against error-rate and latency thresholds.
	 *
	 * @param array{
	 *     requests: float,
	 *     error_rate_pct: ?float,
	 *     edge_latency_avg_ms?: ?float,
	 *     edge_latency_p95_ms?: ?float
	 * }|null $sample
	 * @return array{status: string, breaches: array<int, string>}
	 */
	public static function evaluateSample(?array $sample, float $maxErrorPct = 5.0, float $maxLatencyMs = 500.0): array {
		if ($sample === null) {
			return array('status' => self::STATUS_UNKNOWN, 'breaches' => array('no_sample'));
		}

		$breaches = array();

		if (isset($sample['error_rate_pct']) && $sample['error_rate_pct'] > $maxErrorPct) {
			$breaches[] = sprintf('error_rate_pct %.2f%% > %.2f%%', $sample['error_rate_pct'], $maxErrorPct);
		}

		$latency = $sample['edge_latency_p95_ms'] ?? ($sample['edge_latency_avg_ms'] ?? null);
		if ($latency !== null && $latency > $maxLatencyMs) {
			$breaches[] = sprintf('edge_latency_ms %.1f ms > %.1f ms', $latency, $maxLatencyMs);
		}

		if ($breaches === array()) {
			return array('status' => self::STATUS_OK, 'breaches' => array());
		}

		$status = count($breaches) > 1 || (isset($sample['error_rate_pct']) && $sample['error_rate_pct'] > $maxErrorPct)
			? self::STATUS_CRITICAL
			: self::STATUS_DEGRADED;

		return array('status' => $status, 'breaches' => $breaches);
	}
}
