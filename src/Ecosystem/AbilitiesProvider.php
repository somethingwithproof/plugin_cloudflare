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
use Somethingwithproof\Cloudflare\Discovery\TunnelRepository;
use Somethingwithproof\Cloudflare\Metrics\ObservationReader;
use Somethingwithproof\Cloudflare\Storage\Database;
use Somethingwithproof\Cloudflare\Support\Clock;
use stdClass;

/**
 * Registers strictly read-only Cloudflare abilities via abilities_register.
 *
 * Permissions check the token owner's user ID against the Cloudflare View
 * realm (never reading session state). Output schemas are closed and never
 * expose tokens, ciphertexts, or key file paths.
 */
final class AbilitiesProvider {
	public const CATEGORY           = 'cloudflare';
	public const ABILITY_TUNNELS    = 'cloudflare-list-tunnels';
	public const ABILITY_GET_HEALTH = 'cloudflare-get-health';
	private const MAX_RESULTS       = 200;

	/** @var Database */
	private $db;

	/** @var AccountRepository */
	private $accounts;

	/** @var TunnelRepository */
	private $tunnels;

	/** @var Clock */
	private $clock;

	/** @var callable(int): bool */
	private $hasViewRealm;

	/**
	 * @param callable(int): bool $hasViewRealm
	 */
	public function __construct(
		Database $db,
		AccountRepository $accounts,
		TunnelRepository $tunnels,
		Clock $clock,
		callable $hasViewRealm
	) {
		$this->db           = $db;
		$this->accounts     = $accounts;
		$this->tunnels      = $tunnels;
		$this->clock        = $clock;
		$this->hasViewRealm = $hasViewRealm;
	}

	/**
	 * @param object $registrar AbilitiesRegistrar instance
	 */
	public function register($registrar): void {
		if (!is_object($registrar)) {
			return;
		}

		if (method_exists($registrar, 'registerCategory')) {
			$registrar->registerCategory(self::CATEGORY, 'Cloudflare');
		}

		if (!method_exists($registrar, 'register')) {
			return;
		}

		$registrar->register(self::ABILITY_TUNNELS, $this->listTunnelsDefinition());
		$registrar->register(self::ABILITY_GET_HEALTH, $this->getHealthDefinition());
	}

	/**
	 * @return array<string, mixed>
	 */
	public function listTunnelsDefinition(): array {
		return array(
			'label'        => 'List Cloudflare Tunnels',
			'description'  => 'List discovered Cloudflare tunnels with lifecycle, health status, connector counts, and last seen timestamp.',
			'category'     => self::CATEGORY,
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'account_id' => array('type' => 'integer', 'minimum' => 0),
					'lifecycle'  => array('type' => 'string', 'enum' => TunnelRepository::LIFECYCLES),
					'cf_status'  => array('type' => 'string', 'enum' => TunnelRepository::CF_STATUSES),
					'search'     => array('type' => 'string', 'maxLength' => 128),
					'limit'      => array('type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_RESULTS),
				),
				'additionalProperties' => false,
			),
			'output_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'total'   => array('type' => 'integer', 'minimum' => 0),
					'tunnels' => array(
						'type'     => 'array',
						'maxItems' => self::MAX_RESULTS,
						'items'    => array(
							'type'                 => 'object',
							'properties'           => array(
								'account_id'       => array('type' => 'integer', 'minimum' => 1),
								'cf_tunnel_id'     => array('type' => 'string'),
								'name'             => array('type' => 'string'),
								'lifecycle'        => array('type' => 'string', 'enum' => TunnelRepository::LIFECYCLES),
								'cf_status'        => array('type' => 'string', 'enum' => TunnelRepository::CF_STATUSES),
								'connector_count'  => array('type' => array('integer', 'null')),
								'connection_count' => array('type' => array('integer', 'null')),
								'last_seen_at'     => array('type' => array('integer', 'null')),
							),
							'required'             => array('account_id', 'cf_tunnel_id', 'name', 'lifecycle', 'cf_status', 'connector_count', 'connection_count', 'last_seen_at'),
							'additionalProperties' => false,
						),
					),
				),
				'required'             => array('total', 'tunnels'),
				'additionalProperties' => false,
			),
			'annotations' => array(
				'readOnly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'permission' => function (int $userId): bool {
				return $this->canView($userId);
			},
			'execute' => function (int $userId, stdClass $args): array {
				return $this->executeListTunnels($userId, $args);
			},
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getHealthDefinition(): array {
		return array(
			'label'        => 'Get Cloudflare Health',
			'description'  => 'Return read-only Cloudflare plugin health summary covering accounts, active/stale tunnels, and collector job failures.',
			'category'     => self::CATEGORY,
			'input_schema' => array(
				'type'                 => 'object',
				'properties'           => new stdClass(),
				'additionalProperties' => false,
			),
			'output_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'status'           => array('type' => 'string', 'enum' => array('ok', 'warning', 'error', 'unknown')),
					'accounts_total'   => array('type' => 'integer', 'minimum' => 0),
					'accounts_enabled' => array('type' => 'integer', 'minimum' => 0),
					'tunnels_active'   => array('type' => 'integer', 'minimum' => 0),
					'tunnels_stale'    => array('type' => 'integer', 'minimum' => 0),
					'jobs_failing'     => array('type' => 'integer', 'minimum' => 0),
					'zones_listed'     => array('type' => 'integer', 'minimum' => 0),
				),
				'required'             => array('status', 'accounts_total', 'accounts_enabled', 'tunnels_active', 'tunnels_stale', 'jobs_failing', 'zones_listed'),
				'additionalProperties' => false,
			),
			'annotations' => array(
				'readOnly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'permission' => function (int $userId): bool {
				return $this->canView($userId);
			},
			'execute' => function (int $userId, stdClass $args): array {
				return $this->executeGetHealth($userId);
			},
		);
	}

	private function canView(int $userId): bool {
		return $userId > 0 && ($this->hasViewRealm)($userId) === true;
	}

	/**
	 * @return array{total: int, tunnels: array<int, array<string, mixed>>}
	 */
	public function executeListTunnels(int $userId, stdClass $args): array {
		if (!$this->canView($userId)) {
			return array('total' => 0, 'tunnels' => array());
		}

		$accountId = isset($args->account_id) && is_int($args->account_id) && $args->account_id >= 0 ? $args->account_id : 0;
		$limit     = isset($args->limit) && is_int($args->limit) ? max(1, min(self::MAX_RESULTS, $args->limit)) : self::MAX_RESULTS;
		$filters   = array();

		if (isset($args->lifecycle) && is_string($args->lifecycle) && in_array($args->lifecycle, TunnelRepository::LIFECYCLES, true)) {
			$filters['lifecycle'] = $args->lifecycle;
		}
		if (isset($args->cf_status) && is_string($args->cf_status) && in_array($args->cf_status, TunnelRepository::CF_STATUSES, true)) {
			$filters['cf_status'] = $args->cf_status;
		}
		if (isset($args->search) && is_string($args->search) && trim($args->search) !== '') {
			$filters['search'] = trim($args->search);
		}

		$total = $this->tunnels->count($accountId, $filters);
		$rows  = $this->tunnels->forAccount($accountId, $filters, $limit, 0);
		$items = array();

		foreach ($rows as $row) {
			$items[] = array(
				'account_id'       => (int) $row['account_id'],
				'cf_tunnel_id'     => (string) $row['cf_tunnel_id'],
				'name'             => (string) $row['name'],
				'lifecycle'        => (string) $row['lifecycle'],
				'cf_status'        => (string) $row['cf_status'],
				'connector_count'  => $row['connector_count'] === null ? null : (int) $row['connector_count'],
				'connection_count' => $row['connection_count'] === null ? null : (int) $row['connection_count'],
				'last_seen_at'     => $row['last_seen_at'] === null ? null : (int) $row['last_seen_at'],
			);
		}

		return array('total' => $total, 'tunnels' => $items);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function executeGetHealth(int $userId): array {
		if (!$this->canView($userId)) {
			return array(
				'status'           => 'unknown',
				'accounts_total'   => 0,
				'accounts_enabled' => 0,
				'tunnels_active'   => 0,
				'tunnels_stale'    => 0,
				'jobs_failing'     => 0,
				'zones_listed'     => 0,
			);
		}

		$accounts = $this->accounts->all();
		$enabled  = 0;
		$intervals = array();

		foreach ($accounts as $account) {
			if ($account->isEnabled()) {
				$enabled++;
			}
			$intervals[$account->id()] = $account->discoveryInterval();
		}

		$now          = $this->clock->now();
		$activeRows   = $this->db->fetchAll("SELECT account_id, last_seen_at FROM plugin_cloudflare_tunnels WHERE lifecycle = 'active'");
		$activeCount  = count($activeRows);
		$staleCount   = 0;

		foreach ($activeRows as $row) {
			$interval = $intervals[(int) $row['account_id']] ?? 300;
			$window   = ObservationReader::window($interval);
			$seenAt   = $row['last_seen_at'] === null ? null : (int) $row['last_seen_at'];

			if ($seenAt === null || ($now - $seenAt) > $window) {
				$staleCount++;
			}
		}

		$failingJobs = (int) $this->db->fetchCell('SELECT COUNT(*) FROM plugin_cloudflare_jobs WHERE consecutive_failures > 0');
		$zonesListed = (int) $this->db->fetchCell('SELECT COUNT(*) FROM plugin_cloudflare_zones WHERE listed = 1');

		$status = 'ok';
		if (count($accounts) === 0) {
			$status = 'unknown';
		} elseif ($failingJobs > 0 && $failingJobs >= $enabled && $enabled > 0) {
			$status = 'error';
		} elseif ($failingJobs > 0 || $staleCount > 0) {
			$status = 'warning';
		}

		return array(
			'status'           => $status,
			'accounts_total'   => count($accounts),
			'accounts_enabled' => $enabled,
			'tunnels_active'   => $activeCount,
			'tunnels_stale'    => $staleCount,
			'jobs_failing'     => $failingJobs,
			'zones_listed'     => $zonesListed,
		);
	}
}
