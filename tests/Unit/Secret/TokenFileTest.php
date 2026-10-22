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

namespace Cacti\PluginCloudflare\Tests\Unit\Secret;

use Cacti\PluginCloudflare\Secret\SecretException;
use Cacti\PluginCloudflare\Secret\TokenFile;
use Cacti\PluginCloudflare\Tests\Support\SecretAssertions;
use PHPUnit\Framework\TestCase;

final class TokenFileTest extends TestCase {
	use SecretAssertions;
	/** @var string */
	private $dir;

	/** @var string */
	private $token;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/cf-token-' . bin2hex(random_bytes(6));
		mkdir($this->dir, 0700);
		$this->token = 'cfut_' . bin2hex(random_bytes(24));
	}

	protected function tearDown(): void {
		foreach ((array) scandir($this->dir) as $name) {
			if ($name === '.' || $name === '..') {
				continue;
			}

			$path = $this->dir . '/' . $name;
			if (is_dir($path) && !is_link($path)) {
				rmdir($path);
			} else {
				unlink($path);
			}
		}
		rmdir($this->dir);
	}

	private function tokenFile(string $contents, int $mode = 0600, string $name = 'token'): string {
		$path = $this->dir . '/' . $name;
		file_put_contents($path, $contents);
		chmod($path, $mode);

		return $path;
	}

	private function assertRejected(int $code, string $path): void {
		try {
			TokenFile::read($path);
		} catch (SecretException $e) {
			$this->assertSame($code, $e->getCode(), $e->getReason());
			$this->assertNotLeaked($this->token, $e->getMessage(), 'message');

			return;
		}

		$this->fail('token file accepted');
	}

	public function testReadsAndTrimsToken(): void {
		$path = $this->tokenFile('  ' . $this->token . "\n");

		$this->assertSecretEquals($this->token, TokenFile::read($path));
	}

	public function testGroupReadableAccepted(): void {
		$path = $this->tokenFile($this->token, 0640);

		$this->assertSecretEquals($this->token, TokenFile::read($path));
	}

	public function testRelativeTokenPathRejected(): void {
		$this->tokenFile($this->token);
		$cwd = (string) getcwd();
		chdir($this->dir);

		try {
			$this->assertRejected(SecretException::FILE_INSECURE, 'token');
			$this->assertRejected(SecretException::FILE_INSECURE, './token');
			$this->assertRejected(SecretException::FILE_INSECURE, '');
		} finally {
			chdir($cwd);
		}
	}

	public function testTokenPastedAsPathNotEchoed(): void {
		try {
			TokenFile::read($this->token);
			$this->fail('accepted');
		} catch (SecretException $e) {
			$this->assertNotLeaked($this->token, $e->getMessage(), 'message');
		}
	}

	public function testTokenFileWorldReadableRejected(): void {
		$this->assertRejected(SecretException::FILE_INSECURE, $this->tokenFile($this->token, 0644));
		$this->assertRejected(SecretException::FILE_INSECURE, $this->tokenFile($this->token, 0604, 'other'));
	}

	public function testTokenFileWorldWritableRejected(): void {
		$this->assertRejected(SecretException::FILE_INSECURE, $this->tokenFile($this->token, 0602));
		$this->assertRejected(SecretException::FILE_INSECURE, $this->tokenFile($this->token, 0666, 'both'));
	}

	public function testTokenFileTooLargeRejected(): void {
		$this->assertRejected(SecretException::FILE_UNAVAILABLE, $this->tokenFile($this->token . str_repeat(' ', 4097 - strlen($this->token))));
	}

	public function testTokenFileAtSizeLimitAccepted(): void {
		$path = $this->tokenFile($this->token . str_repeat("\n", 4096 - strlen($this->token)));

		$this->assertSecretEquals($this->token, TokenFile::read($path));
	}

	public function testMissingFileRejected(): void {
		$this->assertRejected(SecretException::FILE_UNAVAILABLE, $this->dir . '/absent');
	}

	public function testDirectoryRejected(): void {
		$this->assertRejected(SecretException::FILE_UNAVAILABLE, $this->dir);
	}

	public function testSymlinkRejected(): void {
		$target = $this->tokenFile($this->token);
		$link = $this->dir . '/link';
		symlink($target, $link);

		$this->assertRejected(SecretException::FILE_INSECURE, $link);
	}

	public function testNulPathRejected(): void {
		$this->assertRejected(SecretException::FILE_UNAVAILABLE, $this->tokenFile($this->token) . "\0x");
	}

	public function testUnreadableFileRejected(): void {
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			$this->markTestSkipped('root bypasses file modes');
		}

		$this->assertRejected(SecretException::FILE_UNAVAILABLE, $this->tokenFile($this->token, 0200));
	}

	public function testGlobalKeyInFileRejected(): void {
		$key = str_repeat('0123456789abcdef', 2) . '01234';
		$path = $this->tokenFile($key . "\n");

		try {
			TokenFile::read($path);
			$this->fail('accepted');
		} catch (SecretException $e) {
			$this->assertSame(SecretException::GLOBAL_KEY, $e->getCode());
			$this->assertNotLeaked($key, $e->getMessage(), 'message');
		}
	}

	public function testInvalidContentRejected(): void {
		$path = $this->tokenFile('s3cr3t but not a token');

		try {
			TokenFile::read($path);
			$this->fail('accepted');
		} catch (SecretException $e) {
			$this->assertSame(SecretException::INVALID, $e->getCode());
			$this->assertNotLeaked('s3cr3t', $e->getMessage(), 'message');
		}
	}

	public function testMessageNamesAbsolutePath(): void {
		$path = $this->tokenFile($this->token, 0644);

		try {
			TokenFile::read($path);
			$this->fail('accepted');
		} catch (SecretException $e) {
			$this->assertStringContainsString($path, $e->getMessage());
		}
	}
}
