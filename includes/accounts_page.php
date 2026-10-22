<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 Thomas Vincent                                       |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cloudflare plugin for Cacti                                             |
 | https://github.com/somethingwithproof/plugin_cloudflare                 |
 +-------------------------------------------------------------------------+
*/

require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/cacti_objects.php';

use Cacti\PluginCloudflare\Account\Account;
use Cacti\PluginCloudflare\Account\AccountService;
use Cacti\PluginCloudflare\Account\ValidationException;
use Cacti\PluginCloudflare\Auth\AccessDenied;
use Cacti\PluginCloudflare\Storage\DatabaseException;

/*
 * Handlers for cloudflare_accounts.php. A handler that ends in a redirect
 * returns the query string to append and prints nothing; the page sends the
 * Location header and exits, so the handlers can run under test.
 */

/**
 * @return array<int, string>
 */
function cloudflare_account_actions() : array {
	return array(
		1 => __('Delete', 'cloudflare'),
		2 => __('Verify', 'cloudflare'),
		3 => __('Enable', 'cloudflare'),
		4 => __('Disable', 'cloudflare'),
		5 => __('Create Cacti Device', 'cloudflare')
	);
}

/*
 * csrf-magic only checks POST bodies, so a mutation reachable by GET would
 * bypass it. Both mutating actions send any other method back to the list
 * before reading input.
 */
function cloudflare_request_is_post() : bool {
	return isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
}

function cloudflare_accounts_denied() : void {
	raise_message(
		'cloudflare_denied',
		__esc('You are not permitted to change Cloudflare accounts.', 'cloudflare'),
		MESSAGE_LEVEL_ERROR
	);
}

function cloudflare_accounts_missing() : void {
	raise_message(
		'cloudflare_missing',
		__esc('The Cloudflare account no longer exists.', 'cloudflare'),
		MESSAGE_LEVEL_ERROR
	);
}

function cloudflare_accounts_unreadable_text() : string {
	return __('The Cloudflare accounts could not be read. See the Cacti log for details.', 'cloudflare');
}

/*
 * A read that throws would reach CactiShutdownHandler, which disables the
 * plugin, so the read paths report a missing or broken schema instead.
 */
function cloudflare_accounts_unreadable(string $context, DatabaseException $e) : void {
	cacti_log('CLOUDFLARE: account ' . $context . ' failed: ' . $e->getMessage(), false, 'CLOUDFLARE');

	raise_message(2, html_escape(cloudflare_accounts_unreadable_text()), MESSAGE_LEVEL_ERROR);
}

/**
 * @throws DatabaseException
 */
function cloudflare_accounts_find(AccountService $service, int $id) : ?Account {
	foreach ($service->list() as $account) {
		if ($account->id() === $id) {
			return $account;
		}
	}

	return null;
}

/**
 * Marks the failed fields and keeps what the user typed, except the token,
 * which never enters the session.
 *
 * @param array<string, string> $errors
 */
function cloudflare_accounts_keep_form(array $errors) : void {
	foreach (array_keys($errors) as $field) {
		$_SESSION['sess_error_fields'][$field] = $field;
	}

	foreach (array('name', 'cf_account_id', 'token_source', 'token_file', 'discovery_interval') as $field) {
		$_SESSION['sess_field_values'][$field] = (string) get_nfilter_request_var($field);
	}

	raise_message(
		'cloudflare_invalid',
		implode('<br>', array_map('html_escape', array_values($errors))),
		MESSAGE_LEVEL_ERROR
	);
}

/**
 * @return string the query string to append to the redirect back to the page
 */
function cloudflare_accounts_save() : string {
	if (!cloudflare_request_is_post()) {
		return '';
	}

	// ================= input validation =================
	get_filter_request_var('id');
	// ====================================================

	if (!isset_request_var('save_component_account')) {
		return '';
	}

	$id = (int) get_request_var('id');

	try {
		$service = cloudflare_account_service();
		$id      = $service->save($id > 0 ? $id : null, cloudflare_account_graphs_guard($service, $id, cloudflare_account_input()));

		raise_message(1);
	} catch (ValidationException $e) {
		cloudflare_accounts_keep_form($e->getErrors());
	} catch (AccessDenied $e) {
		cloudflare_accounts_denied();

		return '';
	} catch (OutOfBoundsException $e) {
		cloudflare_accounts_missing();

		return '';
	} catch (DatabaseException $e) {
		cacti_log('CLOUDFLARE: account save failed: ' . $e->getMessage(), false, 'CLOUDFLARE');

		raise_message(2);
	}

	return '&action=edit&id=' . $id;
}

/**
 * Turning create_graphs on lets the plugin create Cacti graphs, so it also
 * needs the host.php realm. Without it the field is left out and the
 * service keeps the stored value; turning it off needs no more than the
 * Configure realm.
 *
 * @param  array<string, mixed> $input
 * @return array<string, mixed>
 *
 * @throws DatabaseException
 */
function cloudflare_account_graphs_guard(AccountService $service, int $id, array $input) : array {
	if ($input['create_graphs'] !== 1 || api_user_realm_auth('host.php') === true) {
		return $input;
	}

	unset($input['create_graphs']);

	$account = $id > 0 ? cloudflare_accounts_find($service, $id) : null;

	if ($account === null || !$account->createsGraphs()) {
		raise_message(
			'cloudflare_graphs_denied',
			__esc('Create Graphs was left off: turning it on needs the permission to edit Cacti devices.', 'cloudflare'),
			MESSAGE_LEVEL_WARN
		);
	}

	return $input;
}

/**
 * @return array<string, string> message for each result of cloudflare_create_device()
 */
function cloudflare_accounts_device_results() : array {
	return array(
		'created'           => __('Cacti device created', 'cloudflare'),
		'exists'            => __('A Cacti device already exists for this account', 'cloudflare'),
		'templates_missing' => __('The Cloudflare templates are not installed; see the Cacti log', 'cloudflare'),
		'denied'            => __('You are not permitted to create Cacti devices', 'cloudflare'),
		'failed'            => __('The Cacti device could not be created; see the Cacti log', 'cloudflare'),
	);
}

/**
 * Both realms are checked before anything else, so a denied request makes
 * no Cacti call; cloudflare_create_device() checks them again itself.
 *
 * @param array<int, int> $ids
 *
 * @throws DatabaseException
 */
function cloudflare_accounts_create_devices(AccountService $service, array $ids) : void {
	if (!cloudflare_device_permitted()) {
		raise_message(
			'cloudflare_device_denied',
			__esc('You are not permitted to create Cacti devices.', 'cloudflare'),
			MESSAGE_LEVEL_ERROR
		);

		return;
	}

	$accounts = array();

	foreach ($service->list() as $account) {
		$accounts[$account->id()] = $account;
	}

	$results = cloudflare_accounts_device_results();
	$lines   = array();
	$level   = MESSAGE_LEVEL_INFO;

	foreach ($ids as $id) {
		if (!isset($accounts[$id])) {
			continue;
		}

		$result  = cloudflare_create_device($accounts[$id]);
		$lines[] = html_escape($accounts[$id]->name() . ': ' . $results[$result]);

		if ($result !== 'created' && $result !== 'exists') {
			$level = MESSAGE_LEVEL_ERROR;
		}
	}

	if (cacti_sizeof($lines)) {
		raise_message('cloudflare_device', implode('<br>', $lines), $level);
	}
}

/**
 * @param array<int, int> $ids
 */
function cloudflare_accounts_execute(int $action, array $ids) : void {
	$service  = cloudflare_account_service();
	$verified = array();

	if ($action === 5) {
		try {
			cloudflare_accounts_create_devices($service, $ids);
		} catch (DatabaseException $e) {
			cacti_log('CLOUDFLARE: account action 5 failed: ' . $e->getMessage(), false, 'CLOUDFLARE');

			raise_message(2);
		}

		return;
	}

	try {
		foreach ($ids as $id) {
			try {
				switch ($action) {
					case 1:
						$service->delete($id);

						break;
					case 2:
						$verified[] = $service->verify($id);

						break;
					case 3:
					case 4:
						$service->setEnabled($id, $action === 3);

						break;
				}
			} catch (OutOfBoundsException $e) {
				// Removed by someone else since the list was drawn.
			}
		}
	} catch (AccessDenied $e) {
		cloudflare_accounts_denied();

		return;
	} catch (DatabaseException $e) {
		cacti_log('CLOUDFLARE: account action ' . $action . ' failed: ' . $e->getMessage(), false, 'CLOUDFLARE');

		raise_message(2);

		return;
	}

	if ($action !== 2) {
		raise_message(1);

		return;
	}

	$lines = array();
	$level = MESSAGE_LEVEL_INFO;

	foreach ($verified as $account) {
		$lines[] = html_escape($account->name() . ': ' . $account->statusMessage());

		if ($account->status() !== 'ok') {
			$level = MESSAGE_LEVEL_WARN;
		}
	}

	if (cacti_sizeof($lines)) {
		raise_message('cloudflare_verify', implode('<br>', $lines), $level);
	}
}

/**
 * @return string|null the redirect query string, or null once the
 *                     confirmation page has been printed
 */
function cloudflare_accounts_actions() : ?string {
	if (!cloudflare_request_is_post()) {
		return '';
	}

	$cloudflare_account_actions = cloudflare_account_actions();

	// ================= input validation =================
	get_filter_request_var('drp_action');
	// ====================================================

	$action = (int) get_request_var('drp_action');

	if (!isset($cloudflare_account_actions[$action])) {
		return '';
	}

	if (isset_request_var('selected_items')) {
		$selected = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

		if ($selected !== false) {
			cloudflare_accounts_execute($action, array_map('intval', $selected));
		}

		return '';
	}

	$accounts = array();

	try {
		foreach (cloudflare_account_service()->list() as $account) {
			$accounts[$account->id()] = $account;
		}
	} catch (DatabaseException $e) {
		cloudflare_accounts_unreadable('confirmation', $e);

		return '';
	}

	$ids  = array();
	$list = '';

	foreach ($_POST as $var => $val) {
		if (preg_match('/^chk_([0-9]+)$/', (string) $var, $matches) && isset($accounts[(int) $matches[1]])) {
			$ids[] = (int) $matches[1];
			$list .= '<li>' . html_escape($accounts[(int) $matches[1]]->name()) . '</li>';
		}
	}

	if (!cacti_sizeof($ids)) {
		raise_message(40);

		return '';
	}

	$prompts = array(
		1 => __n(
			'Click \'Continue\' to Delete the following Cloudflare Account. Its discovered inventory and job state are ' .
			'removed; Cacti devices and graphs are kept.',
			'Click \'Continue\' to Delete the following Cloudflare Accounts. Their discovered inventory and job state are ' .
			'removed; Cacti devices and graphs are kept.',
			cacti_sizeof($ids),
			'cloudflare'
		),
		2 => __n(
			'Click \'Continue\' to Verify the following Cloudflare Account against the Cloudflare API.',
			'Click \'Continue\' to Verify the following Cloudflare Accounts against the Cloudflare API.',
			cacti_sizeof($ids),
			'cloudflare'
		),
		3 => __n(
			'Click \'Continue\' to Enable the following Cloudflare Account.',
			'Click \'Continue\' to Enable the following Cloudflare Accounts.',
			cacti_sizeof($ids),
			'cloudflare'
		),
		4 => __n(
			'Click \'Continue\' to Disable the following Cloudflare Account.',
			'Click \'Continue\' to Disable the following Cloudflare Accounts.',
			cacti_sizeof($ids),
			'cloudflare'
		),
		5 => __n(
			'Click \'Continue\' to create a Cacti device for the following Cloudflare Account. An account that already ' .
			'has one is left as it is.',
			'Click \'Continue\' to create a Cacti device for each of the following Cloudflare Accounts. An account that ' .
			'already has one is left as it is.',
			cacti_sizeof($ids),
			'cloudflare'
		)
	);

	top_header();

	form_start('cloudflare_accounts.php');

	html_start_box(html_escape($cloudflare_account_actions[$action]), '60%', '', '3', 'center', '');

	print "<tr>
		<td class='textArea'>
			<p>" . html_escape($prompts[$action]) . "</p>
			<div class='itemlist'><ul>$list</ul></div>
		</td>
	</tr>\n";

	print "<tr>
		<td class='saveRow'>
			<input type='hidden' name='action' value='actions'>
			<input type='hidden' name='selected_items' value='" . html_escape(serialize($ids)) . "'>
			<input type='hidden' name='drp_action' value='" . $action . "'>
			<input type='button' class='ui-button ui-corner-all ui-widget cactiReturnTo' value='" .
				__esc('Cancel', 'cloudflare') .
				"'>&nbsp;<input type='submit' class='ui-button ui-corner-all ui-widget' value='" .
				__esc('Continue', 'cloudflare') .
				"' title='" .
				html_escape($cloudflare_account_actions[$action]) .
				"'>
		</td>
	</tr>\n";

	html_end_box();

	form_end();

	bottom_footer();

	return null;
}

/**
 * @return string|null the redirect query string, or null once the form has
 *                     been printed
 */
function cloudflare_accounts_edit() : ?string {
	// ================= input validation =================
	get_filter_request_var('id');
	// ====================================================

	$id          = (int) get_request_var('id');
	$account     = null;
	$credentials = null;

	if ($id > 0) {
		try {
			$service = cloudflare_account_service();
			$account = cloudflare_accounts_find($service, $id);

			if ($account !== null) {
				$credentials = $service->credentialDetails($id);
			}
		} catch (AccessDenied $e) {
			cloudflare_accounts_denied();

			return '';
		} catch (OutOfBoundsException $e) {
			$account = null;
		} catch (DatabaseException $e) {
			cloudflare_accounts_unreadable('edit', $e);

			return '';
		}

		if ($account === null) {
			cloudflare_accounts_missing();

			return '';
		}
	}

	top_header();

	if ($account !== null) {
		$header_label = __esc('Cloudflare Account [edit: %s]', $account->name(), 'cloudflare');
	} else {
		$header_label = __esc('Cloudflare Account [new]', 'cloudflare');
	}

	form_start('cloudflare_accounts.php', 'cloudflare_account');

	html_start_box($header_label, '100%', true, '3', 'center', '');

	draw_edit_form(
		array(
			'config' => array('no_form_tag' => true),
			'fields' => cloudflare_account_fields($account, $credentials, cloudflare_vault() !== null)
		)
	);

	html_end_box(true, true);

	if ($account !== null) {
		cloudflare_accounts_status($account);
	}

	form_save_button('cloudflare_accounts.php', 'return');

	cloudflare_accounts_script();

	bottom_footer();

	return null;
}

function cloudflare_accounts_time(?int $timestamp) : string {
	return $timestamp === null ? __('Never', 'cloudflare') : date('Y-m-d H:i:s', $timestamp);
}

function cloudflare_accounts_status(Account $account) : void {
	$permissions = $account->permissions();

	// Labels and values are escaped here, once, and printed as built.
	$rows = array(
		__esc('Status', 'cloudflare')        => html_escape($account->status()),
		__esc('Message', 'cloudflare')       => html_escape($account->statusMessage()),
		__esc('Token Stored', 'cloudflare')  => $account->hasToken() ? __esc('Yes', 'cloudflare') : __esc('No', 'cloudflare'),
		__esc('Last Checked', 'cloudflare')  => html_escape(cloudflare_accounts_time($account->lastCheckedAt())),
		__esc('Last Verified', 'cloudflare') => html_escape(cloudflare_accounts_time($account->lastVerifiedAt())),
	);

	if ($permissions !== null) {
		foreach ($permissions as $permission => $granted) {
			$rows[__esc('Permission: %s', $permission, 'cloudflare')] = $granted
				? __esc('Granted', 'cloudflare')
				: __esc('Missing', 'cloudflare');
		}
	}

	html_start_box(__esc('Connection Status', 'cloudflare'), '100%', '', '3', 'center', '');

	foreach ($rows as $label => $value) {
		form_alternate_row();
		print '<td style="width:20%">' . $label . '</td><td>' . $value . '</td>';
		form_end_row();
	}

	html_end_box();
}

/*
 * Behaviour lives in a static file rather than inline script, so it runs
 * under every CSP mode; the nonce is added where Cacti provides one.
 */
function cloudflare_accounts_script() : void {
	global $config;

	$nonce = class_exists('CactiSecureHeaders') ? ' ' . CactiSecureHeaders::getNonceAttribute() : '';

	print "<script type='text/javascript' src='" .
		html_escape($config['url_path'] . 'plugins/cloudflare/js/cloudflare_accounts.js') .
		"'" .
		$nonce .
		"></script>\n";
}

function cloudflare_accounts_list() : void {
	global $item_rows;

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
		'filter' => array(
			'filter'  => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
		),
		'sort_column' => array(
			'filter'  => FILTER_CALLBACK,
			'default' => 'name',
			'options' => array('options' => 'sanitize_search_string')
		),
		'sort_direction' => array(
			'filter'  => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => array('options' => 'sanitize_search_string')
		)
	);

	validate_store_request_vars($filters, 'sess_cf_accounts');
	// ================= input validation =================

	$rows = (int) get_request_var('rows');

	if ($rows < 1) {
		$rows = max(1, (int) read_config_option('num_rows_table'));
	}

	$page   = max(1, (int) get_request_var('page'));
	$filter = (string) get_request_var('filter');

	html_start_box(
		__esc('Cloudflare Accounts', 'cloudflare'),
		'100%',
		'',
		'3',
		'center',
		'cloudflare_accounts.php?action=edit'
	);

	?>
	<tr class='even'>
		<td>
			<form id='form_cloudflare_accounts' action='cloudflare_accounts.php'>
			<table class='filterTable' role='presentation'>
				<tr>
					<td>
						<label for='filter'><?php print __('Search', 'cloudflare'); ?></label>
					</td>
					<td>
						<input type='text' class='ui-state-default ui-corner-all' id='filter' size='25'
							value='<?php print html_escape_request_var('filter'); ?>'>
					</td>
					<td>
						<label for='rows'><?php print __('Accounts', 'cloudflare'); ?></label>
					</td>
					<td>
						<select id='rows'>
							<option value='-1'<?php print (get_request_var('rows') == '-1' ? ' selected>' : '>') .
								__('Default', 'cloudflare'); ?></option>
							<?php
							if (cacti_sizeof($item_rows)) {
								foreach ($item_rows as $key => $value) {
									print "<option value='" .
										html_escape($key) .
										"'" .
										(get_request_var('rows') == $key ? ' selected' : '') .
										'>' .
										html_escape($value) .
										"</option>\n";
								}
							}
							?>
						</select>
					</td>
					<td>
						<span>
							<input type='button' class='ui-button ui-corner-all ui-widget' id='refresh'
								value='<?php print __esc('Go', 'cloudflare'); ?>'
								title='<?php print __esc('Set/Refresh Filters', 'cloudflare'); ?>'>
							<input type='button' class='ui-button ui-corner-all ui-widget' id='clear'
								value='<?php print __esc('Clear', 'cloudflare'); ?>'
								title='<?php print __esc('Clear Filters', 'cloudflare'); ?>'>
						</span>
					</td>
				</tr>
			</table>
			</form>
		</td>
	</tr>
	<?php

	html_end_box();

	$unreadable = false;

	try {
		$accounts = cloudflare_account_service()->list();
	} catch (DatabaseException $e) {
		cloudflare_accounts_unreadable('list', $e);

		$accounts   = array();
		$unreadable = true;
	}

	$accounts = cloudflare_account_sort(
		cloudflare_account_filter($accounts, $filter),
		(string) get_request_var('sort_column'),
		(string) get_request_var('sort_direction')
	);

	$total_rows = cacti_sizeof($accounts);
	$accounts   = array_slice($accounts, $rows * ($page - 1), $rows);

	$display_text = array(
		'name'            => array(
			'display' => __('Name', 'cloudflare'),
			'align'   => 'left',
			'sort'    => 'ASC',
			'tip'     => __('The name of this account in Cacti.', 'cloudflare')
		),
		'cf_account_id'   => array('display' => __('Account ID', 'cloudflare'), 'align' => 'left', 'sort' => 'ASC'),
		'has_token'       => array(
			'display' => __('Token Stored', 'cloudflare'),
			'align'   => 'left',
			'sort'    => 'DESC',
			'tip'     => __('Whether an API token is configured. The edit page shows where it is kept.', 'cloudflare')
		),
		'enabled'         => array('display' => __('Enabled', 'cloudflare'), 'align' => 'left', 'sort' => 'DESC'),
		'status'          => array(
			'display' => __('Status', 'cloudflare'),
			'align'   => 'left',
			'sort'    => 'ASC',
			'tip'     => __('The result of the last Verify.', 'cloudflare')
		),
		'last_checked_at' => array('display' => __('Last Checked', 'cloudflare'), 'align' => 'left', 'sort' => 'DESC'),
		'id'              => array('display' => __('ID', 'cloudflare'), 'align' => 'right', 'sort' => 'ASC')
	);

	$nav = html_nav_bar(
		'cloudflare_accounts.php?filter=' . urlencode($filter),
		MAX_DISPLAY_PAGES,
		$page,
		$rows,
		$total_rows,
		cacti_sizeof($display_text) + 1,
		__('Accounts', 'cloudflare'),
		'page',
		'main'
	);

	form_start('cloudflare_accounts.php', 'chk');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	html_header_sort_checkbox($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false);

	if (cacti_sizeof($accounts)) {
		foreach ($accounts as $account) {
			$id = $account->id();

			form_alternate_row('line' . $id, true);
			form_selectable_cell(
				'<a class="linkEditMain" href="' .
					html_escape('cloudflare_accounts.php?action=edit&id=' . $id) .
					'">' .
					html_escape($account->name()) .
					'</a>',
				$id
			);
			form_selectable_cell(html_escape($account->cfAccountId()), $id);
			form_selectable_cell($account->hasToken() ? __esc('Yes', 'cloudflare') : __esc('No', 'cloudflare'), $id);
			form_selectable_cell($account->isEnabled() ? __esc('Yes', 'cloudflare') : __esc('No', 'cloudflare'), $id);
			form_selectable_cell(html_escape($account->status()), $id, '', '', html_escape($account->statusMessage()));
			form_selectable_cell(html_escape(cloudflare_accounts_time($account->lastCheckedAt())), $id);
			form_selectable_cell($id, $id, '', 'right');
			form_checkbox_cell(html_escape($account->name()), $id);
			form_end_row();
		}
	} else {
		/* top_header() has already printed this request's messages, so the
		 * failure is also shown in the table rather than only next time. */
		$empty = $unreadable ? cloudflare_accounts_unreadable_text() : __('No Cloudflare Accounts Found', 'cloudflare');

		print "<tr class='tableRow'><td colspan='" .
			(cacti_sizeof($display_text) + 1) .
			"'><em>" .
			html_escape($empty) .
			"</em></td></tr>\n";
	}

	html_end_box(false);

	if (cacti_sizeof($accounts)) {
		print $nav;
	}

	draw_actions_dropdown(cloudflare_account_actions());

	form_end();

	cloudflare_accounts_script();
}
