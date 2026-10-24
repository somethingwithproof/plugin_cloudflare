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

namespace Cacti\PluginCloudflare\Tests\Integration\Glue;

use Cacti\PluginCloudflare\Tests\Integration\IntegrationTestCase;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;

/**
 * Includes cloudflare.php itself from a stand-in Cacti tree whose
 * include/auth.php loads the glue stubs, so each action is dispatched as
 * Cacti would. The refusal ends in exit and is covered by ViewerPageTest.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ViewerPageDispatchTest extends IntegrationTestCase {
	private const ROOT = __DIR__ . '/../../..';

	private const TAB = "<a class='tab selected' href='/cacti/plugins/cloudflare/cloudflare.php?action=";

	/** @var string|null */
	private $cacti;

	/** @var string */
	private $cwd;

	protected function setUp(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();

		require_once self::ROOT . '/tests/Support/CactiGlueStubs.php';
		cloudflare_glue_reset();

		$stubs = (string) realpath(self::ROOT . '/tests/Support/CactiGlueStubs.php');

		$this->cacti = sys_get_temp_dir() . '/cf-cacti-' . bin2hex(random_bytes(6));
		mkdir($this->cacti . '/include', 0700, true);
		mkdir($this->cacti . '/plugins/cloudflare', 0700, true);
		file_put_contents($this->cacti . '/include/auth.php', '<?php require_once ' . var_export($stubs, true) . ";\n");

		$this->cwd = (string) getcwd();
		chdir($this->cacti . '/plugins/cloudflare');

		$GLOBALS['database_hostname'] = 'cacti-db';
		$GLOBALS['database_port']     = '3306';
		$GLOBALS['database_default']  = 'cacti';
		$GLOBALS['database_sessions'] = array('cacti-db:3306:cacti' => TestDatabase::pdo());
		$GLOBALS['config']            = array('url_path' => '/cacti/');
		$GLOBALS['item_rows']         = array();

		/* An installed plugin, so the page's upgrade call creates the schema. */
		$GLOBALS['cloudflare_glue']['cell'] = '0.3.0';

		$_REQUEST = array();
		$_SESSION = array();
	}

	protected function tearDown(): void {
		if ($this->cacti === null) {
			return;
		}

		chdir($this->cwd);

		unlink($this->cacti . '/include/auth.php');
		rmdir($this->cacti . '/include');
		rmdir($this->cacti . '/plugins/cloudflare');
		rmdir($this->cacti . '/plugins');
		rmdir($this->cacti);

		TestDatabase::reset();
	}

	private function page(): string {
		ob_start();

		try {
			include self::ROOT . '/cloudflare.php';
		} finally {
			$output = (string) ob_get_clean();
		}

		return $output;
	}

	public function testDefaultActionUpgradesThenDrawsTheOverview(): void {
		$output = $this->page();

		$this->assertSame(realpath($this->cacti), getcwd());
		$this->assertCount(1, cloudflare_glue_calls('db_fetch_cell_prepared'), 'upgrade ran');
		$this->assertCount(1, cloudflare_glue_calls('top_header'));
		$this->assertCount(1, cloudflare_glue_calls('bottom_footer'));
		$this->assertStringContainsString(self::TAB . "overview'>", $output);
		$this->assertStringContainsString('No Cloudflare Accounts Found', $output);
	}

	public function testTunnelsActionDrawsTheFilteredList(): void {
		$_REQUEST = array('action' => 'tunnels');

		$output = $this->page();

		$this->assertStringContainsString(self::TAB . "tunnels'>", $output);
		$this->assertStringContainsString("<form id='form_cloudflare_tunnels' action='cloudflare.php'>", $output);
		$this->assertStringContainsString('No Cloudflare Tunnels Found', $output);
		$this->assertStringContainsString('js/cloudflare_viewer.js', $output);
		$this->assertCount(1, cloudflare_glue_calls('html_nav_bar'));
	}

	public function testConnectorsActionAsksForATunnel(): void {
		$_REQUEST = array('action' => 'connectors');

		$output = $this->page();

		$this->assertStringContainsString(self::TAB . "connectors'>", $output);
		$this->assertStringContainsString('Choose a tunnel on the Tunnels tab.', $output);
	}

	/*
	 * An uncaught error would reach CactiShutdownHandler, which disables the
	 * plugin. The schema is missing here, so the handler logs a read failure,
	 * and that log call throws to stand in for any unexpected error.
	 */
	public function testUnexpectedErrorIsLoggedByClassAndRendered(): void {
		$GLOBALS['cloudflare_glue']['cell'] = false;
		$thrown                             = false;
		$GLOBALS['cloudflare_glue']['on_log'] = function () use (&$thrown): void {
			if (!$thrown) {
				$thrown = true;

				throw new \RuntimeException('detail that must not be logged');
			}
		};

		$output = $this->page();

		$this->assertTrue($thrown);
		$lines = array_map(function (array $call): string {
			return (string) $call[0];
		}, cloudflare_glue_calls('cacti_log'));
		$this->assertContains('CLOUDFLARE: viewer page failed (RuntimeException)', $lines);
		$this->assertStringNotContainsString('must not be logged', implode("\n", $lines) . $output);
		$this->assertStringContainsString(
			'The Cloudflare tunnels could not be read. See the Cacti log for details.',
			$output
		);
		$this->assertNotSame(array(), cloudflare_glue_calls('bottom_footer'));
	}
}
