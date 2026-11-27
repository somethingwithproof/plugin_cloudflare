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

use Somethingwithproof\Cloudflare\Account\Account;
use Somethingwithproof\Cloudflare\Account\AccountRepository;
use Somethingwithproof\Cloudflare\Discovery\TunnelRepository;
use Somethingwithproof\Cloudflare\Storage\Database;

/**
 * Plain-array Google AIP resource descriptor provider for the optional
 * api_register_resources hook.
 *
 * Publishes strictly read-only resource descriptors with zero cross-plugin
 * PHP imports and explicitly excludes all credential/secret columns.
 */
final class ApiResourcesProvider {
	/** @var string Resource group namespace for Cloudflare API resources. */
	public const NAMESPACE_PREFIX = 'cloudflare';

	/** @var Database|null Optional database handle for live list/get handlers. */
	private $db;

	/**
	 * Initialize ApiResourcesProvider.
	 *
	 * @param Database|null $db Optional plugin database wrapper
	 */
	public function __construct(?Database $db = null) {
		$this->db = $db;
	}

	/**
	 * Return canonical Google AIP plain-array resource descriptors for accounts
	 * and tunnels.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function descriptors(): array {
		return array(
			array(
				'plugin'        => self::NAMESPACE_PREFIX,
				'collection'    => 'cloudflareAccounts',
				'singleton'     => 'cloudflareAccount',
				'description'   => 'Read-only Cloudflare accounts (secret token fields excluded).',
				'realm_read'    => 'cloudflare.php',
				'schema'        => 'schema/cloudflare.schema.yaml',
				'filter_fields' => array('id', 'cf_account_id', 'name', 'enabled', 'status'),
				'order_fields'  => array('id', 'name', 'status'),
				'handlers'      => array(
					'list' => array($this, 'listAccounts'),
					'get'  => array($this, 'getAccount'),
				),
			),
			array(
				'plugin'        => self::NAMESPACE_PREFIX,
				'collection'    => 'cloudflareTunnels',
				'singleton'     => 'cloudflareTunnel',
				'description'   => 'Read-only Cloudflare tunnels.',
				'realm_read'    => 'cloudflare.php',
				'schema'        => 'schema/cloudflare.schema.yaml',
				'filter_fields' => array('id', 'account_id', 'cf_tunnel_id', 'name', 'lifecycle', 'cf_status', 'tun_type'),
				'order_fields'  => array('id', 'name', 'cf_status', 'last_seen_at'),
				'handlers'      => array(
					'list' => array($this, 'listTunnels'),
					'get'  => array($this, 'getTunnel'),
				),
			),
		);
	}

	/**
	 * List read-only Cloudflare accounts without exposing token ciphertexts or file paths.
	 *
	 * @param array<string, mixed> $context API handler context
	 * @return array<int, array<string, mixed>>
	 */
	public function listAccounts(array $context = array()): array {
		unset($context);

		if ($this->db === null) {
			return array();
		}

		$items = array();
		foreach ((new AccountRepository($this->db))->all() as $account) {
			$items[] = self::accountRecord($account);
		}

		return $items;
	}

	/**
	 * Retrieve a single read-only Cloudflare account by numeric identifier.
	 *
	 * @param string               $id      Account identifier
	 * @param array<string, mixed> $context API handler context
	 * @return array<string, mixed>|null
	 */
	public function getAccount(string $id, array $context = array()): ?array {
		unset($context);

		if ($this->db === null || !ctype_digit($id) || (int) $id < 1) {
			return null;
		}

		$account = (new AccountRepository($this->db))->find((int) $id);

		return $account === null ? null : self::accountRecord($account);
	}

	/**
	 * List read-only Cloudflare tunnels.
	 *
	 * @param array<string, mixed> $context API handler context
	 * @return array<int, array<string, mixed>>
	 */
	public function listTunnels(array $context = array()): array {
		unset($context);

		if ($this->db === null) {
			return array();
		}

		$items = array();
		foreach ((new TunnelRepository($this->db))->forAccount(0, array(), 500, 0) as $row) {
			$items[] = self::tunnelRecord($row);
		}

		return $items;
	}

	/**
	 * Retrieve a single read-only Cloudflare tunnel by numeric identifier.
	 *
	 * @param string               $id      Tunnel identifier
	 * @param array<string, mixed> $context API handler context
	 * @return array<string, mixed>|null
	 */
	public function getTunnel(string $id, array $context = array()): ?array {
		unset($context);

		if ($this->db === null || !ctype_digit($id) || (int) $id < 1) {
			return null;
		}

		$row = (new TunnelRepository($this->db))->find((int) $id);

		return $row === null ? null : self::tunnelRecord($row);
	}

	/**
	 * Append canonical Cloudflare resource descriptors to a plain list.
	 *
	 * @param mixed $resources Existing resource list passed by api_register_resources
	 * @return array<int, array<string, mixed>>
	 */
	public function register($resources = array()): array {
		$list = array();

		if (is_array($resources)) {
			foreach ($resources as $existing) {
				if (is_array($existing)) {
					$list[] = $existing;
				}
			}
		}

		foreach ($this->descriptors() as $descriptor) {
			$list[] = $descriptor;
		}

		return $list;
	}

	/**
	 * Project an Account model into a secret-free API record.
	 *
	 * @param Account $account Account model
	 * @return array<string, mixed>
	 */
	private static function accountRecord(Account $account): array {
		$id = (string) $account->id();

		return array(
			'id'                 => $id,
			'name'               => 'cloudflareAccounts/' . $id,
			'cf_account_id'      => $account->cfAccountId(),
			'display_name'       => $account->name(),
			'enabled'            => $account->isEnabled(),
			'status'             => $account->status(),
			'status_message'     => $account->statusMessage(),
			'collect_tunnels'    => $account->collectsTunnels(),
			'collect_analytics'  => $account->collectsAnalytics(),
			'create_graphs'      => $account->createsGraphs(),
			'discovery_interval' => $account->discoveryInterval(),
		);
	}

	/**
	 * Project a tunnel row into a read-only API record.
	 *
	 * @param array<string, mixed> $row Database row from plugin_cloudflare_tunnels
	 * @return array<string, mixed>
	 */
	private static function tunnelRecord(array $row): array {
		$id = (string) (int) ($row['id'] ?? 0);

		return array(
			'id'               => $id,
			'name'             => 'cloudflareTunnels/' . $id,
			'account_id'       => (int) ($row['account_id'] ?? 0),
			'cf_tunnel_id'     => (string) ($row['cf_tunnel_id'] ?? ''),
			'display_name'     => (string) ($row['name'] ?? ''),
			'lifecycle'        => (string) ($row['lifecycle'] ?? ''),
			'cf_status'        => (string) ($row['cf_status'] ?? ''),
			'tun_type'         => (string) ($row['tun_type'] ?? ''),
			'connector_count'  => $row['connector_count'] === null ? null : (int) $row['connector_count'],
			'connection_count' => $row['connection_count'] === null ? null : (int) $row['connection_count'],
			'last_seen_at'     => $row['last_seen_at'] === null ? null : (int) $row['last_seen_at'],
		);
	}
}
