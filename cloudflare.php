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

chdir('../../');
include('./include/auth.php');
include_once(__DIR__ . '/setup.php');

// Recheck the restored user's realm and plugin status before loading page data.
if (!cloudflare_page_allowed()) {
	raise_ajax_permission_denied();
	header('Location: ../../permission_denied.php');
	exit;
}

include_once(__DIR__ . '/includes/viewer.php');

set_default_action('overview');

/* No upgrade here: this page is in the View realm, and an upgrade migrates
 * the schema and writes outside the plugin directory. Plugins, Settings and
 * Cloudflare Accounts run it. */
try {
	if (get_filter_request_var('export') && in_array(get_request_var('action'), array('tunnels', 'connectors'), true)) {
		cloudflare_viewer_export((string) get_request_var('action'));
		exit;
	}

	top_header();

	switch (get_request_var('action')) {
		case 'analytics':
			cloudflare_viewer_tabs('analytics');
			cloudflare_viewer_analytics();

			break;
		case 'tunnels':
			cloudflare_viewer_tabs('tunnels');
			cloudflare_viewer_tunnels();

			break;
		case 'connectors':
			cloudflare_viewer_tabs('connectors');
			cloudflare_viewer_connectors();

			break;
		default:
			cloudflare_viewer_tabs('overview');
			cloudflare_viewer_overview();

			break;
	}

	bottom_footer();
} catch (Throwable $e) {
	cloudflare_page_failed('viewer', $e, cloudflare_viewer_unreadable_text());
}
