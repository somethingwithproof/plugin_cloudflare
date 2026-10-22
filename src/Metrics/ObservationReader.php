<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 Thomas Vincent                                       |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cloudflare plugin for Cacti                                             |
 | https://github.com/somethingwithproof/plugin_cloudflare                 |
 +-------------------------------------------------------------------------+
*/

namespace Cacti\PluginCloudflare\Metrics;

use Cacti\PluginCloudflare\Api\ErrorClass;
use Cacti\PluginCloudflare\Jobs\JobRepository;
use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Support\Clock;
use Cacti\PluginCloudflare\Support\Ids;
use Cacti\PluginCloudflare\Version;

/**
 * Turns stored tunnel state into observations. Anything this class cannot
 * vouch for comes back as a null value with a reason, never as zero: a zero
 * in an RRD reads as a real outage and cannot be told apart later.
 */
final class ObservationReader {
	public const STALE           = 'stale';
	public const CLOCK_SKEW      = 'clock_skew';
	public const NOT_ACTIVE      = 'not_active';
	public const NEVER_COLLECTED = 'never_collected';
	public const UNKNOWN_TUNNEL  = 'unknown_tunnel';

	/* A stamp slightly ahead of now is clock drift; further ahead, the
	 * stamp itself cannot be trusted. */
	private const SKEW_TOLERANCE = 60;

	/** @var Database */
	private $db;

	/** @var Clock */
	private $clock;

	public function __construct(Database $db, Clock $clock) {
		$this->db    = $db;
		$this->clock = $clock;
	}

	/**
	 * Two missed discoveries plus a minute of scheduling slack before a
	 * value counts as stale.
	 */
	public static function window(int $interval): int {
		return 2 * $interval + 60;
	}

	public function accountForHost(int $hostId): ?int {
		$id = $this->db->fetchCell(
			"SELECT account_id FROM plugin_cloudflare_resources WHERE kind = 'device' AND host_id = ?
			ORDER BY id LIMIT 1",
			array($hostId)
		);

		return $id === null ? null : (int) $id;
	}

	/**
	 * Deleted tunnels stay listed: Cacti orphans a data source whose index
	 * disappears, and a deleted tunnel keeps its graph.
	 *
	 * @return array<string, string> cf_tunnel_id => name
	 */
	public function tunnelIndex(int $accountId): array {
		$index = array();

		foreach ($this->db->fetchAll(
			'SELECT cf_tunnel_id, name FROM plugin_cloudflare_tunnels WHERE account_id = ? ORDER BY cf_tunnel_id',
			array($accountId)
		) as $row) {
			$index[(string) $row['cf_tunnel_id']] = (string) $row['name'];
		}

		return $index;
	}

	/**
	 * @return array<string, Observation> keyed by queryField, in catalog order
	 */
	public function observe(int $accountId, string $cfTunnelId): array {
		/* The column collation is case-insensitive, so an uppercase id would
		 * otherwise match a stored lowercase one. */
		$row = null;
		if ($accountId > 0 && Ids::isUuid($cfTunnelId)) {
			$row = $this->db->fetchRow(
				'SELECT t.lifecycle, t.cf_status, t.connector_count, t.connection_count,
					t.connections_refreshed_at, t.connections_attempted_at, t.connections_error_class,
					t.last_seen_at, a.discovery_interval,
					j.last_status, j.last_error_class, j.consecutive_failures,
					(SELECT COUNT(DISTINCT c.version) FROM plugin_cloudflare_connectors c
						WHERE c.tunnel_id = t.id) AS versions
				FROM plugin_cloudflare_tunnels t
				JOIN plugin_cloudflare_accounts a ON a.id = t.account_id
				LEFT JOIN plugin_cloudflare_jobs j ON j.account_id = t.account_id AND j.job_type = ?
				WHERE t.account_id = ? AND t.cf_tunnel_id = ?',
				array(JobRepository::TYPE_DISCOVERY, $accountId, $cfTunnelId)
			);
		}

		if ($row === null) {
			return $this->allUnknown(self::UNKNOWN_TUNNEL);
		}

		$now       = $this->clock->now();
		$window    = self::window((int) $row['discovery_interval']);
		$seenAt    = self::intOrNull($row['last_seen_at']);
		$refreshed = self::intOrNull($row['connections_refreshed_at']);
		$state     = $this->stateReason($row);

		$healthReason = $state ?? self::timeReason($seenAt, $now, $window);
		$connReason   = $state ?? self::timeReason($refreshed, $now, $window) ?? self::attemptReason($row, $refreshed);

		$health = Catalog::HEALTH[(string) $row['cf_status']] ?? null;
		if ($healthReason === null && $health === null) {
			$healthReason = ErrorClass::MALFORMED;
		}

		$values = array(
			'health'      => array($health, $seenAt, $healthReason),
			'connectors'  => array(self::intOrNull($row['connector_count']), $refreshed, $connReason),
			'connections' => array(self::intOrNull($row['connection_count']), $refreshed, $connReason),
			'versions'    => array((int) $row['versions'], $refreshed, $connReason),
		);

		/* Collection metrics describe an outage, so they ignore job status,
		 * lifecycle short of deletion, and the window. */
		$collect = null;
		if ((string) $row['lifecycle'] === 'deleted') {
			$collect = self::NOT_ACTIVE;
		} elseif ($refreshed === null || $row['last_status'] === null) {
			$collect = self::NEVER_COLLECTED;
		}

		$values['collectAge'] = array(
			$refreshed === null ? null : max(0, $now - $refreshed),
			$now,
			$collect ?? self::timeReason($refreshed, $now, null),
		);
		$values['collectFailures'] = array(self::intOrNull($row['consecutive_failures']), $now, $collect);

		$observations = array();
		foreach (Catalog::all() as $metric) {
			list($value, $at, $reason) = $values[$metric->queryField()];

			if ($reason !== null || $value === null) {
				$observations[$metric->queryField()] = self::unknown($metric, $reason ?? self::NEVER_COLLECTED);

				continue;
			}

			$observations[$metric->queryField()] = new Observation(
				$metric->name(),
				$value,
				$at,
				null,
				Version::METRIC_SCHEMA
			);
		}

		return $observations;
	}

	/**
	 * Job status first, then lifecycle: a failing account explains an
	 * unavailable tunnel, not the other way round.
	 *
	 * @param array<string, mixed> $row
	 */
	private function stateReason(array $row): ?string {
		$status = $row['last_status'];

		if ($status === null || $status === 'never') {
			return self::NEVER_COLLECTED;
		}

		if ($status !== 'ok' && $status !== 'partial') {
			return self::stringOrNull($row['last_error_class']) ?? ErrorClass::TRANSIENT;
		}

		if ((string) $row['lifecycle'] !== 'active') {
			return self::NOT_ACTIVE;
		}

		return null;
	}

	private static function timeReason(?int $at, int $now, ?int $window): ?string {
		if ($at === null) {
			return self::NEVER_COLLECTED;
		}

		$age = $now - $at;

		if ($age < -self::SKEW_TOLERANCE) {
			return self::CLOCK_SKEW;
		}

		if ($window !== null && $age > $window) {
			return self::STALE;
		}

		return null;
	}

	/**
	 * A later attempt than the last refresh means the latest read failed,
	 * so the stored counts belong to an earlier state.
	 *
	 * @param array<string, mixed> $row
	 */
	private static function attemptReason(array $row, ?int $refreshed): ?string {
		$attempted = self::intOrNull($row['connections_attempted_at']);

		if ($attempted !== null && $refreshed !== null && $attempted > $refreshed) {
			return self::stringOrNull($row['connections_error_class']) ?? ErrorClass::TRANSIENT;
		}

		return null;
	}

	/**
	 * @return array<string, Observation>
	 */
	private function allUnknown(string $reason): array {
		$observations = array();
		foreach (Catalog::all() as $metric) {
			$observations[$metric->queryField()] = self::unknown($metric, $reason);
		}

		return $observations;
	}

	private static function unknown(Metric $metric, string $reason): Observation {
		return new Observation($metric->name(), null, null, $reason, Version::METRIC_SCHEMA);
	}

	/**
	 * @param mixed $value
	 */
	private static function intOrNull($value): ?int {
		return $value === null ? null : (int) $value;
	}

	/**
	 * @param mixed $value
	 */
	private static function stringOrNull($value): ?string {
		return $value === null || $value === '' ? null : (string) $value;
	}
}
