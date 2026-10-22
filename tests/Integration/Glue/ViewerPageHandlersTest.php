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

use Cacti\PluginCloudflare\Api\ConnectorRecord;
use Cacti\PluginCloudflare\Api\ErrorClass;
use Cacti\PluginCloudflare\Api\TunnelRecord;
use Cacti\PluginCloudflare\Discovery\Reconciler;
use Cacti\PluginCloudflare\Jobs\JobRepository;
use Cacti\PluginCloudflare\Jobs\Lease;
use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Tests\Integration\IntegrationTestCase;
use Cacti\PluginCloudflare\Tests\Support\FrozenClock;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;

/**
 * Drives the cloudflare.php handlers through the glue stubs against the
 * test database, as the viewer realm would see them.
 */
final class ViewerPageHandlersTest extends IntegrationTestCase {
	private const ROOT    = __DIR__ . '/../../..';
	private const SESSION = 'cacti-db:3306:cacti';
	private const T0      = 1800000000;

	/** @var Database|null */
	private $db;

	public static function setUpBeforeClass(): void {
		require_once self::ROOT . '/tests/Support/CactiGlueStubs.php';
		require_once self::ROOT . '/setup.php';
		require_once self::ROOT . '/includes/viewer.php';
	}

	protected function setUp(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();
		cloudflare_glue_reset();

		$pdo = TestDatabase::pdo();

		$GLOBALS['database_hostname'] = 'cacti-db';
		$GLOBALS['database_port']     = '3306';
		$GLOBALS['database_default']  = 'cacti';
		$GLOBALS['database_sessions'] = array(self::SESSION => $pdo);
		$GLOBALS['config']            = array('url_path' => '/cacti/');
		$GLOBALS['item_rows']         = array(10 => '10', 25 => '25');

		$this->db = new Database($pdo);

		$_REQUEST = array();
		$_SESSION = array();
	}

	protected function tearDown(): void {
		$_REQUEST = array();
		$_SESSION = array();
		unset(
			$GLOBALS['database_sessions'],
			$GLOBALS['database_hostname'],
			$GLOBALS['database_port'],
			$GLOBALS['database_default'],
			$GLOBALS['config'],
			$GLOBALS['item_rows']
		);

		if ($this->db !== null) {
			TestDatabase::reset();
		}
	}

	private function account(string $name, string $cfAccountId): int {
		$this->db->execute(
			"INSERT INTO plugin_cloudflare_accounts (cf_account_id, name, token_source, token_file, created_at, updated_at)
			VALUES (?, ?, 'file', '/etc/cacti/cf.token', 1, 1)",
			array($cfAccountId, $name)
		);

		return $this->db->lastInsertId();
	}

	private static function uuid(int $n): string {
		return sprintf('%08x-0000-4000-8000-%012x', $n, $n);
	}

	private static function tunnel(int $n, string $name, string $status): TunnelRecord {
		return new TunnelRecord(self::uuid($n), $name, $status, 'cfd_tunnel', null, null, null);
	}

	private function lease(JobRepository $jobs, int $accountId): Lease {
		$jobs->ensure(JobRepository::TYPE_DISCOVERY, $accountId);
		$id = (int) $this->db->fetchCell('SELECT id FROM plugin_cloudflare_jobs WHERE account_id = ?', array($accountId));

		$lease = $jobs->lease($id, 'host-a:1', 60);
		$this->assertInstanceOf(Lease::class, $lease);

		return $lease;
	}

	/**
	 * Two accounts: Prod lists three tunnels, succeeds once and then fails twice; Edge
	 * lists one healthy tunnel and succeeds.
	 *
	 * @return array{0: int, 1: int}
	 */
	private function seed(): array {
		plugin_cloudflare_setup_database();

		$prod = $this->account('Prod', 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1');
		$edge = $this->account('Edge', 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2');

		$reconciler = new Reconciler($this->db, new FrozenClock(self::T0));
		$reconciler->applyListing($prod, array(
			self::tunnel(1, 'web', 'healthy'),
			self::tunnel(2, 'api', 'degraded'),
			self::tunnel(3, 'old', 'healthy'),
		));
		(new Reconciler($this->db, new FrozenClock(self::T0 + 60)))->applyListing($prod, array(
			self::tunnel(1, 'web', 'healthy'),
			self::tunnel(2, 'api', 'degraded'),
		));
		$reconciler->applyListing($edge, array(self::tunnel(4, 'edge-web', 'healthy')));

		$first = new JobRepository($this->db, new FrozenClock(self::T0 + 100));
		$first->succeed($this->lease($first, $prod), self::T0 + 200);

		$jobs = new JobRepository($this->db, new FrozenClock(self::T0 + 300));
		$jobs->fail($this->lease($jobs, $prod), ErrorClass::TRANSIENT, self::T0 + 600, true);
		$jobs->fail($this->lease($jobs, $prod), ErrorClass::TRANSIENT, self::T0 + 900, true);
		$jobs->succeed($this->lease($jobs, $edge), self::T0 + 600);

		return array($prod, $edge);
	}

	/**
	 * @return array<int, array<int, string>> the cells of each drawn row
	 */
	private static function rows(): array {
		$rows = array();
		$row  = null;

		foreach ($GLOBALS['cloudflare_glue']['calls'] as $call) {
			if ($call[0] === 'form_alternate_row') {
				$row = array();
			} elseif ($call[0] === 'form_selectable_cell' && $row !== null) {
				$row[] = (string) $call[1][0];
			} elseif ($call[0] === 'form_end_row' && $row !== null) {
				$rows[] = $row;
				$row    = null;
			}
		}

		return $rows;
	}

	/**
	 * @param callable(): void $render
	 */
	private static function capture(callable $render): string {
		ob_start();

		try {
			$render();
		} finally {
			$output = (string) ob_get_clean();
		}

		return $output;
	}

	public function testOverviewReadsCountsAndJobState(): void {
		$this->seed();

		self::capture('cloudflare_viewer_overview');

		$rows = self::rows();
		$this->assertCount(2, $rows);

		/* AccountRepository::all() orders by name. */
		$this->assertStringContainsString('>Edge</a>', $rows[0][0]);
		$this->assertSame(
			array('Healthy', '1', '0', '0', date('Y-m-d H:i:s', self::T0 + 300), '0'),
			array_slice($rows[0], 1)
		);

		$this->assertStringContainsString('>Prod</a>', $rows[1][0]);
		$this->assertSame(
			array('Collection failing', '2', '0', '1', date('Y-m-d H:i:s', self::T0 + 100), '2'),
			array_slice($rows[1], 1)
		);
	}

	public function testTunnelsListEveryAccountSortedAndPaged(): void {
		$this->seed();

		$_REQUEST = array(
			'account_id'     => '0',
			'rows'           => '2',
			'page'           => '2',
			'sort_column'    => 'name',
			'sort_direction' => 'ASC',
		);

		self::capture('cloudflare_viewer_tunnels');

		$nav = cloudflare_glue_calls('html_nav_bar');
		$this->assertCount(1, $nav);
		$this->assertSame(array(2, 2, 4), array($nav[0][2], $nav[0][3], $nav[0][4]));

		/* api, edge-web, old, web: page two holds old and web. */
		$rows = self::rows();
		$this->assertCount(2, $rows);
		$this->assertStringContainsString('>old</a>', $rows[0][0]);
		$this->assertSame('Prod', $rows[0][1]);
		$this->assertSame('deleted', $rows[0][3]);
		$this->assertStringContainsString('>web</a>', $rows[1][0]);
	}

	public function testTunnelsFilterNarrowsToOneAccountAndStatus(): void {
		list($prod) = $this->seed();

		$_REQUEST = array(
			'account_id'     => (string) $prod,
			'cf_status'      => 'degraded',
			'sort_column'    => 'cf_status',
			'sort_direction' => 'DESC',
		);

		$output = self::capture('cloudflare_viewer_tunnels');

		$rows = self::rows();
		$this->assertCount(1, $rows);
		$this->assertStringContainsString('>api</a>', $rows[0][0]);
		$this->assertSame('degraded', $rows[0][1], 'no account column for a single account');
		$this->assertStringContainsString("<option value='" . $prod . "' selected>Prod</option>", $output);

		$sort = cloudflare_glue_calls('html_header_sort');
		$this->assertCount(1, $sort);
		$this->assertSame(array('cf_status', 'DESC'), array($sort[0][1], $sort[0][2]));
	}

	public function testZeroFiltersListEveryTunnelInsteadOfThrowing(): void {
		$this->seed();

		$_REQUEST = array(
			'lifecycle'      => '0',
			'cf_status'      => '0',
			'sort_column'    => '0',
			'sort_direction' => '0',
			'rows'           => '25',
		);

		self::capture('cloudflare_viewer_tunnels');

		$this->assertCount(4, self::rows());
		$this->assertSame(array(), cloudflare_glue_calls('raise_message'));
		$this->assertSame('0', $_SESSION['sess_cf_tunnels_lifecycle'], 'stored as Cacti stores it');
	}

	public function testConnectorsShowOneTunnel(): void {
		list($prod) = $this->seed();

		$tunnelId = (int) $this->db->fetchCell(
			'SELECT id FROM plugin_cloudflare_tunnels WHERE account_id = ? AND name = ?',
			array($prod, 'web')
		);
		(new Reconciler($this->db, new FrozenClock(self::T0)))->applyConnections($tunnelId, array(
			new ConnectorRecord(self::uuid(1001), '2026.1.0', 'linux_amd64', null, 4, array('LHR', 'AMS'), array('192.0.2.1')),
		));

		$_REQUEST = array('tunnel_id' => (string) $tunnelId);

		self::capture('cloudflare_viewer_connectors');

		$this->assertContains(
			array('Connectors for web', '100%', '', '3', 'center', '', false),
			cloudflare_glue_calls('html_start_box')
		);

		$rows = self::rows();
		$this->assertCount(1, $rows);
		$this->assertSame(array(self::uuid(1001), '2026.1.0', 'linux_amd64'), array_slice($rows[0], 0, 3));
		$this->assertSame('4', $rows[0][4]);
	}

	public function testConnectorsOfANonActiveTunnelAreLabelledLastSeen(): void {
		list($prod) = $this->seed();

		$tunnelId = (int) $this->db->fetchCell(
			'SELECT id FROM plugin_cloudflare_tunnels WHERE account_id = ? AND name = ?',
			array($prod, 'web')
		);
		$reconciler = new Reconciler($this->db, new FrozenClock(self::T0));
		$reconciler->applyConnections($tunnelId, array(
			new ConnectorRecord(self::uuid(1001), '2026.1.0', 'linux_amd64', null, 4, array('LHR'), array('192.0.2.1')),
		));

		$_REQUEST = array('tunnel_id' => (string) $tunnelId);
		$label    = 'These connectors were last seen while the tunnel was active.';

		$this->assertStringNotContainsString($label, self::capture('cloudflare_viewer_connectors'));

		$reconciler->markUnavailable($prod);
		cloudflare_glue_reset();
		$_REQUEST = array('tunnel_id' => (string) $tunnelId);
		$output   = self::capture('cloudflare_viewer_connectors');

		$this->assertStringContainsString('This tunnel is unavailable. ' . $label, $output);
		$this->assertCount(1, self::rows(), 'the stored connector is still listed');
	}

	public function testConnectorsForAMissingTunnelSayNotFound(): void {
		$this->seed();

		$_REQUEST = array('tunnel_id' => '999999');
		$output   = self::capture('cloudflare_viewer_connectors');

		$this->assertSame(array(), self::rows());
		$this->assertStringContainsString('The tunnel no longer exists.', $output);

		cloudflare_glue_reset();
		$_REQUEST = array();
		$output   = self::capture('cloudflare_viewer_connectors');
		$this->assertStringContainsString('Choose a tunnel on the Tunnels tab.', $output);
	}

	public function testMissingSchemaIsReportedNotThrown(): void {
		foreach (array('cloudflare_viewer_overview', 'cloudflare_viewer_tunnels') as $handler) {
			cloudflare_glue_reset();
			$_REQUEST = array();
			$_SESSION = array();

			$output = self::capture($handler);

			$this->assertStringContainsString(
				'The Cloudflare tunnels could not be read. See the Cacti log for details.',
				$output,
				$handler
			);
			$this->assertCount(1, cloudflare_glue_calls('raise_message'), $handler);

			$logged = cloudflare_glue_calls('cacti_log');
			$this->assertCount(1, $logged, $handler);
			$this->assertMatchesRegularExpression(
				'#^CLOUDFLARE: viewer \w+ failed: SQLSTATE\[\w+\] driver error \d+$#',
				(string) $logged[0][0]
			);
		}

		$_REQUEST = array('tunnel_id' => '5');
		$output   = self::capture('cloudflare_viewer_connectors');
		$this->assertStringContainsString('could not be read', $output);
	}
}
