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

namespace Cacti\PluginCloudflare\Tests\Integration\Support;

use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class TestDatabaseTest extends TestCase {
	protected function setUp(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();
	}

	public function testSqlModeHasNoStrictModes(): void {
		$modes = explode(',', (string) TestDatabase::pdo()->query('SELECT @@sql_mode')->fetchColumn());

		foreach (array('STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES', 'TRADITIONAL', 'NO_ZERO_DATE', 'NO_ZERO_IN_DATE', 'ONLY_FULL_GROUP_BY', 'NO_AUTO_VALUE_ON_ZERO') as $bad) {
			$this->assertNotContains($bad, $modes);
		}

		$this->assertContains('NO_ENGINE_SUBSTITUTION', $modes);
	}

	public function testFoundRowsReportsMatchedRows(): void {
		$pdo = TestDatabase::pdo();

		$this->assertNotFalse($pdo->exec('CREATE TABLE plugin_cloudflare_probe (id INT PRIMARY KEY, v INT NOT NULL)'));
		$this->assertNotFalse($pdo->exec('INSERT INTO plugin_cloudflare_probe VALUES (1, 5)'));

		$stmt = $pdo->prepare('UPDATE plugin_cloudflare_probe SET v = ? WHERE id = ?');
		$this->assertTrue($stmt->execute(array(5, 1)));
		$this->assertSame(1, $stmt->rowCount());
	}

	public function testResetDropsPluginTables(): void {
		$pdo = TestDatabase::pdo();
		$pdo->exec('CREATE TABLE plugin_cloudflare_probe (id INT)');

		TestDatabase::reset();

		$this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'plugin\\_cloudflare\\_%'")->fetchColumn());
	}
}
