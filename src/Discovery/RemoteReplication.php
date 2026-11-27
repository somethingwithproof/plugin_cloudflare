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

use Somethingwithproof\Cloudflare\Storage\Database;

/**
 * Poller-scoped metadata replication for remote data collectors through the
 * replicate_out hook.
 *
 * Replicates non-secret metadata tables (accounts with token_ciphertext
 * stripped to NULL, tunnels, connectors, zones, resources, metric mappings).
 * Raw vault key files and raw API tokens are NEVER replicated.
 */
final class RemoteReplication {
	/** @var Database */
	private $db;

	public function __construct(Database $db) {
		$this->db = $db;
	}

	/**
	 * Build metadata payloads for a remote data collector, stripping all raw
	 * token ciphertexts and vault key material.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function payloadForPoller(int $remotePollerId): array {
		if ($remotePollerId <= 1) {
			return array();
		}

		/* Explicitly omit token_ciphertext (replaced with NULL) so encrypted
		 * blobs and vault key material never cross poller replication boundaries. */
		$accounts = $this->db->fetchAll(
			'SELECT id, cf_account_id, name, token_source, NULL AS token_ciphertext, token_file,
				enabled, status, status_message, permissions, last_checked_at, last_verified_at,
				collect_tunnels, collect_analytics, create_graphs, discovery_interval, created_at, updated_at
			FROM plugin_cloudflare_accounts
			ORDER BY id'
		);

		return array(
			'plugin_cloudflare_accounts'        => $accounts,
			'plugin_cloudflare_tunnels'         => $this->db->fetchAll('SELECT * FROM plugin_cloudflare_tunnels ORDER BY id'),
			'plugin_cloudflare_connectors'      => $this->db->fetchAll('SELECT * FROM plugin_cloudflare_connectors ORDER BY id'),
			'plugin_cloudflare_zones'           => $this->db->fetchAll('SELECT * FROM plugin_cloudflare_zones ORDER BY account_id, zone_id'),
			'plugin_cloudflare_metric_mappings' => $this->db->fetchAll('SELECT * FROM plugin_cloudflare_metric_mappings ORDER BY metric_name'),
			'plugin_cloudflare_resources'       => $this->db->fetchAll('SELECT * FROM plugin_cloudflare_resources ORDER BY id'),
		);
	}

	/**
	 * @param array<string, mixed>                                                            $data
	 * @param callable(mixed, array<int, array<string, mixed>>, string, int): void|null       $replicator
	 * @return array<string, mixed>
	 */
	public function handle(array $data, ?callable $replicator = null): array {
		$pollerId = isset($data['remote_poller_id']) && is_numeric($data['remote_poller_id'])
			? (int) $data['remote_poller_id']
			: (isset($data['poller_id']) && is_numeric($data['poller_id']) ? (int) $data['poller_id'] : 0);
		$rcnnId = $data['rcnn_id'] ?? false;
		$class  = isset($data['class']) && is_string($data['class']) ? $data['class'] : 'all';

		if ($pollerId <= 1 || $rcnnId === false || ($class !== 'all' && $class !== 'plugins')) {
			return $data;
		}

		$payload = $this->payloadForPoller($pollerId);

		foreach ($payload as $table => $rows) {
			if ($replicator !== null) {
				$replicator($rcnnId, $rows, $table, $pollerId);
			} elseif (function_exists('replicate_out_table')) {
				replicate_out_table($rcnnId, $rows, $table, $pollerId);
			}
		}

		return $data;
	}
}
