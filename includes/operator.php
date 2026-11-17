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

require_once __DIR__ . '/viewer.php';

/** @return list<array{id: string, name: string, state: string, observed: int, interval: int, detail: string}> */
function cloudflare_operator_rows() : array {
	$rows      = array();
	$db        = cloudflare_db();
	$accounts  = (new \Somethingwithproof\Cloudflare\Account\AccountRepository($db))->all();
	$jobs      = (new \Somethingwithproof\Cloudflare\Jobs\JobRepository($db, new \Somethingwithproof\Cloudflare\Support\SystemClock()))->summaries(\Somethingwithproof\Cloudflare\Jobs\JobRepository::TYPE_DISCOVERY);
	$summaries = (new \Somethingwithproof\Cloudflare\Discovery\TunnelRepository($db))->summary();

	foreach ($accounts as $account) {
		$job    = $jobs[$account->id()] ?? null;
		$rows[] = array('id' => (string) $account->id(), 'name' => $account->name(), 'state' => cloudflare_viewer_health($account, $job, $summaries[$account->id()] ?? null), 'observed' => $job['last_finished_at'] ?? 0, 'interval' => $account->discoveryInterval(),
			'detail'            => ($job['last_error_class'] ?? '') . ' ' . __('Inspect tunnels and connector counts. Escalate token changes, account configuration and graph creation to an administrator.', 'cloudflare'));
	}

	return $rows;
}
