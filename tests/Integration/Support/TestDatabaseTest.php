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

namespace Cacti\PluginCloudflare\Tests\Integration\Support;

use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use PDO;
use Cacti\PluginCloudflare\Tests\Integration\IntegrationTestCase;

final class TestDatabaseTest extends IntegrationTestCase {
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

	public function testErrmodeIsSilent(): void {
		$pdo = TestDatabase::pdo();

		$this->assertSame(PDO::ERRMODE_SILENT, $pdo->getAttribute(PDO::ATTR_ERRMODE));
		$this->assertFalse($pdo->query('SELECT * FROM no_such_table'));
	}

	public function testCharsetIsUtf8(): void {
		$charset = TestDatabase::pdo()->query('SELECT @@character_set_client')->fetchColumn();

		$this->assertContains($charset, array('utf8', 'utf8mb3'));
	}

	public function testResetDropsPluginTables(): void {
		$pdo = TestDatabase::pdo();
		$count = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'plugin\\_cloudflare\\_%'";
		$pdo->exec('CREATE TABLE plugin_cloudflare_probe (id INT)');
		$this->assertSame(1, (int) $pdo->query($count)->fetchColumn());

		TestDatabase::reset();

		$this->assertSame(0, (int) $pdo->query($count)->fetchColumn());
	}

	public function testCloseAllKillsHandlesStillReferenced(): void {
		$held = TestDatabase::pdo();

		$this->assertSame(1, TestDatabase::closeAll());
		$this->assertFalse(@$held->query('SELECT 1'));
		$this->assertNotFalse(TestDatabase::pdo()->query('SELECT 1'));
	}
}
