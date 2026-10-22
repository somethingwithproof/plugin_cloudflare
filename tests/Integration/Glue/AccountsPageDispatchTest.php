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

use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use Cacti\PluginCloudflare\Tests\Integration\IntegrationTestCase;

/**
 * Includes cloudflare_accounts.php itself from a stand-in Cacti tree whose
 * include/auth.php loads the glue stubs. Only the paths that render are
 * run: a redirect ends in exit, which would end the test process too.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AccountsPageDispatchTest extends IntegrationTestCase {
	private const ROOT = __DIR__ . '/../../..';

	/** @var string|null */
	private $cacti;

	/** @var string */
	private $cwd;

	protected function setUp(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();

		require_once self::ROOT . '/tests/Support/CactiGlueStubs.php';
		cloudflare_glue_reset();

		$this->cacti = sys_get_temp_dir() . '/cf-cacti-' . bin2hex(random_bytes(6));
		mkdir($this->cacti . '/include', 0700, true);
		mkdir($this->cacti . '/plugins/cloudflare', 0700, true);
		file_put_contents($this->cacti . '/include/auth.php', '<?php require_once ' . var_export((string) realpath(self::ROOT . '/tests/Support/CactiGlueStubs.php'), true) . ";\n");

		$this->cwd = (string) getcwd();
		chdir($this->cacti . '/plugins/cloudflare');

		$GLOBALS['database_hostname'] = 'cacti-db';
		$GLOBALS['database_port']     = '3306';
		$GLOBALS['database_default']  = 'cacti';
		$GLOBALS['database_sessions'] = array('cacti-db:3306:cacti' => TestDatabase::pdo());
		$GLOBALS['config']            = array('url_path' => '/cacti/');
		$GLOBALS['item_rows']         = array();

		/* An installed plugin, so the page's upgrade call creates the schema. */
		$GLOBALS['cloudflare_glue']['cell']                              = '0.2.0';
		$GLOBALS['cloudflare_glue']['settings']['cloudflare_key_file'] = $this->cacti . '/absent.key';

		$_REQUEST = array();
		$_POST    = array();
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
			include self::ROOT . '/cloudflare_accounts.php';
		} finally {
			$output = (string) ob_get_clean();
		}

		return $output;
	}

	/**
	 * @return array<int, mixed>
	 */
	private static function boxTitles(): array {
		return array_map(function (array $call) {
			return $call[0];
		}, cloudflare_glue_calls('html_start_box'));
	}

	public function testDefaultActionUpgradesThenDrawsTheList(): void {
		$output = $this->page();

		$this->assertSame(realpath($this->cacti), getcwd());
		$this->assertSame(array(array('cloudflare')), array_slice(cloudflare_glue_calls('api_plugin_is_enabled'), 0, 1));
		$this->assertCount(1, cloudflare_glue_calls('db_fetch_cell_prepared'), 'upgrade ran');
		$this->assertSame(array('Cloudflare Accounts', ''), self::boxTitles());
		$this->assertCount(1, cloudflare_glue_calls('top_header'));
		$this->assertCount(1, cloudflare_glue_calls('bottom_footer'));
		$this->assertStringContainsString('No Cloudflare Accounts Found', $output);
	}

	public function testEditActionDrawsTheForm(): void {
		$_REQUEST = array('action' => 'edit');

		$this->page();

		$this->assertSame(array('Cloudflare Account [new]'), self::boxTitles());
		$this->assertCount(1, cloudflare_glue_calls('draw_edit_form'));
	}

	public function testActionsConfirmationIsDrawnForAPost(): void {
		require_once self::ROOT . '/setup.php';
		require_once self::ROOT . '/includes/accounts_page.php';
		plugin_cloudflare_setup_database();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_REQUEST                  = array(
			'save_component_account' => '1',
			'id'                     => '0',
			'name'                   => 'Prod',
			'cf_account_id'          => 'ed17574386854bf78a67040be0a770b0',
			'token_source'           => 'file',
			'token_file'             => $this->cacti . '/absent.token',
			'enabled'                => 'on',
			'discovery_interval'     => '300',
		);
		$this->assertSame(1, preg_match('/id=([0-9]+)$/', cloudflare_accounts_save(), $m));

		cloudflare_glue_reset();
		$GLOBALS['cloudflare_glue']['cell'] = '0.2.0';
		$_REQUEST                           = array('action' => 'actions', 'drp_action' => '2');
		$_POST                              = array('chk_' . $m[1] => 'on');

		$output = $this->page();

		$this->assertSame(array('Verify'), self::boxTitles());
		$this->assertStringContainsString('<li>Prod</li>', $output);
	}
}
