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
use Somethingwithproof\Cloudflare\Storage\Database;
use Somethingwithproof\Cloudflare\Storage\DatabaseException;
use Somethingwithproof\Cloudflare\Version;

/**
 * Redacted diagnostic snapshot provider for the optional
 * doctor_register_debug_info hook.
 *
 * Returns plain arrays with zero cross-plugin PHP dependencies and never
 * exposes API tokens, vault ciphertexts, key file contents, or token file
 * paths.
 */
final class DoctorDebugInfo {
	/** @var string Section slug registered with the debug info collector. */
	public const SECTION = 'cloudflare';

	/** @var Database */
	private $db;

	/** @var AccountRepository */
	private $accounts;

	/** @var string Configured vault key file path. */
	private $keyFile;

	/** @var string Configured token directory restriction path. */
	private $tokenDir;

	/**
	 * Construct the redacted diagnostic snapshot provider.
	 *
	 * @param Database          $db       Plugin database wrapper
	 * @param AccountRepository $accounts Account repository
	 * @param string            $keyFile  Configured token vault key file path
	 * @param string            $tokenDir Configured token file directory path
	 */
	public function __construct(
		Database $db,
		AccountRepository $accounts,
		string $keyFile,
		string $tokenDir
	) {
		$this->db       = $db;
		$this->accounts = $accounts;
		$this->keyFile  = trim($keyFile);
		$this->tokenDir = trim($tokenDir);
	}

	/**
	 * Build a redacted diagnostic section safe to include in support bundles.
	 *
	 * @return array{
	 *     id: string,
	 *     plugin: string,
	 *     label: string,
	 *     description: string,
	 *     private: bool,
	 *     fields: array<string, array{label: string, value: string, private: bool}>
	 * }
	 */
	public function section(): array {
		$accounts     = $this->accounts->all();
		$enabledCount = 0;
		$vaultCount   = 0;
		$fileCount    = 0;

		foreach ($accounts as $account) {
			if ($account->isEnabled()) {
				$enabledCount++;
			}

			$material = $this->accounts->tokenMaterial($account->id());

			if ($material['source'] === 'file') {
				$fileCount++;
			} else {
				$vaultCount++;
			}
		}

		$schemaVersion  = $this->safeIntCell('SELECT MAX(version) FROM plugin_cloudflare_migrations');
		$tunnelsActive  = $this->safeIntCell("SELECT COUNT(*) FROM plugin_cloudflare_tunnels WHERE lifecycle = 'active'");
		$tunnelsRetired = $this->safeIntCell("SELECT COUNT(*) FROM plugin_cloudflare_tunnels WHERE lifecycle = 'retired'");
		$zonesListed    = $this->safeIntCell('SELECT COUNT(*) FROM plugin_cloudflare_zones WHERE listed = 1');
		$jobsTotal      = $this->safeIntCell('SELECT COUNT(*) FROM plugin_cloudflare_jobs');
		$jobsFailing    = $this->safeIntCell('SELECT COUNT(*) FROM plugin_cloudflare_jobs WHERE consecutive_failures > 0');

		return array(
			'id'          => 'cloudflare.overview',
			'plugin'      => self::SECTION,
			'label'       => 'Cloudflare',
			'description' => 'Read-only diagnostic summary for plugin_cloudflare (all tokens, ciphertexts, and secret paths redacted).',
			'private'     => false,
			'fields'      => array(
				'plugin_version' => array(
					'label'   => 'Plugin version',
					'value'   => Version::PLUGIN,
					'private' => false,
				),
				'schema_version' => array(
					'label'   => 'Applied schema version',
					'value'   => (string) $schemaVersion,
					'private' => false,
				),
				'accounts_summary' => array(
					'label'   => 'Accounts (enabled / total)',
					'value'   => sprintf('%d / %d', $enabledCount, count($accounts)),
					'private' => false,
				),
				'token_sources' => array(
					'label'   => 'Credential sources (vault / file)',
					'value'   => sprintf('vault=%d, file=%d', $vaultCount, $fileCount),
					'private' => false,
				),
				'vault_key_status' => array(
					'label'   => 'Token vault key status',
					'value'   => $this->describeKeyStatus(),
					'private' => false,
				),
				'token_dir_restricted' => array(
					'label'   => 'Token directory restricted',
					'value'   => $this->tokenDir !== '' ? 'yes' : 'no',
					'private' => false,
				),
				'tunnels_summary' => array(
					'label'   => 'Tunnels (active / retired)',
					'value'   => sprintf('%d / %d', $tunnelsActive, $tunnelsRetired),
					'private' => false,
				),
				'zones_listed' => array(
					'label'   => 'Listed zones',
					'value'   => (string) $zonesListed,
					'private' => false,
				),
				'collector_jobs' => array(
					'label'   => 'Collector jobs (failing / total)',
					'value'   => sprintf('%d / %d', $jobsFailing, $jobsTotal),
					'private' => false,
				),
				'remote_collect' => array(
					'label'   => 'Remote data collector capable',
					'value'   => 'yes',
					'private' => false,
				),
			),
		);
	}

	/**
	 * Return the redacted debug info section list without echoing $context.
	 *
	 * @param mixed $context Doctor debug-info hook context
	 * @return array<int, array{
	 *     id: string,
	 *     plugin: string,
	 *     label: string,
	 *     description: string,
	 *     private: bool,
	 *     fields: array<string, array{label: string, value: string, private: bool}>
	 * }>
	 */
	public function register($context = array()): array {
		unset($context);

		return array($this->section());
	}

	/**
	 * Describe key file availability and mode without disclosing its path or bytes.
	 *
	 * @return string
	 */
	private function describeKeyStatus(): string {
		if ($this->keyFile === '') {
			return 'unconfigured';
		}

		if (is_link($this->keyFile)) {
			return 'symlink_refused';
		}

		if (!file_exists($this->keyFile)) {
			return 'absent';
		}

		$stat = @stat($this->keyFile);

		if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
			return 'not_regular_file';
		}

		return sprintf('present (mode %04o)', $stat['mode'] & 0777);
	}

	/**
	 * Execute an integer aggregate query, returning 0 if a table is absent.
	 *
	 * @param string $sql Aggregate SQL returning a single integer cell
	 * @return int
	 */
	private function safeIntCell(string $sql): int {
		try {
			$value = $this->db->fetchCell($sql);

			return is_numeric($value) ? (int) $value : 0;
		} catch (DatabaseException $e) {
			return 0;
		}
	}
}
