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

namespace Somethingwithproof\Cloudflare\Discovery;

use Somethingwithproof\Cloudflare\Account\AccountRepository;
use Somethingwithproof\Cloudflare\Account\CredentialResolver;
use Somethingwithproof\Cloudflare\Api\ApiException;
use Somethingwithproof\Cloudflare\Api\ErrorClass;
use Somethingwithproof\Cloudflare\Api\ZoneAnalytics;
use Somethingwithproof\Cloudflare\Jobs\JobRepository;
use Somethingwithproof\Cloudflare\Storage\Database;
use Somethingwithproof\Cloudflare\Support\Clock;
use Throwable;

/** Analytics collector component for plugin_cloudflare. */
final class AnalyticsCollector {
	/** @var Database */
	private $db;
	/** @var AccountRepository */
	private $accounts;
	/** @var CredentialResolver */
	private $credentials;
	/** @var ZoneAnalytics */
	private $api;
	/** @var Clock */
	private $clock;
	/** @var callable(): bool */
	private $enabled;

	/**
	 * Initialize AnalyticsCollector.
	 *
	 * @param Database $db
	 * @param AccountRepository $accounts
	 * @param CredentialResolver $credentials
	 * @param ZoneAnalytics $api
	 * @param Clock $clock
	 * @param callable $enabled
	 */
	public function __construct(Database $db, AccountRepository $accounts, CredentialResolver $credentials, ZoneAnalytics $api, Clock $clock, callable $enabled) {
		$this->db = $db;
		$this->accounts = $accounts;
		$this->credentials = $credentials;
		$this->api = $api;
		$this->clock = $clock;
		$this->enabled = $enabled;
	}

	/** Runs under the same collector lock as tunnel discovery and retirement. */
	public function run(int $seconds = 15, int $requests = 20): void {
		$deadline = $this->clock->now() + max(0, $seconds);
		$budget = function () use ($deadline, &$requests): void {
			if ($requests < 1 || $this->clock->now() >= $deadline || !($this->enabled)()) {
				throw new AnalyticsBudgetExhausted('analytics budget exhausted or plugin disabled');
			}
			$requests--;
		};
		$jobs = new JobRepository($this->db, $this->clock);

		foreach ($this->accounts->all() as $account) {
			if ($account->isEnabled() && $account->collectsAnalytics()) {
				$jobs->ensure('analytics', $account->id());
			}
		}

		foreach ($jobs->due('analytics', 10) as $row) {
			$account = $this->accounts->find((int) $row['account_id']);

			if ($account === null || !$account->isEnabled() || !$account->collectsAnalytics() || $requests < 1 || $this->clock->now() >= $deadline) {
				continue;
			}

			$lease = $jobs->lease((int) $row['id'], JobRepository::defaultOwner(), 120);

			if ($lease === null) {
				continue;
			}

			try {
				$token = $this->credentials->resolve($account->id());
				$zones = $this->api->zones($account->cfAccountId(), $token, $budget);
				$this->db->transactional(function () use ($account, $zones): void {
					$this->db->execute('UPDATE plugin_cloudflare_zones SET listed = 0 WHERE account_id = ?', array($account->id()));

					foreach ($zones as $id => $name) {
						$this->db->execute('INSERT INTO plugin_cloudflare_zones (account_id, zone_id, name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), listed = 1', array($account->id(), $id, $name));
					}
				});
				$rows = $this->db->fetchAll('SELECT zone_id FROM plugin_cloudflare_zones WHERE account_id = ? AND listed = 1 ORDER BY attempted_at, zone_id', array($account->id()));
				$partial = false;

				foreach ($rows as $zone) {
					if ($requests < 2 || $this->clock->now() >= $deadline || !($this->enabled)()) {
						$partial = true;

						break;
					}

					$now = $this->clock->now();
					// Read a complete five-minute UTC window ending two minutes ago.
					$end = (int) (floor(($now - 120) / 300) * 300);
					$this->db->execute("UPDATE plugin_cloudflare_zones SET status = 'failed', attempted_at = ? WHERE account_id = ? AND zone_id = ?", array($now, $account->id(), $zone['zone_id']));
					$sample = $this->api->sample((string) $zone['zone_id'], $token, $end - 300, $end, $budget);
					$this->db->execute('UPDATE plugin_cloudflare_zones SET status = ?, sampled_at = ?, window_start = ?, window_end = ?, requests = ?, bytes = ?, errors = ?, sample_interval = ? WHERE account_id = ? AND zone_id = ?', array($sample === null ? 'unsupported' : 'ok', $sample === null ? null : $now, $end - 300, $end, $sample['requests'] ?? null, $sample['bytes'] ?? null, $sample['errors'] ?? null, $sample['sample_interval'] ?? null, $account->id(), $zone['zone_id']));
				}

				if ($partial) {
					$jobs->partial($lease, $this->clock->now() + 30);
				} else {
					$jobs->succeed($lease, $this->clock->now() + max(300, $account->discoveryInterval()));
				}
			} catch (AnalyticsBudgetExhausted $e) {
				$jobs->partial($lease, $this->clock->now() + 30);
			} catch (Throwable $e) {
				$class = $e instanceof ApiException ? $e->getErrorClass() : ErrorClass::CONFIG;
				$retry = $e instanceof ApiException ? $e->getRetryAfter() : null;
				$jobs->fail($lease, $class, JobRepository::nextRun($this->clock->now(), 300, $class, $retry, 1));
			}
		}
	}
}
