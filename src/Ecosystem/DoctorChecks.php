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

namespace Somethingwithproof\Cloudflare\Ecosystem;

use Somethingwithproof\Cloudflare\Account\AccountRepository;
use Somethingwithproof\Cloudflare\Metrics\ObservationReader;
use Somethingwithproof\Cloudflare\Secret\SecretException;
use Somethingwithproof\Cloudflare\Secret\TokenVault;
use Somethingwithproof\Cloudflare\Storage\Database;
use Somethingwithproof\Cloudflare\Support\Clock;

/**
 * Diagnostic health checks exposed through doctor_register_checks and CLI health.
 *
 * Inspects vault key permissions/validity, token directory hygiene, tunnel
 * freshness, and collector API failure rate without ever revealing key bytes
 * or API token material.
 */
final class DoctorChecks {
	public const CATEGORY = 'cloudflare';

	/** @var Database */
	private $db;

	/** @var AccountRepository */
	private $accounts;

	/** @var Clock */
	private $clock;

	/** @var string */
	private $keyFile;

	/** @var string */
	private $tokenDir;

	public function __construct(
		Database $db,
		AccountRepository $accounts,
		Clock $clock,
		string $keyFile,
		string $tokenDir
	) {
		$this->db       = $db;
		$this->accounts = $accounts;
		$this->clock    = $clock;
		$this->keyFile  = trim($keyFile);
		$this->tokenDir = trim($tokenDir);
	}

	/**
	 * @return array<int, array{
	 *     id: string,
	 *     category: string,
	 *     status: string,
	 *     summary: string,
	 *     detail: string,
	 *     repair: string,
	 *     repairable: bool,
	 *     assessment: string
	 * }>
	 */
	public function run(): array {
		return array(
			$this->checkVaultKey(),
			$this->checkTokenDir(),
			$this->checkTunnelFreshness(),
			$this->checkApiFailureRate(),
		);
	}

	/**
	 * @param mixed $registry
	 * @return mixed
	 */
	public function register($registry) {
		$results = $this->run();

		if (is_array($registry)) {
			foreach ($results as $entry) {
				$registry[] = $entry;
			}

			return $registry;
		}

		if (is_object($registry) && method_exists($registry, 'register')) {
			foreach ($results as $entry) {
				$registry->register($entry['id'], $entry);
			}
		}

		return $registry;
	}

	/**
	 * @return array{id: string, category: string, status: string, summary: string, detail: string, repair: string, repairable: bool, assessment: string}
	 */
	private function checkVaultKey(): array {
		$path = $this->keyFile;

		if ($path === '') {
			return self::result('cloudflare_vault_key', 'warn', 'Token vault key path is empty', 'No key file path is configured; only token files can be used.');
		}

		if (!file_exists($path) && !is_link($path)) {
			return self::result('cloudflare_vault_key', 'ok', 'Token vault key file not present (token-file mode)', 'Key file ' . $path . ' is absent; accounts must use token files.');
		}

		if (is_link($path)) {
			return self::result('cloudflare_vault_key', 'fail', 'Token vault key file must not be a symbolic link', 'Refusing symlink at ' . $path . '; use a regular file with mode 0600 or 0640.');
		}

		$stat = @stat($path);
		if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
			return self::result('cloudflare_vault_key', 'fail', 'Token vault key path is not a regular file', 'Path ' . $path . ' cannot be stated as a regular file.');
		}

		$perms = $stat['mode'] & 0777;
		if (($perms & 0006) !== 0) {
			return self::result(
				'cloudflare_vault_key',
				'fail',
				sprintf('Token vault key file permissions too permissive (%04o)', $perms),
				sprintf('Key file %s has world read/write bits (%04o); restrict with chmod 0600 or 0640.', $path, $perms)
			);
		}

		try {
			TokenVault::fromKeyFile($path);
		} catch (SecretException $e) {
			return self::result('cloudflare_vault_key', 'fail', 'Token vault key file rejected by TokenVault', 'Key file ' . $path . ' is unreadable or not a valid 32-byte/64-hex key.');
		}

		return self::result('cloudflare_vault_key', 'ok', 'Token vault key file is valid and properly restricted', sprintf('Key file %s passed mode (%04o) and length checks.', $path, $perms));
	}

	/**
	 * @return array{id: string, category: string, status: string, summary: string, detail: string, repair: string, repairable: bool, assessment: string}
	 */
	private function checkTokenDir(): array {
		$dir = $this->tokenDir;

		if ($dir === '') {
			return self::result('cloudflare_token_dir', 'warn', 'Token file directory restriction is not set', 'Set cloudflare_token_dir to confine account token files to one restricted directory.');
		}

		if ($dir[0] !== '/') {
			return self::result('cloudflare_token_dir', 'fail', 'Token file directory must be an absolute path', 'Configured path: ' . $dir);
		}

		if (is_link($dir)) {
			return self::result('cloudflare_token_dir', 'fail', 'Token file directory must not be a symlink', 'Refusing symlink at ' . $dir);
		}

		if (!is_dir($dir) || !is_readable($dir)) {
			return self::result('cloudflare_token_dir', 'fail', 'Token file directory is missing or unreadable', 'Cannot read directory ' . $dir);
		}

		$stat = @stat($dir);
		if ($stat !== false && (($stat['mode'] & 0002) !== 0)) {
			return self::result('cloudflare_token_dir', 'fail', 'Token file directory is world-writable', sprintf('Directory %s has mode %04o; remove world-writable permission.', $dir, $stat['mode'] & 0777));
		}

		return self::result('cloudflare_token_dir', 'ok', 'Token file directory is present and restricted', 'Token file directory ' . $dir . ' is readable and not world-writable.');
	}

	/**
	 * @return array{id: string, category: string, status: string, summary: string, detail: string, repair: string, repairable: bool, assessment: string}
	 */
	private function checkTunnelFreshness(): array {
		$intervals = array();
		foreach ($this->accounts->all() as $account) {
			$intervals[$account->id()] = $account->discoveryInterval();
		}

		$rows = $this->db->fetchAll("SELECT account_id, name, last_seen_at FROM plugin_cloudflare_tunnels WHERE lifecycle = 'active' ORDER BY name");
		if ($rows === array()) {
			return self::result('cloudflare_tunnel_freshness', 'unknown', 'No active Cloudflare tunnels discovered', 'No active tunnel rows exist in plugin_cloudflare_tunnels.');
		}

		$now   = $this->clock->now();
		$stale = array();

		foreach ($rows as $row) {
			$interval = $intervals[(int) $row['account_id']] ?? 300;
			$window   = ObservationReader::window($interval);
			$seenAt   = $row['last_seen_at'] === null ? null : (int) $row['last_seen_at'];

			if ($seenAt === null || ($now - $seenAt) > $window) {
				$stale[] = (string) $row['name'];
			}
		}

		if ($stale === array()) {
			return self::result('cloudflare_tunnel_freshness', 'ok', 'All active Cloudflare tunnels are fresh', sprintf('All %d active tunnel(s) were observed within their freshness window.', count($rows)));
		}

		$sample = implode(', ', array_slice($stale, 0, 10)) . (count($stale) > 10 ? ', ...' : '');

		return self::result(
			'cloudflare_tunnel_freshness',
			'warn',
			sprintf('%d of %d active Cloudflare tunnel(s) are stale', count($stale), count($rows)),
			'Stale tunnels: ' . $sample
		);
	}

	/**
	 * @return array{id: string, category: string, status: string, summary: string, detail: string, repair: string, repairable: bool, assessment: string}
	 */
	private function checkApiFailureRate(): array {
		$rows = $this->db->fetchAll('SELECT job_type, last_status, last_error_class, consecutive_failures FROM plugin_cloudflare_jobs');

		if ($rows === array()) {
			return self::result('cloudflare_api_failure_rate', 'unknown', 'No Cloudflare collector jobs recorded yet', 'Run the collector poller to populate plugin_cloudflare_jobs.');
		}

		$total   = count($rows);
		$failing = 0;
		$maxFail = 0;
		$classes = array();

		foreach ($rows as $row) {
			$failures = (int) $row['consecutive_failures'];
			if ($failures > 0 || (string) $row['last_status'] === 'failed') {
				$failing++;
				if ($failures > $maxFail) {
					$maxFail = $failures;
				}
				if (isset($row['last_error_class']) && is_string($row['last_error_class']) && $row['last_error_class'] !== '') {
					$classes[$row['last_error_class']] = true;
				}
			}
		}

		$rate = round(100.0 * $failing / $total, 1);

		if ($failing === 0) {
			return self::result('cloudflare_api_failure_rate', 'ok', 'Cloudflare API collector jobs are healthy', sprintf('0 of %d collector job(s) failing.', $total));
		}

		$status = ($rate >= 50.0 || $maxFail >= 5) ? 'fail' : 'warn';
		$detail = sprintf('%d of %d job(s) failing (%.1f%%, max consecutive failures: %d)', $failing, $total, $rate, $maxFail);
		if ($classes !== array()) {
			$detail .= '; error classes: ' . implode(', ', array_keys($classes));
		}

		return self::result('cloudflare_api_failure_rate', $status, sprintf('Cloudflare API job failure rate %.1f%%', $rate), $detail);
	}

	/**
	 * @return array{id: string, category: string, status: string, summary: string, detail: string, repair: string, repairable: bool, assessment: string}
	 */
	private static function result(string $id, string $status, string $summary, string $detail): array {
		return array(
			'id'         => $id,
			'category'   => self::CATEGORY,
			'status'     => $status,
			'summary'    => $summary,
			'detail'     => $detail,
			'repair'     => '',
			'repairable' => false,
			'assessment' => 'assessed',
		);
	}
}
