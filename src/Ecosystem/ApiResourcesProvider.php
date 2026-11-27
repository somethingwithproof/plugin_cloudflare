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

	/**
	 * Return Google AIP-style plain-array resource descriptors for accounts,
	 * tunnels, and zones.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function descriptors(): array {
		return array(
			'cloudflare.accounts' => array(
				'type'       => 'cloudflare.cacti.net/Account',
				'collection' => 'cloudflare/accounts',
				'singular'   => 'cloudflareAccount',
				'plural'     => 'cloudflareAccounts',
				'pattern'    => 'cloudflare/accounts/{account}',
				'methods'    => array('list', 'get'),
				'read_only'  => true,
				'auth_realm' => 'cloudflare_accounts.php',
				'table'      => 'plugin_cloudflare_accounts',
				'primary_key' => 'id',
				'fields'     => array(
					'id',
					'cf_account_id',
					'name',
					'token_source',
					'enabled',
					'status',
					'status_message',
					'collect_tunnels',
					'collect_analytics',
					'create_graphs',
					'discovery_interval',
					'last_checked_at',
					'last_verified_at',
					'created_at',
					'updated_at',
				),
				'redacted_fields' => array('token_ciphertext', 'token_file'),
			),
			'cloudflare.tunnels' => array(
				'type'        => 'cloudflare.cacti.net/Tunnel',
				'collection'  => 'cloudflare/tunnels',
				'singular'    => 'cloudflareTunnel',
				'plural'      => 'cloudflareTunnels',
				'pattern'     => 'cloudflare/accounts/{account}/tunnels/{tunnel}',
				'parent'      => 'cloudflare/accounts/{account}',
				'methods'     => array('list', 'get'),
				'read_only'   => true,
				'auth_realm'  => 'cloudflare.php',
				'table'       => 'plugin_cloudflare_tunnels',
				'primary_key' => 'id',
				'fields'      => array(
					'id',
					'account_id',
					'cf_tunnel_id',
					'name',
					'lifecycle',
					'cf_status',
					'tun_type',
					'connector_count',
					'connection_count',
					'last_seen_at',
				),
				'redacted_fields' => array(),
			),
			'cloudflare.zones' => array(
				'type'        => 'cloudflare.cacti.net/Zone',
				'collection'  => 'cloudflare/zones',
				'singular'    => 'cloudflareZone',
				'plural'      => 'cloudflareZones',
				'pattern'     => 'cloudflare/accounts/{account}/zones/{zone}',
				'parent'      => 'cloudflare/accounts/{account}',
				'methods'     => array('list', 'get'),
				'read_only'   => true,
				'auth_realm'  => 'cloudflare.php',
				'table'       => 'plugin_cloudflare_zones',
				'primary_key' => 'zone_id',
				'fields'      => array(
					'account_id',
					'zone_id',
					'name',
					'listed',
					'status',
					'sampled_at',
					'window_start',
					'window_end',
					'requests',
					'bytes',
					'errors',
					'sample_interval',
				),
				'redacted_fields' => array(),
			),
		);
	}

	/**
	 * Register resource descriptors into an array or registrar object.
	 *
	 * @param mixed $resources Existing resource descriptor array or registrar object
	 * @return mixed Updated resource descriptor array or registrar object
	 */
	public function register($resources) {
		$descriptors = $this->descriptors();

		if (is_array($resources)) {
			foreach ($descriptors as $key => $descriptor) {
				$resources[$key] = $descriptor;
			}

			return $resources;
		}

		if (is_object($resources) && method_exists($resources, 'register')) {
			foreach ($descriptors as $key => $descriptor) {
				$resources->register($key, $descriptor);
			}
		}

		return $resources;
	}
}
