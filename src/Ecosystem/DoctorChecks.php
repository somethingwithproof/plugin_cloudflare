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
	/** @var string Category. */
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

	/**
	 * Initialize DoctorChecks.
	 *
	 * @param Database $db
	 * @param AccountRepository $accounts
	 * @param Clock $clock
	 * @param string $keyFile
	 * @param string $tokenDir
	 */
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
	 * Run or list Cloudflare diagnostic checks in Doctor's canonical descriptor shape.
	 *
	 * @param string $mode Execution mode ('run' or 'list')
	 * @return array<int, array{
	 *     id: string,
	 *     plugin: string,
	 *     category: string,
	 *     title: string,
	 *     status: string,
	 *     message: string,
	 *     details: string,
	 *     remediation: string
	 * }>
	 */
	public function run(string $mode = 'run'): array {
		if ($mode === 'list') {
			return array(
				self::result('cloudflare.vault_key', 'info', 'Cloudflare token vault key', 'Checks token vault key path, permissions, and key validity.', '', 'Ensure the token vault key is a regular 32-byte key file with mode 0600 or 0640.'),
				self::result('cloudflare.token_dir', 'info', 'Cloudflare token directory', 'Checks token file directory restriction, readability, and permissions.', '', 'Configure cloudflare_token_dir to an absolute, non-world-writable directory.'),
				self::result('cloudflare.tunnel_freshness', 'info', 'Cloudflare tunnel freshness', 'Checks whether active Cloudflare tunnels were observed within their freshness window.', '', 'Verify the Cloudflare discovery poller is running and API credentials are valid.'),
				self::result('cloudflare.api_failure_rate', 'info', 'Cloudflare API collector failure rate', 'Checks recent collector job failure rates across configured Cloudflare accounts.', '', 'Inspect Cacti logs and Cloudflare account token permissions for failing collector jobs.'),
			);
		}

		return array(
			$this->checkVaultKey(),
			$this->checkTokenDir(),
			$this->checkTunnelFreshness(),
			$this->checkApiFailureRate(),
		);
	}

	/**
	 * Evaluate Cloudflare checks for doctor_register_checks without echoing $context.
	 *
	 * @param mixed $context Doctor hook context array
	 * @return array<int, array{
	 *     id: string,
	 *     plugin: string,
	 *     category: string,
	 *     title: string,
	 *     status: string,
	 *     message: string,
	 *     details: string,
	 *     remediation: string
	 * }>
	 */
	public function register($context = array()): array {
		$mode = is_array($context) && isset($context['mode']) && is_string($context['mode']) ? $context['mode'] : 'run';

		return $this->run($mode);
	}

	/**
	 * Evaluate token vault key file existence, type, permissions, and format.
	 *
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: string, remediation: string}
	 */
	private function checkVaultKey(): array {
		$path  = $this->keyFile;
		$title = 'Cloudflare token vault key';

		if ($path === '') {
			return self::result('cloudflare.vault_key', 'warn', $title, 'Token vault key path is empty', 'No key file path is configured; only token files can be used.', 'Configure cloudflare_key_file or store account credentials in token files.');
		}

		if (!file_exists($path) && !is_link($path)) {
			return self::result('cloudflare.vault_key', 'ok', $title, 'Token vault key file not present (token-file mode)', 'Key file ' . $path . ' is absent; accounts must use token files.', '');
		}

		if (is_link($path)) {
			return self::result('cloudflare.vault_key', 'fail', $title, 'Token vault key file must not be a symbolic link', 'Refusing symlink at ' . $path . '; use a regular file with mode 0600 or 0640.', 'Replace the symlink with a regular key file owned by the Cacti user (mode 0600).');
		}

		$stat = @stat($path);
		if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
			return self::result('cloudflare.vault_key', 'fail', $title, 'Token vault key path is not a regular file', 'Path ' . $path . ' cannot be stated as a regular file.', 'Point cloudflare_key_file to a regular 32-byte key file.');
		}

		$perms = $stat['mode'] & 0777;
		if (($perms & 0006) !== 0) {
			return self::result(
				'cloudflare.vault_key',
				'fail',
				$title,
				sprintf('Token vault key file permissions too permissive (%04o)', $perms),
				sprintf('Key file %s has world read/write bits (%04o); restrict with chmod 0600 or 0640.', $path, $perms),
				'Restrict key file permissions using chmod 0600 or chmod 0640.'
			);
		}

		try {
			TokenVault::fromKeyFile($path);
		} catch (SecretException $e) {
			return self::result('cloudflare.vault_key', 'fail', $title, 'Token vault key file rejected by TokenVault', 'Key file ' . $path . ' is unreadable or not a valid 32-byte/64-hex key.', 'Provision a readable 32-byte or 64-hex key file for TokenVault.');
		}

		return self::result('cloudflare.vault_key', 'ok', $title, 'Token vault key file is valid and properly restricted', sprintf('Key file %s passed mode (%04o) and length checks.', $path, $perms), '');
	}

	/**
	 * Evaluate token directory restriction path and permissions.
	 *
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: string, remediation: string}
	 */
	private function checkTokenDir(): array {
		$dir   = $this->tokenDir;
		$title = 'Cloudflare token directory';

		if ($dir === '') {
			return self::result('cloudflare.token_dir', 'warn', $title, 'Token file directory restriction is not set', 'Set cloudflare_token_dir to confine account token files to one restricted directory.', 'Configure cloudflare_token_dir to an absolute directory with restricted permissions.');
		}

		if ($dir[0] !== '/') {
			return self::result('cloudflare.token_dir', 'fail', $title, 'Token file directory must be an absolute path', 'Configured path: ' . $dir, 'Set cloudflare_token_dir to an absolute path.');
		}

		if (is_link($dir)) {
			return self::result('cloudflare.token_dir', 'fail', $title, 'Token file directory must not be a symlink', 'Refusing symlink at ' . $dir, 'Point cloudflare_token_dir to a real directory rather than a symlink.');
		}

		if (!is_dir($dir) || !is_readable($dir)) {
			return self::result('cloudflare.token_dir', 'fail', $title, 'Token file directory is missing or unreadable', 'Cannot read directory ' . $dir, 'Create the token directory and grant read access to the Cacti poller user.');
		}

		$stat = @stat($dir);
		if ($stat !== false && (($stat['mode'] & 0002) !== 0)) {
			return self::result('cloudflare.token_dir', 'fail', $title, 'Token file directory is world-writable', sprintf('Directory %s has mode %04o; remove world-writable permission.', $dir, $stat['mode'] & 0777), 'Remove world-writable permission from the token directory.');
		}

		return self::result('cloudflare.token_dir', 'ok', $title, 'Token file directory is present and restricted', 'Token file directory ' . $dir . ' is readable and not world-writable.', '');
	}

	/**
	 * Evaluate active Cloudflare tunnel observation freshness.
	 *
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: string, remediation: string}
	 */
	private function checkTunnelFreshness(): array {
		$title     = 'Cloudflare tunnel freshness';
		$intervals = array();
		foreach ($this->accounts->all() as $account) {
			$intervals[$account->id()] = $account->discoveryInterval();
		}

		$rows = $this->db->fetchAll("SELECT account_id, name, last_seen_at FROM plugin_cloudflare_tunnels WHERE lifecycle = 'active' ORDER BY name");
		if ($rows === array()) {
			return self::result('cloudflare.tunnel_freshness', 'info', $title, 'No active Cloudflare tunnels discovered', 'No active tunnel rows exist in plugin_cloudflare_tunnels.', '');
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
			return self::result('cloudflare.tunnel_freshness', 'ok', $title, 'All active Cloudflare tunnels are fresh', sprintf('All %d active tunnel(s) were observed within their freshness window.', count($rows)), '');
		}

		$sample = implode(', ', array_slice($stale, 0, 10)) . (count($stale) > 10 ? ', ...' : '');

		return self::result(
			'cloudflare.tunnel_freshness',
			'warn',
			$title,
			sprintf('%d of %d active Cloudflare tunnel(s) are stale', count($stale), count($rows)),
			'Stale tunnels: ' . $sample,
			'Verify the Cloudflare discovery poller is running and able to reach the Cloudflare API.'
		);
	}

	/**
	 * Evaluate Cloudflare collector job failure rate.
	 *
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: string, remediation: string}
	 */
	private function checkApiFailureRate(): array {
		$title = 'Cloudflare API collector failure rate';
		$rows  = $this->db->fetchAll('SELECT job_type, last_status, last_error_class, consecutive_failures FROM plugin_cloudflare_jobs');

		if ($rows === array()) {
			return self::result('cloudflare.api_failure_rate', 'info', $title, 'No Cloudflare collector jobs recorded yet', 'Run the collector poller to populate plugin_cloudflare_jobs.', '');
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
			return self::result('cloudflare.api_failure_rate', 'ok', $title, 'Cloudflare API collector jobs are healthy', sprintf('0 of %d collector job(s) failing.', $total), '');
		}

		$status = ($rate >= 50.0 || $maxFail >= 5) ? 'fail' : 'warn';
		$detail = sprintf('%d of %d job(s) failing (%.1f%%, max consecutive failures: %d)', $failing, $total, $rate, $maxFail);
		if ($classes !== array()) {
			$detail .= '; error classes: ' . implode(', ', array_keys($classes));
		}

		return self::result(
			'cloudflare.api_failure_rate',
			$status,
			$title,
			sprintf('Cloudflare API job failure rate %.1f%%', $rate),
			$detail,
			'Inspect Cacti logs for Cloudflare collector failures and verify API token permissions and rate limits.'
		);
	}

	/**
	 * Normalize a status token to Doctor's strict ok|warn|fail|info enum.
	 *
	 * @param string $status Raw status token
	 * @return string
	 */
	private static function normalizeStatus(string $status): string {
		$lower = strtolower(trim($status));

		if ($lower === 'ok' || $lower === 'pass') {
			return 'ok';
		}

		if ($lower === 'warn' || $lower === 'warning') {
			return 'warn';
		}

		if ($lower === 'fail' || $lower === 'critical' || $lower === 'error') {
			return 'fail';
		}

		return 'info';
	}

	/**
	 * Format a single canonical Doctor check descriptor entry.
	 *
	 * @param string $id          Dot-prefixed check identifier
	 * @param string $status      Check status normalized to ok|warn|fail|info
	 * @param string $title       Human-readable check title
	 * @param string $message     Non-empty summary message
	 * @param string $details     Detailed diagnostic text
	 * @param string $remediation Actionable remediation guidance
	 * @return array{id: string, plugin: string, category: string, title: string, status: string, message: string, details: string, remediation: string}
	 */
	private static function result(string $id, string $status, string $title, string $message, string $details, string $remediation): array {
		return array(
			'id'          => $id,
			'plugin'      => self::CATEGORY,
			'category'    => self::CATEGORY,
			'title'       => $title,
			'status'      => self::normalizeStatus($status),
			'message'     => $message,
			'details'     => $details,
			'remediation' => $remediation,
		);
	}
}
