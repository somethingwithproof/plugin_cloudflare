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

use PHPUnit\Framework\TestCase;
use ReflectionFunction;

/**
 * Checks of ss_cloudflare.php that need no database: where the script
 * server finds the function, and that the poller path has no network code.
 */
final class ScriptServerTest extends TestCase {
	private const ROOT = __DIR__ . '/../../..';

	public static function setUpBeforeClass(): void {
		require_once self::ROOT . '/tests/Support/CactiGlueStubs.php';

		self::includeAsScriptServer();
	}

	protected function setUp(): void {
		cloudflare_glue_reset();
	}

	/**
	 * Includes the script the way script_server.php does: $called_by_script_server
	 * is set in the including scope, and the script's error_reporting(0) would
	 * otherwise silence the rest of the suite.
	 */
	private static function includeAsScriptServer(): void {
		$called_by_script_server = true;
		$level                   = error_reporting();

		ob_start();
		include_once self::ROOT . '/ss_cloudflare.php';
		ob_end_clean();

		error_reporting($level);
	}

	/* script_server.php:358 in 1.2.31 rejects a function whose file lies outside base_path. */
	public function testScriptDefinesFunctionInItsOwnFile(): void {
		$this->assertTrue(function_exists('ss_cloudflare'));

		$fn = new ReflectionFunction('ss_cloudflare');

		$this->assertFalse($fn->isInternal());
		$this->assertSame((string) realpath(self::ROOT . '/ss_cloudflare.php'), $fn->getFileName());
		$this->assertSame(array('host_id', 'cmd', 'arg1', 'arg2'), array_map(static function ($p): string {
			return $p->getName();
		}, $fn->getParameters()));

		foreach ($fn->getParameters() as $param) {
			$this->assertTrue($param->isDefaultValueAvailable(), $param->getName());
			$this->assertSame('', $param->getDefaultValue(), $param->getName());
		}
	}

	public function testScriptFollowsTheHostCpuPattern(): void {
		$source = (string) file_get_contents(self::ROOT . '/ss_cloudflare.php');
		$code   = substr($source, (int) strpos($source, "*/\n") + 3);

		$this->assertMatchesRegularExpression('#^\s*error_reporting\(0\);#', $code, 'error_reporting(0) comes first');
		$this->assertMatchesRegularExpression('#if \(!isset\(\$called_by_script_server\)\) \{#', $code);
		$this->assertStringContainsString("include_once(__DIR__ . '/../../include/cli_check.php');", $code);
		$this->assertStringContainsString("print call_user_func_array('ss_cloudflare', \$_SERVER['argv']);", $code);
		$this->assertLessThan(
			(int) strpos($code, 'print call_user_func_array'),
			(int) strpos($code, 'cli_check.php'),
			'the CLI path loads Cacti before calling the function'
		);
	}

	/* The poller path reads plugin tables only; Cloudflare is the collector's job. */
	public function testScriptMakesNoTransportCalls(): void {
		foreach (array('ss_cloudflare.php', 'includes/script_server.php') as $file) {
			$source = (string) file_get_contents(self::ROOT . '/' . $file);

			$this->assertStringNotContainsString('CloudflareClient', $source, $file);
			$this->assertStringNotContainsString('CurlTransport', $source, $file);
			$this->assertDoesNotMatchRegularExpression('#\bcurl_#', $source, $file);
			$this->assertDoesNotMatchRegularExpression(
				'#\b(fsockopen|file_get_contents|stream_socket_client)\s*\(#',
				$source,
				$file
			);
		}
	}

	public function testBadHostIdNeverReachesTheDatabase(): void {
		foreach (array('', '0', '01', '-1', '1;DROP', '1 ', "1\n", '12345678901', 'abc') as $host) {
			$this->assertSame('', cloudflare_ss($host, 'index', '', ''), var_export($host, true));
			$this->assertSame('0', cloudflare_ss($host, 'num_indexes', '', ''), var_export($host, true));
			$this->assertSame('', cloudflare_ss($host, 'query', 'tunnelId', ''), var_export($host, true));
			$this->assertSame(
				'U',
				cloudflare_ss($host, 'get', 'health', '00000001-0000-4000-8000-000000000001'),
				var_export($host, true)
			);
		}

		$this->assertSame(array(), cloudflare_glue_calls('db_connect_real'));
		$this->assertSame(array(), cloudflare_glue_calls('cacti_log'));
	}

	public function testUnreachableDatabaseLogsOnlyTheClassAtHigh(): void {
		unset($GLOBALS['database_sessions']);

		$this->assertSame('U', ss_cloudflare('7', 'get', 'health', '00000001-0000-4000-8000-000000000001'));
		$this->assertSame('U', ss_cloudflare('7', 'num_indexes'));
		$this->assertSame('', ss_cloudflare('7', 'index'));
		$this->assertSame('', ss_cloudflare('7', 'query', 'tunnelName'));

		$logged = cloudflare_glue_calls('cacti_log');
		$this->assertCount(4, $logged);
		foreach ($logged as $call) {
			$this->assertSame(
				array('CLOUDFLARE: script server failed (Cacti\PluginCloudflare\Storage\DatabaseException)',
					false, 'CLOUDFLARE', POLLER_VERBOSITY_HIGH),
				$call
			);
		}
	}

	public function testUnknownCommandPrintsU(): void {
		$this->assertSame('U', cloudflare_ss('7', 'walk', '', ''));
		$this->assertSame('U', cloudflare_ss('7', '', '', ''));
		$this->assertSame(array(), cloudflare_glue_calls('db_connect_real'));
	}
}
