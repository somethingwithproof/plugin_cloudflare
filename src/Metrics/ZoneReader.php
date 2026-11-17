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

final class ZoneReader {
	/** @var Database */
	private $db;
	/** @var Clock */
	private $clock;

	public function __construct(Database $db, Clock $clock) {
		$this->db = $db;
		$this->clock = $clock;
	}

	/** @return array<string, string> */
	public function index(int $host): array {
		$rows = $this->db->fetchAll("SELECT z.zone_id, z.name FROM plugin_cloudflare_zones z JOIN plugin_cloudflare_resources r ON r.account_id = z.account_id AND r.kind = 'device' JOIN plugin_cloudflare_accounts a ON a.id = z.account_id WHERE r.host_id = ? AND z.listed = 1 AND a.enabled = 1 AND a.collect_analytics = 1 ORDER BY z.zone_id", array($host));
		$result = array();

		foreach ($rows as $row) {
			if (preg_match('/^[a-f0-9]{32}\z/', (string) $row['zone_id']) === 1) {
				$result[(string) $row['zone_id']] = str_replace(array("\r", "\n"), ' ', (string) $row['name']);
			}
		}

		return $result;
	}

	public function value(int $host, string $zone, string $field): string {
		if (preg_match('/^[a-f0-9]{32}\z/', $zone) !== 1) {
			return 'U';
		}

		$row = $this->db->fetchRow("SELECT z.*, a.discovery_interval, j.last_status FROM plugin_cloudflare_zones z JOIN plugin_cloudflare_resources r ON r.account_id = z.account_id AND r.kind = 'device' JOIN plugin_cloudflare_accounts a ON a.id = z.account_id LEFT JOIN plugin_cloudflare_jobs j ON j.account_id = z.account_id AND j.job_type = 'analytics' WHERE r.host_id = ? AND z.zone_id = ? AND z.listed = 1 AND a.enabled = 1 AND a.collect_analytics = 1", array($host, $zone));
		$now = $this->clock->now();

		if ($row === null || $row['sampled_at'] === null || (int) $row['sampled_at'] > $now) {
			return 'U';
		}

		$age = $now - (int) $row['sampled_at'];

		if ($field === 'age') {
			return (string) $age;
		}

		if ($row['status'] !== 'ok' || !in_array($row['last_status'], array('ok', 'partial'), true) || $age > 2 * max(300, (int) $row['discovery_interval']) + 60 || (int) $row['window_end'] - (int) $row['window_start'] !== 300) {
			return 'U';
		}

		foreach (array('requests', 'bytes', 'errors', 'sample_interval') as $key) {
			if (!is_numeric($row[$key]) || !is_finite((float) $row[$key]) || (float) $row[$key] < 0) {
				return 'U';
			}
		}

		$values = array('requests' => (float) $row['requests'] / 300, 'bytes' => (float) $row['bytes'] / 300, 'errorPct' => (float) $row['requests'] > 0 ? 100 * (float) $row['errors'] / (float) $row['requests'] : null, 'sampleInterval' => $row['sample_interval']);
		$value = $values[$field] ?? null;

		return $value === null || !is_numeric($value) || !is_finite((float) $value) || (float) $value < 0 ? 'U' : sprintf('%.6F', $value);
	}
}
