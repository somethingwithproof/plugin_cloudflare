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
