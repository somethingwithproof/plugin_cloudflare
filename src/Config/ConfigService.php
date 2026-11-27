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

namespace Somethingwithproof\Cloudflare\Config;

use Somethingwithproof\Cloudflare\Account\AccountRepository;
use Somethingwithproof\Cloudflare\Discovery\TunnelRepository;
use Somethingwithproof\Cloudflare\Metrics\MetricMappingRepository;
use Somethingwithproof\Cloudflare\Storage\Database;
use Somethingwithproof\Cloudflare\Support\Clock;
use Somethingwithproof\Cloudflare\Support\Ids;
use InvalidArgumentException;

/**
 * Configuration-as-Code export, validate, plan, and confirmed apply service
 * for plugin_cloudflare.
 *
 * Exports accounts using TokenVault secret references (vault:<cf_account_id>
 * or file:<path>) and NEVER exports raw API tokens or raw ciphertexts.
 */
final class ConfigService {
	/** @var Database */
	private $db;

	/** @var AccountRepository */
	private $accounts;

	/** @var MetricMappingRepository */
	private $mappings;

	/** @var Clock */
	private $clock;

	public function __construct(
		Database $db,
		AccountRepository $accounts,
		TunnelRepository $tunnels,
		MetricMappingRepository $mappings,
		Clock $clock
	) {
		$this->db       = $db;
		$this->accounts = $accounts;
		$this->mappings = $mappings;
		$this->clock    = $clock;
		unset($tunnels);
	}

	/**
	 * Build the normalized, secret-free CasC configuration array from live DB state.
	 *
	 * @return array<string, mixed>
	 */
	public function exportData(): array {
		$accountsData = array();

		foreach ($this->accounts->all() as $account) {
			$material  = $this->accounts->tokenMaterial($account->id());
			$secretRef = $material['source'] === 'file'
				? 'file:' . (string) ($material['file'] ?? '')
				: 'vault:' . $account->cfAccountId();

			$accountsData[] = array(
				'cf_account_id'      => $account->cfAccountId(),
				'name'               => $account->name(),
				'enabled'            => $account->isEnabled(),
				'collect_tunnels'    => $account->collectsTunnels(),
				'collect_analytics'  => $account->collectsAnalytics(),
				'create_graphs'      => $account->createsGraphs(),
				'discovery_interval' => $account->discoveryInterval(),
				'token_ref'          => $secretRef,
			);
		}

		$zonesData = array();
		foreach ($this->db->fetchAll(
			'SELECT a.cf_account_id, z.zone_id, z.name, z.listed
			FROM plugin_cloudflare_zones z
			JOIN plugin_cloudflare_accounts a ON a.id = z.account_id
			ORDER BY a.cf_account_id, z.zone_id'
		) as $row) {
			$zonesData[] = array(
				'cf_account_id' => (string) $row['cf_account_id'],
				'zone_id'       => (string) $row['zone_id'],
				'name'          => (string) $row['name'],
				'listed'        => (int) $row['listed'] === 1,
			);
		}

		$tunnelsData = array();
		foreach ($this->db->fetchAll(
			'SELECT a.cf_account_id, t.cf_tunnel_id, t.name, t.lifecycle, t.cf_status
			FROM plugin_cloudflare_tunnels t
			JOIN plugin_cloudflare_accounts a ON a.id = t.account_id
			ORDER BY a.cf_account_id, t.cf_tunnel_id'
		) as $row) {
			$tunnelsData[] = array(
				'cf_account_id' => (string) $row['cf_account_id'],
				'cf_tunnel_id'  => (string) $row['cf_tunnel_id'],
				'name'          => (string) $row['name'],
				'lifecycle'     => (string) $row['lifecycle'],
				'cf_status'     => (string) $row['cf_status'],
			);
		}

		return array(
			'schema_version'  => ConfigCodec::SCHEMA_VERSION,
			'accounts'        => $accountsData,
			'zones'           => $zonesData,
			'tunnels'         => $tunnelsData,
			'metric_mappings' => $this->mappings->all(),
		);
	}

	public function export(string $format = ConfigCodec::FORMAT_YAML): string {
		return ConfigCodec::encode($format, $this->exportData());
	}

	/**
	 * Validate a YAML or XML configuration payload without modifying state.
	 *
	 * @return array{valid: bool, errors: array<int, string>, data: ?array<string, mixed>}
	 */
	public function validate(string $content, string $format = ConfigCodec::FORMAT_YAML): array {
		try {
			$data = ConfigCodec::decode($format, $content);
		} catch (InvalidArgumentException $e) {
			return array('valid' => false, 'errors' => array($e->getMessage()), 'data' => null);
		}

		$errors = $this->validateDocument($data);

		return array(
			'valid'  => $errors === array(),
			'errors' => $errors,
			'data'   => $errors === array() ? $data : null,
		);
	}

	/**
	 * Compute a deterministic diff plan and SHA-256 confirmation hash.
	 *
	 * @return array{
	 *     valid: bool,
	 *     plan_hash: string,
	 *     empty: bool,
	 *     errors: array<int, string>,
	 *     changes: array<int, array<string, mixed>>,
	 *     lines: array<int, string>
	 * }
	 */
	public function plan(string $content, string $format = ConfigCodec::FORMAT_YAML): array {
		$checked = $this->validate($content, $format);

		if (!$checked['valid'] || $checked['data'] === null) {
			return array(
				'valid'     => false,
				'plan_hash' => '',
				'empty'     => true,
				'errors'    => $checked['errors'],
				'changes'   => array(),
				'lines'     => array(),
			);
		}

		$existingByCfId = array();
		foreach ($this->accounts->all() as $account) {
			$existingByCfId[$account->cfAccountId()] = $account;
		}

		$changes = array();
		$lines   = array();
		$desired = isset($checked['data']['accounts']) && is_array($checked['data']['accounts']) ? $checked['data']['accounts'] : array();

		foreach ($desired as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$cfId      = (string) $entry['cf_account_id'];
			$name      = (string) $entry['name'];
			$enabled   = !empty($entry['enabled']);
			$tunnels   = !empty($entry['collect_tunnels']);
			$analytics = !empty($entry['collect_analytics']);
			$graphs    = !empty($entry['create_graphs']);
			$interval  = (int) $entry['discovery_interval'];
			$tokenRef  = (string) $entry['token_ref'];

			if (!isset($existingByCfId[$cfId])) {
				$change = array(
					'op'                 => 'create_account',
					'cf_account_id'      => $cfId,
					'name'               => $name,
					'enabled'            => $enabled,
					'collect_tunnels'    => $tunnels,
					'collect_analytics'  => $analytics,
					'create_graphs'      => $graphs,
					'discovery_interval' => $interval,
					'token_ref'          => $tokenRef,
				);
				$changes[] = $change;
				$lines[]   = sprintf('+ account %s (%s) interval=%ds ref=%s', $name, $cfId, $interval, $tokenRef);

				continue;
			}

			$current = $existingByCfId[$cfId];
			$diff    = array();

			if ($current->name() !== $name) {
				$diff['name'] = $name;
			}
			if ($current->isEnabled() !== $enabled) {
				$diff['enabled'] = $enabled;
			}
			if ($current->collectsTunnels() !== $tunnels) {
				$diff['collect_tunnels'] = $tunnels;
			}
			if ($current->collectsAnalytics() !== $analytics) {
				$diff['collect_analytics'] = $analytics;
			}
			if ($current->createsGraphs() !== $graphs) {
				$diff['create_graphs'] = $graphs;
			}
			if ($current->discoveryInterval() !== $interval) {
				$diff['discovery_interval'] = $interval;
			}

			if ($diff !== array()) {
				$changes[] = array(
					'op'            => 'update_account',
					'id'            => $current->id(),
					'cf_account_id' => $cfId,
					'fields'        => $diff,
				);
				$lines[] = sprintf('~ account %s (%s): %s', $name, $cfId, implode(', ', array_keys($diff)));
			}
		}

		$hash = hash('sha256', (string) json_encode($changes));

		return array(
			'valid'     => true,
			'plan_hash' => $hash,
			'empty'     => $changes === array(),
			'errors'    => array(),
			'changes'   => $changes,
			'lines'     => $lines,
		);
	}

	/**
	 * Apply a planned configuration only when $confirmHash matches the live plan hash.
	 *
	 * @return array{applied: bool, plan_hash: string, errors: array<int, string>, lines: array<int, string>}
	 */
	public function apply(string $content, string $confirmHash, string $format = ConfigCodec::FORMAT_YAML): array {
		$plan = $this->plan($content, $format);

		if (!$plan['valid']) {
			return array('applied' => false, 'plan_hash' => '', 'errors' => $plan['errors'], 'lines' => array());
		}

		if ($confirmHash === '' || !hash_equals($plan['plan_hash'], $confirmHash)) {
			return array(
				'applied'   => false,
				'plan_hash' => $plan['plan_hash'],
				'errors'    => array(sprintf('Plan hash mismatch: expected %s, got %s; re-run plan first', $plan['plan_hash'], $confirmHash)),
				'lines'     => array(),
			);
		}

		if ($plan['empty']) {
			return array('applied' => true, 'plan_hash' => $plan['plan_hash'], 'errors' => array(), 'lines' => array());
		}

		$now = $this->clock->now();

		$this->db->transactional(function () use ($plan, $now): void {
			foreach ($plan['changes'] as $change) {
				if ($change['op'] === 'create_account') {
					$ref    = (string) $change['token_ref'];
					$source = strpos($ref, 'file:') === 0 ? 'file' : 'vault';
					$file   = $source === 'file' ? substr($ref, 5) : null;

					$this->accounts->insert(array(
						'cf_account_id'      => (string) $change['cf_account_id'],
						'name'               => (string) $change['name'],
						'token_source'       => $source,
						'token_ciphertext'   => null,
						'token_file'         => $file !== '' ? $file : null,
						'enabled'            => !empty($change['enabled']) ? 1 : 0,
						'status'             => 'unverified',
						'status_message'     => '',
						'collect_tunnels'    => !empty($change['collect_tunnels']) ? 1 : 0,
						'collect_analytics'  => !empty($change['collect_analytics']) ? 1 : 0,
						'create_graphs'      => !empty($change['create_graphs']) ? 1 : 0,
						'discovery_interval' => (int) $change['discovery_interval'],
						'created_at'         => $now,
						'updated_at'         => $now,
					));
				} elseif ($change['op'] === 'update_account') {
					$update = array('updated_at' => $now);
					foreach ($change['fields'] as $field => $val) {
						$update[$field] = is_bool($val) ? ($val ? 1 : 0) : $val;
					}
					$this->accounts->update((int) $change['id'], $update);
				}
			}
		});

		return array(
			'applied'   => true,
			'plan_hash' => $plan['plan_hash'],
			'errors'    => array(),
			'lines'     => $plan['lines'],
		);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<int, string>
	 */
	private function validateDocument(array $data): array {
		$errors = array();

		if (!isset($data['schema_version']) || (int) $data['schema_version'] !== ConfigCodec::SCHEMA_VERSION) {
			$errors[] = 'Unsupported or missing schema_version (expected ' . ConfigCodec::SCHEMA_VERSION . ')';
		}

		$allowedTop = array('schema_version', 'accounts', 'zones', 'tunnels', 'metric_mappings');
		foreach (array_keys($data) as $key) {
			if (!in_array($key, $allowedTop, true)) {
				$errors[] = 'Unknown top-level key: ' . $key;
			}
		}

		if (isset($data['accounts'])) {
			if (!is_array($data['accounts'])) {
				$errors[] = 'accounts must be a list';
			} else {
				$seen = array();
				foreach ($data['accounts'] as $idx => $entry) {
					if (!is_array($entry)) {
						$errors[] = 'accounts[' . $idx . '] must be an object';
						continue;
					}

					$cfId = isset($entry['cf_account_id']) && is_string($entry['cf_account_id']) ? $entry['cf_account_id'] : '';
					if (!Ids::isAccountId($cfId)) {
						$errors[] = 'accounts[' . $idx . '].cf_account_id must be a 32-character lowercase hex ID';
					} elseif (isset($seen[$cfId])) {
						$errors[] = 'Duplicate cf_account_id: ' . $cfId;
					} else {
						$seen[$cfId] = true;
					}

					$name = isset($entry['name']) && is_string($entry['name']) ? trim($entry['name']) : '';
					if ($name === '' || strlen($name) > 128) {
						$errors[] = 'accounts[' . $idx . '].name must be 1..128 characters';
					}

					$interval = isset($entry['discovery_interval']) && is_int($entry['discovery_interval']) ? $entry['discovery_interval'] : -1;
					if ($interval < 60 || $interval > 86400) {
						$errors[] = 'accounts[' . $idx . '].discovery_interval must be between 60 and 86400 seconds';
					}

					$ref = isset($entry['token_ref']) && is_string($entry['token_ref']) ? $entry['token_ref'] : '';
					if (!self::isValidSecretRef($ref)) {
						$errors[] = 'accounts[' . $idx . '].token_ref must use vault:<account_id> or file:<absolute_path>';
					}
				}
			}
		}

		return $errors;
	}

	private static function isValidSecretRef(string $ref): bool {
		if (strpos($ref, 'vault:') === 0) {
			return Ids::isAccountId(substr($ref, 6));
		}

		if (strpos($ref, 'file:') === 0) {
			$path = substr($ref, 5);

			return $path !== '' && $path[0] === '/' && strpos($path, "\0") === false && strpos($path, '..') === false;
		}

		return false;
	}
}
