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

use Cacti\PluginCloudflare\Account\Account;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ViewerPageTest extends TestCase {
	private const ROOT = __DIR__ . '/../../..';

	/** @var string|null */
	private $cacti;

	public static function setUpBeforeClass(): void {
		require_once self::ROOT . '/tests/Support/CactiGlueStubs.php';
		require_once self::ROOT . '/includes/viewer.php';
	}

	protected function setUp(): void {
		cloudflare_glue_reset();
		$_REQUEST          = array();
		$_SESSION          = array();
		$GLOBALS['config'] = array('url_path' => '/cacti/');

		$GLOBALS['cloudflare_glue']['settings']['num_rows_table'] = '30';
	}

	protected function tearDown(): void {
		$_REQUEST = array();
		$_SESSION = array();
		unset($GLOBALS['config']);

		if ($this->cacti !== null) {
			foreach (array('/include/auth.php', '/trace') as $file) {
				if (file_exists($this->cacti . $file)) {
					unlink($this->cacti . $file);
				}
			}

			foreach (array('/include', '/plugins/cloudflare', '/plugins', '') as $dir) {
				rmdir($this->cacti . $dir);
			}

			$this->cacti = null;
		}
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private static function account(array $overrides = array()): Account {
		return Account::fromRow(array_merge(array(
			'id'                 => 1,
			'cf_account_id'      => 'ed17574386854bf78a67040be0a770b0',
			'name'               => 'Prod',
			'token_source'       => 'file',
			'token_file'         => '/etc/cacti/cf.token',
			'has_ciphertext'     => 0,
			'enabled'            => 1,
			'status'             => 'ok',
			'status_message'     => 'Connection verified',
			'permissions'        => null,
			'last_checked_at'    => null,
			'last_verified_at'   => null,
			'collect_tunnels'    => 1,
			'collect_analytics'  => 0,
			'discovery_interval' => 300,
			'created_at'         => 1700000000,
			'updated_at'         => 1700000000,
		), $overrides));
	}

	/**
	 * @return array{active: int, unavailable: int, deleted: int, health: string|null}
	 */
	private static function summary(int $active, ?string $health): array {
		return array('active' => $active, 'unavailable' => 1, 'deleted' => 2, 'health' => $health);
	}

	/**
	 * @return array{
	 *         last_status: string,
	 *         last_finished_at: int|null,
	 *         last_success_at: int|null,
	 *         last_error_class: string|null,
	 *         consecutive_failures: int
	 *         }
	 */
	private static function job(
		string $status,
		int $failures,
		?string $errorClass = null,
		?int $lastSuccess = null
	): array {
		return array(
			'last_status'          => $status,
			'last_finished_at'     => 1800000600,
			'last_success_at'      => $lastSuccess,
			'last_error_class'     => $errorClass,
			'consecutive_failures' => $failures,
		);
	}

	/**
	 * The cells of each table row, in order, from the recorded stub calls.
	 *
	 * @return array<int, array<int, string>>
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

	public function testOverviewShowsCollectionFailingNotHealthy(): void {
		$accounts = array(
			self::account(array('id' => 1, 'name' => 'Prod')),
			self::account(array('id' => 2, 'name' => 'Edge')),
			self::account(array('id' => 3, 'name' => 'Lab')),
		);
		$jobs = array(
			1 => self::job('failed', 4, 'transient', 1800000300),
			2 => self::job('ok', 0, null, 1800000600),
			3 => self::job('failed', 1, 'auth'),
		);

		self::capture(function () use ($accounts, $jobs): void {
			cloudflare_viewer_overview_table(
				$accounts,
				array(1 => self::summary(3, 'healthy'), 2 => self::summary(2, 'healthy')),
				$jobs,
				false
			);
		});

		$rows = self::rows();
		$this->assertCount(3, $rows);

		$this->assertStringContainsString('>Prod</a>', $rows[0][0]);
		$this->assertSame('Collection failing', $rows[0][1]);
		$this->assertSame(array('3', '1', '2'), array_slice($rows[0], 2, 3));
		$this->assertSame('4', $rows[0][6]);
		$this->assertNotContains('Healthy', $rows[0]);

		$this->assertSame(date('Y-m-d H:i:s', 1800000300), $rows[0][5], 'the stored success, not the failed run');

		$this->assertSame('Healthy', $rows[1][1]);
		$this->assertSame(date('Y-m-d H:i:s', 1800000600), $rows[1][5]);
		$this->assertSame('0', $rows[1][6]);

		$this->assertSame(array('Collection failing', '0', '0', '0', 'Never', '1'), array_slice($rows[2], 1));
	}

	public function testOverviewHealthFollowsTheWorstActiveTunnel(): void {
		$healthy = self::account();
		$ok      = self::job('ok', 0);

		$this->assertSame('Down', cloudflare_viewer_health($healthy, $ok, self::summary(3, 'down')));
		$partial = self::job('partial', 0);
		$this->assertSame('Degraded', cloudflare_viewer_health($healthy, $partial, self::summary(3, 'degraded')));
		$this->assertSame('No active tunnels', cloudflare_viewer_health($healthy, $ok, self::summary(0, null)));
		$this->assertSame('No active tunnels', cloudflare_viewer_health($healthy, $ok, null));
		$this->assertSame('Not yet collected', cloudflare_viewer_health($healthy, null, null));
		$this->assertSame('Not yet collected', cloudflare_viewer_health($healthy, self::job('never', 0), null));

		$disabled = self::account(array('enabled' => 0));
		$this->assertSame('Disabled', cloudflare_viewer_health($disabled, self::job('failed', 2), null));
		$this->assertSame(
			'Not collected',
			cloudflare_viewer_health(self::account(array('collect_tunnels' => 0)), self::job('ok', 0), null)
		);
	}

	public function testTunnelsFilterAndSortAllowList(): void {
		$_REQUEST = array(
			'account_id'     => '4',
			'lifecycle'      => 'active',
			'cf_status'      => 'down',
			'filter'         => 'web',
			'sort_column'    => 'cf_status',
			'sort_direction' => 'DESC',
			'rows'           => '25',
			'page'           => '2',
		);

		$this->assertSame(array(
			'account_id' => 4,
			'filters'    => array('lifecycle' => 'active', 'cf_status' => 'down', 'search' => 'web'),
			'sort'       => 'cf_status',
			'direction'  => 'DESC',
			'rows'       => 25,
			'page'       => 2,
		), cloudflare_viewer_tunnel_request());

		$_REQUEST = array();
		$_SESSION = array();

		$this->assertSame(array(
			'account_id' => 0,
			'filters'    => array(),
			'sort'       => 'name',
			'direction'  => 'ASC',
			'rows'       => 30,
			'page'       => 1,
		), cloudflare_viewer_tunnel_request());

		/* Cacti returns '0' before running any filter, so it reaches the page unvalidated. */
		foreach (array('lifecycle', 'cf_status', 'sort_column', 'sort_direction') as $name) {
			$_REQUEST = array($name => '0');
			$_SESSION = array();

			$query = cloudflare_viewer_tunnel_request();

			$this->assertSame(array(), $query['filters'], $name);
			$this->assertSame('name', $query['sort'], $name);
			$this->assertSame('ASC', $query['direction'], $name);
		}

		$_REQUEST = array('lifecycle' => '0', 'cf_status' => '0');
		$_SESSION = array();
		$this->assertSame(array(), cloudflare_viewer_tunnel_request()['filters'], 'both at once');

		/* Every sortable header is a column the repository allows. */
		$sortable = array_filter(array_keys(cloudflare_viewer_tunnel_columns(true)), function (string $key): bool {
			return strpos($key, 'nosort') === false;
		});
		$this->assertNotEmpty($sortable);
		$this->assertSame(array(), array_diff($sortable, \Cacti\PluginCloudflare\Discovery\TunnelRepository::SORT_COLUMNS));

		$refused = array(
			'sort_column'    => array('name; DROP TABLE plugin_cloudflare_tunnels', 'id', 'NAME'),
			'sort_direction' => array('sideways', 'asc; --'),
			'lifecycle'      => array('gone', 'ACTIVE'),
			'cf_status'      => array('HEALTHY', 'up'),
			'account_id'     => array('-1', 'abc', '1 OR 1=1'),
		);

		foreach ($refused as $name => $values) {
			foreach ($values as $value) {
				$_REQUEST = array($name => $value);
				$_SESSION = array();

				try {
					cloudflare_viewer_tunnel_request();
					$this->fail($name . '=' . $value . ' was accepted');
				} catch (RuntimeException $e) {
					$this->assertSame('die_html_input_error(' . $name . ')', $e->getMessage());
				}
			}
		}
	}

	public function testEscapesTunnelNames(): void {
		$hostile = '<script>alert(1)</script>';

		$output = self::capture(function () use ($hostile): void {
			cloudflare_viewer_tunnel_table(
				array(array(
					'id'               => 9,
					'account_id'       => 1,
					'name'             => $hostile,
					'cf_status'        => 'healthy',
					'tun_type'         => '<b>cfd</b>',
					'lifecycle'        => 'active',
					'connector_count'  => 2,
					'connection_count' => 4,
					'last_seen_at'     => 1800000000,
				)),
				array(1 => '<i>Prod</i>'),
				true
			);
			cloudflare_viewer_connector_table(array(array(
				'id'               => 3,
				'cf_connector_id'  => '00000001-0000-4000-8000-000000000001',
				'version'          => '<img src=x onerror=alert(1)>',
				'arch'             => 'linux_amd64',
				'run_at'           => null,
				'connection_count' => 2,
				'colos'            => 'LHR,"AMS"',
				'origin_ips'       => '192.0.2.1',
				'last_seen_at'     => 1800000000,
			)));
			cloudflare_viewer_overview_table(
				array(self::account(array('name' => $hostile))),
				array(),
				array(),
				false
			);
		});

		$trace = var_export($GLOBALS['cloudflare_glue']['calls'], true) . $output;

		foreach (array('<script>', '<b>cfd', '<i>Prod', '<img', '"AMS"') as $raw) {
			$this->assertStringNotContainsString($raw, $trace);
		}

		$this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $trace);
		$this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $trace);
		$this->assertStringContainsString('cloudflare.php?action=connectors&amp;tunnel_id=9', $trace);
	}

	/* The Reconciler leaves the last counts on a tunnel that stops being active; they are no longer current. */
	public function testNonActiveTunnelsShowNoCounts(): void {
		$tunnels = array();
		foreach (array('active', 'unavailable', 'deleted') as $n => $lifecycle) {
			$tunnels[] = array(
				'id'               => $n + 1,
				'account_id'       => 1,
				'name'             => '<b>t' . $n . '</b>',
				'cf_status'        => 'healthy',
				'tun_type'         => 'cfd_tunnel',
				'lifecycle'        => $lifecycle,
				'connector_count'  => 2,
				'connection_count' => 4,
				'last_seen_at'     => 1800000000,
			);
		}

		self::capture(function () use ($tunnels): void {
			cloudflare_viewer_tunnel_table($tunnels, array(), false);
		});

		$rows = self::rows();
		$this->assertSame(array('active', '2', '4'), array($rows[0][2], $rows[0][4], $rows[0][5]));
		$this->assertSame(array('unavailable', '-', '-'), array($rows[1][2], $rows[1][4], $rows[1][5]));
		$this->assertSame(array('deleted', '-', '-'), array($rows[2][2], $rows[2][4], $rows[2][5]));
		$this->assertStringNotContainsString('<b>', var_export($GLOBALS['cloudflare_glue']['calls'], true));
	}

	public function testDisabledPluginDenied(): void {
		list($output, $calls) = $this->runPage(false, array('action' => 'tunnels'));

		$this->assertSame('', $output);
		$this->assertContains('raise_ajax_permission_denied', $calls);
		foreach (array('top_header', 'html_start_box', 'db_connect_real', 'db_fetch_cell_prepared') as $function) {
			$this->assertNotContains($function, $calls, $function);
		}

		$source = (string) file_get_contents(self::ROOT . '/cloudflare.php');
		$guarded = preg_match('#\nif \(!cloudflare_plugin_enabled\(\)\) \{(.*?)\n\}#s', $source, $guard, PREG_OFFSET_CAPTURE);
		$this->assertSame(1, $guarded);
		$this->assertStringContainsString('permission_denied.php', $guard[1][0]);
		$this->assertStringContainsString('exit;', $guard[1][0]);
		$this->assertGreaterThan((int) strpos($source, "include('./include/auth.php');"), $guard[0][1]);
		$this->assertLessThan((int) strpos($source, 'plugin_cloudflare_upgrade();'), $guard[0][1]);

		/* The same harness with the plugin enabled does draw the page. */
		list($output, $calls) = $this->runPage(true, array());
		$this->assertContains('top_header', $calls);
		$this->assertStringContainsString("class='tab selected'", $output);
	}

	public function testNoMutationPaths(): void {
		$source = (string) file_get_contents(self::ROOT . '/cloudflare.php')
			. (string) file_get_contents(self::ROOT . '/includes/viewer.php');

		$this->assertDoesNotMatchRegularExpression(
			'#\$_POST|REQUEST_METHOD|form_start\(|method=|db_execute|->execute\(|' .
			'->(insert|update|delete|ensure|lease|succeed|partial|fail|mark\w+|apply\w+)\(|' .
			'cloudflare_(account_service|client|discovery_service)\(#i',
			$source
		);

		$this->assertSame(0, preg_match_all('#\becho\b#', $source));

		preg_match_all("#case '([a-z_]+)':#", (string) file_get_contents(self::ROOT . '/cloudflare.php'), $cases);
		$this->assertSame(array(), array_diff($cases[1], array('overview', 'tunnels', 'connectors')));

		/* An action the page does not know draws the overview and writes nothing. */
		list($output, $calls) = $this->runPage(true, array('action' => 'save', 'id' => '1'));
		$this->assertStringContainsString('action=overview', $output);
		$this->assertNotContains('db_execute_prepared', $calls);
	}

	/**
	 * Runs cloudflare.php in a child PHP process, from a stand-in Cacti tree
	 * whose include/auth.php loads the glue stubs. The page ends in exit when
	 * it refuses, which would end this process too.
	 *
	 * @param array<string, string> $request
	 * @return array{0: string, 1: array<int, string>} the output and the stub functions called
	 */
	private function runPage(bool $enabled, array $request): array {
		if ($this->cacti === null) {
			$this->cacti = sys_get_temp_dir() . '/cf-viewer-' . bin2hex(random_bytes(6));
			mkdir($this->cacti . '/include', 0700, true);
			mkdir($this->cacti . '/plugins/cloudflare', 0700, true);
		}

		$trace = $this->cacti . '/trace';

		$stubs = (string) realpath(self::ROOT . '/tests/Support/CactiGlueStubs.php');

		file_put_contents(
			$this->cacti . '/include/auth.php',
			'<?php require_once ' . var_export($stubs, true) . ";\n" .
			"cloudflare_glue_reset();\n" .
			'$GLOBALS[\'cloudflare_glue\'][\'enabled\'] = ' . var_export($enabled, true) . ";\n" .
			'$GLOBALS[\'config\'] = array(\'url_path\' => \'/cacti/\');' . "\n" .
			'$_REQUEST = ' . var_export($request, true) . ";\n" .
			'register_shutdown_function(function () { file_put_contents(' . var_export($trace, true) .
			', json_encode(array_column($GLOBALS[\'cloudflare_glue\'][\'calls\'], 0))); });' . "\n"
		);

		$page    = (string) realpath(self::ROOT . '/cloudflare.php');
		$command = array(
			PHP_BINARY,
			'-d',
			'display_errors=stderr',
			'-r',
			'chdir(' . var_export($this->cacti . '/plugins/cloudflare', true) . '); include ' . var_export($page, true) . ';',
		);

		$process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
		$this->assertIsResource($process);

		$output = (string) stream_get_contents($pipes[1]);
		$errors = (string) stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		$this->assertSame('', $errors);
		$this->assertFileExists($trace);

		$calls = json_decode((string) file_get_contents($trace), true);
		unlink($trace);

		return array($output, is_array($calls) ? $calls : array());
	}
}
