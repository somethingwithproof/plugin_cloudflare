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

namespace Cacti\PluginCloudflare\Tests\Support;

/**
 * PHPUnit failure messages print both operands, so string assertions on a
 * secret would put it in test output. These compare without echoing it.
 */
trait SecretAssertions {
	private function assertNotLeaked(string $secret, string $haystack, string $where): void {
		$this->assertTrue(strpos($haystack, $secret) === false, 'secret leaked into ' . $where);
	}

	private function assertSecretEquals(string $expected, string $actual): void {
		$this->assertTrue($expected === $actual, 'secret mismatch');
	}
}
