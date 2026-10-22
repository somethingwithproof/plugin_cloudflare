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

namespace Cacti\PluginCloudflare\Tests\Unit\Glue;

use Cacti\PluginCloudflare\Api\ApiException;
use Cacti\PluginCloudflare\Api\CloudflareClient;
use Cacti\PluginCloudflare\Api\ErrorClass;
use Cacti\PluginCloudflare\Http\Response;
use Cacti\PluginCloudflare\Tests\Support\FakeTransport;
use Cacti\PluginCloudflare\Tests\Support\RecordingSleeper;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class SetupContractTest extends TestCase {
	private const ROOT = __DIR__ . '/../../..';

	public static function setUpBeforeClass(): void {
		require_once self::ROOT . '/tests/Support/CactiGlueStubs.php';
		require_once self::ROOT . '/setup.php';
		require_once self::ROOT . '/includes/settings.php';
		require_once self::ROOT . '/includes/accounts.php';
	}

	/** @var string|null */
	private $keyDir;

	protected function setUp(): void {
		cloudflare_glue_reset();
		unset($GLOBALS['cloudflare_extension_loaded'], $GLOBALS['cloudflare_key_refusal_logged'], $GLOBALS['menu'], $GLOBALS['tabs'], $GLOBALS['settings']);
	}

	protected function tearDown(): void {
		unset($GLOBALS['cloudflare_extension_loaded'], $GLOBALS['cloudflare_key_refusal_logged'], $GLOBALS['menu'], $GLOBALS['tabs'], $GLOBALS['settings']);

		if ($this->keyDir !== null) {
			foreach ((array) glob($this->keyDir . '/*') as $file) {
				unlink((string) $file);
			}
			rmdir($this->keyDir);
			$this->keyDir = null;
		}
	}

	/**
	 * @return array<int, string>
	 */
	private static function loggedLines(): array {
		return array_map(function (array $call): string {
			return (string) $call[0];
		}, cloudflare_glue_calls('cacti_log'));
	}

	public function testPluginEnabledHelperFollowsCacti(): void {
		$this->assertTrue(cloudflare_plugin_enabled());

		$GLOBALS['cloudflare_glue']['enabled'] = false;
		$this->assertTrue(cloudflare_plugin_enabled(), 'Cacti answers from a per-process cache');
		$this->assertSame(array(array('cloudflare'), array('cloudflare')), cloudflare_glue_calls('api_plugin_is_enabled'));

		cloudflare_glue_reset();
		$GLOBALS['cloudflare_glue']['enabled'] = false;
		$this->assertFalse(cloudflare_plugin_enabled());
	}

	public function testDisabledPluginAddsNoMenuOrNavigation(): void {
		$GLOBALS['cloudflare_glue']['enabled'] = false;
		$GLOBALS['menu'] = array('Configuration' => array('settings.php' => 'Settings'));
		$nav             = array('index.php:' => array('title' => 'Console'));

		cloudflare_config_arrays();

		$this->assertSame(array('Configuration' => array('settings.php' => 'Settings')), $GLOBALS['menu']);
		$this->assertSame($nav, cloudflare_draw_navigation_text($nav));
	}

	public function testEnabledPluginAddsMenuAndNavigation(): void {
		$GLOBALS['menu'] = array();

		cloudflare_config_arrays();
		$nav = cloudflare_draw_navigation_text(array());

		$this->assertSame(array(
			'Configuration' => array('plugins/cloudflare/cloudflare_accounts.php' => 'Cloudflare Accounts'),
			'Management'    => array('plugins/cloudflare/cloudflare.php' => 'Cloudflare Tunnels'),
		), $GLOBALS['menu']);
		$this->assertSame(array(
			'cloudflare_accounts.php:',
			'cloudflare_accounts.php:edit',
			'cloudflare_accounts.php:actions',
			'cloudflare.php:',
			'cloudflare.php:overview',
			'cloudflare.php:tunnels',
			'cloudflare.php:connectors',
		), array_keys($nav));
		$this->assertSame('index.php:,cloudflare.php:', $nav['cloudflare.php:tunnels']['mapping']);
	}

	public function testSettingsTabStaysButUpgradeWaitsWhileDisabled(): void {
		$GLOBALS['cloudflare_glue']['page']    = 'settings.php';
		$GLOBALS['cloudflare_glue']['enabled'] = false;

		cloudflare_config_settings();

		$this->assertSame('Cloudflare', $GLOBALS['tabs']['cloudflare']);
		$this->assertArrayHasKey('cloudflare_key_file', $GLOBALS['settings']['cloudflare']);
		$this->assertSame(array(), cloudflare_glue_calls('db_fetch_cell_prepared'));

		cloudflare_glue_reset();
		$GLOBALS['cloudflare_glue']['page'] = 'settings.php';
		cloudflare_config_settings();
		$this->assertCount(1, cloudflare_glue_calls('db_fetch_cell_prepared'));
	}

	public function testAccountsPageRefusesWhenPluginDisabled(): void {
		$source = (string) file_get_contents(self::ROOT . '/cloudflare_accounts.php');

		$this->assertSame(1, preg_match('#\nif \(!cloudflare_plugin_enabled\(\)\) \{(.*?)\n\}#s', $source, $guard, PREG_OFFSET_CAPTURE));
		$this->assertStringContainsString('raise_ajax_permission_denied();', $guard[1][0]);
		$this->assertStringContainsString('permission_denied.php', $guard[1][0]);
		$this->assertStringContainsString('exit;', $guard[1][0]);

		$at = $guard[0][1];
		$this->assertGreaterThan((int) strpos($source, "include('./include/auth.php');"), $at);
		foreach (array('plugin_cloudflare_upgrade();', 'set_default_action();', 'switch (get_request_var(\'action\'))') as $later) {
			$this->assertLessThan((int) strpos($source, $later), $at, $later);
		}
	}

	private function keyFile(int $mode): string {
		$this->keyDir = sys_get_temp_dir() . '/cf-key-' . bin2hex(random_bytes(6));
		mkdir($this->keyDir, 0700);
		$path = $this->keyDir . '/cloudflare.key';
		file_put_contents($path, bin2hex(random_bytes(32)));
		chmod($path, $mode);

		return $path;
	}

	public function testRefusedKeyFileIsLoggedOncePerRequest(): void {
		$path = $this->keyFile(0644);
		$key  = (string) file_get_contents($path);
		$GLOBALS['cloudflare_glue']['settings']['cloudflare_key_file'] = $path;

		$this->assertNull(cloudflare_vault());
		$this->assertNull(cloudflare_vault());

		$this->assertSame(array('CLOUDFLARE: key file refused (reason=key_unavailable)'), self::loggedLines());
		$this->assertStringNotContainsString(substr($key, 0, 16), implode("\n", self::loggedLines()));
	}

	public function testMissingKeyFileIsNotLogged(): void {
		$GLOBALS['cloudflare_glue']['settings']['cloudflare_key_file'] = sys_get_temp_dir() . '/cf-absent-' . bin2hex(random_bytes(6));

		$this->assertNull(cloudflare_vault());
		$this->assertSame(array(), self::loggedLines());
	}

	public function testUsableKeyFileIsNotLogged(): void {
		$GLOBALS['cloudflare_glue']['settings']['cloudflare_key_file'] = $this->keyFile(0600);

		$this->assertNotNull(cloudflare_vault());
		$this->assertSame(array(), self::loggedLines());
	}

	public function testInstallRegistersExpectedHooksAndRealms(): void {
		plugin_cloudflare_install();

		$this->assertSame(array(
			array('cloudflare', 'config_arrays', 'cloudflare_config_arrays', 'includes/settings.php', false),
			array('cloudflare', 'config_settings', 'cloudflare_config_settings', 'includes/settings.php', false),
			array('cloudflare', 'draw_navigation_text', 'cloudflare_draw_navigation_text', 'includes/settings.php', false),
			array('cloudflare', 'poller_bottom', 'cloudflare_poller_bottom', 'includes/polling.php', false),
		), cloudflare_glue_calls('api_plugin_register_hook'));

		$this->assertSame(array(
			array('cloudflare', 'cloudflare.php', 'Cloudflare: View', false),
			array('cloudflare', 'cloudflare_accounts.php', 'Cloudflare: Configure', true),
		), cloudflare_glue_calls('api_plugin_register_realm'));

		foreach (cloudflare_glue_calls('cacti_log') as $call) {
			$this->assertStringNotContainsString('improperly', (string) $call[0]);
		}
	}

	public function testHookFunctionsExistInTheirFiles(): void {
		foreach (array('cloudflare_config_arrays', 'cloudflare_config_settings', 'cloudflare_draw_navigation_text') as $function) {
			$this->assertTrue(function_exists($function), $function);
		}
	}

	public function testVersionParsesInfo(): void {
		$info = plugin_cloudflare_version();

		$this->assertSame('cloudflare', $info['name']);
		$this->assertSame('0.2.0', $info['version']);
		$this->assertSame('Cloudflare', $info['longname']);
		$this->assertSame('1.2.25', $info['compat']);
		$this->assertSame('online_view:1, online_mgmt:1, offline_view:0, offline_mgmt:0, remote_collect:0', $info['capabilities']);
		$this->assertSame('', $info['requires']);
		/* api_plugin_install() reads author unguarded (lib/plugins.php:741). */
		$this->assertSame('Thomas Vincent', $info['author']);
		$this->assertSame('', $info['email']);
		$this->assertSame('https://github.com/somethingwithproof/plugin_cloudflare', $info['homepage']);
	}

	public function testCheckConfigFailsWithoutCurl(): void {
		$GLOBALS['cloudflare_extension_loaded'] = function (string $name): bool {
			return $name !== 'curl';
		};

		$this->assertFalse(plugin_cloudflare_check_config());
		$this->assertSame(array(), cloudflare_glue_calls('db_fetch_cell_prepared'), 'upgrade must not run');

		$logged = array_map(function (array $call): string {
			return (string) $call[0];
		}, cloudflare_glue_calls('cacti_log'));
		$this->assertContains('CLOUDFLARE: the curl PHP extension is required', $logged);
	}

	public function testCheckConfigRequiresOpensslAndPdoMysql(): void {
		foreach (array('openssl', 'pdo_mysql') as $missing) {
			$GLOBALS['cloudflare_extension_loaded'] = function (string $name) use ($missing): bool {
				return $name !== $missing;
			};

			$this->assertFalse(plugin_cloudflare_check_config(), $missing);
		}
	}

	public function testCheckConfigPassesAndRunsUpgradeWhenExtensionsPresent(): void {
		$GLOBALS['cloudflare_extension_loaded'] = function (string $name): bool {
			return true;
		};

		$this->assertTrue(plugin_cloudflare_check_config());

		$queries = cloudflare_glue_calls('db_fetch_cell_prepared');
		$this->assertCount(1, $queries);
		$this->assertStringContainsString('FROM plugin_config', (string) $queries[0][0]);
		$this->assertSame(array('cloudflare'), $queries[0][1]);
	}

	public function testCheckConfigUsesTheRealExtensionList(): void {
		$expected = extension_loaded('curl') && extension_loaded('openssl') && extension_loaded('pdo_mysql');

		$this->assertSame($expected, plugin_cloudflare_check_config());
	}

	public function testUninstallStillClearsSettingsWhenTheDatabaseIsUnreachable(): void {
		unset($GLOBALS['database_sessions']);

		plugin_cloudflare_uninstall();

		$this->assertSame(array('CLOUDFLARE: dropping plugin tables failed: SQLSTATE[HY000] driver error 2002'), self::loggedLines());
		$this->assertSame(array(
			array('DELETE FROM settings WHERE name LIKE ? ESCAPE \'!\'', array('cloudflare!_%')),
		), cloudflare_glue_calls('db_execute_prepared'));
	}

	/**
	 * @return array<string, array{0: mixed, 1: int}>
	 */
	public static function settingValues(): array {
		return array(
			'unset'          => array('', 10),
			'in range'       => array('30', 30),
			'lower bound'    => array('5', 5),
			'upper bound'    => array('60', 60),
			'below range'    => array('4', 10),
			'above range'    => array('61', 10),
			'not a number'   => array('ten', 10),
			'fraction'       => array('12.5', 10),
			'padded'         => array(' 12 ', 12),
			'newline'        => array("12\n", 12),
			'plus sign'      => array('+12', 12),
			'negative'       => array('-12', 10),
			'hex'            => array('0x0c', 10),
			'trailing text'  => array('12s', 10),
			'leading zero'   => array('012', 10),
			'exponent'       => array('1e1', 10),
		);
	}

	/**
	 * @dataProvider settingValues
	 *
	 * @param mixed $stored
	 */
	public function testIntegerSettingFallsBackWhenUnusable($stored, int $expected): void {
		$GLOBALS['cloudflare_glue']['settings']['cloudflare_ui_timeout'] = $stored;

		$this->assertSame($expected, cloudflare_setting_int('cloudflare_ui_timeout', 10, 5, 60));
	}

	/**
	 * A Verify runs inside a page request, so a long retry-after is not slept.
	 */
	public function testUiClientDoesNotSleepForALongRetryAfter(): void {
		$transport = new FakeTransport();
		$transport->queue(new Response(429, array('retry-after' => '5'), '{"success":false,"errors":[]}'));
		$sleeper = new RecordingSleeper();
		$client  = new CloudflareClient($transport, cloudflare_ui_retry_policy($sleeper));

		try {
			$client->request('GET', '/user/tokens/verify', 'cfut_' . str_repeat('a', 40));
			$this->fail('no ApiException thrown');
		} catch (ApiException $e) {
			$this->assertSame(ErrorClass::RATE_LIMITED, $e->getErrorClass());
			$this->assertSame(5, $e->getRetryAfter());
		}

		$this->assertSame(array(), $sleeper->slept);
		$this->assertCount(1, $transport->requests());
	}

	public function testUiClientRetriesOnceForAShortRetryAfter(): void {
		$transport = new FakeTransport();
		for ($i = 0; $i < 3; $i++) {
			$transport->queue(new Response(429, array('retry-after' => '2'), '{"success":false,"errors":[]}'));
		}
		$sleeper = new RecordingSleeper();
		$client  = new CloudflareClient($transport, cloudflare_ui_retry_policy($sleeper));

		try {
			$client->request('GET', '/user/tokens/verify', 'cfut_' . str_repeat('a', 40));
			$this->fail('no ApiException thrown');
		} catch (ApiException $e) {
			$this->assertSame(ErrorClass::RATE_LIMITED, $e->getErrorClass());
		}

		$this->assertSame(array(2), $sleeper->slept);
		$this->assertCount(2, $transport->requests());
	}

	public function testUpgradeSkipsWhenPluginIsNotInstalled(): void {
		$this->assertFalse(plugin_cloudflare_upgrade());
		$this->assertSame(array(), cloudflare_glue_calls('db_execute_prepared'));
		$this->assertSame(array(), cloudflare_glue_calls('db_connect_real'));
	}

	public function testEveryPhpFileStartsWithLicenseHeader(): void {
		$lines  = file(__FILE__);
		$header = implode('', array_slice(is_array($lines) ? $lines : array(), 0, 19));

		$this->assertStringContainsString('Copyright (C) 2026 Thomas Vincent', $header);
		$this->assertStringContainsString('GNU General Public License', $header);
		$this->assertStringContainsString('https://github.com/somethingwithproof/plugin_cloudflare', $header);
		$this->assertStringEndsWith("*/\n", $header);
		$this->assertStringNotContainsString('Cacti Group', $header);

		$root  = (string) realpath(self::ROOT);
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
		$seen  = 0;

		foreach ($files as $file) {
			$path     = (string) $file;
			$relative = substr($path, strlen($root) + 1);

			if (substr($path, -4) !== '.php' || preg_match('#^(vendor|\.superpowers|\.git|tests/docker/build)/#', $relative) === 1) {
				continue;
			}

			$this->assertSame($header, substr((string) file_get_contents($path), 0, strlen($header)), $relative);
			$seen++;
		}

		$this->assertGreaterThan(40, $seen);
	}

	public function testAccountsPageChecksRealmOnEveryMutation(): void {
		$source   = (string) file_get_contents(self::ROOT . '/cloudflare_accounts.php');
		$handlers = (string) file_get_contents(self::ROOT . '/includes/accounts_page.php');

		$this->assertMatchesRegularExpression('#^chdir\(\'\.\./\.\./\'\);\s*include\(\'\./include/auth\.php\'\);#m', $source);

		foreach (array('save', 'actions') as $action) {
			$this->assertSame(1, preg_match('#case \'' . $action . '\':\s*\$redirect = (\w+)\(\);#', $source, $match), 'case ' . $action);

			/* The method check is the handler's first statement. */
			$body = self::functionBody($handlers, $match[1]);
			$this->assertMatchesRegularExpression('#^function \w+\(\) : \??string \{\s*if \(!cloudflare_request_is_post\(\)\) \{\s*return \'\';#', $body, $match[1]);
			$this->assertStringContainsString('cloudflare_account_service()', $body, $match[1]);
		}

		/* Writes go through AccountService, whose Authorizer re-checks the realm. */
		$this->assertDoesNotMatchRegularExpression('#AccountRepository|db_execute|->(insert|update)\(#', $source . $handlers);

		$bootstrap = (string) file_get_contents(self::ROOT . '/includes/bootstrap.php');
		$this->assertStringContainsString('api_user_realm_auth(\'cloudflare_accounts.php\')', $bootstrap);
	}

	public function testAccountsPageEscapesAccountFields(): void {
		$source = (string) file_get_contents(self::ROOT . '/cloudflare_accounts.php')
			. (string) file_get_contents(self::ROOT . '/includes/accounts_page.php');

		$this->assertSame(0, preg_match_all('#\becho\b#', $source));
		$this->assertGreaterThan(0, preg_match_all('#->(name|cfAccountId|tokenFile|statusMessage|status)\(\)#', $source));

		foreach (explode("\n", $source) as $number => $line) {
			if (preg_match('#->(name|cfAccountId|tokenFile|statusMessage|status)\(\)#', $line) !== 1) {
				continue;
			}

			$escaped = preg_match('#(html_escape|__esc)\([^;]*->(name|cfAccountId|tokenFile|statusMessage|status)\(\)#', $line) === 1;
			$field   = preg_match('#\'value\'\s*=>#', $line) === 1;
			$logic   = preg_match('#^\s*(if|\}?\s*elseif|return|\$\w+\s*=|case)\b#', $line) === 1 && preg_match('#\bprint\b|form_selectable#', $line) !== 1;

			$this->assertTrue($escaped || $field || $logic, 'line ' . ($number + 1) . ' renders an account field unescaped');
		}
	}

	private static function functionBody(string $source, string $name): string {
		$start = strpos($source, 'function ' . $name . '(');
		if ($start === false) {
			return '';
		}

		$next = strpos($source, "\nfunction ", $start + 1);

		return $next === false ? substr($source, $start) : substr($source, $start, $next - $start);
	}
}
