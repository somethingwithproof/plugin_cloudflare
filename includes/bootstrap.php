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

use Cacti\PluginCloudflare\Account\AccountRepository;
use Cacti\PluginCloudflare\Account\AccountService;
use Cacti\PluginCloudflare\Account\CredentialResolver;
use Cacti\PluginCloudflare\Api\CloudflareClient;
use Cacti\PluginCloudflare\Api\RetryPolicy;
use Cacti\PluginCloudflare\Auth\CallbackAuthorizer;
use Cacti\PluginCloudflare\Discovery\DiscoveryService;
use Cacti\PluginCloudflare\Discovery\Reconciler;
use Cacti\PluginCloudflare\Http\CurlTransport;
use Cacti\PluginCloudflare\Http\OutboundPolicy;
use Cacti\PluginCloudflare\Jobs\JobRepository;
use Cacti\PluginCloudflare\Secret\SecretException;
use Cacti\PluginCloudflare\Secret\TokenVault;
use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Storage\DatabaseException;
use Cacti\PluginCloudflare\Support\Sleeper;
use Cacti\PluginCloudflare\Support\SystemClock;
use Cacti\PluginCloudflare\Support\SystemSleeper;

if (!defined('CLOUDFLARE_DEFAULT_KEY_FILE')) {
	define('CLOUDFLARE_DEFAULT_KEY_FILE', '/etc/cacti/cloudflare.key');
	define('CLOUDFLARE_CONNECT_TIMEOUT', 5);
}

/**
 * Wraps Cacti's own handle rather than opening a second connection, so the
 * plugin shares Cacti's credentials, TLS settings and session sql_mode.
 */
function cloudflare_db() : Database {
	global $database_sessions, $database_hostname, $database_username, $database_password,
		$database_default, $database_type, $database_port, $database_retries,
		$database_ssl, $database_ssl_key, $database_ssl_cert, $database_ssl_ca;

	$session = "$database_hostname:$database_port:$database_default";

	if (isset($database_sessions[$session]) && $database_sessions[$session] instanceof PDO) {
		return new Database($database_sessions[$session]);
	}

	/* Same arguments include/global.php:363 passes. db_connect_real() stores
	 * the handle in $database_sessions, so later calls take the branch above. */
	$handle = db_connect_real(
		$database_hostname,
		$database_username,
		$database_password,
		$database_default,
		isset($database_type) ? $database_type : 'mysql',
		$database_port,
		isset($database_retries) ? $database_retries : 2,
		isset($database_ssl) ? $database_ssl : false,
		isset($database_ssl_key) ? $database_ssl_key : '',
		isset($database_ssl_cert) ? $database_ssl_cert : '',
		isset($database_ssl_ca) ? $database_ssl_ca : ''
	);

	if (!$handle instanceof PDO) {
		throw new DatabaseException('HY000', 2002);
	}

	return new Database($handle);
}

/**
 * Returns null when the key file is missing or unusable; the account page
 * then offers only the token file source.
 */
function cloudflare_vault() : ?TokenVault {
	$path = trim((string) read_config_option('cloudflare_key_file'));

	if ($path === '') {
		$path = CLOUDFLARE_DEFAULT_KEY_FILE;
	}

	try {
		return TokenVault::fromKeyFile($path);
	} catch (SecretException $e) {
		if ($e->getReason() !== 'key_unavailable') {
			throw $e;
		}

		/* A missing file is the documented way to run without the vault; a
		 * file that exists but is refused is a misconfiguration worth one
		 * line per request. Only the reason is logged, never the contents. */
		if ((file_exists($path) || is_link($path)) && empty($GLOBALS['cloudflare_key_refusal_logged'])) {
			$GLOBALS['cloudflare_key_refusal_logged'] = true;

			cacti_log('CLOUDFLARE: key file refused (reason=' . $e->getReason() . ')', false, 'CLOUDFLARE');
		}

		return null;
	}
}

/**
 * Reads an integer setting, falling back to $default when it is unset, not
 * a plain decimal integer, or outside [$min, $max]. is_numeric() is avoided
 * because PHP 8 accepts trailing whitespace that PHP 7.4 rejects.
 */
function cloudflare_setting_int(string $name, int $default, int $min, int $max) : int {
	$value = filter_var(
		trim((string) read_config_option($name)),
		FILTER_VALIDATE_INT,
		array('options' => array('min_range' => $min, 'max_range' => $max))
	);

	return $value === false ? $default : $value;
}

function cloudflare_client(int $connect, int $total, RetryPolicy $retry) : CloudflareClient {
	return new CloudflareClient(
		new CurlTransport(OutboundPolicy::production()),
		$retry,
		'https://api.cloudflare.com/client/v4',
		$connect,
		$total
	);
}

/**
 * The UI client retries once at most and sleeps for a 429's retry-after only
 * up to 2 seconds (docs/design.md, Error classification), so a Verify cannot
 * hold the page for several total timeouts or a 30 second wait.
 */
function cloudflare_ui_retry_policy(Sleeper $sleeper) : RetryPolicy {
	return new RetryPolicy(new SystemClock(), $sleeper, 1, 2);
}

function cloudflare_account_service() : AccountService {
	$db = cloudflare_db();

	return new AccountService(
		new AccountRepository($db),
		new CallbackAuthorizer(function () : bool {
			return api_user_realm_auth('cloudflare_accounts.php') === true;
		}),
		cloudflare_client(
			CLOUDFLARE_CONNECT_TIMEOUT,
			cloudflare_setting_int('cloudflare_ui_timeout', 10, 5, 60),
			cloudflare_ui_retry_policy(new SystemSleeper())
		),
		cloudflare_vault(),
		new SystemClock(),
		function (string $line) : void {
			cacti_log($line, false, 'CLOUDFLARE');
		}
	);
}

/**
 * The collector runs detached from poller.php, so it may wait out a 429's
 * retry-after up to 30 seconds; the 50 second run budget still bounds it.
 * Runs in system context: there is no user, so no Authorizer.
 * The enabled check reads plugin_config each time: api_plugin_is_enabled()
 * caches its first answer for the process (lib/plugins.php:886), which would
 * hide a disable made while the collector runs.
 */
function cloudflare_discovery_service() : DiscoveryService {
	$db       = cloudflare_db();
	$clock    = new SystemClock();
	$accounts = new AccountRepository($db);

	return new DiscoveryService(
		$accounts,
		new CredentialResolver($accounts, cloudflare_vault()),
		cloudflare_client(
			CLOUDFLARE_CONNECT_TIMEOUT,
			cloudflare_setting_int('cloudflare_poller_timeout', 20, 5, 60),
			new RetryPolicy($clock, new SystemSleeper(), 2, 30)
		),
		new Reconciler($db, $clock),
		new JobRepository($db, $clock),
		$db,
		$clock,
		function () use ($db) : bool {
			return cloudflare_plugin_status_enabled($db);
		},
		function (string $line) : void {
			cacti_log($line, false, 'CLOUDFLARE', POLLER_VERBOSITY_MEDIUM);
		}
	);
}

/* A read failure counts as disabled, so the run stops instead of carrying on blind. */
function cloudflare_plugin_status_enabled(Database $db) : bool {
	try {
		return (int) $db->fetchCell('SELECT status FROM plugin_config WHERE directory = ?', array('cloudflare')) === 1;
	} catch (DatabaseException $e) {
		return false;
	}
}
