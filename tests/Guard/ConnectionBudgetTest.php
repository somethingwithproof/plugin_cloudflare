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

namespace Cacti\PluginCloudflare\Tests\Guard;

use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * phpunit.xml.dist lists this directory after tests/Integration, so it runs
 * once every integration class has finished. Leaked handles on finished test
 * instances pushed the suite to 145 of MariaDB's default 151 connections.
 */
final class ConnectionBudgetTest extends TestCase {
	private const BUDGET = 50;

	public function testSuiteLeavesFewConnectionsOpen(): void {
		TestDatabase::skipIfUnavailable($this);

		$row = TestDatabase::pdo()->query("SHOW STATUS LIKE 'Threads_connected'")->fetch();

		$this->assertIsArray($row);
		$this->assertLessThan(self::BUDGET, (int) $row[1]);
	}
}
