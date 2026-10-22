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

namespace Cacti\PluginCloudflare\Features;

use Behat\Behat\Context\Context;
use Cacti\PluginCloudflare\Api\ErrorClass;
use Cacti\PluginCloudflare\Http\Response;
use Cacti\PluginCloudflare\Http\TransportException;
use Cacti\PluginCloudflare\Metrics\Catalog;
use Cacti\PluginCloudflare\Metrics\Metric;
use Cacti\PluginCloudflare\Metrics\ObservationReader;
use Cacti\PluginCloudflare\Tests\Support\FakeTransport;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use RuntimeException;
use Throwable;

/**
 * Outage and metrics-integrity steps. Discovery, the observation reader, the
 * script server and the poller hook are the real code on the test database;
 * only the Cloudflare transport is faked and Cacti's own functions are the
 * recording glue stubs.
 */
final class MetricsContext implements Context {
	private const SESSION = 'cacti-db:3306:cacti';
	private const HOST    = 41;
	private const BLOG    = 'f70ff985-a4ef-4643-bbbc-4a0ed4fc8415';

	/* RetryPolicy allows two retries after the first attempt. */
	private const ATTEMPTS = 3;
	private const OUTAGE   = 3;

	/** @var World */
	private $world;

	/** @var int */
	private $accountId = 0;

	/** @var array<int, array<string, mixed>> */
	private $resourcesBefore = array();

	/** @var array<string, mixed> */
	private $blogBefore = array();

	/** @var Throwable|null */
	private $pollerFault = null;

	public function __construct() {
		$this->world = new World();
	}

	/**
	 * @AfterScenario
	 */
	public function cleanUp(): void {
		unset(
			$GLOBALS['database_sessions'],
			$GLOBALS['database_hostname'],
			$GLOBALS['database_port'],
			$GLOBALS['database_default'],
			$GLOBALS['config']
		);

		$this->world->close();
	}

	/**
	 * @Given Cacti is monitoring existing devices
	 */
	public function cactiIsMonitoring(): void {
		$this->discoverWithConnections(FakeTransport::fixture('tunnel-connections'));
	}

	/**
	 * @Given the Cloudflare API becomes unavailable
	 */
	public function theApiBecomesUnavailable(): void {
		for ($i = 0; $i < self::ATTEMPTS * self::OUTAGE; $i++) {
			$this->world->transport()->queue(new TransportException(TransportException::TRANSIENT, 'timeout'));
		}
	}

	/**
	 * @When the polling cycle executes
	 */
	public function thePollingCycleExecutes(): void {
		try {
			cloudflare_poller_bottom();
		} catch (Throwable $e) {
			$this->pollerFault = $e;
		}

		/* The hook only launches poller_cloudflare.php; this is its discovery pass. */
		for ($run = 0; $run < self::OUTAGE; $run++) {
			$this->world->clock()->advance(300);
			$this->makeDue();

			if ($this->world->discovery()->runDue()[$this->accountId]['status'] !== 'failed') {
				throw new RuntimeException('A discovery run did not fail during the outage');
			}
		}
	}

	/**
	 * @Then unrelated Cacti polling must continue
	 */
	public function unrelatedPollingContinues(): void {
		if ($this->pollerFault !== null) {
			throw new RuntimeException('The poller hook threw while Cloudflare was down');
		}
		if (count(cloudflare_glue_calls('exec_background')) !== 1) {
			throw new RuntimeException('The poller hook did not launch the collector exactly once');
		}
	}

	/**
	 * @Then Cloudflare observations must be classified as unavailable
	 */
	public function observationsAreUnavailable(): void {
		foreach ($this->tunnels() as $tunnel) {
			if ($tunnel['lifecycle'] !== 'unavailable') {
				throw new RuntimeException('A tunnel was not marked unavailable');
			}

			$observations = $this->reader()->observe($this->accountId, (string) $tunnel['cf_tunnel_id']);

			foreach (Catalog::all() as $metric) {
				if ($metric->kind() !== Metric::KIND_STATE) {
					continue;
				}

				$observation = $observations[$metric->queryField()];
				if ($observation->value() !== null || $observation->reason() !== ErrorClass::TRANSIENT) {
					throw new RuntimeException('A state metric was not unknown with the transient reason');
				}
			}
		}
	}

	/**
	 * @Then existing graph history must remain intact
	 */
	public function graphHistoryIsIntact(): void {
		$this->assertResourcesUnchanged();
	}

	/**
	 * @Given a tunnel previously reported four connections
	 */
	public function aTunnelReportedFourConnections(): void {
		$this->discoverWithConnections(self::connectionsResponse(4));

		$this->blogBefore = $this->blog();
		if ((int) $this->blogBefore['connection_count'] !== 4) {
			throw new RuntimeException('The tunnel did not store four connections');
		}
	}

	/**
	 * @When the next metrics request fails
	 */
	public function theNextMetricsRequestFails(): void {
		$this->world->clock()->advance(300);
		$this->makeDue();

		$transport = $this->world->transport();
		$transport->queue(FakeTransport::fixture('tunnel-list'));
		for ($i = 0; $i < self::ATTEMPTS; $i++) {
			$transport->queue(new TransportException(TransportException::TRANSIENT, 'timeout'));
		}
		$transport->queue(FakeTransport::fixture('tunnel-connections'));
		$transport->queue(FakeTransport::fixture('tunnel-connections'));

		if ($this->world->discovery()->runDue()[$this->accountId]['status'] !== 'ok') {
			throw new RuntimeException('The run with one failed connections read did not finish');
		}
	}

	/**
	 * @Then the observation must be marked unknown
	 */
	public function theObservationIsUnknown(): void {
		$observation = $this->reader()->observe($this->accountId, self::BLOG)['connections'];

		if ($observation->value() !== null || $observation->reason() !== ErrorClass::TRANSIENT) {
			throw new RuntimeException('The connections observation was not unknown with the transient reason');
		}
		if ($this->scriptServerValue() !== 'U') {
			throw new RuntimeException('The script server did not answer U');
		}
	}

	/**
	 * @Then the system must not record zero connections
	 */
	public function noZeroConnections(): void {
		if ($this->scriptServerValue() === '0') {
			throw new RuntimeException('The script server answered zero connections');
		}
		if ((int) $this->blog()['connection_count'] === 0) {
			throw new RuntimeException('The stored connection count became zero');
		}
	}

	/**
	 * @Then the previous historical samples must remain unchanged
	 */
	public function historyUnchanged(): void {
		$row = $this->blog();

		/* The stored count and its stamp are what the last good poll saw. */
		foreach (array('connection_count', 'connector_count', 'connections_refreshed_at') as $column) {
			if ($row[$column] !== $this->blogBefore[$column]) {
				throw new RuntimeException('A stored sample changed after the failed request');
			}
		}
		$this->assertResourcesUnchanged();
	}

	/**
	 * Account, device and graph resources, and one discovery that stores the
	 * given connections body for the first tunnel.
	 */
	private function discoverWithConnections(Response $first): void {
		$this->boot();

		$this->accountId = $this->world->addDiscoveryAccount();
		$this->world->database()->execute(
			'UPDATE plugin_cloudflare_accounts SET enabled = 1, collect_tunnels = 1 WHERE id = ?',
			array($this->accountId)
		);

		$transport = $this->world->transport();
		$transport->queue(FakeTransport::fixture('tunnel-list'));
		$transport->queue($first);
		$transport->queue(FakeTransport::fixture('tunnel-connections'));
		$transport->queue(FakeTransport::fixture('tunnel-connections'));

		if ($this->world->discovery()->runDue()[$this->accountId]['status'] !== 'ok') {
			throw new RuntimeException('The first discovery did not finish');
		}

		$this->seedResources();
	}

	private function seedResources(): void {
		$db = $this->world->database();
		$db->execute(
			"INSERT INTO plugin_cloudflare_resources (account_id, kind, host_id, created_at) VALUES (?, 'device', ?, 1)",
			array($this->accountId, self::HOST)
		);

		$graph = 100;
		foreach ($this->tunnels() as $tunnel) {
			$graph++;
			$db->execute(
				"INSERT INTO plugin_cloudflare_resources
				(account_id, kind, tunnel_id, template_hash, host_id, local_graph_id, local_data_id, created_at)
				VALUES (?, 'graph', ?, ?, ?, ?, ?, 1)",
				array($this->accountId, $tunnel['id'], str_repeat('a', 32), self::HOST, $graph, $graph + 1000)
			);
		}

		$this->resourcesBefore = $this->resources();
		if (count($this->resourcesBefore) !== 4) {
			throw new RuntimeException('The resource rows were not seeded');
		}
	}

	private function boot(): void {
		$root = dirname(__DIR__, 2);
		require_once $root . '/tests/Support/CactiGlueStubs.php';
		require_once $root . '/setup.php';
		require_once $root . '/includes/polling.php';
		require_once $root . '/includes/script_server.php';

		cloudflare_glue_reset();
		$GLOBALS['cloudflare_glue']['settings']['path_php_binary'] = '/usr/bin/php';
		$GLOBALS['database_hostname'] = 'cacti-db';
		$GLOBALS['database_port']     = '3306';
		$GLOBALS['database_default']  = 'cacti';
		$GLOBALS['database_sessions'] = array(self::SESSION => TestDatabase::pdo());
		$GLOBALS['config']            = array('poller_id' => 1);
	}

	private function reader(): ObservationReader {
		return new ObservationReader($this->world->database(), $this->world->clock());
	}

	private function scriptServerValue(): string {
		return cloudflare_ss((string) self::HOST, 'get', 'connections', self::BLOG, $this->reader());
	}

	private function makeDue(): void {
		$this->world->database()->execute(
			'UPDATE plugin_cloudflare_jobs SET next_run_at = 0 WHERE account_id = ?',
			array($this->accountId)
		);
	}

	private function assertResourcesUnchanged(): void {
		if ($this->resources() !== $this->resourcesBefore) {
			throw new RuntimeException('The resource rows changed');
		}

		foreach ($GLOBALS['cloudflare_glue']['calls'] as $call) {
			if (preg_match('/remove|delete/', (string) $call[0]) === 1) {
				throw new RuntimeException('A Cacti object was removed');
			}
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function resources(): array {
		return $this->world->database()->fetchAll(
			'SELECT * FROM plugin_cloudflare_resources WHERE account_id = ? ORDER BY id',
			array($this->accountId)
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function tunnels(): array {
		return $this->world->database()->fetchAll(
			'SELECT * FROM plugin_cloudflare_tunnels WHERE account_id = ? ORDER BY id',
			array($this->accountId)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function blog(): array {
		$row = $this->world->database()->fetchRow(
			'SELECT * FROM plugin_cloudflare_tunnels WHERE account_id = ? AND cf_tunnel_id = ?',
			array($this->accountId, self::BLOG)
		);

		return is_array($row) ? $row : array();
	}

	/* One connector holding $count connections, built from the shared fixture. */
	private static function connectionsResponse(int $count): Response {
		$body = json_decode(FakeTransport::fixture('tunnel-connections')->body(), true);
		$conn = $body['result'][0]['conns'][0];

		$conns = array();
		for ($i = 1; $i <= $count; $i++) {
			$conn['id']   = sprintf('1bedc50d-42b3-473c-b108-%012d', $i);
			$conn['uuid'] = $conn['id'];
			$conns[]      = $conn;
		}
		$body['result'][0]['conns'] = $conns;

		return new Response(200, array(), (string) json_encode($body));
	}
}
