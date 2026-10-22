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
use Cacti\PluginCloudflare\Storage\Migration;
use Cacti\PluginCloudflare\Storage\DatabaseException;
use Cacti\PluginCloudflare\Storage\Migrations\M001Foundation;
use Cacti\PluginCloudflare\Storage\Migrator;
use Cacti\PluginCloudflare\Tests\Support\ReleaseLockFailingStatement;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use PDO;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase {
	/** @var Database */
	private $db;

	protected function setUp(): void {
		TestDatabase::skipIfUnavailable($this);
		$this->cleanup();

		$this->db = new Database(TestDatabase::pdo());
	}

	protected function tearDown(): void {
		$this->cleanup();
	}

	private function cleanup(): void {
		TestDatabase::reset();
		TestDatabase::pdo()->exec('DROP TABLE IF EXISTS host_unrelated');
		TestDatabase::pdo()->exec('DROP TABLE IF EXISTS pluginxcloudflarexlookalike');
	}

	private function migrator(int $lockTimeout = 10): Migrator {
		return new Migrator($this->db, Migrator::defaultMigrations(), $lockTimeout);
	}

	private function tableExists(string $table): bool {
		return (int) $this->db->fetchCell(
			'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
			array($table)
		) === 1;
	}

	private function insertAccount(string $cfId = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1'): int {
		$this->db->execute(
			"INSERT INTO plugin_cloudflare_accounts (cf_account_id, name, token_source, created_at, updated_at) VALUES (?, 'acme', 'file', 1, 1)",
			array($cfId)
		);

		return $this->db->lastInsertId();
	}

	private function insertJob(string $type, int $accountId): void {
		$this->db->execute(
			'INSERT INTO plugin_cloudflare_jobs (job_type, account_id) VALUES (?, ?)',
			array($type, $accountId)
		);
	}

	public function testFreshInstallCreatesAllTables(): void {
		$this->assertSame(0, $this->migrator()->currentVersion());
		$this->assertSame(array(1, 2), $this->migrator()->pending());
		$this->assertSame(array(1, 2), $this->migrator()->migrate());

		foreach (array('migrations', 'accounts', 'jobs', 'tunnels', 'connectors') as $suffix) {
			$this->assertTrue($this->tableExists('plugin_cloudflare_' . $suffix), $suffix);
		}

		$this->assertSame(2, $this->migrator()->currentVersion());
		$this->assertSame(array(), $this->migrator()->pending());
	}

	public function testMigrateTwiceIsNoop(): void {
		$this->migrator()->migrate();

		$this->assertSame(array(), $this->migrator()->migrate());
		$this->assertSame(2, $this->migrator()->currentVersion());
	}

	public function testInterruptedMigrationResumes(): void {
		$statements = (new M001Foundation())->statements($this->db);
		$this->assertGreaterThan(2, count($statements));

		$this->db->execute('CREATE TABLE IF NOT EXISTS plugin_cloudflare_migrations (version INT UNSIGNED NOT NULL PRIMARY KEY, applied_at INT UNSIGNED NOT NULL) ENGINE=InnoDB');
		$this->db->execute($statements[0]);
		$this->db->execute($statements[1]);
		$this->assertSame(0, $this->migrator()->currentVersion());

		$this->assertSame(array(1, 2), $this->migrator()->migrate());
		$this->assertSame(2, $this->migrator()->currentVersion());

		$accountId = $this->insertAccount();
		$this->insertJob('discovery', $accountId);
		$this->db->execute('DELETE FROM plugin_cloudflare_accounts WHERE id = ?', array($accountId));
		$this->assertSame(0, (int) $this->db->fetchCell('SELECT COUNT(*) FROM plugin_cloudflare_jobs'));
	}

	public function testConcurrentMigrationRunsOnce(): void {
		$other = new Database(TestDatabase::pdo());
		$this->assertTrue($other->acquireLock('plugin_cloudflare_migrate', 0));

		try {
			$this->migrator(1)->migrate();
			$this->fail('Expected DatabaseException');
		} catch (DatabaseException $e) {
			$this->assertSame('HY000', $e->getSqlState());
		}

		$this->assertFalse($this->tableExists('plugin_cloudflare_accounts'));

		$other->releaseLock('plugin_cloudflare_migrate');

		$this->assertSame(array(1, 2), $this->migrator(1)->migrate());
	}

	public function testReleaseFailureKeepsTheMigrationError(): void {
		$pdo = TestDatabase::pdo();
		$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, array(ReleaseLockFailingStatement::class));
		$broken = new class () implements Migration {
			public function version(): int {
				return 1;
			}

			public function statements(Database $db): array {
				return array('CREATE TABLE plugin_cloudflare_broken (');
			}
		};

		try {
			(new Migrator(new Database($pdo), array($broken)))->migrate();
			$this->fail('Expected DatabaseException');
		} catch (DatabaseException $e) {
			$this->assertSame('42000', $e->getSqlState());
		} finally {
			$pdo->query("SELECT RELEASE_LOCK('plugin_cloudflare_migrate')");
		}
	}

	public function testReleaseFailureSurfacesAfterSuccessfulMigration(): void {
		$pdo = TestDatabase::pdo();
		$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, array(ReleaseLockFailingStatement::class));

		try {
			(new Migrator(new Database($pdo), Migrator::defaultMigrations()))->migrate();
			$this->fail('Expected DatabaseException');
		} catch (DatabaseException $e) {
			$this->assertSame('HY000', $e->getSqlState());
			$this->assertSame(2006, $e->getDriverCode());
		} finally {
			$pdo->query("SELECT RELEASE_LOCK('plugin_cloudflare_migrate')");
		}

		$this->assertSame(1, $this->migrator()->currentVersion());
	}

	public function testAccountIdUniqueConstraint(): void {
		$this->migrator()->migrate();
		$this->insertAccount();

		try {
			$this->insertAccount();
			$this->fail('Expected DatabaseException');
		} catch (DatabaseException $e) {
			$this->assertSame('23000', $e->getSqlState());
		}
	}

	public function testJobsUniquePerTypeAndAccount(): void {
		$this->migrator()->migrate();
		$first = $this->insertAccount();
		$second = $this->insertAccount('b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2');

		$this->insertJob('discovery', $first);
		$this->insertJob('discovery', $second);
		$this->insertJob('analytics', $first);

		try {
			$this->insertJob('discovery', $first);
			$this->fail('Expected DatabaseException');
		} catch (DatabaseException $e) {
			$this->assertSame('23000', $e->getSqlState());
		}
	}

	public function testJobRequiresExistingAccount(): void {
		$this->migrator()->migrate();

		$this->expectException(DatabaseException::class);
		$this->insertJob('discovery', 999);
	}

	public function testDeletingAccountCascadesJobs(): void {
		$this->migrator()->migrate();
		$id = $this->insertAccount();
		$this->insertJob('discovery', $id);
		$this->insertJob('analytics', $id);

		$this->db->execute('DELETE FROM plugin_cloudflare_accounts WHERE id = ?', array($id));

		$this->assertSame(0, (int) $this->db->fetchCell('SELECT COUNT(*) FROM plugin_cloudflare_jobs'));
	}

	public function testDropAllRemovesOnlyPluginTables(): void {
		$this->migrator()->migrate();
		$this->db->execute('CREATE TABLE plugin_cloudflare_later (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
		$this->db->execute('CREATE TABLE host_unrelated (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
		// An unescaped _ in the LIKE pattern would match this name.
		$this->db->execute('CREATE TABLE pluginxcloudflarexlookalike (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');

		$this->migrator()->dropAll();

		foreach (array('migrations', 'accounts', 'jobs', 'later') as $suffix) {
			$this->assertFalse($this->tableExists('plugin_cloudflare_' . $suffix), $suffix);
		}

		$this->assertTrue($this->tableExists('host_unrelated'));
		$this->assertTrue($this->tableExists('pluginxcloudflarexlookalike'));
	}

	public function testRerunAfterLostVersionRowIsIdempotent(): void {
		$this->migrator()->migrate();
		$this->db->execute('DELETE FROM plugin_cloudflare_migrations');

		$this->assertSame(array(1, 2), $this->migrator()->migrate());
		$this->assertSame(2, $this->migrator()->currentVersion());

		$count = (int) $this->db->fetchCell(
			"SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'plugin_cloudflare_jobs' AND constraint_type = 'FOREIGN KEY'"
		);
		$this->assertSame(1, $count);
	}

	public function testDuplicateMigrationVersionsAreRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		new Migrator($this->db, array(new M001Foundation(), new M001Foundation()));
	}

	public function testDropAllRemovesVersionRecordBeforeOtherPluginTables(): void {
		$this->migrator()->migrate();
		// A host table referencing a later plugin table makes that drop fail,
		// which stops dropAll right after the core tables.
		$this->db->execute('CREATE TABLE plugin_cloudflare_later (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
		$this->db->execute('CREATE TABLE host_unrelated (later_id INT UNSIGNED NOT NULL, FOREIGN KEY (later_id) REFERENCES plugin_cloudflare_later (id)) ENGINE=InnoDB');

		try {
			$this->migrator()->dropAll();
			$this->fail('Expected DatabaseException');
		} catch (DatabaseException $e) {
			// MariaDB refuses the drop with 23000/1451; MySQL 8 reports
			// ER_FK_CANNOT_DROP_PARENT as HY000/3730.
			$this->assertContains(
				$e->getSqlState() . '/' . $e->getDriverCode(),
				array('23000/1451', 'HY000/3730'),
				$e->getMessage()
			);
		}

		foreach (array('jobs', 'accounts', 'migrations') as $suffix) {
			$this->assertFalse($this->tableExists('plugin_cloudflare_' . $suffix), $suffix);
		}

		$this->assertTrue($this->tableExists('plugin_cloudflare_later'));
	}
}
