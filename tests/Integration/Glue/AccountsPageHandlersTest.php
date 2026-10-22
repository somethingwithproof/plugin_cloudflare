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

use Cacti\PluginCloudflare\Tests\Support\SecretAssertions;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Drives the cloudflare_accounts.php handlers through the glue stubs, with
 * the real AccountService on the test database. No case reaches the
 * Cloudflare API: verify runs only on file accounts whose file is absent.
 */
final class AccountsPageHandlersTest extends TestCase {
	use SecretAssertions;

	private const ROOT    = __DIR__ . '/../../..';
	private const SESSION = 'cacti-db:3306:cacti';
	private const ACCOUNT = 'ed17574386854bf78a67040be0a770b0';
	private const OTHER   = '0123456789abcdef0123456789abcdef';

	/** @var PDO */
	private $pdo;

	/** @var string */
	private $dir;

	/** @var string */
	private $token;

	public static function setUpBeforeClass(): void {
		require_once self::ROOT . '/tests/Support/CactiGlueStubs.php';
		require_once self::ROOT . '/setup.php';
		require_once self::ROOT . '/includes/accounts_page.php';
	}

	protected function setUp(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();
		cloudflare_glue_reset();

		$this->pdo = TestDatabase::pdo();

		$GLOBALS['database_hostname'] = 'cacti-db';
		$GLOBALS['database_port']     = '3306';
		$GLOBALS['database_default']  = 'cacti';
		$GLOBALS['database_sessions'] = array(self::SESSION => $this->pdo);
		$GLOBALS['config']            = array('url_path' => '/cacti/');
		$GLOBALS['item_rows']         = array(10 => '10', 25 => '25');

		plugin_cloudflare_setup_database();

		$this->dir = sys_get_temp_dir() . '/cf-page-' . bin2hex(random_bytes(6));
		mkdir($this->dir, 0700);
		file_put_contents($this->dir . '/cloudflare.key', bin2hex(random_bytes(32)));
		chmod($this->dir . '/cloudflare.key', 0600);
		$GLOBALS['cloudflare_glue']['settings']['cloudflare_key_file'] = $this->dir . '/cloudflare.key';

		$this->token               = 'cfut_' . bin2hex(random_bytes(20));
		$_REQUEST                  = array();
		$_POST                     = array();
		$_SESSION                  = array();
		$_SERVER['REQUEST_METHOD'] = 'POST';
	}

	protected function tearDown(): void {
		$_REQUEST = array();
		$_POST    = array();
		$_SESSION = array();
		unset($_SERVER['REQUEST_METHOD'], $GLOBALS['database_sessions'], $GLOBALS['database_hostname'], $GLOBALS['database_port'], $GLOBALS['database_default'], $GLOBALS['config'], $GLOBALS['item_rows'], $GLOBALS['cloudflare_key_refusal_logged']);

		if ($this->dir !== null && is_dir($this->dir)) {
			foreach ((array) glob($this->dir . '/*') as $file) {
				unlink((string) $file);
			}
			rmdir($this->dir);
		}

		if ($this->pdo !== null) {
			TestDatabase::reset();
		}
	}

	/**
	 * @param array<string, string> $overrides
	 * @return array<string, string>
	 */
	private function form(array $overrides = array()): array {
		return array_merge(array(
			'action'                 => 'save',
			'save_component_account' => '1',
			'id'                     => '0',
			'name'                   => 'Prod',
			'cf_account_id'          => self::ACCOUNT,
			'token_source'           => 'vault',
			'token'                  => $this->token,
			'token_confirm'          => $this->token,
			'token_file'             => '',
			'enabled'                => 'on',
			'collect_tunnels'        => 'on',
			'discovery_interval'     => '300',
		), $overrides);
	}

	/**
	 * A file account whose file is absent, so verify fails before any HTTP.
	 */
	private function fileAccount(string $name, string $cfAccountId): int {
		$_REQUEST = $this->form(array(
			'name'          => $name,
			'cf_account_id' => $cfAccountId,
			'token_source'  => 'file',
			'token_file'    => $this->dir . '/absent.token',
		));

		$redirect = cloudflare_accounts_save();
		$this->assertSame(1, preg_match('/^&action=edit&id=([0-9]+)$/', $redirect, $m), $redirect);

		cloudflare_glue_reset();
		$GLOBALS['cloudflare_glue']['settings']['cloudflare_key_file'] = $this->dir . '/cloudflare.key';
		$_REQUEST = array();

		return (int) $m[1];
	}

	private function accountCount(): int {
		return (int) $this->pdo->query('SELECT COUNT(*) FROM plugin_cloudflare_accounts')->fetchColumn();
	}

	/**
	 * @return array<string, mixed>|false
	 */
	private function row(int $id) {
		$stmt = $this->pdo->prepare('SELECT * FROM plugin_cloudflare_accounts WHERE id = ?');
		$stmt->execute(array($id));

		return $stmt->fetch(PDO::FETCH_ASSOC);
	}

	/**
	 * @return array<int, mixed> the message ids passed to raise_message()
	 */
	private static function messageIds(): array {
		return array_map(function (array $call) {
			return $call[0];
		}, cloudflare_glue_calls('raise_message'));
	}

	/**
	 * Everything the stubs recorded, the session and the printed output.
	 */
	private function trace(string $output = ''): string {
		return var_export($GLOBALS['cloudflare_glue']['calls'], true) . var_export($_SESSION, true) . $output;
	}

	/**
	 * @param callable(): mixed $handler
	 * @return array{0: mixed, 1: string}
	 */
	private static function capture(callable $handler): array {
		ob_start();

		try {
			$returned = $handler();
		} finally {
			$output = (string) ob_get_clean();
		}

		return array($returned, $output);
	}

	public function testSaveRefusesGetBeforeReadingInput(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_REQUEST                  = $this->form();

		$this->assertSame('', cloudflare_accounts_save());
		$this->assertSame(0, $this->accountCount());
		$this->assertSame(array(), cloudflare_glue_calls('raise_message'));
	}

	public function testSaveRefusesAMissingMethod(): void {
		unset($_SERVER['REQUEST_METHOD']);
		$_REQUEST = $this->form();

		$this->assertSame('', cloudflare_accounts_save());
		$this->assertSame(0, $this->accountCount());
	}

	public function testSaveWithoutTheFormMarkerChangesNothing(): void {
		$_REQUEST = $this->form();
		unset($_REQUEST['save_component_account']);

		$this->assertSame('', cloudflare_accounts_save());
		$this->assertSame(0, $this->accountCount());
		$this->assertSame(array(), cloudflare_glue_calls('raise_message'));
	}

	public function testSaveStoresTheAccountAndReturnsToItsEditPage(): void {
		$_REQUEST = $this->form();

		$redirect = cloudflare_accounts_save();

		$id = (int) $this->pdo->query('SELECT id FROM plugin_cloudflare_accounts')->fetchColumn();
		$this->assertGreaterThan(0, $id);
		$this->assertSame('&action=edit&id=' . $id, $redirect);
		$this->assertSame(array(1), self::messageIds());

		$row = $this->row($id);
		$this->assertIsArray($row);
		$this->assertSame('Prod', $row['name']);
		$this->assertNotLeaked($this->token, var_export($row, true) . $this->trace(), 'saved row and page state');
	}

	public function testSaveUpdatesAnExistingAccount(): void {
		$id       = $this->fileAccount('Before', self::ACCOUNT);
		$_REQUEST = $this->form(array('id' => (string) $id, 'name' => 'After', 'token_source' => 'file', 'token_file' => $this->dir . '/absent.token'));

		$this->assertSame('&action=edit&id=' . $id, cloudflare_accounts_save());

		$row = $this->row($id);
		$this->assertIsArray($row);
		$this->assertSame('After', $row['name']);
		$this->assertSame(1, $this->accountCount());
	}

	public function testInvalidSaveKeepsTheFormWithoutTheToken(): void {
		$_REQUEST = $this->form(array('name' => 'Prod <b>', 'cf_account_id' => 'not-an-id'));

		$this->assertSame('&action=edit&id=0', cloudflare_accounts_save());

		$this->assertSame(0, $this->accountCount());
		$this->assertSame(array('cf_account_id' => 'cf_account_id'), $_SESSION['sess_error_fields']);
		$this->assertSame('Prod <b>', $_SESSION['sess_field_values']['name']);
		$this->assertSame('not-an-id', $_SESSION['sess_field_values']['cf_account_id']);
		$this->assertArrayNotHasKey('token', $_SESSION['sess_field_values']);

		$messages = cloudflare_glue_calls('raise_message');
		$this->assertCount(1, $messages);
		$this->assertSame(array('cloudflare_invalid', MESSAGE_LEVEL_ERROR), array($messages[0][0], $messages[0][2]));
		$this->assertNotLeaked($this->token, $this->trace(), 'invalid save');
	}

	public function testTokenConfirmationMismatchKeepsTheFormWithoutEitherToken(): void {
		$_REQUEST = $this->form(array('token_confirm' => $this->token . 'x'));

		$this->assertSame('&action=edit&id=0', cloudflare_accounts_save());

		$this->assertSame(0, $this->accountCount());
		$this->assertSame(array('token' => 'token'), $_SESSION['sess_error_fields']);
		$this->assertNotLeaked($this->token, $this->trace(), 'mismatched token');
	}

	public function testSaveWithoutTheRealmIsDenied(): void {
		$GLOBALS['cloudflare_glue']['realm'] = false;
		$_REQUEST                            = $this->form();

		$this->assertSame('', cloudflare_accounts_save());

		$this->assertSame(0, $this->accountCount());
		$this->assertSame(array('cloudflare_denied'), self::messageIds());
		$this->assertSame(array(array('cloudflare_accounts.php')), array_slice(cloudflare_glue_calls('api_user_realm_auth'), 0, 1));
	}

	public function testSavingAnAccountThatWasDeletedReportsItMissing(): void {
		$_REQUEST = $this->form(array('id' => '999'));

		$this->assertSame('', cloudflare_accounts_save());

		$this->assertSame(0, $this->accountCount());
		$this->assertSame(array('cloudflare_missing'), self::messageIds());
	}

	public function testDatabaseFailureOnSaveIsLoggedWithoutTheToken(): void {
		TestDatabase::reset();
		$_REQUEST = $this->form();

		$this->assertSame('&action=edit&id=0', cloudflare_accounts_save());

		$this->assertSame(array(2), self::messageIds());
		$logged = cloudflare_glue_calls('cacti_log');
		$this->assertCount(1, $logged);
		$this->assertStringStartsWith('CLOUDFLARE: account save failed: ', (string) $logged[0][0]);
		$this->assertNotLeaked($this->token, $this->trace(), 'failed save');
	}

	public function testActionsRefuseGet(): void {
		$id                        = $this->fileAccount('Prod', self::ACCOUNT);
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_REQUEST                  = array('drp_action' => '1', 'selected_items' => serialize(array($id)));

		$this->assertSame('', cloudflare_accounts_actions());
		$this->assertSame(1, $this->accountCount());
	}

	public function testUnknownActionChangesNothing(): void {
		$id       = $this->fileAccount('Prod', self::ACCOUNT);
		$_REQUEST = array('drp_action' => '9', 'selected_items' => serialize(array($id)));

		$this->assertSame('', cloudflare_accounts_actions());
		$this->assertSame(1, $this->accountCount());
		$this->assertSame(array(), cloudflare_glue_calls('raise_message'));
	}

	public function testConfirmationListsOnlyKnownAccountsEscaped(): void {
		$id       = $this->fileAccount('Prod <b>&</b>', self::ACCOUNT);
		$_REQUEST = array('drp_action' => '1');
		$_POST    = array('chk_' . $id => 'on', 'chk_999' => 'on', 'drp_action' => '1');

		list($redirect, $output) = self::capture('cloudflare_accounts_actions');

		$this->assertNull($redirect);
		$this->assertStringContainsString('<li>Prod &lt;b&gt;&amp;&lt;/b&gt;</li>', $output);
		$this->assertStringNotContainsString('<b>', $output);
		$this->assertStringContainsString("name='selected_items' value='" . html_escape(serialize(array($id))) . "'", $output);
		$this->assertStringContainsString("name='drp_action' value='1'", $output);
		$this->assertStringContainsString('Click &apos;Continue&apos; to Delete the following Cloudflare Account.', $output);
		$this->assertCount(1, cloudflare_glue_calls('top_header'));
		$this->assertCount(1, cloudflare_glue_calls('bottom_footer'));
		$this->assertSame(1, $this->accountCount());
	}

	public function testConfirmationUsesThePluralPrompt(): void {
		$first    = $this->fileAccount('One', self::ACCOUNT);
		$second   = $this->fileAccount('Two', self::OTHER);
		$_REQUEST = array('drp_action' => '4');
		$_POST    = array('chk_' . $first => 'on', 'chk_' . $second => 'on');

		list(, $output) = self::capture('cloudflare_accounts_actions');

		$this->assertStringContainsString('to Disable the following Cloudflare Accounts.', $output);
		$this->assertStringContainsString('<li>One</li><li>Two</li>', $output);
	}

	public function testConfirmationWithNothingSelectedReturnsToTheList(): void {
		$this->fileAccount('Prod', self::ACCOUNT);
		$_REQUEST = array('drp_action' => '1');
		$_POST    = array('chk_x' => 'on');

		list($redirect, $output) = self::capture('cloudflare_accounts_actions');

		$this->assertSame('', $redirect);
		$this->assertSame('', $output);
		$this->assertSame(array(40), self::messageIds());
	}

	public function testDeleteRemovesTheSelectedAccounts(): void {
		$first    = $this->fileAccount('One', self::ACCOUNT);
		$second   = $this->fileAccount('Two', self::OTHER);
		$_REQUEST = array('drp_action' => '1', 'selected_items' => serialize(array($first)));

		$this->assertSame('', cloudflare_accounts_actions());

		$this->assertFalse($this->row($first));
		$this->assertIsArray($this->row($second));
		$this->assertSame(array(1), self::messageIds());
	}

	public function testDisableAndEnableToggleTheAccount(): void {
		$id = $this->fileAccount('Prod', self::ACCOUNT);

		$_REQUEST = array('drp_action' => '4', 'selected_items' => serialize(array($id)));
		cloudflare_accounts_actions();
		$row = $this->row($id);
		$this->assertIsArray($row);
		$this->assertSame(0, (int) $row['enabled']);

		$_REQUEST = array('drp_action' => '3', 'selected_items' => serialize(array($id)));
		cloudflare_accounts_actions();
		$row = $this->row($id);
		$this->assertIsArray($row);
		$this->assertSame(1, (int) $row['enabled']);
	}

	public function testVerifyReportsEachAccountEscapedAtWarningLevel(): void {
		$id       = $this->fileAccount('Prod <i>', self::ACCOUNT);
		$_REQUEST = array('drp_action' => '2', 'selected_items' => serialize(array($id)));

		$this->assertSame('', cloudflare_accounts_actions());

		$row = $this->row($id);
		$this->assertIsArray($row);
		$this->assertSame('error', $row['status']);

		$messages = cloudflare_glue_calls('raise_message');
		$this->assertCount(1, $messages);
		$this->assertSame('cloudflare_verify', $messages[0][0]);
		$this->assertSame(MESSAGE_LEVEL_WARN, $messages[0][2]);
		$this->assertStringStartsWith('Prod &lt;i&gt;: ', (string) $messages[0][1]);
	}

	public function testVerifyOfAnAccountDeletedMeanwhileSaysNothing(): void {
		$_REQUEST = array('drp_action' => '2', 'selected_items' => serialize(array(999)));

		$this->assertSame('', cloudflare_accounts_actions());
		$this->assertSame(array(), cloudflare_glue_calls('raise_message'));
	}

	public function testDeleteOfAnAccountDeletedMeanwhileStillSucceeds(): void {
		$id       = $this->fileAccount('Prod', self::ACCOUNT);
		$_REQUEST = array('drp_action' => '1', 'selected_items' => serialize(array(999, $id)));

		$this->assertSame('', cloudflare_accounts_actions());
		$this->assertSame(0, $this->accountCount());
		$this->assertSame(array(1), self::messageIds());
	}

	public function testTamperedSelectionIsIgnored(): void {
		$id       = $this->fileAccount('Prod', self::ACCOUNT);
		$_REQUEST = array('drp_action' => '1', 'selected_items' => 'O:8:"stdClass":0:{}');

		$this->assertSame('', cloudflare_accounts_actions());
		$this->assertIsArray($this->row($id));
		$this->assertSame(array(), cloudflare_glue_calls('raise_message'));
	}

	public function testActionWithoutTheRealmIsDeniedAndStops(): void {
		$id                                  = $this->fileAccount('Prod', self::ACCOUNT);
		$GLOBALS['cloudflare_glue']['realm'] = false;
		$_REQUEST                            = array('drp_action' => '1', 'selected_items' => serialize(array($id)));

		$this->assertSame('', cloudflare_accounts_actions());

		$this->assertIsArray($this->row($id));
		$this->assertSame(array('cloudflare_denied'), self::messageIds());
	}

	public function testDatabaseFailureDuringAnActionIsLogged(): void {
		$id = $this->fileAccount('Prod', self::ACCOUNT);
		TestDatabase::reset();
		$_REQUEST = array('drp_action' => '1', 'selected_items' => serialize(array($id)));

		$this->assertSame('', cloudflare_accounts_actions());

		$this->assertSame(array(2), self::messageIds());
		$logged = cloudflare_glue_calls('cacti_log');
		$this->assertCount(1, $logged);
		$this->assertStringStartsWith('CLOUDFLARE: account action 1 failed: ', (string) $logged[0][0]);
	}

	/**
	 * The schema is gone, as when the plugin files are present but the
	 * tables were dropped. An exception escaping a page handler would reach
	 * Cacti's shutdown handler, which disables the plugin.
	 */
	private function dropAccountsTable(): void {
		$this->pdo->exec('DROP TABLE IF EXISTS plugin_cloudflare_jobs');
		$this->pdo->exec('DROP TABLE plugin_cloudflare_accounts');
		cloudflare_glue_reset();
		$GLOBALS['cloudflare_glue']['settings']['cloudflare_key_file'] = $this->dir . '/cloudflare.key';
	}

	private function assertUnreadableReported(string $context): void {
		$this->assertSame(array(array(2, 'The Cloudflare accounts could not be read. See the Cacti log for details.', MESSAGE_LEVEL_ERROR)), cloudflare_glue_calls('raise_message'));

		$logged = cloudflare_glue_calls('cacti_log');
		$this->assertCount(1, $logged);
		$this->assertSame('CLOUDFLARE: account ' . $context . ' failed: SQLSTATE[42S02] driver error 1146', (string) $logged[0][0]);
	}

	public function testListWithoutTheAccountsTableReportsItInsteadOfThrowing(): void {
		$this->dropAccountsTable();
		$_REQUEST = array('filter' => '', 'rows' => '-1', 'page' => '1', 'sort_column' => 'name', 'sort_direction' => 'ASC');

		list(, $output) = self::capture('cloudflare_accounts_list');

		$this->assertUnreadableReported('list');
		$this->assertStringContainsString('The Cloudflare accounts could not be read. See the Cacti log for details.', $output);
		$this->assertStringNotContainsString('No Cloudflare Accounts Found', $output);
		$this->assertSame(array(), cloudflare_glue_calls('form_selectable_cell'));
	}

	public function testEditWithoutTheAccountsTableReturnsToTheList(): void {
		$id = $this->fileAccount('Prod', self::ACCOUNT);
		$this->dropAccountsTable();
		$_REQUEST = array('id' => (string) $id);

		list($redirect, $output) = self::capture('cloudflare_accounts_edit');

		$this->assertSame('', $redirect);
		$this->assertSame('', $output);
		$this->assertSame(array(), cloudflare_glue_calls('top_header'));
		$this->assertUnreadableReported('edit');
	}

	public function testConfirmationWithoutTheAccountsTableReturnsToTheList(): void {
		$id = $this->fileAccount('Prod', self::ACCOUNT);
		$this->dropAccountsTable();
		$_REQUEST = array('drp_action' => '1');
		$_POST    = array('chk_' . $id => 'on', 'drp_action' => '1');

		list($redirect, $output) = self::capture('cloudflare_accounts_actions');

		$this->assertSame('', $redirect);
		$this->assertSame('', $output);
		$this->assertSame(array(), cloudflare_glue_calls('top_header'));
		$this->assertUnreadableReported('confirmation');
	}

	public function testEditWithANonNumericIdStopsBeforeAnyOutput(): void {
		$_REQUEST = array('id' => '1 OR 1=1');

		ob_start();

		try {
			cloudflare_accounts_edit();
			$this->fail('get_filter_request_var accepted a non-numeric id');
		} catch (\RuntimeException $e) {
			$this->assertSame('die_html_input_error(id)', $e->getMessage());
		} finally {
			$this->assertSame('', (string) ob_get_clean());
		}

		$this->assertSame(array(), cloudflare_glue_calls('top_header'));
	}

	public function testEditOfAMissingAccountReturnsToTheList(): void {
		$_REQUEST = array('id' => '999');

		list($redirect, $output) = self::capture('cloudflare_accounts_edit');

		$this->assertSame('', $redirect);
		$this->assertSame('', $output);
		$this->assertSame(array('cloudflare_missing'), self::messageIds());
		$this->assertSame(array(), cloudflare_glue_calls('top_header'));
	}

	public function testEditOfANewAccountDrawsAnEmptyForm(): void {
		list($redirect, $output) = self::capture('cloudflare_accounts_edit');

		$this->assertNull($redirect);

		$form = cloudflare_glue_calls('draw_edit_form');
		$this->assertCount(1, $form);
		$this->assertSame('', $form[0][0]['fields']['name']['value']);
		$this->assertArrayHasKey('token', $form[0][0]['fields'], 'the vault is available');

		$boxes = cloudflare_glue_calls('html_start_box');
		$this->assertCount(1, $boxes);
		$this->assertSame('Cloudflare Account [new]', $boxes[0][0]);
		$this->assertStringContainsString("src='/cacti/plugins/cloudflare/js/cloudflare_accounts.js'", $output);
		$this->assertCount(1, cloudflare_glue_calls('form_save_button'));
	}

	public function testEditWithoutTheVaultOffersOnlyTheFileSource(): void {
		$GLOBALS['cloudflare_glue']['settings']['cloudflare_key_file'] = $this->dir . '/absent.key';

		self::capture('cloudflare_accounts_edit');

		$form = cloudflare_glue_calls('draw_edit_form');
		$this->assertArrayNotHasKey('token', $form[0][0]['fields']);
	}

	public function testEditOfAStoredAccountShowsItsEscapedStatusAndNoToken(): void {
		$_REQUEST = $this->form(array('name' => 'Prod <b>'));
		cloudflare_accounts_save();
		$id = (int) $this->pdo->query('SELECT id FROM plugin_cloudflare_accounts')->fetchColumn();
		$this->pdo->prepare('UPDATE plugin_cloudflare_accounts SET status = ?, status_message = ?, permissions = ? WHERE id = ?')
			->execute(array('permission_denied', 'Missing <tunnel>', '{"tunnel_read":false,"zone_read":true}', $id));

		cloudflare_glue_reset();
		$GLOBALS['cloudflare_glue']['settings']['cloudflare_key_file'] = $this->dir . '/cloudflare.key';
		$_REQUEST = array('id' => (string) $id);

		list($redirect, $output) = self::capture('cloudflare_accounts_edit');

		$this->assertNull($redirect);
		$boxes = cloudflare_glue_calls('html_start_box');
		$this->assertSame('Cloudflare Account [edit: Prod &lt;b&gt;]', $boxes[0][0]);
		$this->assertSame('Connection Status', $boxes[1][0]);
		$this->assertStringContainsString('<td>permission_denied</td>', $output);
		$this->assertStringContainsString('<td>Missing &lt;tunnel&gt;</td>', $output);
		$this->assertStringContainsString('<td style="width:20%">Token Stored</td><td>Yes</td>', $output);
		$this->assertStringContainsString('<td style="width:20%">Last Verified</td><td>Never</td>', $output);
		$this->assertStringContainsString('<td style="width:20%">Permission: tunnel_read</td><td>Missing</td>', $output);
		$this->assertStringContainsString('<td style="width:20%">Permission: zone_read</td><td>Granted</td>', $output);
		$this->assertStringNotContainsString('<tunnel>', $output);
		$this->assertNotLeaked($this->token, $this->trace($output), 'edit page');
	}

	public function testEditOfAFileAccountShowsItsPathFromTheCredentialDetails(): void {
		$id       = $this->fileAccount('Prod', self::ACCOUNT);
		$_REQUEST = array('id' => (string) $id);

		self::capture('cloudflare_accounts_edit');

		$fields = cloudflare_glue_calls('draw_edit_form')[0][0]['fields'];
		$this->assertSame('file', $fields['token_source']['value']);
		$this->assertSame($this->dir . '/absent.token', $fields['token_file']['value']);
	}

	public function testEditWithoutTheConfigureRightIsDenied(): void {
		$id                                  = $this->fileAccount('Prod', self::ACCOUNT);
		$GLOBALS['cloudflare_glue']['realm'] = false;
		$_REQUEST                            = array('id' => (string) $id);

		list($redirect, $output) = self::capture('cloudflare_accounts_edit');

		$this->assertSame('', $redirect);
		$this->assertSame('', $output);
		$this->assertSame(array('cloudflare_denied'), self::messageIds());
		$this->assertSame(array(), cloudflare_glue_calls('draw_edit_form'));
	}

	public function testListShowsAccountsEscapedWithWhetherATokenIsStored(): void {
		$this->fileAccount('Zed <i>', self::OTHER);
		$_REQUEST = $this->form(array('name' => 'Alpha'));
		cloudflare_accounts_save();
		cloudflare_glue_reset();
		$GLOBALS['cloudflare_glue']['settings']['cloudflare_key_file'] = $this->dir . '/cloudflare.key';
		$_REQUEST = array('filter' => "x'><script>", 'rows' => '-1', 'page' => '1', 'sort_column' => 'name', 'sort_direction' => 'ASC');

		list(, $output) = self::capture('cloudflare_accounts_list');

		$this->assertStringContainsString("<label for='filter'>Search</label>", $output);
		$this->assertStringContainsString("<label for='rows'>Accounts</label>", $output);
		$this->assertStringContainsString("<table class='filterTable' role='presentation'>", $output);
		$this->assertStringContainsString("value='x&apos;&gt;&lt;script&gt;'", $output);
		$this->assertStringNotContainsString('<script>', $output);
		$this->assertStringContainsString("<option value='25'>25</option>", $output);
		$this->assertStringContainsString('No Cloudflare Accounts Found', $output, 'the filter matches nothing');

		cloudflare_glue_reset();
		$GLOBALS['cloudflare_glue']['settings']['num_rows_table'] = '30';
		$_REQUEST['filter'] = '';

		list(, $output) = self::capture('cloudflare_accounts_list');

		$cells = array_map(function (array $call): string {
			return (string) $call[0];
		}, cloudflare_glue_calls('form_selectable_cell'));
		$this->assertStringContainsString('>Alpha</a>', $cells[0]);
		$this->assertSame('Yes', $cells[2]);
		$this->assertStringContainsString('>Zed &lt;i&gt;</a>', $cells[7]);
		$this->assertSame('Yes', $cells[9]);
		$this->assertStringNotContainsString($this->dir, $this->trace($output), 'the token file path is not listed');
		$this->assertSame(array('Zed &lt;i&gt;'), array_slice(array_map(function (array $call): string {
			return (string) $call[0];
		}, cloudflare_glue_calls('form_checkbox_cell')), 1));
		$this->assertStringNotContainsString('No Cloudflare Accounts Found', $output);

		$nav = cloudflare_glue_calls('html_nav_bar');
		$this->assertSame(array(1, 30, 2), array($nav[0][2], $nav[0][3], $nav[0][4]));
		$this->assertSame(array(array(cloudflare_account_actions(), 1)), cloudflare_glue_calls('draw_actions_dropdown'));
		$this->assertNotLeaked($this->token, $this->trace($output), 'list page');
	}

	public function testListPagesAndSorts(): void {
		$this->fileAccount('One', self::ACCOUNT);
		$this->fileAccount('Two', self::OTHER);
		$_REQUEST = array('filter' => '', 'rows' => '1', 'page' => '2', 'sort_column' => 'name', 'sort_direction' => 'DESC', 'action' => '');

		self::capture('cloudflare_accounts_list');

		$cells = cloudflare_glue_calls('form_selectable_cell');
		$this->assertCount(7, $cells, 'one row of seven columns');
		$this->assertStringContainsString('>One</a>', (string) $cells[0][0]);
	}

	public function testListFlagsAVaultAccountWhoseTokenIsGone(): void {
		$_REQUEST = $this->form();
		cloudflare_accounts_save();
		$this->pdo->exec('UPDATE plugin_cloudflare_accounts SET token_ciphertext = NULL');
		cloudflare_glue_reset();
		$GLOBALS['cloudflare_glue']['settings']['cloudflare_key_file'] = $this->dir . '/cloudflare.key';
		$_REQUEST = array('filter' => '', 'rows' => '-1', 'page' => '1', 'sort_column' => 'name', 'sort_direction' => 'ASC');

		self::capture('cloudflare_accounts_list');

		$this->assertSame('No', cloudflare_glue_calls('form_selectable_cell')[2][0]);
	}
}
