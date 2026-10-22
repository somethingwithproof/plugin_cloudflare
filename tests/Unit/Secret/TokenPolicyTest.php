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
use Cacti\PluginCloudflare\Secret\TokenPolicy;
use Cacti\PluginCloudflare\Tests\Support\SecretAssertions;
use PHPUnit\Framework\TestCase;

final class TokenPolicyTest extends TestCase {
	use SecretAssertions;
	public function testAcceptsScopedTokens(): void {
		TokenPolicy::validate(str_repeat('aB3_-', 8));
		TokenPolicy::validate(str_repeat('x', 256));
		$this->addToAssertionCount(2);
	}

	public function testAcceptsPrefixedTokens(): void {
		TokenPolicy::validate('cfut_' . str_repeat('Ab9', 14));
		TokenPolicy::validate('cfat_' . str_repeat('Zz0', 14));
		$this->addToAssertionCount(2);
	}

	public function testEncryptFailedReason(): void {
		$e = new SecretException(SecretException::ENCRYPT_FAILED);

		$this->assertSame(7, $e->getCode());
		$this->assertSame('encrypt_failed', $e->getReason());
	}

	public function testRejectsGlobalApiKey(): void {
		$key = str_repeat('0123456789abcdef', 2) . '01234';
		$this->assertSame(37, strlen($key));

		try {
			TokenPolicy::validate($key);
			$this->fail('global key accepted');
		} catch (SecretException $e) {
			$this->assertSame(SecretException::GLOBAL_KEY, $e->getCode());
			$this->assertSame('global_key', $e->getReason());
			$this->assertNotLeaked($key, $e->getMessage(), 'message');
		}
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function badTokens(): array {
		return array(
			'empty'            => array(''),
			'39 chars'         => array(str_repeat('a', 39)),
			'257 chars'        => array(str_repeat('a', 257)),
			'space'            => array(str_repeat('a', 20) . ' ' . str_repeat('a', 20)),
			'dot'              => array(str_repeat('a', 20) . '.' . str_repeat('a', 20)),
			'plus'             => array(str_repeat('a', 20) . '+' . str_repeat('a', 20)),
			'trailing newline' => array(str_repeat('a', 40) . "\n"),
			'leading space'    => array(' ' . str_repeat('a', 40)),
			'uppercase hex 37' => array(strtoupper(str_repeat('ab', 18) . 'c')),
		);
	}

	/**
	 * @dataProvider badTokens
	 */
	public function testRejectsShortAndIllegalChars(string $token): void {
		try {
			TokenPolicy::validate($token);
			$this->fail('invalid token accepted');
		} catch (SecretException $e) {
			$this->assertSame(SecretException::INVALID, $e->getCode());
			$this->assertSame('invalid', $e->getReason());
		}
	}

	public function testExceptionMessageNeverContainsInput(): void {
		$inputs = array(
			str_repeat('abcdef0123', 3) . 'abcdef0',
			'cfut_s3cr3t',
			str_repeat('s3cr3t', 10) . '!',
		);

		foreach ($inputs as $input) {
			try {
				TokenPolicy::validate($input);
				$this->fail('accepted');
			} catch (SecretException $e) {
				$this->assertNotLeaked($input, $e->getMessage(), 'message');
				$this->assertNotLeaked('s3cr3t', $e->getMessage(), 'message');
				$this->assertNotLeaked('abcdef0123', $e->getMessage(), 'message');
			}
		}
	}

	/**
	 * PHP 8.2+ honours the SensitiveParameter attribute; older runtimes rely
	 * on zend.exception_ignore_args, which production php.ini enables.
	 */
	public function testTraceNeverContainsInput(): void {
		if (PHP_VERSION_ID < 80200) {
			$this->markTestSkipped('SensitiveParameter needs PHP 8.2');
		}

		$previous = ini_set('zend.exception_ignore_args', '0');

		try {
			TokenPolicy::validate('s3cr3t');
			$this->fail('accepted');
		} catch (SecretException $e) {
			$this->assertNotLeaked('s3cr3t', $e->getTraceAsString(), 'trace');
		} finally {
			ini_set('zend.exception_ignore_args', (string) $previous);
		}
	}
}
