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

namespace Cacti\PluginCloudflare\Tests\Unit\Secret;

use Cacti\PluginCloudflare\Secret\SecretException;
use Cacti\PluginCloudflare\Secret\TokenVault;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

final class TokenVaultTest extends TestCase {
	use TestTrait;

	private const ACCOUNT = '0123456789abcdef0123456789abcdef';

	/** @var string */
	private $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/cf-vault-' . bin2hex(random_bytes(6));
		mkdir($this->dir, 0700);
	}

	protected function tearDown(): void {
		foreach ((array) scandir($this->dir) as $name) {
			if ($name !== '.' && $name !== '..') {
				unlink($this->dir . '/' . $name);
			}
		}
		rmdir($this->dir);
	}

	private function keyFile(string $contents, int $mode = 0600, string $name = 'key'): string {
		$path = $this->dir . '/' . $name;
		file_put_contents($path, $contents);
		chmod($path, $mode);

		return $path;
	}

	private function vault(?string $key = null): TokenVault {
		return TokenVault::fromKeyFile($this->keyFile($key ?? random_bytes(32), 0600, bin2hex(random_bytes(4))));
	}

	private function assertReason(int $code, callable $fn): SecretException {
		try {
			$fn();
		} catch (SecretException $e) {
			$this->assertSame($code, $e->getCode(), $e->getReason());

			return $e;
		}

		$this->fail('no SecretException thrown');
	}

	public function testRoundTrip(): void {
		$alphabet = str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-');
		$vault = $this->vault();

		$this->limitTo(50)
			->forAll(
				Generators::choose(40, 256),
				Generators::vector(256, Generators::elements($alphabet)),
				Generators::vector(32, Generators::elements(str_split('0123456789abcdef')))
			)
			->then(function (int $len, array $chars, array $hex) use ($vault): void {
				$token = substr(implode('', $chars), 0, $len);
				$aad = TokenVault::aadFor(implode('', $hex));
				$blob = $vault->encrypt($token, $aad);

				$this->assertStringStartsWith('v1:', $blob);
				$this->assertStringNotContainsString($token, $blob);
				$this->assertSame($token, $vault->decrypt($blob, $aad));
			});
	}

	public function testBlobLayout(): void {
		$token = str_repeat('t', 40);
		$blob = $this->vault()->encrypt($token, TokenVault::aadFor(self::ACCOUNT));
		$raw = base64_decode(substr($blob, 3), true);

		$this->assertIsString($raw);
		$this->assertSame(12 + 16 + strlen($token), strlen($raw));
	}

	public function testAadFor(): void {
		$this->assertSame('plugin_cloudflare:account:' . self::ACCOUNT, TokenVault::aadFor(self::ACCOUNT));
	}

	public function testCiphertextDiffersEachTime(): void {
		$vault = $this->vault();
		$aad = TokenVault::aadFor(self::ACCOUNT);
		$token = str_repeat('k', 40);

		$this->assertNotSame($vault->encrypt($token, $aad), $vault->encrypt($token, $aad));
	}

	public function testDecryptWithWrongAadFails(): void {
		$vault = $this->vault();
		$blob = $vault->encrypt(str_repeat('k', 40), TokenVault::aadFor(self::ACCOUNT));

		$this->assertReason(SecretException::DECRYPT_FAILED, function () use ($vault, $blob): void {
			$vault->decrypt($blob, TokenVault::aadFor(str_repeat('f', 32)));
		});
	}

	public function testDecryptWithWrongKeyFails(): void {
		$token = str_repeat('k', 40);
		$blob = $this->vault()->encrypt($token, TokenVault::aadFor(self::ACCOUNT));
		$other = $this->vault();

		$e = $this->assertReason(SecretException::DECRYPT_FAILED, function () use ($other, $blob): void {
			$other->decrypt($blob, TokenVault::aadFor(self::ACCOUNT));
		});
		$this->assertStringNotContainsString($token, $e->getMessage());
		$this->assertStringNotContainsString(substr($blob, 3), $e->getMessage());
	}

	public function testTamperedTagFails(): void {
		$vault = $this->vault();
		$aad = TokenVault::aadFor(self::ACCOUNT);
		$raw = (string) base64_decode(substr($vault->encrypt(str_repeat('k', 40), $aad), 3), true);

		foreach (array(0, 12, 27, 28) as $offset) {
			$bad = $raw;
			$bad[$offset] = chr(ord($bad[$offset]) ^ 0x01);
			$blob = 'v1:' . base64_encode($bad);

			$this->assertReason(SecretException::DECRYPT_FAILED, function () use ($vault, $blob, $aad): void {
				$vault->decrypt($blob, $aad);
			});
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function malformedBlobs(): array {
		return array(
			'no prefix'     => array(base64_encode(str_repeat("\1", 60))),
			'v2 prefix'     => array('v2:' . base64_encode(str_repeat("\1", 60))),
			'not base64'    => array('v1:@@@@'),
			'base64 spaces' => array('v1:' . chunk_split(base64_encode(str_repeat("\1", 60)), 8, ' ')),
			'too short'     => array('v1:' . base64_encode(str_repeat("\1", 27))),
			'empty'         => array(''),
		);
	}

	/**
	 * @dataProvider malformedBlobs
	 */
	public function testMalformedBlobRejected(string $blob): void {
		$vault = $this->vault();

		$this->assertReason(SecretException::DECRYPT_FAILED, function () use ($vault, $blob): void {
			$vault->decrypt($blob, TokenVault::aadFor(self::ACCOUNT));
		});
	}

	public function testHexKeyAccepted(): void {
		$key = random_bytes(32);
		$token = str_repeat('h', 40);
		$aad = TokenVault::aadFor(self::ACCOUNT);

		$hex = TokenVault::fromKeyFile($this->keyFile(bin2hex($key) . "\n", 0600, 'hex'));
		$raw = TokenVault::fromKeyFile($this->keyFile($key, 0600, 'raw'));
		$upper = TokenVault::fromKeyFile($this->keyFile(strtoupper(bin2hex($key)), 0600, 'upper'));

		$this->assertSame($token, $raw->decrypt($hex->encrypt($token, $aad), $aad));
		$this->assertSame($token, $hex->decrypt($upper->encrypt($token, $aad), $aad));
	}

	public function testKeyFileWorldReadableRejected(): void {
		$path = $this->keyFile(random_bytes(32), 0644);

		$this->assertReason(SecretException::KEY_UNAVAILABLE, function () use ($path): void {
			TokenVault::fromKeyFile($path);
		});
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function badKeys(): array {
		return array(
			'31 bytes'        => array(str_repeat("\1", 31)),
			'33 bytes'        => array(str_repeat("\1", 33)),
			'63 hex'          => array(str_repeat('a', 63)),
			'65 hex'          => array(str_repeat('a', 65)),
			'64 hex two nl'   => array(str_repeat('a', 64) . "\n\n"),
			'64 hex crlf'     => array(str_repeat('a', 64) . "\r\n"),
			'64 non-hex'      => array(str_repeat('g', 64)),
			'leading space'   => array(' ' . str_repeat('a', 64)),
			'empty'           => array(''),
		);
	}

	/**
	 * @dataProvider badKeys
	 */
	public function testBadKeyContentRejected(string $contents): void {
		$path = $this->keyFile($contents);

		$this->assertReason(SecretException::KEY_UNAVAILABLE, function () use ($path): void {
			TokenVault::fromKeyFile($path);
		});
	}

	public function testMissingKeyFileRejected(): void {
		$path = $this->dir . '/absent';

		$e = $this->assertReason(SecretException::KEY_UNAVAILABLE, function () use ($path): void {
			TokenVault::fromKeyFile($path);
		});
		$this->assertSame('key_unavailable', $e->getReason());
	}

	public function testKeyFileSymlinkRejected(): void {
		$target = $this->keyFile(random_bytes(32));
		$link = $this->dir . '/link';
		symlink($target, $link);

		$this->assertReason(SecretException::KEY_UNAVAILABLE, function () use ($link): void {
			TokenVault::fromKeyFile($link);
		});
	}

	public function testKeyFileDirectoryRejected(): void {
		$dir = $this->dir;

		$this->assertReason(SecretException::KEY_UNAVAILABLE, function () use ($dir): void {
			TokenVault::fromKeyFile($dir);
		});
	}

	public function testKeyFileNulPathRejected(): void {
		$this->assertReason(SecretException::KEY_UNAVAILABLE, function (): void {
			TokenVault::fromKeyFile("/etc/cacti/cloudflare.key\0x");
		});
	}

	public function testKeyNeverDumped(): void {
		$key = random_bytes(32);
		$vault = TokenVault::fromKeyFile($this->keyFile($key));

		$this->assertStringNotContainsString(bin2hex($key), bin2hex(print_r($vault, true)));

		ob_start();
		var_dump($vault);
		$this->assertStringNotContainsString(bin2hex($key), bin2hex((string) ob_get_clean()));
	}
}
