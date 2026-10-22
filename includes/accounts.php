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

require_once __DIR__ . '/autoload.php';

use Cacti\PluginCloudflare\Account\Account;
use Cacti\PluginCloudflare\Account\ValidationException;

/**
 * Cacti keeps realms loaded and config_arrays enabled while a plugin is
 * disabled (lib/plugins.php:1103, :1268), so every entry point checks this.
 */
function cloudflare_plugin_enabled() : bool {
	return function_exists('api_plugin_is_enabled') && api_plugin_is_enabled('cloudflare') === true;
}

/**
 * CactiShutdownHandler disables a plugin whose page dies with an uncaught
 * error, so each page ends here instead. Only the class is logged, since a
 * message may carry request or driver text. top_header() and bottom_footer()
 * include their templates once, so calling them again is harmless.
 */
function cloudflare_page_failed(string $page, Throwable $e, string $text) : void {
	cacti_log('CLOUDFLARE: ' . $page . ' page failed (' . get_class($e) . ')', false, 'CLOUDFLARE');

	// This is the last handler before CactiShutdownHandler, so a broken template must not escape it either.
	try {
		top_header();

		html_start_box(__esc('Cloudflare', 'cloudflare'), '100%', '', '3', 'center', '');

		print "<tr class='tableRow'><td><em>" . html_escape($text) . "</em></td></tr>\n";

		html_end_box();

		bottom_footer();
	} catch (Throwable $render) {
		cacti_log('CLOUDFLARE: ' . $page . ' error page failed (' . get_class($render) . ')', false, 'CLOUDFLARE');
	}
}

/**
 * Edit form for draw_edit_form(). The token box is always empty: a stored
 * token is never sent back to the browser, only whether one exists.
 * $credentials comes from AccountService::credentialDetails(), and is null
 * for a new account.
 *
 * @param  array{token_source: string, token_file: string|null, has_token: bool}|null $credentials
 * @return array<string, array<string, mixed>>
 */
function cloudflare_account_fields(?Account $account, ?array $credentials, bool $vault_available) : array {
	$sources = array();

	if ($vault_available) {
		$sources['vault'] = __('Encrypted in the Cacti database', 'cloudflare');
	}

	$sources['file'] = __('Token file on this server', 'cloudflare');

	$source = $credentials === null ? 'vault' : $credentials['token_source'];

	if (!isset($sources[$source])) {
		$source = 'file';
	}

	$fields = array(
		'spacer_account' => array(
			'method'        => 'spacer',
			'friendly_name' => __('Account', 'cloudflare'),
		),
		'name' => array(
			'method'        => 'textbox',
			'friendly_name' => __('Name', 'cloudflare'),
			'description'   => __('A label for this account in Cacti.', 'cloudflare'),
			'value'         => $account === null ? '' : $account->name(),
			'max_length'    => '100',
			'size'          => '50'
		),
		'cf_account_id' => array(
			'method'        => 'textbox',
			'friendly_name' => __('Account ID', 'cloudflare'),
			'description'   => __(
				'The 32-character Cloudflare account ID, shown in the dashboard URL and on the account overview page.',
				'cloudflare'
			),
			'value'         => $account === null ? '' : $account->cfAccountId(),
			'max_length'    => '32',
			'size'          => '40'
		),
		'spacer_token' => array(
			'method'        => 'spacer',
			'friendly_name' => __('API Token', 'cloudflare'),
		),
		'token_source' => array(
			'method'        => 'drop_array',
			'friendly_name' => __('Token Storage', 'cloudflare'),
			'description'   => $vault_available
				? __('Where the scoped API token is kept. Global API Keys are not accepted.', 'cloudflare')
				: __(
					'Encrypted storage is unavailable because the key file in Settings is missing or unusable; only token files ' .
					'can be used.',
					'cloudflare'
				),
			'value'         => $source,
			'array'         => $sources
		),
	);

	if ($vault_available) {
		$fields['token'] = array(
			'method'        => 'textbox_password',
			'friendly_name' => __('API Token', 'cloudflare'),
			'description'   => $credentials !== null && $credentials['has_token'] && $credentials['token_source'] === 'vault'
				? __('Leave blank to keep the current token', 'cloudflare')
				: __('A scoped API token with Account Cloudflare Tunnel Read permission.', 'cloudflare'),
			'value'         => '',
			'max_length'    => '256',
			'size'          => '60'
		);
	}

	$fields['token_file'] = array(
		'method'        => 'textbox',
		'friendly_name' => __('Token File', 'cloudflare'),
		'description'   => __(
			'Absolute path to a file that contains only the token. It must be readable by the web server and poller users ' .
			'and must not be world-readable.',
			'cloudflare'
		),
		'value'         => $credentials === null ? '' : (string) $credentials['token_file'],
		'max_length'    => '255',
		'size'          => '60'
	);

	$fields += array(
		'spacer_collection' => array(
			'method'        => 'spacer',
			'friendly_name' => __('Collection', 'cloudflare'),
		),
		'enabled' => array(
			'method'        => 'checkbox',
			'friendly_name' => __('Enabled', 'cloudflare'),
			'description'   => __('Disabled accounts are not polled.', 'cloudflare'),
			'value'         => ($account === null || $account->isEnabled()) ? 'on' : '',
		),
		'collect_tunnels' => array(
			'method'        => 'checkbox',
			'friendly_name' => __('Collect Tunnels', 'cloudflare'),
			'description'   => __('Discover Cloudflare Tunnels and their connectors.', 'cloudflare'),
			'value'         => ($account === null || $account->collectsTunnels()) ? 'on' : '',
		),
		'collect_analytics' => array(
			'method'        => 'checkbox',
			'friendly_name' => __('Collect Analytics', 'cloudflare'),
			'description'   => __(
				'Collect zone traffic analytics. This needs the Account Analytics Read and Analytics Read (Zone) permissions.',
				'cloudflare'
			),
			'value'         => ($account !== null && $account->collectsAnalytics()) ? 'on' : '',
		),
		'discovery_interval' => array(
			'method'        => 'textbox',
			'friendly_name' => __('Discovery Interval', 'cloudflare'),
			'description'   => __('Seconds between inventory refreshes, from 60 to 86400.', 'cloudflare'),
			'value'         => (string) ($account === null ? 300 : $account->discoveryInterval()),
			'max_length'    => '5',
			'size'          => '8'
		),
		'id' => array(
			'method' => 'hidden_zero',
			'value'  => (string) ($account === null ? 0 : $account->id())
		),
		'save_component_account' => array(
			'method' => 'hidden',
			'value'  => '1'
		),
	);

	return $fields;
}

/**
 * Builds AccountService input from the posted form. Cacti posts 'on' for a
 * checked box and nothing for an unchecked one, so absence means 0; the
 * service's keep-existing default never applies to a form save.
 *
 * @return array<string, mixed>
 *
 * @throws ValidationException when the token and its confirmation differ
 */
function cloudflare_account_input() : array {
	$source = (string) get_nfilter_request_var('token_source');
	$token  = '';

	if ($source === 'vault') {
		$token = (string) get_nfilter_request_var('token');

		if (!hash_equals($token, (string) get_nfilter_request_var('token_confirm'))) {
			throw new ValidationException(array('token' => __('The token and its confirmation do not match', 'cloudflare')));
		}
	}

	$input = array(
		'name'               => (string) get_nfilter_request_var('name'),
		'cf_account_id'      => (string) get_nfilter_request_var('cf_account_id'),
		'token_source'       => $source,
		'token'              => $token,
		'token_file'         => (string) get_nfilter_request_var('token_file'),
		'discovery_interval' => (string) get_nfilter_request_var('discovery_interval'),
	);

	foreach (array('enabled', 'collect_tunnels', 'collect_analytics') as $flag) {
		$input[$flag] = (isset_request_var($flag) && get_nfilter_request_var($flag) === 'on') ? 1 : 0;
	}

	return $input;
}

/**
 * @param  array<int, Account> $accounts
 * @return array<int, Account>
 */
function cloudflare_account_filter(array $accounts, string $filter) : array {
	if ($filter === '') {
		return array_values($accounts);
	}

	$matches = array();

	foreach ($accounts as $account) {
		if (stripos($account->name(), $filter) !== false || stripos($account->cfAccountId(), $filter) !== false) {
			$matches[] = $account;
		}
	}

	return $matches;
}

/**
 * Sort keys are an allow-list; anything else sorts by name. Ties fall back
 * to the row id so the order is stable across page loads.
 *
 * @param  array<int, Account> $accounts
 * @return array<int, Account>
 */
function cloudflare_account_sort(array $accounts, string $column, string $direction) : array {
	$keys = array(
		'name'            => function (Account $a) { return strtolower($a->name()); },
		'cf_account_id'   => function (Account $a) { return $a->cfAccountId(); },
		'has_token'       => function (Account $a) { return $a->hasToken() ? 1 : 0; },
		'enabled'         => function (Account $a) { return $a->isEnabled() ? 1 : 0; },
		'status'          => function (Account $a) { return $a->status(); },
		'last_checked_at' => function (Account $a) { return (int) $a->lastCheckedAt(); },
		'id'              => function (Account $a) { return $a->id(); },
	);

	$key  = isset($keys[$column]) ? $keys[$column] : $keys['name'];
	$sign = strtoupper($direction) === 'DESC' ? -1 : 1;

	usort($accounts, function (Account $a, Account $b) use ($key, $sign) : int {
		$order = $key($a) <=> $key($b);

		return $order !== 0 ? $sign * $order : $a->id() <=> $b->id();
	});

	return $accounts;
}
