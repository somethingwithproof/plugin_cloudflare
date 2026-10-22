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
use Cacti\PluginCloudflare\Account\CredentialResolver;
use Cacti\PluginCloudflare\Secret\SecretException;
use Cacti\PluginCloudflare\Secret\TokenVault;
use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Storage\Migrator;
use Cacti\PluginCloudflare\Tests\Support\SecretAssertions;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use OutOfBoundsException;
use Cacti\PluginCloudflare\Tests\Integration\IntegrationTestCase;

final class CredentialResolverTest extends IntegrationTestCase {
	use SecretAssertions;

	private const ACCOUNT = 'ed17574386854bf78a67040be0a770b0';

	/** @var Database|null */
	private $db;

	/** @var string */
	private $dir;

	/** @var TokenVault */
	private $vault;

	/** @var string */
	private $token;

	protected function setUp(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();

		$this->db = new Database(TestDatabase::pdo());
		(new Migrator($this->db, Migrator::defaultMigrations()))->migrate();

		$this->dir = sys_get_temp_dir() . '/cf-resolver-' . bin2hex(random_bytes(6));
		mkdir($this->dir, 0700);

		$this->vault = TokenVault::fromKeyFile($this->secretFile('key', random_bytes(32)));
		$this->token = 'cfut_' . bin2hex(random_bytes(20));
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

	private function database(): Database {
		$this->assertNotNull($this->db);

		return $this->db;
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private function insertAccount(array $overrides = array()): int {
		return (new AccountRepository($this->database()))->insert(array_merge(array(
			'cf_account_id'    => self::ACCOUNT,
			'name'             => 'Production',
			'token_source'     => 'vault',
			'token_ciphertext' => $this->vault->encrypt($this->token, TokenVault::aadFor(self::ACCOUNT)),
			'token_file'       => null,
			'created_at'       => 1800000000,
			'updated_at'       => 1800000000,
		), $overrides));
	}

	private function resolver(?TokenVault $vault): CredentialResolver {
		return new CredentialResolver(new AccountRepository($this->database()), $vault);
	}

	private function reasonOf(callable $fn): string {
		try {
			$fn();
		} catch (SecretException $e) {
			return $e->getReason();
		}

		$this->fail('no SecretException thrown');
	}

	public function testResolvesVaultToken(): void {
		$id = $this->insertAccount();

		$this->assertSecretEquals($this->token, $this->resolver($this->vault)->resolve($id));
	}

	public function testResolvesFileToken(): void {
		$path = $this->secretFile('token', $this->token . "\n");
		$id   = $this->insertAccount(array('token_source' => 'file', 'token_ciphertext' => null, 'token_file' => $path));

		$this->assertSecretEquals($this->token, $this->resolver(null)->resolve($id));
	}

	public function testEmptyDecryptRejected(): void {
		$id = $this->insertAccount(array(
			'token_ciphertext' => $this->vault->encrypt('', TokenVault::aadFor(self::ACCOUNT)),
		));

		$this->assertSame('invalid', $this->reasonOf(function () use ($id): void {
			$this->resolver($this->vault)->resolve($id);
		}));
	}

	public function testNullVaultRejectsVaultAccount(): void {
		$id = $this->insertAccount();

		$this->assertSame('key_unavailable', $this->reasonOf(function () use ($id): void {
			$this->resolver(null)->resolve($id);
		}));
	}

	public function testMissingCiphertextIsDecryptFailure(): void {
		$id = $this->insertAccount(array('token_ciphertext' => null));

		$this->assertSame('decrypt_failed', $this->reasonOf(function () use ($id): void {
			$this->resolver($this->vault)->resolve($id);
		}));
	}

	public function testInsecureFileRejected(): void {
		$path = $this->secretFile('token', $this->token);
		chmod($path, 0644);
		$id = $this->insertAccount(array('token_source' => 'file', 'token_ciphertext' => null, 'token_file' => $path));

		$this->assertSame('file_insecure', $this->reasonOf(function () use ($id): void {
			$this->resolver(null)->resolve($id);
		}));
	}

	public function testUnknownAccountThrowsOutOfBounds(): void {
		$this->expectException(OutOfBoundsException::class);

		$this->resolver($this->vault)->resolve(999999);
	}
}
