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

namespace Cacti\PluginCloudflare\Tests\Unit\Storage;

use Cacti\PluginCloudflare\Storage\DatabaseException;
use PHPUnit\Framework\TestCase;

final class DatabaseExceptionTest extends TestCase {
	public function testUnterminatedLiteralIsMaskedToEnd(): void {
		$e = new DatabaseException('42000', 1064, 'You have an error in your SQL syntax; check the manual near \'s3cr3t-tail');

		$this->assertStringContainsString('42000', $e->getMessage());
		$this->assertStringContainsString('error in your SQL syntax', $e->getMessage());
		$this->assertStringNotContainsString('s3cr3t', $e->getMessage());
	}

	public function testUnterminatedDoubleQuoteIsMaskedToEnd(): void {
		$e = new DatabaseException('42000', 1064, 'near "s3cr3t-tail');

		$this->assertStringNotContainsString('s3cr3t', $e->getMessage());
	}

	public function testEscapedQuoteInsideLiteralIsMasked(): void {
		$e = new DatabaseException('HY000', 1366, 'Bad value \'it\\\'s s3cr3t\' for column \'c\' at row 1');

		$this->assertStringNotContainsString('s3cr3t', $e->getMessage());
		$this->assertStringContainsString('for column', $e->getMessage());
	}

	public function testBacktickSpanIsMasked(): void {
		$e = new DatabaseException('42S02', 1146, 'Table `db`.`s3cr3t_table` doesn\'t exist');

		$this->assertStringContainsString('42S02', $e->getMessage());
		$this->assertStringNotContainsString('s3cr3t', $e->getMessage());
	}

	public function testClass23OmitsDriverText(): void {
		$e = new DatabaseException('23000', 1062, 'Duplicate entry \'s3cr3t-value\' for key \'v\'');

		$this->assertSame('SQLSTATE[23000] driver error 1062', $e->getMessage());
		$this->assertSame('23000', $e->getSqlState());
	}
}
