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

namespace Cacti\PluginCloudflare\Tests\Unit\Support;

use Cacti\PluginCloudflare\Support\Redactor;
use Cacti\PluginCloudflare\Tests\Support\SecretAssertions;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase {
	use SecretAssertions;
	public function testRedactsBearerHeaderAndSecrets(): void {
		$token = 'cfut_' . str_repeat('Ab9', 14);
		$text = "GET /user/tokens/verify\nauthorization:   bearer abc.DEF-ghi\nAuthorization: Bearer " . $token . "\nbody " . $token . ' end';

		$out = Redactor::redact($text, array($token));

		$this->assertNotLeaked($token, $out, 'output');
		$this->assertNotLeaked('abc.DEF-ghi', $out, 'output');
		$this->assertTrue("GET /user/tokens/verify\nauthorization:   bearer [REDACTED]\nAuthorization: Bearer [REDACTED]\nbody [REDACTED] end" === $out, 'unexpected redaction output');
	}

	public function testShortSecretsRedacted(): void {
		$this->assertSame('pin [REDACTED] ok', Redactor::redact('pin 42x ok', array('42x')));
	}

	public function testEmptySecretIgnored(): void {
		$this->assertSame('unchanged', Redactor::redact('unchanged', array('', 'absent')));
	}

	public function testLongerSecretRedactedWhole(): void {
		$out = Redactor::redact('x abcdef y', array('abc', 'abcdef'));

		$this->assertSame('x [REDACTED] y', $out);
	}

	public function testNoSecretsStillScrubsBearer(): void {
		$this->assertSame('Authorization: Bearer [REDACTED] tail', Redactor::redact('Authorization: Bearer s3cr3t tail', array()));
	}
}
