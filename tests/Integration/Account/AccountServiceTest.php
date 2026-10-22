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

namespace Cacti\PluginCloudflare\Tests\Integration\Account;

use Cacti\PluginCloudflare\Account\AccountRepository;
use Cacti\PluginCloudflare\Account\AccountService;
use Cacti\PluginCloudflare\Account\ValidationException;
use Cacti\PluginCloudflare\Api\CloudflareClient;
use Cacti\PluginCloudflare\Api\RetryPolicy;
use Cacti\PluginCloudflare\Auth\AccessDenied;
use Cacti\PluginCloudflare\Auth\CallbackAuthorizer;
use Cacti\PluginCloudflare\Http\Response;
use Cacti\PluginCloudflare\Secret\TokenVault;
use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Storage\Migrator;
use Cacti\PluginCloudflare\Tests\Support\FakeTransport;
use Cacti\PluginCloudflare\Tests\Support\FrozenClock;
use Cacti\PluginCloudflare\Tests\Support\RecordingSleeper;
use Cacti\PluginCloudflare\Tests\Support\SecretAssertions;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;

final class AccountServiceTest extends TestCase {
	use SecretAssertions;

	private const ACCOUNT = 'ed17574386854bf78a67040be0a770b0';
	private const OTHER   = '0123456789abcdef0123456789abcdef';
	private const NOW     = 1800000000;
	private const OK_BODY = '{"success":true,"errors":[],"messages":[],"result":[]}';

	/** @var Database */
	private $db;

	/** @var string */
	private $dir;

	/** @var TokenVault */
	private $vault;

	/** @var FakeTransport */
	private $transport;

	/** @var string */
	private $token;

	/** @var bool */
	private $allowed = true;

	/** @var array<int, string> */
	private $logged = array();

	protected function setUp(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();

		$this->db = new Database(TestDatabase::pdo());
		(new Migrator($this->db, Migrator::defaultMigrations()))->migrate();

		$this->dir = sys_get_temp_dir() . '/cf-account-' . bin2hex(random_bytes(6));
		mkdir($this->dir, 0700);

		$this->vault     = TokenVault::fromKeyFile($this->secretFile('key', random_bytes(32)));
		$this->transport = new FakeTransport();
		$this->token     = 'cfut_' . bin2hex(random_bytes(20));
	}

	protected function tearDown(): void {
		if (is_string($this->dir) && is_dir($this->dir)) {
			foreach ((array) scandir($this->dir) as $name) {
				if ($name !== '.' && $name !== '..') {
					unlink($this->dir . '/' . $name);
				}
			}
			rmdir($this->dir);
		}

		TestDatabase::reset();
	}

	private function secretFile(string $name, string $contents): string {
		$path = $this->dir . '/' . $name;
		file_put_contents($path, $contents);
		chmod($path, 0600);

		return $path;
	}

	/**
	 * @param TokenVault|null|false $vault false means the default vault
	 */
	private function service($vault = false, int $now = self::NOW): AccountService {
		$clock  = new FrozenClock($now);
		$client = new CloudflareClient($this->transport, new RetryPolicy($clock, new RecordingSleeper()));

		return new AccountService(
			new AccountRepository($this->db),
			new CallbackAuthorizer(function (): bool {
				return $this->allowed;
			}),
			$client,
			$vault === false ? $this->vault : $vault,
			$clock,
			function (string $line): void {
				$this->logged[] = $line;
			}
		);
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function input(array $overrides = array()): array {
		return array_merge(array(
			'cf_account_id'      => self::ACCOUNT,
			'name'               => 'Production',
			'token_source'       => 'vault',
			'token'              => $this->token,
			'token_file'         => '',
			'enabled'            => 1,
			'collect_tunnels'    => 1,
			'collect_analytics'  => 0,
			'discovery_interval' => 300,
		), $overrides);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function rawRow(int $id): array {
		$row = $this->db->fetchRow('SELECT * FROM plugin_cloudflare_accounts WHERE id = ?', array($id));
		$this->assertNotNull($row);

		return $row;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function allRows(): array {
		return $this->db->fetchAll('SELECT * FROM plugin_cloudflare_accounts ORDER BY id');
	}

	/**
	 * @return array<string, string>
	 */
	private function validationErrors(callable $fn): array {
		try {
			$fn();
		} catch (ValidationException $e) {
			$this->assertNotLeaked($this->token, $e->getMessage() . var_export($e->getErrors(), true), 'validation errors');

			return $e->getErrors();
		}

		$this->fail('no ValidationException thrown');
	}

	private function queueVerifyOk(bool $tunnelRead = true): void {
		$this->transport->queue(FakeTransport::fixture('token-verify-active'));
		$this->transport->queue($tunnelRead ? new Response(200, array(), self::OK_BODY) : FakeTransport::fixture('error-envelope', 403));
		$this->transport->queue(new Response(200, array(), self::OK_BODY));
	}

	private function assertTokenNotInUrls(): void {
		foreach ($this->transport->requests() as $request) {
			$this->assertNotLeaked($this->token, $request->url(), 'request url');
		}
	}

	public function testSaveValidAccountEncryptsToken(): void {
		$id  = $this->service()->save(null, $this->input());
		$row = $this->rawRow($id);

		$this->assertSame(self::ACCOUNT, $row['cf_account_id']);
		$this->assertSame('vault', $row['token_source']);
		$this->assertSame('v1:', substr((string) $row['token_ciphertext'], 0, 3));
		$this->assertNotLeaked($this->token, var_export($row, true), 'account row');
		$this->assertSecretEquals($this->token, $this->vault->decrypt((string) $row['token_ciphertext'], TokenVault::aadFor(self::ACCOUNT)));
		$this->assertNull($row['token_file']);
		$this->assertSame('unknown', $row['status']);
		$this->assertSame(self::NOW, (int) $row['created_at']);
		$this->assertSame(self::NOW, (int) $row['updated_at']);
	}

	public function testAccountIdIsNormalized(): void {
		$id = $this->service()->save(null, $this->input(array('cf_account_id' => "  ED17574386854BF78A67040BE0A770B0\t")));

		$this->assertSame(self::ACCOUNT, $this->rawRow($id)['cf_account_id']);
	}

	public function testInvalidFieldsAreAllReported(): void {
		$errors = $this->validationErrors(function (): void {
			$this->service()->save(null, $this->input(array(
				'cf_account_id'      => 'not-an-id',
				'name'               => "bad\x07name",
				'token_source'       => 'env',
				'enabled'            => 'yes',
				'discovery_interval' => 59,
			)));
		});

		$this->assertSame(
			array('cf_account_id', 'name', 'token_source', 'enabled', 'discovery_interval'),
			array_keys($errors)
		);
		$this->assertSame(array(), $this->allRows());
	}

	public function testRejectsGlobalApiKey(): void {
		$globalKey = bin2hex(random_bytes(18)) . 'a';

		try {
			$this->service()->save(null, $this->input(array('token' => $globalKey)));
			$this->fail('no ValidationException thrown');
		} catch (ValidationException $e) {
			$this->assertNotLeaked($globalKey, $e->getMessage() . var_export($e->getErrors(), true), 'validation errors');
			$this->assertArrayHasKey('token', $e->getErrors());
			$this->assertStringContainsString('Global API Keys', $e->getErrors()['token']);
		}

		$this->assertSame(array(), $this->allRows());
	}

	public function testDuplicateAccountIdIsValidationError(): void {
		$this->service()->save(null, $this->input());

		$errors = $this->validationErrors(function (): void {
			$this->service()->save(null, $this->input(array('name' => 'Copy', 'cf_account_id' => strtoupper(self::ACCOUNT))));
		});

		$this->assertSame(array('cf_account_id'), array_keys($errors));
		$this->assertCount(1, $this->allRows());
	}

	public function testSaveWithBlankTokenKeepsExistingToken(): void {
		$id     = $this->service()->save(null, $this->input());
		$before = $this->rawRow($id)['token_ciphertext'];

		$this->assertSame($id, $this->service()->save($id, $this->input(array('token' => '  ', 'name' => 'Renamed'))));

		$row = $this->rawRow($id);
		$this->assertSame('Renamed', $row['name']);
		$this->assertTrue($before === $row['token_ciphertext'], 'ciphertext changed');
	}

	public function testChangingAccountIdRequiresToken(): void {
		$id = $this->service()->save(null, $this->input());

		$errors = $this->validationErrors(function () use ($id): void {
			$this->service()->save($id, $this->input(array('token' => '', 'cf_account_id' => self::OTHER)));
		});
		$this->assertSame(array('token' => 'Re-enter the token when changing the account ID'), $errors);

		$this->service()->save($id, $this->input(array('cf_account_id' => self::OTHER)));
		$row = $this->rawRow($id);
		$this->assertSecretEquals($this->token, $this->vault->decrypt((string) $row['token_ciphertext'], TokenVault::aadFor(self::OTHER)));
	}

	public function testUnauthorizedSaveChangesNothing(): void {
		$id     = $this->service()->save(null, $this->input());
		$before = $this->allRows();

		$this->allowed = false;

		foreach (array($id, null) as $target) {
			try {
				$this->service()->save($target, $this->input(array('name' => 'Hijacked', 'cf_account_id' => self::OTHER)));
				$this->fail('no AccessDenied thrown');
			} catch (AccessDenied $e) {
				$this->assertSame('Not authorized to configure Cloudflare accounts', $e->getMessage());
			}
		}

		$this->assertSame($before, $this->allRows());
	}

	public function testUnauthorizedDeleteAndVerifyDenied(): void {
		$id     = $this->service()->save(null, $this->input());
		$before = $this->allRows();

		$this->allowed = false;

		foreach (array('delete', 'verify') as $method) {
			try {
				$this->service()->$method($id);
				$this->fail('no AccessDenied thrown for ' . $method);
			} catch (AccessDenied $e) {
				$this->assertSame('Not authorized to configure Cloudflare accounts', $e->getMessage());
			}
		}

		$this->assertSame($before, $this->allRows());
		$this->assertSame(array(), $this->transport->requests());
	}

	public function testCredentialDetailsRequiresConfigure(): void {
		$id = $this->service()->save(null, $this->input());

		$this->allowed = false;

		try {
			$this->service()->credentialDetails($id);
			$this->fail('no AccessDenied thrown');
		} catch (AccessDenied $e) {
			$this->assertSame('Not authorized to configure Cloudflare accounts', $e->getMessage());
		}
	}

	public function testCredentialDetailsNeverReturnsSecret(): void {
		$id      = $this->service()->save(null, $this->input());
		$details = $this->service()->credentialDetails($id);

		$this->assertSame(array('token_source' => 'vault', 'token_file' => null, 'has_token' => true), $details);

		$dump = var_export($details, true);
		$this->assertNotLeaked($this->token, $dump, 'credential details');
		$this->assertStringNotContainsString('v1:', $dump);
		$this->assertStringNotContainsString('ciphertext', $dump);

		$this->expectException(OutOfBoundsException::class);
		$this->service()->credentialDetails($id + 1);
	}

	public function testCredentialDetailsReturnTheTokenFilePath(): void {
		$path = $this->secretFile('token', $this->token);
		$id   = $this->service()->save(null, $this->input(array('token_source' => 'file', 'token' => '', 'token_file' => $path)));

		$details = $this->service()->credentialDetails($id);

		$this->assertSame(array('token_source' => 'file', 'token_file' => $path, 'has_token' => true), $details);
		$this->assertNotLeaked($this->token, var_export($details, true), 'file credential details');
	}

	public function testDeleteRemovesAccountAndJobs(): void {
		$id = $this->service()->save(null, $this->input());
		$this->db->execute('INSERT INTO plugin_cloudflare_jobs (job_type, account_id) VALUES (?, ?)', array('discovery', $id));

		$this->service()->delete($id);

		$this->assertSame(array(), $this->allRows());
		$this->assertSame(0, (int) $this->db->fetchCell('SELECT COUNT(*) FROM plugin_cloudflare_jobs'));
	}

	public function testVerifySuccessSetsOkAndPermissions(): void {
		$id = $this->service()->save(null, $this->input());
		$this->queueVerifyOk();

		$account = $this->service()->verify($id);

		$this->assertSame('ok', $account->status());
		$this->assertSame(array('tunnel_read' => true, 'zone_read' => true), $account->permissions());
		$this->assertSame(self::NOW, $account->lastCheckedAt());
		$this->assertSame(self::NOW, $account->lastVerifiedAt());
		$this->assertSame('{"tunnel_read":true,"zone_read":true}', $this->rawRow($id)['permissions']);
		$this->assertNotLeaked($this->token, $account->statusMessage(), 'status message');

		$requests = $this->transport->requests();
		$this->assertCount(3, $requests);
		$this->assertSame('https://api.cloudflare.com/client/v4/accounts/' . self::ACCOUNT . '/tokens/verify', $requests[0]->url());
		$this->assertSame('https://api.cloudflare.com/client/v4/accounts/' . self::ACCOUNT . '/cfd_tunnel?per_page=1', $requests[1]->url());
		$this->assertSame('https://api.cloudflare.com/client/v4/zones?account.id=' . self::ACCOUNT . '&per_page=5', $requests[2]->url());
		$this->assertTokenNotInUrls();
	}

	public function testVerifyUserOwnedTokenFallsBackToUserEndpoint(): void {
		$id = $this->service()->save(null, $this->input());
		$this->transport->queue(FakeTransport::fixture('account-verify-1000', 401));
		$this->transport->queue(FakeTransport::fixture('token-verify-active'));
		$this->transport->queue(new Response(200, array(), self::OK_BODY));
		$this->transport->queue(new Response(200, array(), self::OK_BODY));

		$this->assertSame('ok', $this->service()->verify($id)->status());
	}

	public function testVerifyRevokedPermissionSetsPermissionDenied(): void {
		$id = $this->service()->save(null, $this->input());
		$this->queueVerifyOk(false);

		$account = $this->service()->verify($id);

		$this->assertSame('permission_denied', $account->status());
		$this->assertSame(array('tunnel_read' => false, 'zone_read' => true), $account->permissions());
		$this->assertSame(self::NOW, $account->lastCheckedAt());
		$this->assertNull($account->lastVerifiedAt());
	}

	public function testVerifyInvalidTokenSetsAuthFailed(): void {
		$id = $this->service()->save(null, $this->input());
		$this->transport->queue(FakeTransport::fixture('auth-error', 401));
		$this->transport->queue(FakeTransport::fixture('auth-error', 401));

		$account = $this->service()->verify($id);

		$this->assertSame('auth_failed', $account->status());
		$this->assertSame('Token is invalid or expired', $account->statusMessage());
		$this->assertNull($account->permissions());
		$this->assertNull($account->lastVerifiedAt());
		$this->assertSame(self::NOW, $account->lastCheckedAt());
	}

	public function testVerifyExpiredTokenSetsTokenInactive(): void {
		$id = $this->service()->save(null, $this->input());
		$this->transport->queue(FakeTransport::fixture('token-verify-expired'));

		$account = $this->service()->verify($id);

		$this->assertSame('token_inactive', $account->status());
		$this->assertCount(1, $this->transport->requests());
	}

	public function testVerifyUnreachableKeepsLastVerifiedAt(): void {
		$id = $this->service()->save(null, $this->input());
		$this->queueVerifyOk();
		$this->service()->verify($id);

		for ($i = 0; $i < 3; $i++) {
			$this->transport->queue(new Response(503, array(), '{"success":false,"errors":[]}'));
		}

		$account = $this->service(false, self::NOW + 600)->verify($id);

		$this->assertSame('unreachable', $account->status());
		$this->assertSame(self::NOW, $account->lastVerifiedAt());
		$this->assertSame(self::NOW + 600, $account->lastCheckedAt());
		$this->assertSame(array('tunnel_read' => true, 'zone_read' => true), $account->permissions());
	}

	public function testVerifyWithUndecryptableTokenMarksError(): void {
		$id    = $this->service()->save(null, $this->input());
		$other = TokenVault::fromKeyFile($this->secretFile('other-key', random_bytes(32)));

		$account = $this->service($other)->verify($id);

		$this->assertSame('error', $account->status());
		$this->assertSame('Stored token cannot be decrypted', $account->statusMessage());
		$this->assertSame(self::NOW, $account->lastCheckedAt());
		$this->assertSame(array(), $this->transport->requests());
	}

	public function testVerifyRejectsEmptyDecryptedToken(): void {
		$id = $this->service()->save(null, $this->input());
		$this->db->execute(
			'UPDATE plugin_cloudflare_accounts SET token_ciphertext = ? WHERE id = ?',
			array($this->vault->encrypt('', TokenVault::aadFor(self::ACCOUNT)), $id)
		);

		$this->assertSame('error', $this->service()->verify($id)->status());
		$this->assertSame(array(), $this->transport->requests());
	}

	public function testVaultUnavailableRejectsVaultSource(): void {
		$errors = $this->validationErrors(function (): void {
			$this->service(null)->save(null, $this->input());
		});

		$this->assertSame(
			array('token_source' => 'Encrypted storage is unavailable; configure the key file or use a token file'),
			$errors
		);
		$this->assertSame(array(), $this->allRows());
	}

	public function testFileSourceStoresPathNotToken(): void {
		$path = $this->secretFile('token', $this->token . "\n");

		$id  = $this->service(null)->save(null, $this->input(array('token_source' => 'file', 'token' => '', 'token_file' => $path)));
		$row = $this->rawRow($id);

		$this->assertSame('file', $row['token_source']);
		$this->assertSame($path, $row['token_file']);
		$this->assertNull($row['token_ciphertext']);
		$this->assertNotLeaked($this->token, var_export($row, true), 'account row');

		$this->queueVerifyOk();
		$this->assertSame('ok', $this->service(null)->verify($id)->status());
	}

	public function testFileSourceRequiresAbsolutePath(): void {
		$errors = $this->validationErrors(function (): void {
			$this->service()->save(null, $this->input(array('token_source' => 'file', 'token_file' => 'relative/token')));
		});

		$this->assertSame(array('token_file'), array_keys($errors));
	}

	public function testInsecureTokenFileMarksError(): void {
		$path = $this->secretFile('token', $this->token);
		chmod($path, 0644);

		$id      = $this->service()->save(null, $this->input(array('token_source' => 'file', 'token_file' => $path)));
		$account = $this->service()->verify($id);

		$this->assertSame('error', $account->status());
		$this->assertSame('Token file is missing or insecure', $account->statusMessage());
		$this->assertSame(array(), $this->transport->requests());
	}

	public function testSwitchingSourcesHandlesCiphertext(): void {
		$id   = $this->service()->save(null, $this->input());
		$path = $this->secretFile('token', $this->token);

		$this->service()->save($id, $this->input(array('token_source' => 'file', 'token' => '', 'token_file' => $path)));
		$this->assertNull($this->rawRow($id)['token_ciphertext']);

		$errors = $this->validationErrors(function () use ($id): void {
			$this->service()->save($id, $this->input(array('token' => '')));
		});
		$this->assertSame(array('token'), array_keys($errors));

		$this->service()->save($id, $this->input());
		$row = $this->rawRow($id);
		$this->assertSame('vault', $row['token_source']);
		$this->assertNull($row['token_file']);
		$this->assertSame('v1:', substr((string) $row['token_ciphertext'], 0, 3));
	}

	public function testListNeverExposesSecrets(): void {
		$path = $this->secretFile('token', $this->token);
		$this->service()->save(null, $this->input());
		$this->service()->save(null, $this->input(array(
			'cf_account_id' => self::OTHER,
			'name'          => 'Alpha',
			'token_source'  => 'file',
			'token_file'    => $path,
		)));

		$this->allowed = false;
		$accounts      = $this->service()->list();
		$dump          = var_export($accounts, true) . print_r($accounts, true);

		$this->assertCount(2, $accounts);
		$this->assertSame('Alpha', $accounts[0]->name());
		$this->assertTrue($accounts[0]->hasToken());
		$this->assertTrue($accounts[1]->hasToken());
		$this->assertStringNotContainsString('v1:', $dump);
		$this->assertNotLeaked($this->token, $dump, 'account list');

		/* list() needs no right, so where the token lives stays out of it. */
		$this->assertStringNotContainsString($path, $dump);
		foreach (array('token_file', 'tokenFile', 'token_source', 'tokenSource') as $field) {
			$this->assertStringNotContainsStringIgnoringCase($field, $dump);
		}
	}

	public function testStatusTransitionsAreLoggedWithoutToken(): void {
		$id = $this->service()->save(null, $this->input());
		$this->queueVerifyOk();
		$this->service()->verify($id);
		$this->transport->queue(FakeTransport::fixture('auth-error', 401));
		$this->transport->queue(FakeTransport::fixture('auth-error', 401));
		$this->service()->verify($id);

		$this->assertCount(2, $this->logged);
		$this->assertStringContainsString('unknown -> ok', $this->logged[0]);
		$this->assertStringContainsString('ok -> auth_failed', $this->logged[1]);

		$all = implode("\n", $this->logged);
		$this->assertNotLeaked($this->token, $all, 'log');
		$this->assertStringNotContainsString('v1:', $all);
	}

	public function testSetEnabledChangesOnlyTheFlag(): void {
		$id     = $this->service()->save(null, $this->input());
		$before = $this->rawRow($id);

		$this->service(false, self::NOW + 60)->setEnabled($id, false);
		$after = $this->rawRow($id);

		$this->assertSame('0', (string) $after['enabled']);
		$this->assertSame((string) (self::NOW + 60), (string) $after['updated_at']);
		$this->assertSame($before['token_ciphertext'], $after['token_ciphertext']);
		unset($before['enabled'], $before['updated_at'], $after['enabled'], $after['updated_at']);
		$this->assertSame($before, $after);

		$this->service()->setEnabled($id, true);
		$this->assertSame('1', (string) $this->rawRow($id)['enabled']);
	}

	public function testSetEnabledWorksWithoutTheVault(): void {
		$id = $this->service()->save(null, $this->input());

		$this->service(null)->setEnabled($id, false);

		$this->assertSame('0', (string) $this->rawRow($id)['enabled']);
	}

	public function testUnauthorizedSetEnabledDenied(): void {
		$id     = $this->service()->save(null, $this->input());
		$before = $this->allRows();

		$this->allowed = false;

		try {
			$this->service()->setEnabled($id, false);
			$this->fail('no AccessDenied thrown');
		} catch (AccessDenied $e) {
			$this->assertSame('Not authorized to configure Cloudflare accounts', $e->getMessage());
		}

		$this->assertSame($before, $this->allRows());
	}

	public function testSetEnabledOnMissingAccountThrows(): void {
		$this->expectException(OutOfBoundsException::class);

		$this->service()->setEnabled(999, true);
	}

	public function testNewCredentialsResetAVerifiedStatus(): void {
		$id = $this->service()->save(null, $this->input());
		$this->queueVerifyOk();
		$this->service()->verify($id);

		$this->service()->save($id, $this->input(array('token' => '', 'name' => 'Renamed')));
		$this->assertSame('ok', $this->rawRow($id)['status'], 'a rename keeps the verify result');

		$this->service()->save($id, $this->input(array('token' => 'cfut_' . bin2hex(random_bytes(20)))));

		$row = $this->rawRow($id);
		$this->assertSame('unknown', $row['status']);
		$this->assertSame('', $row['status_message']);
		$this->assertNull($row['permissions']);
		$this->assertStringContainsString('ok -> unknown [credentials changed]', (string) end($this->logged));
		$this->assertNotLeaked($this->token, implode("\n", $this->logged), 'log');
	}

	public function testOmittedFlagsKeepTheStoredValues(): void {
		$id    = $this->service()->save(null, $this->input(array('enabled' => 0, 'collect_tunnels' => 0, 'collect_analytics' => 1)));
		$input = $this->input(array('token' => '', 'name' => 'Renamed'));
		unset($input['enabled'], $input['collect_tunnels'], $input['collect_analytics']);

		$this->service()->save($id, $input);

		$row = $this->rawRow($id);
		$this->assertSame(array(0, 0, 1), array((int) $row['enabled'], (int) $row['collect_tunnels'], (int) $row['collect_analytics']));
	}

	public function testVerifyOfAVaultTokenWithoutTheVaultMarksError(): void {
		$id = $this->service()->save(null, $this->input());

		$account = $this->service(null)->verify($id);

		$this->assertSame('error', $account->status());
		$this->assertSame('Stored token cannot be decrypted', $account->statusMessage());
		$this->assertSame(array(), $this->transport->requests());
	}

	public function testVerifyOfAVaultAccountWithNoCiphertextMarksError(): void {
		$id = $this->service()->save(null, $this->input());
		$this->db->execute('UPDATE plugin_cloudflare_accounts SET token_ciphertext = NULL WHERE id = ?', array($id));

		$this->assertSame('error', $this->service()->verify($id)->status());
		$this->assertSame(array(), $this->transport->requests());
	}

	public function testVerifyThatStaysRateLimitedRecordsIt(): void {
		$id = $this->service()->save(null, $this->input());
		for ($i = 0; $i < 3; $i++) {
			$this->transport->queue(new Response(429, array(), '{"success":false,"errors":[]}'));
		}

		$account = $this->service()->verify($id);

		$this->assertSame('rate_limited', $account->status());
		$this->assertNull($account->lastVerifiedAt());
	}
}
