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

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Builds a PDO handle the way Cacti's db_connect_real() does (lib/database.php
 * in 1.2.31): FOUND_ROWS on, silent errors after connect, utf8, and a session
 * sql_mode with the strict modes removed.
 */
final class TestDatabase {
	const DEFAULT_DSN = 'mysql:host=127.0.0.1;port=33061;dbname=cacti_cf_test;charset=utf8';

	/** @var string|null */
	private static $unavailable = null;

	public static function pdo(): PDO {
		$pdo = new PDO(self::dsn(), self::user(), self::pass(), array(
			self::foundRowsAttr() => true,
			PDO::ATTR_TIMEOUT          => 2,
			PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
		));
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

		$bad_modes = array(
			'STRICT_TRANS_TABLES',
			'STRICT_ALL_TABLES',
			'TRADITIONAL',
			'NO_ZERO_DATE',
			'NO_ZERO_IN_DATE',
			'ONLY_FULL_GROUP_BY',
			'NO_AUTO_VALUE_ON_ZERO',
		);

		$new_modes = array();
		$res = $pdo->query('SELECT @@sql_mode');
		if ($res === false) {
			throw new RuntimeException('Cannot read sql_mode: ' . implode(' ', $pdo->errorInfo()));
		}

		foreach (explode(',', (string) $res->fetchColumn()) as $mode) {
			if ($mode !== '' && !in_array($mode, $bad_modes, true)) {
				$new_modes[] = $mode;
			}
		}

		foreach (array('NO_ENGINE_SUBSTITUTION', 'ALLOW_INVALID_DATES') as $mode) {
			if (!in_array($mode, $new_modes, true)) {
				$new_modes[] = $mode;
			}
		}

		$stmt = $pdo->prepare('SET SESSION sql_mode = ?');
		$stmt->execute(array(implode(',', $new_modes)));

		return $pdo;
	}

	public static function reset(): void {
		$pdo = self::pdo();

		$res = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'plugin\\_cloudflare\\_%'");
		if ($res === false) {
			throw new RuntimeException('Cannot list plugin tables: ' . implode(' ', $pdo->errorInfo()));
		}

		$tables = $res->fetchAll(PDO::FETCH_COLUMN);

		$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
		foreach ($tables as $table) {
			$pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', (string) $table) . '`');
		}
		$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
	}

	/**
	 * Skips locally when the database is unreachable. CI (CI=true) fails
	 * instead so a missing service cannot pass silently.
	 */
	public static function skipIfUnavailable(TestCase $t): void {
		if (self::$unavailable === null) {
			self::$unavailable = '';

			try {
				self::pdo();
			} catch (PDOException $e) {
				self::$unavailable = 'Test database unreachable at ' . self::dsn() . ' (run `make db-up`): ' . $e->getMessage();
			}
		}

		if (self::$unavailable !== '') {
			if (getenv('CI') !== false && getenv('CI') !== '') {
				$t->fail(self::$unavailable);
			}

			$t->markTestSkipped(self::$unavailable);
		}
	}

	/* PDO::MYSQL_ATTR_* is deprecated on PHP 8.5; the class constant does not exist on 7.4. */
	private static function foundRowsAttr(): int {
		return defined('Pdo\\Mysql::ATTR_FOUND_ROWS') ? constant('Pdo\\Mysql::ATTR_FOUND_ROWS') : PDO::MYSQL_ATTR_FOUND_ROWS;
	}

	private static function dsn(): string {
		return self::env('CF_TEST_DSN', self::DEFAULT_DSN);
	}

	private static function user(): string {
		return self::env('CF_TEST_DB_USER', 'cacti');
	}

	private static function pass(): string {
		return self::env('CF_TEST_DB_PASS', 'cacti');
	}

	private static function env(string $name, string $default): string {
		$value = getenv($name);

		return ($value === false || $value === '') ? $default : $value;
	}
}
