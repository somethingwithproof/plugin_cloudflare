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

namespace Somethingwithproof\Cloudflare\Api;

/** Fixed, ungrouped queries avoid truncated top-N data and unbounded labels. */
final class ZoneAnalytics {
	/** @var CloudflareClient */
	private $client;

	public function __construct(CloudflareClient $client) {
		$this->client = $client;
	}

	/**
	 * @param callable(): void $budget called before each request
	 * @return array<string, string> zone tag => name
	 */
	public function zones(
		string $account,
		#[\SensitiveParameter]
		string $token,
		callable $budget
	): array {
		$zones = array();

		for ($page = 1; $page <= 20; $page++) {
			$budget();
			$body = $this->client->request('GET', '/zones', $token, array('account.id' => $account, 'page' => $page, 'per_page' => 50));
			$rows = $body['result'] ?? null;

			if (!is_array($rows) || count($rows) > 50) {
				throw new ApiException(ErrorClass::MALFORMED);
			}

			foreach ($rows as $zone) {
				if (!is_array($zone) || !isset($zone['id'], $zone['name'], $zone['account']['id']) || !is_string($zone['id']) || preg_match('/^[a-f0-9]{32}\z/', $zone['id']) !== 1 || !is_string($zone['name']) || $zone['account']['id'] !== $account || isset($zones[$zone['id']])) {
					throw new ApiException(ErrorClass::MALFORMED);
				}

				$zones[$zone['id']] = substr($zone['name'], 0, 255);
			}

			if (count($rows) < 50) {
				return $zones;
			}
		}

		throw new ApiException(ErrorClass::MALFORMED, null, array(), 'zone pagination budget exceeded');
	}

	/**
	 * Returns null for a dataset whose per-zone limits cannot cover the
	 * requested interval. Values are sampled estimates, not billing totals.
	 * @param callable(): void $budget
	 * @return array{requests: float, bytes: float, errors: float, sample_interval: float}|null
	 */
	public function sample(
		string $zone,
		#[\SensitiveParameter]
		string $token,
		int $start,
		int $end,
		callable $budget
	): ?array {
		if (preg_match('/^[a-f0-9]{32}\z/', $zone) !== 1 || $start < 0 || $end <= $start) {
			throw new ApiException(ErrorClass::CONFIG);
		}

		$budget();
		$data = $this->client->graphql('query($zone: string!) { viewer { zones(filter: {zoneTag: $zone}) { settings { httpRequestsAdaptiveGroups { enabled maxDuration notOlderThan maxPageSize maxNumberOfFields } } } } }', array('zone' => $zone), $token);
		$rows = $data['viewer']['zones'] ?? null;
		$settings = is_array($rows) && count($rows) === 1 ? ($rows[0]['settings']['httpRequestsAdaptiveGroups'] ?? null) : null;

		if (!is_array($settings) || ($settings['enabled'] ?? null) !== true || !is_int($settings['maxDuration'] ?? null) || $settings['maxDuration'] < $end - $start || !is_int($settings['notOlderThan'] ?? null) || $settings['notOlderThan'] < $end - $start + 120 || ($settings['maxPageSize'] ?? 0) < 1 || ($settings['maxNumberOfFields'] ?? 0) < 4) {
			return null;
		}

		$budget();
		$data = $this->client->graphql('query($zone: string!, $start: Time!, $end: Time!) { viewer { zones(filter: {zoneTag: $zone}) { total: httpRequestsAdaptiveGroups(limit: 1, filter: {datetime_geq: $start, datetime_lt: $end, requestSource: "eyeball"}) { count sum { edgeResponseBytes } avg { sampleInterval } } errors: httpRequestsAdaptiveGroups(limit: 1, filter: {datetime_geq: $start, datetime_lt: $end, requestSource: "eyeball", edgeResponseStatus_geq: 500, edgeResponseStatus_lt: 600}) { count } } } }', array('zone' => $zone, 'start' => gmdate('Y-m-d\TH:i:s\Z', $start), 'end' => gmdate('Y-m-d\TH:i:s\Z', $end)), $token);
		$rows = $data['viewer']['zones'] ?? null;

		if (!is_array($rows) || count($rows) !== 1 || !is_array($rows[0]['total'] ?? null) || !is_array($rows[0]['errors'] ?? null) || count($rows[0]['total']) > 1 || count($rows[0]['errors']) > 1) {
			throw new ApiException(ErrorClass::MALFORMED);
		}

		$total = $rows[0]['total'];
		$errors = $rows[0]['errors'];
		$requests = $total === array() ? 0.0 : self::number($total[0]['count'] ?? null);
		$bytes = $total === array() ? 0.0 : self::number($total[0]['sum']['edgeResponseBytes'] ?? null);
		$count = $errors === array() ? 0.0 : self::number($errors[0]['count'] ?? null);
		$interval = $total === array() ? 1.0 : self::number($total[0]['avg']['sampleInterval'] ?? null);

		if ($count > $requests || $interval < 1) {
			throw new ApiException(ErrorClass::MALFORMED);
		}

		return array('requests' => $requests, 'bytes' => $bytes, 'errors' => $count, 'sample_interval' => $interval);
	}

	/** @param mixed $value */
	private static function number($value): float {
		if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0) {
			throw new ApiException(ErrorClass::MALFORMED);
		}

		return (float) $value;
	}
}
