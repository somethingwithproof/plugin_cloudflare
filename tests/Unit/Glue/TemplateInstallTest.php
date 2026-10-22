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

namespace Cacti\PluginCloudflare\Tests\Unit\Glue;

use Closure;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Cacti's tables are answered by the db_fetch_cell_prepared() stub; the
 * plugin's own mapping table lives in SQLite standing in for Cacti's handle.
 */
final class TemplateInstallTest extends TestCase {
	private const ROOT = __DIR__ . '/../../..';

	private const TEMPLATES = self::ROOT . '/templates/cloudflare_templates.xml';

	private const RESOURCE = self::ROOT . '/templates/resource/cloudflare_tunnels.xml';

	/* What import_xml_data() returns for a clean import (lib/import.php:278-300). */
	private const IMPORTED = array(
		'data_query' => array(array('type' => 'new', 'title' => 'Cloudflare Tunnels', 'result' => 'success')),
		'host_template' => array(array('type' => 'new', 'title' => 'Cloudflare Account', 'result' => 'success')),
		'files' => array('resource/script_server/cloudflare_tunnels.xml'),
	);

	/** @var string */
	private $base;

	/** @var PDO|null */
	private $pdo;

	/** @var array<string, int> hash => id of the Cacti objects that "exist" */
	private $objects = array();

	/** @var int objects exist only after more imports than this have run */
	private $deletedAt = 0;

	/** @var bool whether GET_LOCK succeeds */
	private $lockFree = true;

	public static function setUpBeforeClass(): void {
		require_once self::ROOT . '/tests/Support/CactiGlueStubs.php';
		require_once self::ROOT . '/includes/templates.php';
	}

	protected function setUp(): void {
		if (!extension_loaded('pdo_sqlite')) {
			$this->markTestSkipped('pdo_sqlite is not loaded');
		}

		cloudflare_glue_reset();

		$this->base = sys_get_temp_dir() . '/cf-base-' . bin2hex(random_bytes(6));
		mkdir($this->base . '/resource/script_server', 0755, true);
		self::cactiLibraries($this->base);

		$this->pdo = $this->sqlite();

		$GLOBALS['config']            = array('base_path' => $this->base);
		$GLOBALS['database_hostname'] = 'cacti-db';
		$GLOBALS['database_port']     = '3306';
		$GLOBALS['database_default']  = 'cacti';
		$GLOBALS['database_sessions'] = array('cacti-db:3306:cacti' => $this->pdo);

		$GLOBALS['cloudflare_glue']['import'] = self::IMPORTED;
		$GLOBALS['cloudflare_glue']['cell']   = $this->cactiTables();
	}

	protected function tearDown(): void {
		unset($GLOBALS['config'], $GLOBALS['database_sessions'], $GLOBALS['import_messages']);
		$this->pdo = null;

		if (is_dir($this->base . '/resource/script_server')) {
			chmod($this->base . '/resource/script_server', 0755);
		}

		foreach (array('/resource/script_server', '/resource', '/lib', '') as $dir) {
			foreach ((array) glob($this->base . $dir . '/{,.}*', GLOB_BRACE) as $file) {
				if (is_file((string) $file)) {
					unlink((string) $file);
				}
			}

			if (is_dir($this->base . $dir)) {
				rmdir($this->base . $dir);
			}
		}
	}

	/**
	 * Stands in for the libraries the install loads beside import.php; the
	 * stubs cover what the test reaches, so the files stay empty.
	 */
	private static function cactiLibraries(string $base): void {
		mkdir($base . '/lib');

		foreach (array('template.php', 'utility.php') as $file) {
			file_put_contents($base . '/lib/' . $file, "<?php\n");
		}
	}

	private function sqlite(): PDO {
		$pdo = new PDO('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
		$pdo->sqliteCreateFunction('GET_LOCK', function (): int {
			return $this->lockFree ? 1 : 0;
		}, 2);
		$pdo->sqliteCreateFunction('RELEASE_LOCK', function (): int {
			return 1;
		}, 1);
		$pdo->exec('CREATE TABLE plugin_cloudflare_metric_mappings (
			metric_name VARCHAR(64) NOT NULL PRIMARY KEY,
			schema_version INTEGER NOT NULL,
			ds_type VARCHAR(7) NOT NULL,
			unit VARCHAR(16) NOT NULL,
			query_field VARCHAR(32) NOT NULL,
			data_template_hash CHAR(32) NOT NULL,
			rrd_ds_name VARCHAR(19) NOT NULL,
			UNIQUE (data_template_hash, rrd_ds_name)
		)');

		return $pdo;
	}

	/**
	 * Answers the default profile query and the hash lookups. import_xml_data
	 * "creates" every object, so lookups succeed only after an import, and
	 * raising $deletedAt "deletes" them until the next one.
	 */
	private function cactiTables(): Closure {
		return function (string $sql, array $params) {
			if (strpos($sql, 'FROM data_source_profiles') !== false) {
				return '1';
			}

			if (preg_match('/^SELECT id FROM `([a-z_]+)` WHERE hash = \?$/', $sql) === 1) {
				if (count(cloudflare_glue_calls('import_xml_data')) <= $this->deletedAt) {
					return false;
				}

				$hash = (string) $params[0];
				if (!isset($this->objects[$hash])) {
					$this->objects[$hash] = count($this->objects) + 1;
				}

				return (string) $this->objects[$hash];
			}

			return false;
		};
	}

	private function target(): string {
		return $this->base . '/resource/script_server/cloudflare_tunnels.xml';
	}

	/**
	 * @return array<int, string>
	 */
	private static function loggedLines(): array {
		return array_map(function (array $call): string {
			return (string) $call[0];
		}, cloudflare_glue_calls('cacti_log'));
	}

	private function mappingCount(): int {
		$this->assertNotNull($this->pdo);

		return (int) $this->pdo->query('SELECT COUNT(*) FROM plugin_cloudflare_metric_mappings')->fetchColumn();
	}

	public function testInstallCopiesResourceAndImportsOnce(): void {
		$sha = hash_file('sha256', self::TEMPLATES);

		$this->assertTrue(cloudflare_templates_install());

		$this->assertFileEquals(self::RESOURCE, $this->target());
		$this->assertSame('0644', substr(sprintf('%o', fileperms($this->target())), -4));
		$this->assertSame(array(array($sha, false, '1')), cloudflare_glue_calls('import_xml_data'));
		$this->assertSame(
			array(array('cloudflare_templates_sha256', $sha, false)),
			cloudflare_glue_calls('set_config_option')
		);
		$this->assertSame(6, $this->mappingCount());
		$this->assertSame(array(), self::loggedLines());

		$this->assertTrue(cloudflare_templates_install());

		$this->assertCount(1, cloudflare_glue_calls('import_xml_data'));
		$this->assertCount(1, cloudflare_glue_calls('set_config_option'));
		$this->assertSame(6, $this->mappingCount());
		$this->assertSame(array('cloudflare_tunnels.xml'), array_values(array_diff(
			(array) scandir($this->base . '/resource/script_server'),
			array('.', '..')
		)), 'no temporary file is left behind');
	}

	public function testChangedTemplateReimports(): void {
		$this->assertTrue(cloudflare_templates_install());

		/* An older release recorded another hash and copied another resource. */
		$GLOBALS['cloudflare_glue']['settings']['cloudflare_templates_sha256'] = str_repeat('0', 64);
		file_put_contents($this->target(), '<interface></interface>');

		$this->assertTrue(cloudflare_templates_install());

		$this->assertFileEquals(self::RESOURCE, $this->target());
		$this->assertCount(2, cloudflare_glue_calls('import_xml_data'));
		$this->assertSame(
			hash_file('sha256', self::TEMPLATES),
			$GLOBALS['cloudflare_glue']['settings']['cloudflare_templates_sha256']
		);
	}

	public function testUnwritableResourceDirLogsAndReturnsFalse(): void {
		chmod($this->base . '/resource/script_server', 0555);

		if (is_writable($this->base . '/resource/script_server')) {
			$this->markTestSkipped('running as a user that ignores directory permissions');
		}

		$this->assertFalse(cloudflare_templates_install());

		$this->assertFileDoesNotExist($this->target());
		$this->assertSame(array(), cloudflare_glue_calls('import_xml_data'));
		$this->assertSame(array(), cloudflare_glue_calls('set_config_option'));

		$logs = cloudflare_glue_calls('cacti_log');
		$this->assertCount(1, $logs);
		$this->assertSame('CLOUDFLARE: cannot write ' . $this->target(), $logs[0][0]);
		$this->assertSame(POLLER_VERBOSITY_LOW, $logs[0][3]);
	}

	public function testFailedImportRecordsNoHashAndRetries(): void {
		$GLOBALS['cloudflare_glue']['import'] = array(
			'data_query' => array(array('type' => 'new', 'title' => 'Cloudflare Tunnels', 'result' => 'fail')),
		);

		$this->assertFalse(cloudflare_templates_install());
		$this->assertSame(array(), cloudflare_glue_calls('set_config_option'));
		$this->assertSame(array('CLOUDFLARE: template import failed (data_query)'), self::loggedLines());

		/* A parse error returns an empty result and queues message 7 (lib/import.php:38). */
		$GLOBALS['cloudflare_glue']['import'] = function (): array {
			$GLOBALS['import_messages'][] = 7;

			return array();
		};

		$this->assertFalse(cloudflare_templates_install());
		$this->assertSame(array(), cloudflare_glue_calls('set_config_option'));
		$this->assertCount(2, cloudflare_glue_calls('import_xml_data'));

		$GLOBALS['cloudflare_glue']['import'] = self::IMPORTED;

		$this->assertTrue(cloudflare_templates_install());
		$this->assertCount(3, cloudflare_glue_calls('import_xml_data'));
		$this->assertCount(1, cloudflare_glue_calls('set_config_option'));
	}

	public function testMissingDefaultProfileStopsBeforeImport(): void {
		$GLOBALS['cloudflare_glue']['cell'] = false;

		$this->assertFalse(cloudflare_templates_install());
		$this->assertSame(array(), cloudflare_glue_calls('import_xml_data'));
		$this->assertSame(array('CLOUDFLARE: template import skipped; no default data source profile'), self::loggedLines());
	}

	public function testReadyNullUntilImported(): void {
		$this->assertNull(cloudflare_templates_ready());

		$this->assertTrue(cloudflare_templates_install());

		$ready = cloudflare_templates_ready();
		$this->assertNotNull($ready);
		$this->assertSame(array('host_template_id', 'data_query_id', 'data_template_id', 'graphs'), array_keys($ready));
		$this->assertSame(array('connectors', 'health', 'collection'), array_keys($ready['graphs']));

		foreach ($ready['graphs'] as $graph) {
			$this->assertSame(array('graph_template_id', 'snmp_query_graph_id'), array_keys($graph));
			$this->assertIsInt($graph['graph_template_id']);
			$this->assertIsInt($graph['snmp_query_graph_id']);
		}

		/* Every hash looked up names an object of the right type in the shipped XML. */
		$xml    = (string) file_get_contents(self::TEMPLATES);
		$types  = array(
			'host_template'    => '02',
			'snmp_query'       => '04',
			'data_template'    => '01',
			'graph_templates'  => '00',
			'snmp_query_graph' => '11',
		);
		$lookups = 0;

		foreach (cloudflare_glue_calls('db_fetch_cell_prepared') as $call) {
			if (preg_match('/^SELECT id FROM `([a-z_]+)` WHERE hash = \?$/', (string) $call[0], $m) !== 1) {
				continue;
			}

			$lookups++;
			$this->assertArrayHasKey($m[1], $types);
			$this->assertStringContainsString('<hash_' . $types[$m[1]] . '0103' . $call[1][0] . '>', $xml);
		}
		$this->assertGreaterThan(0, $lookups);

		unlink($this->target());

		$this->assertNull(cloudflare_templates_ready(), 'the data query cannot run without its resource file');
	}

	public function testUnknownBasePathWritesNothing(): void {
		$GLOBALS['config'] = array();

		$this->assertFalse(cloudflare_templates_install());
		$this->assertNull(cloudflare_templates_ready());
		$this->assertSame(array(), cloudflare_glue_calls('import_xml_data'));
		$this->assertSame(array('CLOUDFLARE: templates not installed; the Cacti base path is unknown'), self::loggedLines());
	}

	public function testDeletedTemplateIsRestored(): void {
		$this->assertTrue(cloudflare_templates_install());
		$this->assertNotNull(cloudflare_templates_ready());

		/* An admin deletes the graph templates; the recorded hash still matches. */
		$this->deletedAt = 1;
		$this->assertNull(cloudflare_templates_ready());

		$this->assertTrue(cloudflare_templates_install());

		$this->assertCount(2, cloudflare_glue_calls('import_xml_data'));
		$this->assertNotNull(cloudflare_templates_ready());
	}

	public function testHeldLockDefersWithoutTouchingAnything(): void {
		$this->lockFree = false;

		$this->assertFalse(cloudflare_templates_install());

		$this->assertFileDoesNotExist($this->target());
		$this->assertSame(array(), cloudflare_glue_calls('import_xml_data'));
		$this->assertSame(0, $this->mappingCount());
		$logs = cloudflare_glue_calls('cacti_log');
		$this->assertCount(1, $logs);
		$this->assertSame('CLOUDFLARE: template install deferred; another process holds the template lock', $logs[0][0]);
		$this->assertSame(POLLER_VERBOSITY_LOW, $logs[0][3]);
	}

	public function testRecordedHashIsReadPastTheRequestCache(): void {
		$sha = hash_file('sha256', self::TEMPLATES);
		$this->assertTrue(cloudflare_templates_install());

		/* This request read the setting before another process finished its
		 * import and recorded the hash. */
		$GLOBALS['cloudflare_glue']['cached']['cloudflare_templates_sha256'] = '';
		$GLOBALS['cloudflare_glue']['settings']['cloudflare_templates_sha256'] = $sha;
		$this->assertSame('', read_config_option('cloudflare_templates_sha256'));

		$this->assertTrue(cloudflare_templates_install());

		$this->assertCount(1, cloudflare_glue_calls('import_xml_data'));
	}

	public function testLinkAtTargetIsReplacedNotFollowed(): void {
		$cases = array('different' => '<decoy/>', 'identical' => (string) file_get_contents(self::RESOURCE));

		foreach ($cases as $case => $content) {
			$decoy = $this->base . '/decoy-' . $case . '.xml';
			file_put_contents($decoy, $content);
			@unlink($this->target());
			symlink($decoy, $this->target());

			$this->assertTrue(cloudflare_templates_install(), $case);

			$this->assertFalse(is_link($this->target()), $case);
			$this->assertFileEquals(self::RESOURCE, $this->target(), $case);
			$this->assertSame($content, file_get_contents($decoy), $case);
		}
	}
}
