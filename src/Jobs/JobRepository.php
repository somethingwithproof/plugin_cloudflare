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

namespace Cacti\PluginCloudflare\Jobs;

use Cacti\PluginCloudflare\Api\ErrorClass;
use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Support\Clock;
use InvalidArgumentException;

final class JobRepository {
	public const TYPE_DISCOVERY = 'discovery';

	private const TABLE = 'plugin_cloudflare_jobs';

	private const OWNER_MAX = 64;

	/* job_type is VARCHAR(32); a longer value would be truncated in a non-strict session. */
	private const TYPE_PATTERN = '/^[a-z_]{1,32}$/';

	private const MIN_RETRY = 60;

	private const MAX_BACKOFF = 3600;

	private const AUTH_DELAY = 3600;

	/* Matches only while the lease is unchanged, so a holder whose lease was taken over writes nothing. */
	private const HELD = ' WHERE id = ? AND lease_owner = ? AND lease_until = ?';

	/** @var Database */
	private $db;

	/** @var Clock */
	private $clock;

	public function __construct(Database $db, Clock $clock) {
		$this->db    = $db;
		$this->clock = $clock;
	}

	/**
	 * Keeps the tail when trimming: the pid is what tells two collectors on
	 * one host apart.
	 */
	public static function defaultOwner(): string {
		return substr((string) gethostname() . ':' . getmypid(), -self::OWNER_MAX);
	}

	/**
	 * Not INSERT IGNORE: that would also turn a missing account (a foreign
	 * key failure) into a silent no-op.
	 */
	public function ensure(string $type, int $accountId): void {
		if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
			throw new InvalidArgumentException('invalid job type');
		}

		$this->db->execute(
			'INSERT INTO ' . self::TABLE . ' (job_type, account_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE id = id',
			array($type, $accountId)
		);
	}

	/**
	 * next_run_at follows the Clock that nextRun() fed; lease expiry follows
	 * the database clock, as in lease().
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function due(string $type, int $limit): array {
		if ($limit < 1) {
			throw new InvalidArgumentException('invalid limit');
		}

		/* $limit is a checked int. pdo_mysql emulates prepares by default and would quote a bound LIMIT. */
		return $this->db->fetchAll(
			'SELECT j.* FROM ' . self::TABLE . ' j
			INNER JOIN plugin_cloudflare_accounts a ON a.id = j.account_id
			WHERE j.job_type = ? AND a.enabled = 1
			AND (j.job_type <> ? OR a.collect_tunnels = 1)
			AND j.next_run_at <= ?
			AND (j.lease_until IS NULL OR j.lease_until < UNIX_TIMESTAMP())
			ORDER BY j.next_run_at, j.id LIMIT ' . $limit,
			array($type, self::TYPE_DISCOVERY, $this->clock->now())
		);
	}

	/**
	 * One conditional UPDATE, so of two collectors racing for the job only
	 * one matches the row. Expiry is set and compared on the database clock,
	 * so skew between pollers cannot make a live lease look expired.
	 */
	public function lease(int $jobId, string $owner, int $ttl): ?Lease {
		if ($ttl < 1) {
			throw new InvalidArgumentException('invalid lease ttl');
		}

		$dbNow = (int) $this->db->fetchCell('SELECT UNIX_TIMESTAMP()');
		$until = $dbNow + $ttl;

		$matched = $this->db->execute(
			'UPDATE ' . self::TABLE . ' SET lease_owner = ?, lease_until = ?, last_started_at = ?
			WHERE id = ? AND (lease_until IS NULL OR lease_until < ?)',
			array($owner, $until, $this->clock->now(), $jobId, $dbNow)
		);

		return $matched === 1 ? new Lease($jobId, $owner, $until) : null;
	}

	public function succeed(Lease $lease, int $nextRunAt): void {
		$this->writeHeld(
			$lease,
			'UPDATE ' . self::TABLE . " SET last_status = 'ok', last_error_class = NULL, consecutive_failures = 0,
			last_finished_at = ?, next_run_at = ?, lease_owner = NULL, lease_until = NULL" . self::HELD,
			array($this->clock->now(), $nextRunAt)
		);
	}

	/**
	 * @return int consecutive_failures including this one
	 */
	public function fail(Lease $lease, string $errorClass, int $nextRunAt): int {
		return (int) $this->db->transactional(function () use ($lease, $errorClass, $nextRunAt) {
			$this->writeHeld(
				$lease,
				'UPDATE ' . self::TABLE . " SET last_status = 'failed', last_error_class = ?,
				consecutive_failures = consecutive_failures + 1, last_finished_at = ?, next_run_at = ?,
				lease_owner = NULL, lease_until = NULL" . self::HELD,
				array($errorClass, $this->clock->now(), $nextRunAt)
			);

			/* The UPDATE's row lock lasts until commit, so this reads the value it wrote. */
			return $this->db->fetchCell(
				'SELECT consecutive_failures FROM ' . self::TABLE . ' WHERE id = ?',
				array($lease->jobId())
			);
		});
	}

	/**
	 * Writes nothing, but run inside the caller's transaction it locks the
	 * job row and throws LeaseLost, so the caller's other writes roll back.
	 * A no-op UPDATE still reports its row because FOUND_ROWS is on.
	 */
	public function touch(Lease $lease): void {
		$this->writeHeld($lease, 'UPDATE ' . self::TABLE . ' SET lease_owner = lease_owner' . self::HELD, array());
	}

	public function release(Lease $lease): void {
		$this->writeHeld(
			$lease,
			'UPDATE ' . self::TABLE . ' SET lease_owner = NULL, lease_until = NULL' . self::HELD,
			array()
		);
	}

	/**
	 * @param string|null $errorClass an ErrorClass value, or null after a success
	 * @param int         $failures   consecutive failures, used only for transient errors
	 */
	public static function nextRun(int $now, int $interval, ?string $errorClass, ?int $retryAfter, int $failures): int {
		if ($errorClass === null) {
			return $now + $interval;
		}

		switch ($errorClass) {
			case ErrorClass::RATE_LIMITED:
				return $now + max($retryAfter ?? self::MIN_RETRY, self::MIN_RETRY);
			case ErrorClass::TRANSIENT:
				return $now + self::backoff($interval, $failures);
			case ErrorClass::AUTH:
			case ErrorClass::PERMISSION:
				return $now + self::AUTH_DELAY;
			default:
				// config, malformed, and any class added later.
				return $now + $interval;
		}
	}

	/* Doubles step by step so a long failure streak cannot overflow 2 ** $failures. */
	private static function backoff(int $interval, int $failures): int {
		$delay = $interval;

		for ($i = 0; $i < $failures && $delay < self::MAX_BACKOFF; $i++) {
			$delay *= 2;
		}

		return min($delay, self::MAX_BACKOFF);
	}

	/**
	 * @param array<int, mixed> $params values for the SET clause
	 */
	private function writeHeld(Lease $lease, string $sql, array $params): void {
		$params[] = $lease->jobId();
		$params[] = $lease->owner();
		$params[] = $lease->leaseUntil();

		if ($this->db->execute($sql, $params) !== 1) {
			throw new LeaseLost($lease);
		}
	}
}
