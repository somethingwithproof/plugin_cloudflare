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

namespace Cacti\PluginCloudflare\Tests\Integration\Glue;

use Cacti\PluginCloudflare\Account\AccountRepository;
use Cacti\PluginCloudflare\Metrics\ResourceRepository;
use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Support\SystemClock;
use Cacti\PluginCloudflare\Tests\Integration\IntegrationTestCase;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use PDO;
use RuntimeException;

/**
 * Drives cloudflare_sync_cacti_objects() with the plugin tables on the test
 * database and Cacti's host, graph_local and host_snmp_cache answered from
 * the stub model in the glue state.
 */
final class CactiObjectsTest extends IntegrationTestCase {
	private const ROOT          = __DIR__ . '/../../..';
	private const SESSION       = 'cacti-db:3306:cacti';
	private const HOST_TEMPLATE = 3;
	private const DATA_QUERY    = 4;
	private const DATA_TEMPLATE = 5;
	private const GRAPHS        = array('connectors' => 10, 'health' => 11, 'collection' => 12);
	private const QUERY_GRAPHS  = array('connectors' => 20, 'health' => 21, 'collection' => 22);

	/** @var PDO|null */
	private $pdo;

	/** @var Database|null */
	private $db;

	/** @var string|null */
	private $base;

	/** @var array<int, true> hosts whose deleted column is 'on' */
	private $deletedHosts = array();

	/** @var array<string, true>|null tunnel indexes in host_snmp_cache; null means every tunnel */
	private $cachedIndexes;

	/** @var array<int, string>|null fields cached for each index; null means only the index field */
	private $cachedFields;

	public static function setUpBeforeClass(): void {
		require_once self::ROOT . '/tests/Support/CactiGlueStubs.php';
		require_once self::ROOT . '/setup.php';
		require_once self::ROOT . '/includes/cacti_objects.php';
	}

	protected function setUp(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();
		cloudflare_glue_reset();

		$this->pdo = TestDatabase::pdo();
		$this->db  = new Database($this->pdo);

		$GLOBALS['database_hostname'] = 'cacti-db';
		$GLOBALS['database_port']     = '3306';
		$GLOBALS['database_default']  = 'cacti';
		$GLOBALS['database_sessions'] = array(self::SESSION => $this->pdo);
		$GLOBALS['config']            = array('poller_id' => 1);

		plugin_cloudflare_setup_database();

		$this->cacti();
	}

	protected function tearDown(): void {
		unset($GLOBALS['database_sessions'], $GLOBALS['database_hostname'], $GLOBALS['database_port'], $GLOBALS['database_default'], $GLOBALS['config']);

		if (is_string($this->base) && is_dir($this->base)) {
			self::removeTree($this->base);
		}

		if ($this->pdo !== null) {
			TestDatabase::reset();
		}
	}

	private static function removeTree(string $path): void {
		foreach ((array) scandir($path) as $name) {
			if ($name === '.' || $name === '..') {
				continue;
			}

			$child = $path . '/' . $name;
			is_dir($child) && !is_link($child) ? self::removeTree($child) : unlink($child);
		}

		rmdir($path);
	}

	private function db(): Database {
		$this->assertNotNull($this->db);

		return $this->db;
	}

	/**
	 * A base path with the resource XML and empty stand-ins for the
	 * libraries the glue loads, and a cell closure that answers the
	 * template, host, graph_local and host_snmp_cache lookups.
	 */
	private function cacti(): void {
		$this->base = sys_get_temp_dir() . '/cf-cacti-' . bin2hex(random_bytes(6));
		mkdir($this->base . '/resource/script_server', 0755, true);
		mkdir($this->base . '/lib');

		foreach (array('api_data_source.php', 'api_device.php', 'api_graph.php', 'data_query.php', 'snmp.php', 'template.php', 'utility.php') as $file) {
			file_put_contents($this->base . '/lib/' . $file, "<?php\n");
		}

		copy(self::ROOT . '/templates/resource/cloudflare_tunnels.xml', $this->base . '/resource/script_server/cloudflare_tunnels.xml');

		$GLOBALS['config']['base_path']                   = $this->base;
		$GLOBALS['cloudflare_glue']['data_template_id'] = self::DATA_TEMPLATE;

		$objects = array(
			md5('plugin_cloudflare/host_template') => self::HOST_TEMPLATE,
			md5('plugin_cloudflare/data_query')    => self::DATA_QUERY,
			md5('plugin_cloudflare/data_template') => self::DATA_TEMPLATE,
		);

		foreach (self::GRAPHS as $key => $id) {
			$objects[md5('plugin_cloudflare/graph_template/' . $key)]   = $id;
			$objects[md5('plugin_cloudflare/data_query_graph/' . $key)] = self::QUERY_GRAPHS[$key];
		}

		$GLOBALS['cloudflare_glue']['cell'] = function (string $sql, array $params) use ($objects) {
			if (strpos($sql, 'FROM host WHERE') !== false) {
				$id = (int) $params[0];

				return isset($GLOBALS['cloudflare_glue']['hosts'][$id]) && !isset($this->deletedHosts[$id]) ? $id : false;
			}

			if (strpos($sql, 'FROM host_snmp_cache') !== false) {
				$index  = (string) $params[2];
				$fields = $this->cachedFields ?? array((string) $GLOBALS['cloudflare_glue']['index_type']);

				// Without a field_name condition, a row cached for any field matches.
				if (strpos($sql, 'field_name = ?') !== false && !in_array((string) $params[3], $fields, true)) {
					return false;
				}

				return $this->cachedIndexes === null || isset($this->cachedIndexes[$index]) ? $index : false;
			}

			if (strpos($sql, 'FROM graph_local') !== false) {
				foreach ($GLOBALS['cloudflare_glue']['graph_local'] as $id => $graph) {
					if (array($graph['host_id'], $graph['snmp_query_id'], $graph['snmp_index'], $graph['graph_template_id'])
						=== array((int) $params[0], (int) $params[1], (string) $params[2], (int) $params[3])) {
						return $id;
					}
				}

				return false;
			}

			if (strpos($sql, 'FROM graph_templates_item') !== false) {
				return $GLOBALS['cloudflare_glue']['graph_local'][(int) $params[0]]['local_data_id'] ?? false;
			}

			return $objects[(string) ($params[0] ?? '')] ?? false;
		};
	}

	private function account(string $cfAccountId, bool $createGraphs = true): int {
		$id = (new AccountRepository($this->db()))->insert(array(
			'cf_account_id'      => $cfAccountId,
			'name'               => 'acme ' . $cfAccountId,
			'token_source'       => 'file',
			'token_file'         => sys_get_temp_dir() . '/cf-absent-token-' . $cfAccountId,
			'discovery_interval' => 300,
			'created_at'         => 1,
			'updated_at'         => 1,
		));

		$this->db()->execute('UPDATE plugin_cloudflare_accounts SET create_graphs = ? WHERE id = ?', array($createGraphs ? 1 : 0, $id));

		return $id;
	}

	private function resources(): ResourceRepository {
		return new ResourceRepository($this->db(), new SystemClock());
	}

	// A device the Create action made: a host in the stub model and the plugin's device row.
	private function device(int $accountId, int $hostId): void {
		$GLOBALS['cloudflare_glue']['hosts'][$hostId] = 'h' . $hostId . '.cloudflare.invalid';

		$this->resources()->recordDevice($accountId, $hostId);
	}

	private function tunnel(int $accountId, string $cfTunnelId, string $lifecycle = 'active'): int {
		$this->db()->execute(
			'INSERT INTO plugin_cloudflare_tunnels (account_id, cf_tunnel_id, name, cf_status, tun_type, lifecycle,
			first_seen_at, last_seen_at, lifecycle_changed_at) VALUES (?, ?, ?, \'healthy\', \'cfd_tunnel\', ?, 1, 1, 1)',
			array($accountId, $cfTunnelId, 'tunnel ' . $cfTunnelId, $lifecycle)
		);

		return (int) $this->db()->fetchCell(
			'SELECT id FROM plugin_cloudflare_tunnels WHERE account_id = ? AND cf_tunnel_id = ?',
			array($accountId, $cfTunnelId)
		);
	}

	private static function uuid(int $n): string {
		return sprintf('%08d-0000-4000-8000-000000000000', $n);
	}

	/**
	 * @return array{status: string, error_class: string|null, added: int, updated: int, deleted: int}
	 */
	private static function outcome(string $status): array {
		return array('status' => $status, 'error_class' => null, 'added' => 0, 'updated' => 0, 'deleted' => 0);
	}

	/**
	 * @return array<int, array<int, mixed>> graph_local model: id => [template, host, index, local_data_id]
	 */
	private static function graphLocal(): array {
		$graphs = array();

		foreach ($GLOBALS['cloudflare_glue']['graph_local'] as $id => $graph) {
			$graphs[$id] = array($graph['graph_template_id'], $graph['host_id'], $graph['snmp_index'], $graph['local_data_id']);
		}

		return $graphs;
	}

	/**
	 * @return array<int, array<int, int|string>> tunnel, template hash, host, graph, data source of each graph row
	 */
	private function graphRows(int $accountId): array {
		return array_map(function (array $row): array {
			return array($row['tunnel_id'], $row['template_hash'], $row['host_id'], $row['local_graph_id'], $row['local_data_id']);
		}, $this->resources()->graphs($accountId));
	}

	/**
	 * @return array<int, string>
	 */
	private static function logLines(): array {
		return array_map(function (array $call): string {
			return (string) $call[0];
		}, cloudflare_glue_calls('cacti_log'));
	}

	public function testReindexAfterSuccessfulDiscoveryOnly(): void {
		$outcomes = array();
		$host     = 1;

		foreach (array('ok', 'partial', 'failed', 'skipped', 'lease_lost') as $n => $status) {
			$id = $this->account(sprintf('%032d', $n + 1), false);
			$this->device($id, $host++);
			$outcomes[$id] = self::outcome($status);
		}

		$noDevice            = $this->account(sprintf('%032d', 9), false);
		$outcomes[$noDevice] = self::outcome('ok');

		$this->assertSame(array('reindexed' => 2, 'graphs' => 0, 'skipped' => 0), cloudflare_sync_cacti_objects($outcomes));
		$this->assertSame(
			array(array(1, self::DATA_QUERY, false, true), array(2, self::DATA_QUERY, false, true)),
			cloudflare_glue_calls('run_data_query')
		);
		$this->assertSame(array(), cloudflare_glue_calls('api_device_save'));
	}

	public function testRenameRefreshesTitles(): void {
		$id = $this->account(sprintf('%032d', 1));
		$this->device($id, 1);
		$this->tunnel($id, self::uuid(1));

		cloudflare_sync_cacti_objects(array($id => self::outcome('ok')));
		$this->db()->execute('UPDATE plugin_cloudflare_tunnels SET name = ? WHERE account_id = ?', array('renamed', $id));
		cloudflare_sync_cacti_objects(array($id => self::outcome('ok')));

		// Without $force the title cache follows only index changes (lib/data_query.php:356-368).
		$this->assertSame(
			array(array(1, self::DATA_QUERY, false, true), array(1, self::DATA_QUERY, false, true)),
			cloudflare_glue_calls('run_data_query')
		);
	}

	public function testCreatesThreeGraphsPerActiveTunnelOnce(): void {
		$id    = $this->account(sprintf('%032d', 1));
		$this->device($id, 7);
		$one   = $this->tunnel($id, self::uuid(1));
		$two   = $this->tunnel($id, self::uuid(2));
		$this->tunnel($id, self::uuid(3), 'unavailable');
		$this->tunnel($id, self::uuid(4), 'deleted');

		$this->assertSame(array('reindexed' => 1, 'graphs' => 6, 'skipped' => 0), cloudflare_sync_cacti_objects(array($id => self::outcome('ok'))));

		$expected = array();
		$graph    = 1;

		foreach (array($one => 1, $two => 2) as $tunnel => $n) {
			foreach (self::GRAPHS as $key => $template) {
				$this->assertSame(array($template, 7, array(
					'snmp_query_id'       => self::DATA_QUERY,
					'snmp_index_on'       => 'tunnelId',
					'snmp_query_graph_id' => self::QUERY_GRAPHS[$key],
					'snmp_index'          => self::uuid($n),
				), array()), cloudflare_glue_calls('create_complete_graph_from_template')[$graph - 1]);

				// One data source per tunnel: its three graphs share one RRD.
				$expected[] = array($tunnel, md5('plugin_cloudflare/graph_template/' . $key), 7, $graph, $n);
				$graph++;
			}
		}

		usort($expected, function (array $a, array $b): int {
			return array($a[0], $a[1]) <=> array($b[0], $b[1]);
		});

		$this->assertSame($expected, $this->graphRows($id));
		$this->assertSame(array(1 => array(self::DATA_TEMPLATE, 7, self::DATA_QUERY, self::uuid(1)), 2 => array(self::DATA_TEMPLATE, 7, self::DATA_QUERY, self::uuid(2))), $GLOBALS['cloudflare_glue']['data_local']);
		$this->assertSame(array(array(7, 1, 0), array(7, 1, 0), array(7, 1, 0), array(7, 2, 0), array(7, 2, 0), array(7, 2, 0)), cloudflare_glue_calls('push_out_host'));

		$GLOBALS['cloudflare_glue']['calls'] = array();

		$this->assertSame(array('reindexed' => 1, 'graphs' => 0, 'skipped' => 0), cloudflare_sync_cacti_objects(array($id => self::outcome('partial'))));
		$this->assertSame(array(), cloudflare_glue_calls('create_complete_graph_from_template'));
		$this->assertCount(6, $GLOBALS['cloudflare_glue']['graph_local']);
	}

	public function testTunnelMissingFromTheIndexCacheWaits(): void {
		$id = $this->account(sprintf('%032d', 1));
		$this->device($id, 7);
		$this->tunnel($id, self::uuid(1));
		$this->tunnel($id, self::uuid(2));
		$this->cachedIndexes = array(self::uuid(1) => true);

		$this->assertSame(3, cloudflare_sync_cacti_objects(array($id => self::outcome('ok')))['graphs']);

		$this->cachedIndexes = null;

		$this->assertSame(3, cloudflare_sync_cacti_objects(array($id => self::outcome('ok')))['graphs']);
		$this->assertCount(6, $this->graphRows($id));
	}

	/* Create builds the data source from the index field, so that is the cache row that must exist. */
	public function testIndexCacheCheckMatchesTheIndexField(): void {
		$id = $this->account(sprintf('%032d', 1));
		$this->device($id, 7);
		$this->tunnel($id, self::uuid(1));
		$this->cachedFields = array('tunnelName');

		$this->assertSame(0, cloudflare_sync_cacti_objects(array($id => self::outcome('ok')))['graphs']);
		$this->assertSame(array(), cloudflare_glue_calls('create_complete_graph_from_template'));

		$this->cachedFields = array('tunnelName', 'tunnelId');

		$this->assertSame(3, cloudflare_sync_cacti_objects(array($id => self::outcome('ok')))['graphs']);
	}

	public function testFailedReindexIsNotCounted(): void {
		$id = $this->account(sprintf('%032d', 1), false);
		$this->device($id, 7);
		$GLOBALS['cloudflare_glue']['reindex'] = false;

		$this->assertSame(
			array('reindexed' => 0, 'graphs' => 0, 'skipped' => 0),
			cloudflare_sync_cacti_objects(array($id => self::outcome('ok')))
		);
		$this->assertCount(1, cloudflare_glue_calls('run_data_query'));
	}

	public function testAdoptsExistingGraph(): void {
		$id     = $this->account(sprintf('%032d', 1));
		$this->device($id, 7);
		$tunnel = $this->tunnel($id, self::uuid(1));

		$GLOBALS['cloudflare_glue']['graph_local'][77] = array(
			'graph_template_id'   => self::GRAPHS['health'],
			'host_id'             => 7,
			'snmp_query_id'       => self::DATA_QUERY,
			'snmp_query_graph_id' => self::QUERY_GRAPHS['health'],
			'snmp_index'          => self::uuid(1),
			'local_data_id'       => 88,
		);

		$this->assertSame(2, cloudflare_sync_cacti_objects(array($id => self::outcome('ok')))['graphs']);

		$this->assertSame(
			array(self::GRAPHS['connectors'], self::GRAPHS['collection']),
			array_column(cloudflare_glue_calls('create_complete_graph_from_template'), 0)
		);
		$this->assertContains(array($tunnel, md5('plugin_cloudflare/graph_template/health'), 7, 77, 88), $this->graphRows($id));
		$this->assertCount(3, $this->graphRows($id));
	}

	public function testAdminDeletedGraphNotRecreated(): void {
		$id = $this->account(sprintf('%032d', 1));
		$this->device($id, 7);
		$this->tunnel($id, self::uuid(1));

		cloudflare_sync_cacti_objects(array($id => self::outcome('ok')));
		unset($GLOBALS['cloudflare_glue']['graph_local'][2]);
		$GLOBALS['cloudflare_glue']['calls'] = array();

		$this->assertSame(0, cloudflare_sync_cacti_objects(array($id => self::outcome('ok')))['graphs']);
		$this->assertSame(array(), cloudflare_glue_calls('create_complete_graph_from_template'));
		$this->assertCount(3, $this->graphRows($id));
	}

	public function testCreateGraphsOffOnlyReindexes(): void {
		$id = $this->account(sprintf('%032d', 1), false);
		$this->device($id, 7);
		$this->tunnel($id, self::uuid(1));

		$this->assertSame(array('reindexed' => 1, 'graphs' => 0, 'skipped' => 0), cloudflare_sync_cacti_objects(array($id => self::outcome('ok'))));
		$this->assertCount(1, cloudflare_glue_calls('run_data_query'));
		$this->assertSame(array(), cloudflare_glue_calls('create_complete_graph_from_template'));
		$this->assertSame(array(), $this->graphRows($id));
	}

	public function testDeletedDeviceIsNotRecreated(): void {
		$id = $this->account(sprintf('%032d', 1));
		$this->device($id, 7);
		$this->tunnel($id, self::uuid(1));
		unset($GLOBALS['cloudflare_glue']['hosts'][7]);

		$skipped = array('reindexed' => 0, 'graphs' => 0, 'skipped' => 1);
		$this->assertSame($skipped, cloudflare_sync_cacti_objects(array($id => self::outcome('ok'))));
		$this->assertSame($skipped, cloudflare_sync_cacti_objects(array($id => self::outcome('ok'))));

		// Cacti marks a host deleted before a remote poller removes it.
		$this->device($id, 8);
		$this->deletedHosts[8] = true;
		$this->assertSame($skipped, cloudflare_sync_cacti_objects(array($id => self::outcome('ok'))));

		$this->assertSame(array(), cloudflare_glue_calls('run_data_query'));
		$this->assertSame(array(), cloudflare_glue_calls('api_device_save'));
		$this->assertSame(array(), cloudflare_glue_calls('create_complete_graph_from_template'));
		$this->assertSame(array(
			'CLOUDFLARE: account ' . $id . ' device host 7 is gone; not synced until Create Cacti device replaces it',
			'CLOUDFLARE: account ' . $id . ' device host 8 is gone; not synced until Create Cacti device replaces it',
		), self::logLines());
		$this->assertSame(array(POLLER_VERBOSITY_LOW), array_values(array_unique(array_column(cloudflare_glue_calls('cacti_log'), 3))));
	}

	public function testGraphRowsOfAReplacedHostAreIgnored(): void {
		$id     = $this->account(sprintf('%032d', 1));
		$this->device($id, 7);
		$tunnel = $this->tunnel($id, self::uuid(1));

		cloudflare_sync_cacti_objects(array($id => self::outcome('ok')));
		$old = $GLOBALS['cloudflare_glue']['graph_local'];

		// The admin deleted host 7 in Cacti but kept its graphs, then created the device again.
		unset($GLOBALS['cloudflare_glue']['hosts'][7]);
		$this->device($id, 9);
		$GLOBALS['cloudflare_glue']['calls'] = array();

		$this->assertSame(3, cloudflare_sync_cacti_objects(array($id => self::outcome('ok')))['graphs']);

		$this->assertSame(array(9, 9, 9), array_column(cloudflare_glue_calls('create_complete_graph_from_template'), 1));
		$this->assertSame($old, array_intersect_key($GLOBALS['cloudflare_glue']['graph_local'], $old));
		$this->assertSame(array(9, 9, 9), array_column($this->graphRows($id), 2));
		$this->assertSame(array($tunnel, $tunnel, $tunnel), array_column($this->graphRows($id), 0));
	}

	public function testSyncNeverRemovesCactiObjects(): void {
		$id = $this->account(sprintf('%032d', 1));
		$this->device($id, 7);
		$this->tunnel($id, self::uuid(1));
		$this->tunnel($id, self::uuid(2));

		cloudflare_sync_cacti_objects(array($id => self::outcome('ok')));
		$graphs = self::graphLocal();
		$rows   = $this->graphRows($id);

		foreach (array('unavailable', 'deleted') as $lifecycle) {
			$this->db()->execute('UPDATE plugin_cloudflare_tunnels SET lifecycle = ? WHERE cf_tunnel_id = ?', array($lifecycle, self::uuid(1)));
			cloudflare_sync_cacti_objects(array($id => self::outcome('partial')));
		}

		$this->db()->execute('UPDATE plugin_cloudflare_accounts SET create_graphs = 0 WHERE id = ?', array($id));
		cloudflare_sync_cacti_objects(array($id => self::outcome('ok')));
		unset($GLOBALS['cloudflare_glue']['hosts'][7]);
		cloudflare_sync_cacti_objects(array($id => self::outcome('ok')));

		foreach (array('api_graph_remove', 'api_data_source_remove', 'api_device_remove') as $function) {
			$this->assertSame(array(), cloudflare_glue_calls($function), $function);
		}

		// Cacti's tables are written only through its own APIs, never by direct SQL.
		$this->assertSame(array(), cloudflare_glue_calls('db_execute_prepared'));

		$this->assertSame($graphs, self::graphLocal());
		$this->assertSame($rows, $this->graphRows($id));
		$this->assertSame(7, $this->resources()->device($id)['host_id'] ?? null);
	}

	public function testGraphCapAndBudget(): void {
		$id = $this->account(sprintf('%032d', 1));
		$this->device($id, 7);
		$this->tunnel($id, self::uuid(1));
		$this->tunnel($id, self::uuid(2));

		$this->assertSame(
			array('reindexed' => 0, 'graphs' => 0, 'skipped' => 0),
			cloudflare_sync_cacti_objects(array($id => self::outcome('ok')), 0)
		);
		$this->assertSame(array(), cloudflare_glue_calls('run_data_query'));

		$this->assertSame(4, cloudflare_sync_cacti_objects(array($id => self::outcome('ok')), 20, 4)['graphs']);
		$this->assertCount(4, $GLOBALS['cloudflare_glue']['graph_local']);

		$this->assertSame(2, cloudflare_sync_cacti_objects(array($id => self::outcome('ok')), 20, 4)['graphs']);
		$this->assertCount(6, $this->graphRows($id));
	}

	public function testDeadlinePassingMidTunnelStopsBeforeTheNextGraph(): void {
		$id = $this->account(sprintf('%032d', 1));
		$this->device($id, 7);
		$this->tunnel($id, self::uuid(1));

		/* The budget is whole seconds from time(), so the cache lookup, which
		 * runs once per tunnel after the first deadline check, waits it out. */
		$until  = time() + 2;
		$answer = $GLOBALS['cloudflare_glue']['cell'];
		$GLOBALS['cloudflare_glue']['cell'] = function (string $sql, array $params) use ($answer, $until) {
			if (strpos($sql, 'FROM host_snmp_cache') !== false) {
				while (time() < $until) {
					usleep(50000);
				}
			}

			return $answer($sql, $params);
		};

		$this->assertSame(1, cloudflare_sync_cacti_objects(array($id => self::outcome('ok')), 1)['graphs']);
		$this->assertCount(1, $this->graphRows($id));
	}

	public function testMissingTemplatesLogOnceAtLow(): void {
		$outcomes = array();

		foreach (array(1 => 7, 2 => 8) as $n => $host) {
			$id = $this->account(sprintf('%032d', $n));
			$this->device($id, $host);
			$outcomes[$id] = self::outcome('ok');
		}

		$this->assertNotNull($this->base);
		unlink($this->base . '/resource/script_server/cloudflare_tunnels.xml');

		$this->assertSame(
			array('reindexed' => 0, 'graphs' => 0, 'skipped' => 0),
			cloudflare_sync_cacti_objects($outcomes)
		);
		$this->assertSame(array('CLOUDFLARE: graph sync skipped; templates missing'), self::logLines());
		$this->assertSame(POLLER_VERBOSITY_LOW, cloudflare_glue_calls('cacti_log')[0][3]);
		$this->assertSame(array(), cloudflare_glue_calls('run_data_query'));
	}

	public function testFaultInOneAccountDoesNotStopTheNext(): void {
		$first  = $this->account(sprintf('%032d', 1));
		$second = $this->account(sprintf('%032d', 2), false);
		$this->device($first, 7);
		$this->device($second, 8);

		$answer = $GLOBALS['cloudflare_glue']['cell'];
		$GLOBALS['cloudflare_glue']['cell'] = function (string $sql, array $params) use ($answer) {
			if (strpos($sql, 'FROM host WHERE') !== false && (int) $params[0] === 7) {
				throw new RuntimeException('detail that must not be logged');
			}

			return $answer($sql, $params);
		};

		$this->assertSame(
			array('reindexed' => 1, 'graphs' => 0, 'skipped' => 0),
			cloudflare_sync_cacti_objects(array($first => self::outcome('ok'), $second => self::outcome('ok')))
		);
		$this->assertSame(array(array(8, self::DATA_QUERY, false, true)), cloudflare_glue_calls('run_data_query'));
		$this->assertSame(array('CLOUDFLARE: account ' . $first . ' graph sync failed (RuntimeException)'), self::logLines());
		$this->assertSame(POLLER_VERBOSITY_LOW, cloudflare_glue_calls('cacti_log')[0][3]);
	}
}
