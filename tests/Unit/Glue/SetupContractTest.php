<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Cacti Group                                      |
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
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

namespace Cacti\PluginCloudflare\Tests\Unit\Glue;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class SetupContractTest extends TestCase {
	private const ROOT = __DIR__ . '/../../..';

	public static function setUpBeforeClass(): void {
		require_once self::ROOT . '/tests/Support/CactiGlueStubs.php';
		require_once self::ROOT . '/setup.php';
	}

	protected function setUp(): void {
		cloudflare_glue_reset();
		unset($GLOBALS['cloudflare_extension_loaded']);
	}

	protected function tearDown(): void {
		unset($GLOBALS['cloudflare_extension_loaded']);
	}

	public function testInstallRegistersExpectedHooksAndRealms(): void {
		plugin_cloudflare_install();

		$this->assertSame(array(
			array('cloudflare', 'config_arrays', 'cloudflare_config_arrays', 'includes/settings.php', false),
			array('cloudflare', 'config_settings', 'cloudflare_config_settings', 'includes/settings.php', false),
			array('cloudflare', 'draw_navigation_text', 'cloudflare_draw_navigation_text', 'includes/settings.php', false),
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
		require_once self::ROOT . '/includes/settings.php';

		foreach (array('cloudflare_config_arrays', 'cloudflare_config_settings', 'cloudflare_draw_navigation_text') as $function) {
			$this->assertTrue(function_exists($function), $function);
		}
	}

	public function testVersionParsesInfo(): void {
		$info = plugin_cloudflare_version();

		$this->assertSame('cloudflare', $info['name']);
		$this->assertSame('1.0.0', $info['version']);
		$this->assertSame('Cloudflare', $info['longname']);
		$this->assertSame('1.2.25', $info['compat']);
		$this->assertSame('online_view:1, online_mgmt:1, offline_view:0, offline_mgmt:0, remote_collect:0', $info['capabilities']);
		$this->assertSame('', $info['requires']);
		/* api_plugin_install() reads author unguarded (lib/plugins.php:741). */
		$this->assertArrayHasKey('author', $info);
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

	public function testUpgradeSkipsWhenPluginIsNotInstalled(): void {
		$this->assertFalse(plugin_cloudflare_upgrade());
		$this->assertSame(array(), cloudflare_glue_calls('db_execute_prepared'));
		$this->assertSame(array(), cloudflare_glue_calls('db_connect_real'));
	}

	public function testEveryPhpFileStartsWithLicenseHeader(): void {
		$lines  = file(__FILE__);
		$header = implode('', array_slice(is_array($lines) ? $lines : array(), 0, 23));

		$this->assertStringContainsString('Copyright (C) 2026 The Cacti Group', $header);
		$this->assertStringContainsString('GNU General Public License', $header);

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
		$source = (string) file_get_contents(self::ROOT . '/cloudflare_accounts.php');

		$this->assertMatchesRegularExpression('#^chdir\(\'\.\./\.\./\'\);\s*include\(\'\./include/auth\.php\'\);#m', $source);

		foreach (array('save', 'actions') as $action) {
			$this->assertSame(1, preg_match('#case \'' . $action . '\':\s*(\w+)\(\);#', $source, $match), 'case ' . $action);

			$body = self::functionBody($source, $match[1]);
			$this->assertStringContainsString('cloudflare_require_post()', $body, $match[1]);
			$this->assertStringContainsString('cloudflare_account_service()', $body, $match[1]);
		}

		/* Writes go through AccountService, whose Authorizer re-checks the realm. */
		$this->assertDoesNotMatchRegularExpression('#AccountRepository|db_execute|->(insert|update)\(#', $source);

		$bootstrap = (string) file_get_contents(self::ROOT . '/includes/bootstrap.php');
		$this->assertStringContainsString('api_user_realm_auth(\'cloudflare_accounts.php\')', $bootstrap);
	}

	public function testAccountsPageEscapesAccountFields(): void {
		$source = (string) file_get_contents(self::ROOT . '/cloudflare_accounts.php');

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
