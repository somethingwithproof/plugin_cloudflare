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

namespace Cacti\PluginCloudflare\Tests\Unit\Storage;

use Cacti\PluginCloudflare\Storage\DatabaseException;
use PHPUnit\Framework\TestCase;

final class DatabaseExceptionTest extends TestCase {
	public function testDriverCodeExposed(): void {
		$e = new DatabaseException('42S22', 1054);

		$this->assertSame(1054, $e->getDriverCode());
		$this->assertSame('42S22', $e->getSqlState());
		$this->assertSame('SQLSTATE[42S22] driver error 1054', $e->getMessage());
	}
}
