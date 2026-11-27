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

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/accounts.php';

use Somethingwithproof\Cloudflare\Account\AccountRepository;
use Somethingwithproof\Cloudflare\Config\ConfigService;
use Somethingwithproof\Cloudflare\Discovery\RemoteReplication;
use Somethingwithproof\Cloudflare\Discovery\TunnelRepository;
use Somethingwithproof\Cloudflare\Ecosystem\AbilitiesProvider;
use Somethingwithproof\Cloudflare\Ecosystem\ApiResourcesProvider;
use Somethingwithproof\Cloudflare\Ecosystem\CascProvider;
use Somethingwithproof\Cloudflare\Ecosystem\DoctorChecks;
use Somethingwithproof\Cloudflare\Ecosystem\DoctorDebugInfo;
use Somethingwithproof\Cloudflare\Metrics\MetricMappingRepository;
use Somethingwithproof\Cloudflare\Storage\Database;
use Somethingwithproof\Cloudflare\Storage\DatabaseException;
use Somethingwithproof\Cloudflare\Support\SystemClock;

/**
 * Construct the CasC configuration service wired to the Cacti database.
 *
 * @return ConfigService
 */
function cloudflare_config_service() : ConfigService {
	$db = cloudflare_db();

	return new ConfigService(
		$db,
		new AccountRepository($db),
		new TunnelRepository($db),
		new MetricMappingRepository($db),
		new SystemClock()
	);
}

/**
 * Construct the Doctor health check runner for vault key, token dir, tunnels, and jobs.
 *
 * @return DoctorChecks
 */
function cloudflare_doctor_checks() : DoctorChecks {
	$db      = cloudflare_db();
	$keyFile = trim((string) read_config_option('cloudflare_key_file'));

	if ($keyFile === '') {
		$keyFile = CLOUDFLARE_DEFAULT_KEY_FILE;
	}

	return new DoctorChecks(
		$db,
		new AccountRepository($db),
		new SystemClock(),
		$keyFile,
		cloudflare_token_directory()
	);
}

/**
 * Construct the redacted Doctor debug information provider.
 *
 * @return DoctorDebugInfo
 */
function cloudflare_doctor_debug_info() : DoctorDebugInfo {
	$db      = cloudflare_db();
	$keyFile = trim((string) read_config_option('cloudflare_key_file'));

	if ($keyFile === '') {
		$keyFile = CLOUDFLARE_DEFAULT_KEY_FILE;
	}

	return new DoctorDebugInfo(
		$db,
		new AccountRepository($db),
		$keyFile,
		cloudflare_token_directory()
	);
}

/**
 * Evaluate View realm access for $userId directly against Cacti's auth tables
 * (never reading session state), supporting both direct and group grants.
 */
function cloudflare_user_has_view_realm(Database $db, int $userId) : bool {
	if ($userId < 1) {
		return false;
	}

	try {
		$enabled = $db->fetchCell("SELECT enabled FROM user_auth WHERE id = ? AND enabled = 'on'", array($userId));

		if ($enabled !== 'on') {
			return false;
		}

		$direct = (int) $db->fetchCell(
			"SELECT COUNT(*)
			FROM user_auth_realm AS uar
			INNER JOIN plugin_realms AS pr ON pr.id + 100 = uar.realm_id
			WHERE uar.user_id = ? AND pr.plugin = 'cloudflare'
				AND pr.file IN ('cloudflare.php', 'cloudflare_operator.php', 'cloudflare_accounts.php')",
			array($userId)
		);

		if ($direct > 0) {
			return true;
		}

		$group = (int) $db->fetchCell(
			"SELECT COUNT(*)
			FROM user_auth_group_members AS uagm
			INNER JOIN user_auth_group AS uag ON uag.id = uagm.group_id AND uag.enabled = 'on'
			INNER JOIN user_auth_group_realm AS uagr ON uagr.group_id = uag.id
			INNER JOIN plugin_realms AS pr ON pr.id + 100 = uagr.realm_id
			WHERE uagm.user_id = ? AND pr.plugin = 'cloudflare'
				AND pr.file IN ('cloudflare.php', 'cloudflare_operator.php', 'cloudflare_accounts.php')",
			array($userId)
		);

		return $group > 0;
	} catch (DatabaseException $e) {
		return false;
	}
}

/**
 * abilities_register hook: register read-only cloudflare-list-tunnels and
 * cloudflare-get-health abilities gated on the Cloudflare View realm.
 *
 * @param mixed $registrar
 */
function cloudflare_abilities_register($registrar) : void {
	if (!cloudflare_plugin_enabled() || !is_object($registrar)) {
		return;
	}

	try {
		$db       = cloudflare_db();
		$provider = new AbilitiesProvider(
			$db,
			new AccountRepository($db),
			new TunnelRepository($db),
			new SystemClock(),
			function (int $userId) use ($db) : bool {
				return cloudflare_user_has_view_realm($db, $userId);
			}
		);

		$provider->register($registrar);
	} catch (Throwable $e) {
		cacti_log('CLOUDFLARE: abilities registration failed (' . get_class($e) . ')', false, 'CLOUDFLARE');
	}
}

/**
 * doctor_register_checks hook: report vault key permissions, token dir hygiene,
 * tunnel freshness, and API collector failure rate.
 *
 * @param  mixed                            $context
 * @return array<int, array<string, mixed>>
 */
function cloudflare_doctor_register_checks($context = array()) : array {
	if (!cloudflare_plugin_enabled()) {
		return array();
	}

	try {
		return cloudflare_doctor_checks()->register($context);
	} catch (Throwable $e) {
		cacti_log('CLOUDFLARE: doctor checks failed (' . get_class($e) . ')', false, 'CLOUDFLARE');

		return array();
	}
}

/**
 * casc_register_providers hook: register the Cloudflare CasC provider for
 * accounts (secret references only), zones, tunnels, and metric mappings.
 *
 * @param  mixed $providers
 * @return mixed
 */
function cloudflare_casc_register_providers($providers = null) {
	if (!cloudflare_plugin_enabled()) {
		return is_array($providers) ? $providers : array();
	}

	try {
		return (new CascProvider(cloudflare_config_service()))->register($providers);
	} catch (Throwable $e) {
		cacti_log('CLOUDFLARE: CasC provider registration failed (' . get_class($e) . ')', false, 'CLOUDFLARE');

		return is_array($providers) ? $providers : array();
	}
}

/**
 * doctor_register_debug_info hook: contribute a redacted plain-array diagnostic
 * snapshot with zero cross-plugin imports and no exposed secrets.
 *
 * @param  mixed                            $context
 * @return array<int, array<string, mixed>>
 */
function cloudflare_doctor_register_debug_info($context = array()) : array {
	if (!cloudflare_plugin_enabled()) {
		return array();
	}

	try {
		return cloudflare_doctor_debug_info()->register($context);
	} catch (Throwable $e) {
		cacti_log('CLOUDFLARE: doctor debug info failed (' . get_class($e) . ')', false, 'CLOUDFLARE');

		return array();
	}
}

/**
 * api_register_resources hook: publish read-only Google AIP plain-array
 * resource descriptors with zero cross-plugin imports.
 *
 * @param  mixed                            $resources
 * @return array<int, array<string, mixed>>
 */
function cloudflare_api_register_resources($resources = array()) : array {
	if (!cloudflare_plugin_enabled()) {
		return is_array($resources) ? array_values(array_filter($resources, 'is_array')) : array();
	}

	try {
		return (new ApiResourcesProvider(cloudflare_db()))->register($resources);
	} catch (Throwable $e) {
		cacti_log('CLOUDFLARE: API resource registration failed (' . get_class($e) . ')', false, 'CLOUDFLARE');

		return is_array($resources) ? array_values(array_filter($resources, 'is_array')) : array();
	}
}

/**
 * replicate_out hook: sync non-secret Cloudflare metadata tables to remote
 * data collectors, excluding raw vault key material and token ciphertexts.
 *
 * @param  mixed $data
 * @return mixed
 */
function cloudflare_replicate_out($data) {
	if (!cloudflare_plugin_enabled() || !is_array($data)) {
		return $data;
	}

	try {
		return (new RemoteReplication(cloudflare_db()))->handle($data);
	} catch (Throwable $e) {
		cacti_log('CLOUDFLARE: remote replication failed (' . get_class($e) . ')', false, 'CLOUDFLARE');

		return $data;
	}
}
