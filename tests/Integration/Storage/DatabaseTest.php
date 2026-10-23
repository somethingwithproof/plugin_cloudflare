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

namespace Cacti\PluginCloudflare\Tests\Integration\Storage;

use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Storage\DatabaseException;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use Eris\Generators;
use Eris\TestTrait;
use PDO;
use Cacti\PluginCloudflare\Tests\Integration\IntegrationTestCase;
use RuntimeException;

final class DatabaseTest extends IntegrationTestCase {
	use TestTrait;

	/** @var Database */
	private $db;

	protected function setUp(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();

		$pdo = TestDatabase::pdo();
		$pdo->exec('CREATE TABLE plugin_cloudflare_probe (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL) ENGINE=InnoDB');

		$this->db = new Database($pdo);
	}

	protected function tearDown(): void {
		TestDatabase::reset();
	}

	public function testRoundTripWithBoundParams(): void {
		$this->assertSame(1, $this->db->execute('INSERT INTO plugin_cloudflare_probe (name) VALUES (?)', array('alpha')));
		$id = $this->db->lastInsertId();
		$this->assertGreaterThan(0, $id);

		$this->db->execute('INSERT INTO plugin_cloudflare_probe (name) VALUES (?)', array("o'brien; DROP TABLE x"));

		$rows = $this->db->fetchAll('SELECT id, name FROM plugin_cloudflare_probe ORDER BY id');
		$this->assertCount(2, $rows);
		$this->assertSame("o'brien; DROP TABLE x", $rows[1]['name']);

		$row = $this->db->fetchRow('SELECT name FROM plugin_cloudflare_probe WHERE id = ?', array($id));
		$this->assertSame(array('name' => 'alpha'), $row);
		$this->assertNull($this->db->fetchRow('SELECT name FROM plugin_cloudflare_probe WHERE id = ?', array(9999)));

		$this->assertSame('alpha', $this->db->fetchCell('SELECT name FROM plugin_cloudflare_probe WHERE id = ?', array($id)));
		$this->assertNull($this->db->fetchCell('SELECT name FROM plugin_cloudflare_probe WHERE id = ?', array(9999)));
		$this->assertSame(1, $this->db->execute('UPDATE plugin_cloudflare_probe SET name = ? WHERE id = ?', array('alpha', $id)));
	}

	public function testFailedStatementThrowsWithSqlStateWithoutParams(): void {
		try {
			$this->db->execute('INSERT INTO plugin_cloudflare_missing (name) VALUES (?)', array('s3cr3t-value'));
			$this->fail('Expected DatabaseException');
		} catch (DatabaseException $e) {
			$this->assertSame('42S02', $e->getSqlState());
			$this->assertStringContainsString('42S02', $e->getMessage());
			$this->assertStringNotContainsString('s3cr3t-value', $e->getMessage());
			$this->assertInstanceOf(RuntimeException::class, $e);
		}
	}

	public function testTransactionalRollsBackOnException(): void {
		try {
			$this->db->transactional(function (): void {
				$this->db->execute('INSERT INTO plugin_cloudflare_probe (name) VALUES (?)', array('doomed'));

				throw new RuntimeException('boom');
			});
			$this->fail('Expected RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertSame('boom', $e->getMessage());
		}

		$this->assertSame(0, (int) $this->db->fetchCell('SELECT COUNT(*) FROM plugin_cloudflare_probe'));
	}

	public function testTransactionalReturnsCallbackValueAndCommits(): void {
		$result = $this->db->transactional(function () {
			$this->db->execute('INSERT INTO plugin_cloudflare_probe (name) VALUES (?)', array('kept'));

			return 42;
		});

		$this->assertSame(42, $result);
		$this->assertSame(1, (int) $this->db->fetchCell('SELECT COUNT(*) FROM plugin_cloudflare_probe'));
	}

	public function testNestedTransactionalJoinsOuter(): void {
		try {
			$this->db->transactional(function (): void {
				$this->db->execute('INSERT INTO plugin_cloudflare_probe (name) VALUES (?)', array('outer'));
				$this->db->transactional(function (): void {
					$this->db->execute('INSERT INTO plugin_cloudflare_probe (name) VALUES (?)', array('inner'));
				});
				$this->assertSame(2, (int) $this->db->fetchCell('SELECT COUNT(*) FROM plugin_cloudflare_probe'));

				throw new RuntimeException('outer fails');
			});
			$this->fail('Expected RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertSame('outer fails', $e->getMessage());
		}

		$this->assertSame(0, (int) $this->db->fetchCell('SELECT COUNT(*) FROM plugin_cloudflare_probe'));

		try {
			$this->db->transactional(function (): void {
				$this->db->execute('INSERT INTO plugin_cloudflare_probe (name) VALUES (?)', array('outer'));
				$this->db->transactional(function (): void {
					throw new RuntimeException('inner fails');
				});
			});
			$this->fail('Expected RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertSame('inner fails', $e->getMessage());
		}

		$this->assertSame(0, (int) $this->db->fetchCell('SELECT COUNT(*) FROM plugin_cloudflare_probe'));

		$this->db->transactional(function (): void {
			$this->db->transactional(function (): void {
				$this->db->execute('INSERT INTO plugin_cloudflare_probe (name) VALUES (?)', array('both'));
			});
		});
		$this->assertSame(1, (int) $this->db->fetchCell('SELECT COUNT(*) FROM plugin_cloudflare_probe'));
	}

	public function testLockIsExclusiveAcrossConnections(): void {
		$other = new Database(TestDatabase::pdo());

		$this->assertTrue($this->db->acquireLock('cf_test_lock', 0));
		$this->assertFalse($other->acquireLock('cf_test_lock', 0));

		$this->db->releaseLock('cf_test_lock');

		$this->assertTrue($other->acquireLock('cf_test_lock', 0));
		$other->releaseLock('cf_test_lock');
	}

	public function testWorksWithErrmodeSilentHandle(): void {
		$pdo = TestDatabase::pdo();
		$this->assertSame(PDO::ERRMODE_SILENT, $pdo->getAttribute(PDO::ATTR_ERRMODE));

		$db = new Database($pdo);

		try {
			$db->fetchAll('SELECT * FROM plugin_cloudflare_missing');
			$this->fail('Expected DatabaseException');
		} catch (DatabaseException $e) {
			$this->assertSame('42S02', $e->getSqlState());
		}

		$this->assertSame(PDO::ERRMODE_SILENT, $pdo->getAttribute(PDO::ATTR_ERRMODE));
	}

	public function testHandleStillUsableAfterFailedStatement(): void {
		try {
			$this->db->execute('INSERT INTO plugin_cloudflare_missing VALUES (1)');
		} catch (DatabaseException $e) {
			// expected
		}

		$this->assertSame(1, (int) $this->db->fetchCell('SELECT 1'));
	}

	public function testDuplicateKeyErrorDoesNotLeakValue(): void {
		$this->db->execute('CREATE TABLE plugin_cloudflare_uniq (v VARCHAR(64) NOT NULL, UNIQUE KEY (v))');
		$this->db->execute('INSERT INTO plugin_cloudflare_uniq (v) VALUES (?)', array('s3cr3t-value'));

		try {
			$this->db->execute('INSERT INTO plugin_cloudflare_uniq (v) VALUES (?)', array('s3cr3t-value'));
			$this->fail('Expected DatabaseException');
		} catch (DatabaseException $e) {
			$this->assertSame('23000', $e->getSqlState());
			$this->assertStringContainsString('23000', $e->getMessage());
			$this->assertStringContainsString('1062', $e->getMessage());
			$this->assertStringNotContainsString('s3cr3t-value', $e->getMessage());
			$this->assertNull($e->getPrevious());
		}
	}

	public function testExceptionModeHandleFailuresBecomeDatabaseException(): void {
		$pdo = TestDatabase::pdo();
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$db = new Database($pdo);

		try {
			$db->execute('INSERT INTO plugin_cloudflare_missing (name) VALUES (?)', array('s3cr3t-value'));
			$this->fail('Expected DatabaseException');
		} catch (DatabaseException $e) {
			$this->assertSame('42S02', $e->getSqlState());
			$this->assertNull($e->getPrevious());
		}

		$this->assertSame(1, (int) $db->fetchCell('SELECT 1'));
	}

	public function testUnknownColumnDoesNotLeakIdentifier(): void {
		try {
			$this->db->fetchAll('SELECT * FROM plugin_cloudflare_probe WHERE no_such_col_s3cr3t = 1');
			$this->fail('Expected DatabaseException');
		} catch (DatabaseException $e) {
			$this->assertSame('42S22', $e->getSqlState());
			$this->assertSame(1054, $e->getDriverCode());
			$this->assertSame('SQLSTATE[42S22] driver error 1054', $e->getMessage());
			$this->assertStringNotContainsString('no_such_col_s3cr3t', $e->getMessage());
		}
	}

	/* Emulated prepares interpolate the bound value, and MariaDB echoes it in the 1064 "near '...'" excerpt. */
	public function testMessageNeverIncludesDriverText(): void {
		$alphabet = array('a', 's', '\'', '"', '\\', '`', ' ', '?', ':');

		$this->limitTo(100)
			->forAll(
				Generators::seq(Generators::elements($alphabet)),
				Generators::seq(Generators::elements($alphabet))
			)
			->then(function (array $prefix, array $suffix): void {
				$value = implode('', $prefix) . 'SECRET' . implode('', $suffix);

				try {
					$this->db->fetchAll('SELECT 1 FROM dual WHERE = ?', array($value));
					$this->fail('Expected DatabaseException');
				} catch (DatabaseException $e) {
					$this->assertSame('SQLSTATE[42000] driver error 1064', $e->getMessage());
					$this->assertStringNotContainsString('SECRET', $e->getMessage());
				}
			});
	}
}
