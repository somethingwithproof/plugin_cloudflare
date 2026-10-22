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

use Cacti\PluginCloudflare\Tests\Integration\IntegrationTestCase;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * phpunit.xml.dist lists this directory after tests/Integration, so it runs
 * once every integration class has finished. The end state alone proves
 * nothing, because closeAll() kills leftovers at each class boundary; the
 * per-test peak and the leak count are what catch a handle left behind.
 */
final class ConnectionBudgetTest extends TestCase {
	private const BUDGET = 50;

	public function testSuiteLeavesFewConnectionsOpen(): void {
		TestDatabase::skipIfUnavailable($this);

		$row = TestDatabase::pdo()->query("SHOW STATUS LIKE 'Threads_connected'")->fetch();

		$this->assertIsArray($row);
		$this->assertLessThan(self::BUDGET, (int) $row[1]);
	}

	public function testNoTestHeldItsHandlesPastTearDown(): void {
		$this->assertSame(0, IntegrationTestCase::leakedHandles(), 'handles were still referenced at the end of a class');
	}

	public function testPeakStayedUnderBudget(): void {
		$this->assertLessThan(self::BUDGET, TestDatabase::peakThreads());
	}
}
