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

use Somethingwithproof\Cloudflare\OperatorPage;

chdir('../../');
include('./include/auth.php');
include_once(__DIR__ . '/setup.php');

if (!cloudflare_page_allowed()) {
	http_response_code(403);
	exit;
}
require_once(__DIR__ . '/includes/operator.php');

$user    = (int) ($_SESSION['sess_user_id'] ?? 0);
$allowed = $user > 0 && api_plugin_user_realm_auth('cloudflare_operator.php');

if (!$allowed) {
	http_response_code(403);
	exit;
}
// No operator actions beyond acknowledge exist yet; the page and the action check accept extras later.
$actions = array();
$action  = (string) get_nfilter_request_var('action');
$session = $_SESSION['cloudflare_triage'] ?? array();
$session = is_array($session) ? $session : array();

try {
	// Re-read on POST too: stale forms never act on observations that have since changed. The list is account-wide, like the View realm.
	$rows = cloudflare_operator_rows();
	$now  = time();

	if ($action !== '' && $action !== 'default') {
		$method = $_SERVER['REQUEST_METHOD'] ?? '';
		$status = OperatorPage::actionStatus($allowed, $action, $method, $method === 'POST' && csrf_check(false), array_merge(array('acknowledge'), array_keys($actions)));

		if ($status !== 200) {
			http_response_code($status);

			if ($status === 405) {
				header('Allow: POST');
			}
			exit;
		}
		$id  = (string) get_nfilter_request_var('id');
		$row = null;

		foreach ($rows as $candidate) {
			if ($candidate['id'] === $id) {
				$row = $candidate;

				break;
			}
		}

		if ($row === null) {
			http_response_code(403);
			exit;
		}
		$fingerprint = (string) get_nfilter_request_var('fingerprint');

		if (!hash_equals(OperatorPage::fingerprint($row), $fingerprint)) {
			http_response_code(409);
			exit;
		}

		if ($action === 'acknowledge') {
			if (!OperatorPage::acknowledge($session, $row, $fingerprint, $user, $now)) {
				http_response_code(409);
				exit;
			}
			$_SESSION['cloudflare_triage'] = $session;
		}
		cacti_log('Operator ' . $action . ' by user ' . $user . ' on object ' . hash('sha256', $id), false, 'CLOUDFLARE');
		header('Location: cloudflare_operator.php');
		exit;
	}
	top_header();
	print '<p><a href="cloudflare.php">' . html_escape(__('Monitoring view', 'cloudflare')) . '</a></p>';
	OperatorPage::render($rows, $session, $user, $now, $actions);
	bottom_footer();
} catch (Throwable $error) {
	http_response_code(503);
	cloudflare_page_failed('operator', $error, __('Observations could not be read. Ask an administrator to check the plugin log.', 'cloudflare'));
}
