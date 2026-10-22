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

use Cacti\PluginCloudflare\Account\Account;
use Cacti\PluginCloudflare\Account\ValidationException;
use Cacti\PluginCloudflare\Tests\Support\SecretAssertions;
use PHPUnit\Framework\TestCase;

final class AccountsPageTest extends TestCase {
	use SecretAssertions;

	private const ROOT = __DIR__ . '/../../..';

	/** @var string */
	private $token;

	public static function setUpBeforeClass(): void {
		require_once self::ROOT . '/tests/Support/CactiGlueStubs.php';
		require_once self::ROOT . '/includes/accounts.php';
	}

	protected function setUp(): void {
		cloudflare_glue_reset();
		$_REQUEST    = array();
		$this->token = 'cfut_' . bin2hex(random_bytes(20));
	}

	protected function tearDown(): void {
		$_REQUEST = array();
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private static function account(array $overrides = array()): Account {
		return Account::fromRow(array_merge(array(
			'id'                 => 7,
			'cf_account_id'      => 'ed17574386854bf78a67040be0a770b0',
			'name'               => 'Prod <b>&</b>',
			'token_source'       => 'vault',
			'token_file'         => null,
			'has_ciphertext'     => 1,
			'enabled'            => 1,
			'status'             => 'ok',
			'status_message'     => 'Connection verified',
			'permissions'        => null,
			'last_checked_at'    => 1800000000,
			'last_verified_at'   => 1800000000,
			'collect_tunnels'    => 1,
			'collect_analytics'  => 0,
			'discovery_interval' => 600,
			'created_at'         => 1700000000,
			'updated_at'         => 1700000000,
		), $overrides));
	}

	/**
	 * @return array{token_source: string, token_file: string|null, has_token: bool}
	 */
	private static function vaultCredentials(): array {
		return array('token_source' => 'vault', 'token_file' => null, 'has_token' => true);
	}

	public function testTokenFieldIsAnEmptyPasswordBoxThatKeepsTheCurrentToken(): void {
		$fields = cloudflare_account_fields(self::account(), self::vaultCredentials(), true);

		$this->assertSame('textbox_password', $fields['token']['method']);
		$this->assertSame('', $fields['token']['value']);
		$this->assertSame('Leave blank to keep the current token', $fields['token']['description']);
	}

	public function testEditFieldsCarryTheAccountValues(): void {
		$fields = cloudflare_account_fields(
			self::account(),
			array('token_source' => 'file', 'token_file' => '/etc/cacti/cf.token', 'has_token' => true),
			true
		);

		$this->assertSame('Prod <b>&</b>', $fields['name']['value']);
		$this->assertSame('ed17574386854bf78a67040be0a770b0', $fields['cf_account_id']['value']);
		$this->assertSame('file', $fields['token_source']['value']);
		$this->assertSame('/etc/cacti/cf.token', $fields['token_file']['value']);
		$this->assertSame('on', $fields['enabled']['value']);
		$this->assertSame('on', $fields['collect_tunnels']['value']);
		$this->assertSame('', $fields['collect_analytics']['value']);
		$this->assertSame('600', $fields['discovery_interval']['value']);
		$this->assertSame('7', $fields['id']['value']);
	}

	public function testNewAccountUsesSchemaDefaults(): void {
		$fields = cloudflare_account_fields(null, null, true);

		$this->assertSame('', $fields['name']['value']);
		$this->assertSame('vault', $fields['token_source']['value']);
		$this->assertSame('on', $fields['enabled']['value']);
		$this->assertSame('on', $fields['collect_tunnels']['value']);
		$this->assertSame('', $fields['collect_analytics']['value']);
		$this->assertSame('300', $fields['discovery_interval']['value']);
		$this->assertSame('0', $fields['id']['value']);
		$this->assertSame('', $fields['token']['value']);
	}

	public function testVaultSourceIsHiddenWithoutTheVault(): void {
		$this->assertSame(array('vault', 'file'), array_keys(cloudflare_account_fields(null, null, true)['token_source']['array']));

		$fields = cloudflare_account_fields(self::account(), self::vaultCredentials(), false);
		$this->assertSame(array('file'), array_keys($fields['token_source']['array']));
		$this->assertSame('file', $fields['token_source']['value']);
		$this->assertArrayNotHasKey('token', $fields);
		$this->assertStringContainsString('missing or unusable', (string) $fields['token_source']['description']);
	}

	public function testCheckboxesBecomeExplicitIntegers(): void {
		$_REQUEST = array(
			'name'               => 'Prod',
			'cf_account_id'      => 'ed17574386854bf78a67040be0a770b0',
			'token_source'       => 'file',
			'token_file'         => '/etc/cacti/cf.token',
			'enabled'            => 'on',
			'collect_analytics'  => 'on',
			'discovery_interval' => '600',
		);

		$input = cloudflare_account_input();

		$this->assertSame(1, $input['enabled']);
		$this->assertSame(0, $input['collect_tunnels']);
		$this->assertSame(1, $input['collect_analytics']);
		$this->assertSame('600', $input['discovery_interval']);
		$this->assertSame('/etc/cacti/cf.token', $input['token_file']);
	}

	public function testTokenIsIgnoredForTheFileSource(): void {
		$_REQUEST = array('token_source' => 'file', 'token' => $this->token, 'token_confirm' => 'different');

		$input = cloudflare_account_input();

		$this->assertSame('', $input['token']);
		$this->assertNotLeaked($this->token, var_export($input, true), 'file source input');
	}

	public function testVaultTokenIsPassedThroughWhenConfirmed(): void {
		$_REQUEST = array('token_source' => 'vault', 'token' => $this->token, 'token_confirm' => $this->token);

		$this->assertSecretEquals($this->token, cloudflare_account_input()['token']);
	}

	public function testTokenConfirmationMismatchIsAValidationError(): void {
		$_REQUEST = array('token_source' => 'vault', 'token' => $this->token, 'token_confirm' => $this->token . 'x');

		try {
			cloudflare_account_input();
			$this->fail('no ValidationException thrown');
		} catch (ValidationException $e) {
			$this->assertSame(array('token'), array_keys($e->getErrors()));
			$this->assertNotLeaked($this->token, $e->getMessage() . var_export($e->getErrors(), true), 'validation error');
		}
	}

	public function testFilterMatchesNameAndAccountIdCaseInsensitively(): void {
		$accounts = array(
			self::account(array('id' => 1, 'name' => 'Production')),
			self::account(array('id' => 2, 'name' => 'Staging', 'cf_account_id' => '0123456789abcdef0123456789abcdef')),
		);

		$this->assertSame(array(1), self::ids(cloudflare_account_filter($accounts, 'PROD')));
		$this->assertSame(array(2), self::ids(cloudflare_account_filter($accounts, '0123456789')));
		$this->assertSame(array(1, 2), self::ids(cloudflare_account_filter($accounts, '')));
	}

	public function testSortUsesAllowListAndDirection(): void {
		$accounts = array(
			self::account(array('id' => 1, 'name' => 'beta', 'status' => 'ok')),
			self::account(array('id' => 2, 'name' => 'Alpha', 'status' => 'auth_failed')),
			self::account(array('id' => 3, 'name' => 'gamma', 'status' => 'unknown')),
		);

		$this->assertSame(array(2, 1, 3), self::ids(cloudflare_account_sort($accounts, 'name', 'ASC')));
		$this->assertSame(array(3, 1, 2), self::ids(cloudflare_account_sort($accounts, 'name', 'DESC')));
		$this->assertSame(array(2, 1, 3), self::ids(cloudflare_account_sort($accounts, 'status', 'ASC')));
		$this->assertSame(array(2, 1, 3), self::ids(cloudflare_account_sort($accounts, 'bogus; DROP', 'sideways')));
	}

	/**
	 * @param array<int, Account> $accounts
	 * @return array<int, int>
	 */
	private static function ids(array $accounts): array {
		$ids = array();
		foreach ($accounts as $account) {
			$ids[] = $account->id();
		}

		return $ids;
	}
}
