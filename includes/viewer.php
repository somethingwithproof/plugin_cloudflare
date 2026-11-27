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

use Somethingwithproof\Cloudflare\Account\Account;
use Somethingwithproof\Cloudflare\Account\AccountRepository;
use Somethingwithproof\Cloudflare\Discovery\TunnelRepository;
use Somethingwithproof\Cloudflare\Jobs\JobRepository;
use Somethingwithproof\Cloudflare\Storage\Database;
use Somethingwithproof\Cloudflare\Storage\DatabaseException;
use Somethingwithproof\Cloudflare\Support\SystemClock;

/*
 * Handlers for cloudflare.php. They read the plugin tables and nothing
 * else: the collector is the only code that talks to Cloudflare, and the
 * View realm has nothing to change, so there is no POST path to protect.
 */

/**
 * Viewer unreadable text.
 *
 * @return string
 */
function cloudflare_viewer_unreadable_text() : string {
	return __('The Cloudflare tunnels could not be read. See the Cacti log for details.', 'cloudflare');
}

/*
 * A read that throws would reach CactiShutdownHandler, which disables the
 * plugin, so a missing or broken schema is reported instead. Only a
 * DatabaseException's message is known to carry no bound values or driver
 * text; anything else is logged by class.
 */
/**
 * Viewer unreadable.
 *
 * @param  string    $context
 * @param  Throwable $e
 * @return void
 */
function cloudflare_viewer_unreadable(string $context, Throwable $e) : void {
	$detail = $e instanceof DatabaseException ? $e->getMessage() : get_class($e);

	cacti_log('CLOUDFLARE: viewer ' . $context . ' failed: ' . $detail, false, 'CLOUDFLARE');

	raise_message('cloudflare_unreadable', html_escape(cloudflare_viewer_unreadable_text()), MESSAGE_LEVEL_ERROR);
}

/**
 * Viewer time.
 *
 * @param  ?int   $timestamp
 * @return string
 */
function cloudflare_viewer_time(?int $timestamp) : string {
	return $timestamp === null ? __('Never', 'cloudflare') : date('Y-m-d H:i:s', $timestamp);
}

// A one-line box, for the cases where there is no table to draw.
/**
 * Viewer notice.
 *
 * @param  string $title
 * @param  string $text
 * @return void
 */
function cloudflare_viewer_notice(string $title, string $text) : void {
	html_start_box(html_escape($title), '100%', '', '3', 'center', '');

	print "<tr class='tableRow'><td><em>" . html_escape($text) . "</em></td></tr>\n";

	html_end_box();
}

// Same markup as thold_tabs() in thold_functions.php.
/**
 * Viewer tabs.
 *
 * @param  string $current
 * @return void
 */
function cloudflare_viewer_tabs(string $current) : void {
	global $config;

	$tabs = array(
		'overview'   => __('Overview', 'cloudflare'),
		'tunnels'    => __('Tunnels', 'cloudflare'),
		'connectors' => __('Connectors', 'cloudflare'),
		'analytics'  => __('Zone Analytics', 'cloudflare'),
	);

	print "<div class='tabs'><nav><ul>\n";

	foreach ($tabs as $tab => $label) {
		print "<li><a class='tab" . ($tab === $current ? " selected'" : "'") .
			" href='" . html_escape($config['url_path'] . 'plugins/cloudflare/cloudflare.php?action=' . $tab) . "'>" .
			html_escape($label) . "</a></li>\n";
	}

	print "</ul></nav></div>\n";
}

/**
 * Collection state comes first: while discovery keeps failing, the stored
 * tunnel statuses are only as fresh as the last success.
 *
 * @param array{
 *        last_status: string,
 *        last_finished_at: int|null,
 *        last_success_at: int|null,
 *        last_error_class: string|null,
 *        consecutive_failures: int
 *        }|null $job
 * @param array{
 *        active: int,
 *        unavailable: int,
 *        deleted: int,
 *        health: string|null
 *        }|null $summary
 */
function cloudflare_viewer_health(Account $account, ?array $job, ?array $summary) : string {
	if (!$account->isEnabled()) {
		return __('Disabled', 'cloudflare');
	}

	if (!$account->collectsTunnels()) {
		return __('Not collected', 'cloudflare');
	}

	if ($job !== null && $job['consecutive_failures'] > 0) {
		return __('Collection failing', 'cloudflare');
	}

	if ($summary === null && ($job === null || $job['last_status'] === 'never')) {
		return __('Not yet collected', 'cloudflare');
	}

	switch ($summary === null ? null : $summary['health']) {
		case 'down':
			return __('Down', 'cloudflare');
		case 'degraded':
			return __('Degraded', 'cloudflare');
		case 'healthy':
			return __('Healthy', 'cloudflare');
		case 'inactive':
			return __('Inactive', 'cloudflare');
		default:
			return __('No active tunnels', 'cloudflare');
	}
}

/**
 * Viewer overview.
 *
 * @return void
 */
function cloudflare_viewer_overview() : void {
	$accounts   = array();
	$summaries  = array();
	$jobs       = array();
	$unreadable = false;

	try {
		$db        = cloudflare_db();
		$accounts  = (new AccountRepository($db))->all();
		$summaries = (new TunnelRepository($db))->summary();
		$jobs      = (new JobRepository($db, new SystemClock()))->summaries(JobRepository::TYPE_DISCOVERY);
	} catch (DatabaseException $e) {
		cloudflare_viewer_unreadable('overview', $e);

		$accounts   = array();
		$unreadable = true;
	}

	cloudflare_viewer_overview_table($accounts, $summaries, $jobs, $unreadable);
}

/**
 * @param array<int, Account> $accounts
 * @param array<int, array{
 *        active: int,
 *        unavailable: int,
 *        deleted: int,
 *        health: string|null
 *        }> $summaries
 * @param array<int, array{
 *        last_status: string,
 *        last_finished_at: int|null,
 *        last_success_at: int|null,
 *        last_error_class: string|null,
 *        consecutive_failures: int
 *        }> $jobs
 */
function cloudflare_viewer_overview_table(array $accounts, array $summaries, array $jobs, bool $unreadable) : void {
	$display_text = array(
		array('display' => __('Account', 'cloudflare'), 'align' => 'left'),
		array(
			'display' => __('Status', 'cloudflare'),
			'align'   => 'left',
			'tip'     => __('The worst status among active tunnels, or Collection failing while discovery fails.', 'cloudflare')
		),
		array('display' => __('Active', 'cloudflare'), 'align' => 'right'),
		array('display' => __('Unavailable', 'cloudflare'), 'align' => 'right'),
		array('display' => __('Deleted', 'cloudflare'), 'align' => 'right'),
		array('display' => __('Last Successful Discovery', 'cloudflare'), 'align' => 'left'),
		array('display' => __('Consecutive Failures', 'cloudflare'), 'align' => 'right'),
	);

	html_start_box(__esc('Cloudflare Accounts', 'cloudflare'), '100%', '', '3', 'center', '');

	html_header($display_text);

	foreach ($accounts as $account) {
		$id      = $account->id();
		$job     = isset($jobs[$id]) ? $jobs[$id] : null;
		$summary = isset($summaries[$id]) ? $summaries[$id] : null;
		$title   = '';

		if ($job !== null && $job['consecutive_failures'] > 0 && $job['last_error_class'] !== null) {
			$title = html_escape(__('Last error: %s', $job['last_error_class'], 'cloudflare'));
		}

		form_alternate_row();
		form_selectable_cell(
			'<a class="linkEditMain" href="' .
				html_escape('cloudflare.php?action=tunnels&account_id=' . $id) .
				'">' .
				html_escape($account->name()) .
				'</a>',
			$id
		);
		form_selectable_cell(html_escape(cloudflare_viewer_health($account, $job, $summary)), $id, '', '', $title);

		foreach (array('active', 'unavailable', 'deleted') as $lifecycle) {
			form_selectable_cell((string) ($summary === null ? 0 : $summary[$lifecycle]), $id, '', 'right');
		}

		form_selectable_cell(html_escape(cloudflare_viewer_time($job === null ? null : $job['last_success_at'])), $id);
		form_selectable_cell((string) ($job === null ? 0 : $job['consecutive_failures']), $id, '', 'right');
		form_end_row();
	}

	if (!cacti_sizeof($accounts)) {
		/* top_header() has already printed this request's messages, so the
		 * failure is also shown in the table rather than only next time. */
		$empty = $unreadable ? cloudflare_viewer_unreadable_text() : __('No Cloudflare Accounts Found', 'cloudflare');

		print "<tr class='tableRow'><td colspan='" . cacti_sizeof($display_text) . "'><em>" .
			html_escape($empty) . "</em></td></tr>\n";
	}

	html_end_box();
}

/**
 * Keys are TunnelRepository::SORT_COLUMNS; Cacti treats a key containing
 * 'nosort' as not sortable.
 *
 * @return array<string, array<string, string>>
 */
function cloudflare_viewer_tunnel_columns(bool $all_accounts) : array {
	$columns = array(
		'name' => array('display' => __('Tunnel', 'cloudflare'), 'align' => 'left', 'sort' => 'ASC'),
	);

	if ($all_accounts) {
		$columns['nosort_account'] = array('display' => __('Account', 'cloudflare'), 'align' => 'left');
	}

	return $columns + array(
		'cf_status'        => array(
			'display' => __('Cloudflare Status', 'cloudflare'),
			'align'   => 'left',
			'sort'    => 'ASC',
			'tip'     => __('The status Cloudflare reported at the last discovery.', 'cloudflare')
		),
		'lifecycle'        => array(
			'display' => __('Lifecycle', 'cloudflare'),
			'align'   => 'left',
			'sort'    => 'ASC',
			'tip'     => __('Whether the last discoveries still found this tunnel.', 'cloudflare')
		),
		'tun_type'         => array('display' => __('Type', 'cloudflare'), 'align' => 'left', 'sort' => 'ASC'),
		'connector_count'  => array('display' => __('Connectors', 'cloudflare'), 'align' => 'right', 'sort' => 'DESC'),
		'connection_count' => array('display' => __('Connections', 'cloudflare'), 'align' => 'right', 'sort' => 'DESC'),
		'last_seen_at'     => array('display' => __('Last Seen', 'cloudflare'), 'align' => 'left', 'sort' => 'DESC'),
	);
}

/**
 * Validates the Tunnels filter, keeping it in the session the way Cacti's
 * own list pages do. Invalid input ends the request in
 * die_html_input_error(), so nothing unchecked reaches the repository.
 *
 * @return array{
 *         account_id: int,
 *         filters: array<string, string>,
 *         sort: string,
 *         direction: string,
 *         rows: int,
 *         page: int
 *         }
 */
function cloudflare_viewer_tunnel_request() : array {
	$any_of = function (array $values) : string {
		return '/^(' . implode('|', array_map(function (string $value) : string {
			return preg_quote($value, '/');
		}, $values)) . ')$/';
	};

	// ================= input validation and session storage =================
	$filters = array(
		'rows' => array(
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1'
		),
		'page' => array(
			'filter'  => FILTER_VALIDATE_INT,
			'default' => '1'
		),
		'account_id' => array(
			'filter'  => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '0',
			'options' => array('options' => array('min_range' => 0))
		),
		'lifecycle' => array(
			'filter'  => FILTER_VALIDATE_REGEXP,
			'pageset' => true,
			'default' => '',
			'options' => array('options' => array('regexp' => $any_of(TunnelRepository::LIFECYCLES)))
		),
		'cf_status' => array(
			'filter'  => FILTER_VALIDATE_REGEXP,
			'pageset' => true,
			'default' => '',
			'options' => array('options' => array('regexp' => $any_of(TunnelRepository::CF_STATUSES)))
		),
		'filter' => array(
			'filter'  => FILTER_CALLBACK,
			'pageset' => true,
			'default' => '',
			'options' => array('options' => 'sanitize_search_string')
		),
		'sort_column' => array(
			'filter'  => FILTER_VALIDATE_REGEXP,
			'default' => 'name',
			'options' => array('options' => array('regexp' => $any_of(TunnelRepository::SORT_COLUMNS)))
		),
		'sort_direction' => array(
			'filter'  => FILTER_VALIDATE_REGEXP,
			'default' => 'ASC',
			'options' => array('options' => array('regexp' => '/^(ASC|DESC)$/'))
		)
	);

	validate_store_request_vars($filters, 'sess_cf_tunnels');
	// ================= input validation =================

	$rows = (int) get_request_var('rows');

	if ($rows < 1) {
		$rows = max(1, (int) read_config_option('num_rows_table'));
	}

	/* validate_store_request_vars() passes '0' through without running the
	 * filter, so each value is checked against the repository's list again;
	 * one it would reject is dropped rather than thrown from the page. */
	$query_filters = array();
	$allowed       = array('lifecycle' => TunnelRepository::LIFECYCLES, 'cf_status' => TunnelRepository::CF_STATUSES);

	foreach ($allowed as $name => $values) {
		$value = (string) get_request_var($name);

		if (in_array($value, $values, true)) {
			$query_filters[$name] = $value;
		}
	}

	$search = (string) get_request_var('filter');

	if ($search !== '') {
		$query_filters['search'] = $search;
	}

	$sort = (string) get_request_var('sort_column');

	return array(
		'account_id' => (int) get_request_var('account_id'),
		'filters'    => $query_filters,
		'sort'       => in_array($sort, TunnelRepository::SORT_COLUMNS, true) ? $sort : 'name',
		'direction'  => get_request_var('sort_direction') === 'DESC' ? 'DESC' : 'ASC',
		'rows'       => $rows,
		'page'       => max(1, (int) get_request_var('page')),
	);
}

/**
 * @param array<int|string, string> $values value => label
 */
function cloudflare_viewer_select(string $id, string $label, array $values, string $current) : void {
	print "<td><label for='" . $id . "'>" . html_escape($label) . "</label></td>\n<td><select id='" . $id . "'>\n";

	foreach ($values as $value => $text) {
		print "<option value='" . html_escape((string) $value) . "'" .
			((string) $value === $current ? ' selected' : '') . '>' . html_escape($text) . "</option>\n";
	}

	print "</select></td>\n";
}

/**
 * @param array<int, string> $accounts id => name
 * @param array{
 *        account_id: int,
 *        filters: array<string, string>,
 *        sort: string,
 *        direction: string,
 *        rows: int,
 *        page: int
 *        } $query
 */
function cloudflare_viewer_tunnel_filter(array $accounts, array $query) : void {
	global $item_rows;

	$lifecycles = array('' => __('Any', 'cloudflare'));

	foreach (TunnelRepository::LIFECYCLES as $lifecycle) {
		$lifecycles[$lifecycle] = ucfirst($lifecycle);
	}

	$statuses = array('' => __('Any', 'cloudflare'));

	foreach (TunnelRepository::CF_STATUSES as $status) {
		$statuses[$status] = ucfirst($status);
	}

	$rows = array('-1' => __('Default', 'cloudflare'));

	if (is_array($item_rows)) {
		foreach ($item_rows as $key => $value) {
			$rows[$key] = (string) $value;
		}
	}

	html_start_box(__esc('Cloudflare Tunnels', 'cloudflare'), '100%', '', '3', 'center', '');

	print "<tr class='even'><td>\n<form id='form_cloudflare_tunnels' action='cloudflare.php'>\n" .
		"<table class='filterTable' role='presentation'><tr>\n";

	$choices = array(0 => __('All', 'cloudflare')) + $accounts;

	cloudflare_viewer_select('account_id', __('Account', 'cloudflare'), $choices, (string) $query['account_id']);
	$filters = $query['filters'];

	cloudflare_viewer_select('lifecycle', __('Lifecycle', 'cloudflare'), $lifecycles, $filters['lifecycle'] ?? '');
	cloudflare_viewer_select('cf_status', __('Status', 'cloudflare'), $statuses, $filters['cf_status'] ?? '');

	print "<td><label for='filter'>" . __esc('Search', 'cloudflare') . "</label></td>\n" .
		"<td><input type='text' class='ui-state-default ui-corner-all' id='filter' size='25' value='" .
		html_escape_request_var('filter') . "'></td>\n";

	cloudflare_viewer_select('rows', __('Tunnels', 'cloudflare'), $rows, (string) get_request_var('rows'));

	print "<td><span>\n" .
		"<input type='button' class='ui-button ui-corner-all ui-widget' id='refresh' value='" . __esc('Go', 'cloudflare') .
		"' title='" . __esc('Set/Refresh Filters', 'cloudflare') . "'>\n" .
		"<input type='button' class='ui-button ui-corner-all ui-widget' id='clear' value='" . __esc('Clear', 'cloudflare') .
		"' title='" . __esc('Clear Filters', 'cloudflare') . "'>\n" .
		"</span></td>\n</tr></table>\n</form>\n</td></tr>\n";

	html_end_box();
}

/**
 * Viewer tunnels.
 *
 * @return void
 */
function cloudflare_viewer_tunnels() : void {
	$query      = cloudflare_viewer_tunnel_request();
	$accounts   = array();
	$tunnels    = array();
	$total      = 0;
	$unreadable = false;

	try {
		$db = cloudflare_db();

		foreach ((new AccountRepository($db))->all() as $account) {
			$accounts[$account->id()] = $account->name();
		}

		$repository = new TunnelRepository($db);
		$total      = $repository->count($query['account_id'], $query['filters']);

		/* A page past the end, left in the session by a narrower filter,
		 * shows the last page; the clamp also keeps the offset in range. */
		$query['page'] = min($query['page'], max(1, (int) ceil($total / $query['rows'])));

		$tunnels = $repository->forAccount(
			$query['account_id'],
			$query['filters'],
			$query['rows'],
			$query['rows'] * ($query['page'] - 1),
			$query['sort'],
			$query['direction']
		);
	} catch (DatabaseException | InvalidArgumentException $e) {
		cloudflare_viewer_unreadable('tunnels', $e);

		$unreadable = true;
	}

	cloudflare_viewer_tunnel_filter($accounts, $query);
	cloudflare_viewer_export_link('tunnels', array(
		'account_id'     => $query['account_id'],
		'lifecycle'      => $query['filters']['lifecycle'] ?? '',
		'cf_status'      => $query['filters']['cf_status'] ?? '',
		'filter'         => $query['filters']['search'] ?? '',
		'sort_column'    => $query['sort'],
		'sort_direction' => $query['direction'],
	));

	$all_accounts = $query['account_id'] === 0;
	$columns      = cloudflare_viewer_tunnel_columns($all_accounts);

	$nav = html_nav_bar(
		'cloudflare.php?action=tunnels',
		MAX_DISPLAY_PAGES,
		$query['page'],
		$query['rows'],
		$total,
		cacti_sizeof($columns),
		__('Tunnels', 'cloudflare'),
		'page',
		'main'
	);

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	html_header_sort($columns, $query['sort'], $query['direction'], 1, 'cloudflare.php?action=tunnels');

	if (cacti_sizeof($tunnels)) {
		cloudflare_viewer_tunnel_table($tunnels, $accounts, $all_accounts);
	} else {
		$empty = $unreadable ? cloudflare_viewer_unreadable_text() : __('No Cloudflare Tunnels Found', 'cloudflare');

		print "<tr class='tableRow'><td colspan='" . cacti_sizeof($columns) . "'><em>" .
			html_escape($empty) . "</em></td></tr>\n";
	}

	html_end_box(false);

	if (cacti_sizeof($tunnels)) {
		print $nav;
	}

	cloudflare_viewer_script();
}

/**
 * @param mixed $value a nullable count column
 */
function cloudflare_viewer_count($value) : string {
	return $value === null ? '-' : (string) (int) $value;
}

/**
 * @param array<int, array<string, mixed>> $tunnels  repository rows
 * @param array<int, string>               $accounts id => name
 */
function cloudflare_viewer_tunnel_table(array $tunnels, array $accounts, bool $all_accounts) : void {
	foreach ($tunnels as $tunnel) {
		$id         = (int) $tunnel['id'];
		$account_id = (int) $tunnel['account_id'];
		$last_seen  = $tunnel['last_seen_at'] === null ? null : (int) $tunnel['last_seen_at'];

		form_alternate_row();
		form_selectable_cell(
			'<a class="linkEditMain" href="' .
				html_escape('cloudflare.php?action=connectors&tunnel_id=' . $id) .
				'">' .
				html_escape((string) $tunnel['name']) .
				'</a>',
			$id
		);

		if ($all_accounts) {
			$account = isset($accounts[$account_id]) ? $accounts[$account_id] : (string) $account_id;

			form_selectable_cell(html_escape($account), $id);
		}

		form_selectable_cell(html_escape((string) $tunnel['cf_status']), $id);
		$lifecycle = html_escape((string) $tunnel['lifecycle']);

		if ($tunnel['lifecycle'] === 'deleted' && empty($tunnel['retired_at']) && api_user_realm_auth('cloudflare_accounts.php') === true && api_user_realm_auth('host.php') === true) {
			$lifecycle .= " <a href='cloudflare_accounts.php?action=retire&amp;tunnel_id=" . $id . "'>" . __esc('Retire tunnel', 'cloudflare') . '</a>';
		}

		form_selectable_cell($lifecycle, $id);
		form_selectable_cell(html_escape((string) $tunnel['tun_type']), $id);
		// The Reconciler keeps the last counts on a tunnel that stops being active, so they are not current.
		$current = (string) $tunnel['lifecycle'] === 'active';
		form_selectable_cell(cloudflare_viewer_count($current ? $tunnel['connector_count'] : null), $id, '', 'right');
		form_selectable_cell(cloudflare_viewer_count($current ? $tunnel['connection_count'] : null), $id, '', 'right');
		form_selectable_cell(html_escape(cloudflare_viewer_time($last_seen)), $id);
		form_end_row();
	}
}

/**
 * Viewer connectors.
 *
 * @return void
 */
function cloudflare_viewer_connectors() : void {
	// ================= input validation =================
	get_filter_request_var('tunnel_id');
	// ====================================================

	$tunnel_id = (int) get_request_var('tunnel_id');
	$title     = __('Connectors', 'cloudflare');

	if ($tunnel_id < 1) {
		cloudflare_viewer_notice($title, __('Choose a tunnel on the Tunnels tab.', 'cloudflare'));

		return;
	}

	try {
		$repository = new TunnelRepository(cloudflare_db());
		$tunnel     = $repository->find($tunnel_id);
		$connectors = $tunnel === null ? array() : $repository->connectors($tunnel_id);
	} catch (DatabaseException $e) {
		cloudflare_viewer_unreadable('connectors', $e);
		cloudflare_viewer_notice($title, cloudflare_viewer_unreadable_text());

		return;
	}

	if ($tunnel === null) {
		cloudflare_viewer_notice($title, __('The tunnel no longer exists.', 'cloudflare'));

		return;
	}

	cloudflare_viewer_export_link('connectors', array('tunnel_id' => $tunnel_id));

	html_start_box(__esc('Connectors for %s', (string) $tunnel['name'], 'cloudflare'), '100%', '', '3', 'center', '');

	$display_text = array(
		array('display' => __('Connector ID', 'cloudflare'), 'align' => 'left'),
		array('display' => __('Version', 'cloudflare'), 'align' => 'left'),
		array('display' => __('Architecture', 'cloudflare'), 'align' => 'left'),
		array('display' => __('Running Since', 'cloudflare'), 'align' => 'left'),
		array('display' => __('Connections', 'cloudflare'), 'align' => 'right'),
		array('display' => __('Data Centers', 'cloudflare'), 'align' => 'left'),
		array('display' => __('Origin IPs', 'cloudflare'), 'align' => 'left'),
		array('display' => __('Last Seen', 'cloudflare'), 'align' => 'left'),
	);

	html_header($display_text);

	if (cacti_sizeof($connectors)) {
		if ((string) $tunnel['lifecycle'] !== 'active') {
			print "<tr class='tableRow'><td colspan='" . cacti_sizeof($display_text) . "'><em>" .
				__esc(
					'This tunnel is %s. These connectors were last seen while the tunnel was active.',
					(string) $tunnel['lifecycle'],
					'cloudflare'
				) . "</em></td></tr>\n";
		}

		cloudflare_viewer_connector_table($connectors);
	} else {
		print "<tr class='tableRow'><td colspan='" . cacti_sizeof($display_text) . "'><em>" .
			__esc('No connectors are running for this tunnel.', 'cloudflare') . "</em></td></tr>\n";
	}

	html_end_box();
}

/**
 * @param array<int, array<string, mixed>> $connectors repository rows
 */
function cloudflare_viewer_connector_table(array $connectors) : void {
	foreach ($connectors as $connector) {
		$id     = (int) $connector['id'];
		$run_at = $connector['run_at'] === null ? null : (int) $connector['run_at'];

		form_alternate_row();
		form_selectable_cell(html_escape((string) $connector['cf_connector_id']), $id);
		form_selectable_cell(html_escape((string) $connector['version']), $id);
		form_selectable_cell(html_escape((string) $connector['arch']), $id);
		form_selectable_cell(html_escape(cloudflare_viewer_time($run_at)), $id);
		form_selectable_cell(cloudflare_viewer_count($connector['connection_count']), $id, '', 'right');
		form_selectable_cell(html_escape((string) $connector['colos']), $id);
		form_selectable_cell(html_escape((string) $connector['origin_ips']), $id);
		form_selectable_cell(html_escape(cloudflare_viewer_time((int) $connector['last_seen_at'])), $id);
		form_end_row();
	}
}

/*
 * Behaviour lives in a static file rather than inline script, so it runs
 * under every CSP mode; the nonce is added where Cacti provides one.
 */
/**
 * Viewer script.
 *
 * @return void
 */
function cloudflare_viewer_script() : void {
	global $config;

	$nonce = class_exists('CactiSecureHeaders') ? ' ' . CactiSecureHeaders::getNonceAttribute() : '';

	print "<script type='text/javascript' src='" .
		html_escape($config['url_path'] . 'plugins/cloudflare/js/cloudflare_viewer.js') .
		"'" .
		$nonce .
		"></script>\n";
}

/** @param array<string, int|string> $filters */
function cloudflare_viewer_export_link(string $view, array $filters) : void {
	$url = 'cloudflare.php?' . http_build_query(array('action' => $view, 'export' => 1) + $filters, '', '&', PHP_QUERY_RFC3986);

	print "<p><a class='ui-button ui-corner-all ui-widget' href='" . html_escape($url) . "'>" . __esc('Export') . "</a></p>\n";
}

/** @param resource $stream
 * @param array<int, mixed> $values
 */
function cloudflare_viewer_csv_row($stream, array $values) : void {
	$cells = array_map(function ($value) : string {
		$cell = $value === null ? '' : (string) $value;

		// Spreadsheet importers can ignore leading controls before a formula.
		return preg_match('/^(?:[\x00-\x20]*[=+@-]|[\t\r\n])/', $cell) === 1 ? "'" . $cell : $cell;
	}, $values);

	if (fputcsv($stream, $cells, ',', '"', '') === false) {
		throw new RuntimeException('CSV write failed');
	}
}

/**
 * Build the download before sending headers so a failed read never looks
 * like a successful, truncated inventory. tmpfile bounds memory use and is
 * removed automatically on every path. Reads use the page's validated filters.
 */
/** @return resource */
function cloudflare_viewer_export_file(string $view, ?Database $db = null) {
	$stream = tmpfile();

	if ($stream === false) {
		throw new RuntimeException('CSV temporary file unavailable');
	}

	try {
		$db         = $db ?? cloudflare_db();

		// One snapshot, so concurrent renames cannot shift rows between batches.
		$db->snapshot(function () use ($db, $stream, $view) : void {
			$repository = new TunnelRepository($db);
			$accounts   = array();

			foreach ((new AccountRepository($db))->all() as $account) {
				$accounts[$account->id()] = $account->name();
			}

			if ($view === 'connectors') {
				get_filter_request_var('tunnel_id');
				$tunnel = $repository->find((int) get_request_var('tunnel_id'));
				cloudflare_viewer_csv_row($stream, array('account', 'tunnel_id', 'tunnel', 'lifecycle', 'connector_id', 'version', 'architecture', 'data_centers', 'origin_ips', 'connections', 'last_seen_at'));

				foreach ($tunnel === null ? array() : $repository->connectors((int) $tunnel['id']) as $connector) {
					cloudflare_viewer_csv_row($stream, array($accounts[(int) $tunnel['account_id']] ?? '', $tunnel['cf_tunnel_id'], $tunnel['name'], $tunnel['lifecycle'], $connector['cf_connector_id'], $connector['version'], $connector['arch'], $connector['colos'], $connector['origin_ips'], $connector['connection_count'], $connector['last_seen_at']));
				}
			} else {
				$query = cloudflare_viewer_tunnel_request();
				cloudflare_viewer_csv_row($stream, array('account', 'tunnel_id', 'name', 'lifecycle', 'status', 'connectors', 'connections', 'last_connection_read'));
				$offset = 0;

				do {
					$tunnels = $repository->forAccount($query['account_id'], $query['filters'], 500, $offset, $query['sort'], $query['direction']);

					foreach ($tunnels as $tunnel) {
						$active = $tunnel['lifecycle'] === 'active';
						cloudflare_viewer_csv_row($stream, array($accounts[(int) $tunnel['account_id']] ?? '', $tunnel['cf_tunnel_id'], $tunnel['name'], $tunnel['lifecycle'], $tunnel['cf_status'], $active ? $tunnel['connector_count'] : null, $active ? $tunnel['connection_count'] : null, $tunnel['connections_refreshed_at']));
					}

					$offset += 500;
				} while (count($tunnels) === 500);
			}
		});

		rewind($stream);

		return $stream;
	} catch (Throwable $e) {
		fclose($stream);

		throw $e;
	}
}

/**
 * Viewer export.
 *
 * @param  string $view
 * @return void
 */
function cloudflare_viewer_export(string $view) : void {
	$stream = cloudflare_viewer_export_file($view);

	try {
		header('Content-Type: text/csv; charset=UTF-8');
		header('Content-Disposition: attachment; filename="cloudflare-' . $view . '.csv"');
		header('Cache-Control: no-store');
		header('X-Content-Type-Options: nosniff');
		fpassthru($stream);
	} finally {
		fclose($stream);
	}
}

/**
 * Viewer analytics.
 *
 * @return void
 */
function cloudflare_viewer_analytics() : void {
	$rows   = cloudflare_db()->fetchAll("SELECT z.*, a.name AS account_name, r.host_id FROM plugin_cloudflare_zones z JOIN plugin_cloudflare_accounts a ON a.id = z.account_id LEFT JOIN plugin_cloudflare_resources r ON r.account_id = a.id AND r.kind = 'device' WHERE z.listed = 1 ORDER BY a.name, z.name LIMIT 200");
	$reader = new \Somethingwithproof\Cloudflare\Metrics\ZoneReader(cloudflare_db(), new SystemClock());
	html_start_box(__esc('Zone Analytics', 'cloudflare'), '100%', '', '3', 'center', '');
	html_header(array(__('Account', 'cloudflare'), __('Zone', 'cloudflare'), __('Status', 'cloudflare'), __('Requests/s', 'cloudflare'), __('Bytes/s', 'cloudflare'), __('HTTP 5xx (%)', 'cloudflare'), __('Last Seen', 'cloudflare')));

	foreach ($rows as $row) {
		$id = (int) $row['account_id'];
		form_alternate_row();
		form_selectable_cell(html_escape((string) $row['account_name']), $id);
		form_selectable_cell(html_escape((string) $row['name']), $id);
		form_selectable_cell(html_escape((string) $row['status']), $id);

		foreach (array('requests', 'bytes', 'errorPct') as $field) {
			form_selectable_cell(html_escape($reader->value((int) $row['host_id'], (string) $row['zone_id'], $field)), $id);
		}

		form_selectable_cell(html_escape(cloudflare_viewer_time($row['sampled_at'] === null ? null : (int) $row['sampled_at'])), $id);
		form_end_row();
	}

	html_end_box();
}
