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
use Cacti\PluginCloudflare\Tests\Support\FakeTransport;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use RuntimeException;

/**
 * Discovery and rate-limit steps. They drive the real DiscoveryService
 * against the database; only the Cloudflare transport is faked.
 */
final class DiscoveryContext implements Context {
	/* The UUIDs in tests/fixtures/cloudflare/tunnel-list.json. */
	private const TUNNELS = array(
		'f70ff985-a4ef-4643-bbbc-4a0ed4fc8415',
		'0b6a8e4c-6c3b-4f64-9d6e-2f6c1f8a1d11',
		'7d1c3f52-91a4-4a39-8a55-3e1b0c6f2b77',
	);

	/* RetryPolicy allows two retries after the first attempt. */
	private const ATTEMPT_CAP = 3;

	private const SESSION = 'cacti-db:3306:cacti';

	/** @var World */
	private $world;

	/** @var int */
	private $accountId = 0;

	/** @var array<int, array<string, mixed>> */
	private $tunnelsBefore = array();

	/** @var int */
	private $requestsBefore = 0;

	/** @var array<int, int> */
	private $sleepsAfterRetry = array();

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
			$GLOBALS['config'],
			$GLOBALS['item_rows']
		);
		$_REQUEST = array();
		$_SESSION = array();

		$this->world->close();
	}

	/**
	 * @Given a configured Cloudflare account
	 */
	public function aConfiguredAccount(): void {
		$this->accountId = $this->world->addDiscoveryAccount();
	}

	/**
	 * @Given the Cloudflare API returns three tunnels
	 */
	public function theApiReturnsThreeTunnels(): void {
		$this->queueDiscovery();
	}

	/**
	 * @When the discovery process executes
	 */
	public function theDiscoveryExecutes(): void {
		$this->runDiscovery('ok');
	}

	/**
	 * @Then Cacti should store exactly three discovered tunnels
	 */
	public function exactlyThreeTunnels(): void {
		if (count($this->tunnels()) !== 3) {
			throw new RuntimeException('Expected exactly three stored tunnels');
		}
	}

	/**
	 * @Then each tunnel should retain its Cloudflare identifier
	 */
	public function eachTunnelKeepsItsIdentifier(): void {
		$stored = array_column($this->tunnels(), 'cf_tunnel_id');
		sort($stored);
		$expected = self::TUNNELS;
		sort($expected);

		if ($stored !== $expected) {
			throw new RuntimeException('Stored Cloudflare identifiers differ from the listing');
		}
	}

	/**
	 * @Given three tunnels have already been discovered
	 */
	public function threeTunnelsAlreadyDiscovered(): void {
		$this->aConfiguredAccount();
		$this->queueDiscovery();
		$this->runDiscovery('ok');
		$this->exactlyThreeTunnels();

		$this->tunnelsBefore = $this->tunnels();
	}

	/**
	 * @When the discovery process executes again with unchanged results
	 */
	public function discoveryExecutesAgain(): void {
		$this->makeDue();
		$this->queueDiscovery();
		$this->runDiscovery('ok');
	}

	/**
	 * @Then the tunnel count must remain three
	 */
	public function tunnelCountRemainsThree(): void {
		$this->exactlyThreeTunnels();
	}

	/**
	 * @Then existing resource associations must be preserved
	 */
	public function associationsPreserved(): void {
		/* The primary key is what connectors and graphs will reference. */
		$now    = array_column($this->tunnels(), 'id', 'cf_tunnel_id');
		$before = array_column($this->tunnelsBefore, 'id', 'cf_tunnel_id');
		if ($now !== $before) {
			throw new RuntimeException('A tunnel row id changed between discovery runs');
		}
	}

	/**
	 * @Given the API request limit has been reached
	 */
	public function theRequestLimitIsReached(): void {
		$this->threeTunnelsAlreadyDiscovered();
		$this->makeDue();
		$this->requestsBefore = count($this->world->transport()->requests());
	}

	/**
	 * @When the Cloudflare collector receives HTTP 429
	 */
	public function theCollectorReceives429(): void {
		/* A short Retry-After on every attempt: the policy sleeps it twice, then gives up. */
		for ($i = 0; $i < self::ATTEMPT_CAP; $i++) {
			/* The body is ignored; only the status and Retry-After matter. */
			$this->world->transport()->queue(
				FakeTransport::fixture('error-envelope', 429, array('Retry-After' => '5'))
			);
		}

		$this->runDiscovery('failed');
		$this->sleepsAfterRetry = $this->world->sleeper()->slept;
	}

	/**
	 * @Then it must apply bounded retry handling
	 */
	public function boundedRetry(): void {
		$sent = count($this->world->transport()->requests()) - $this->requestsBefore;

		if ($sent !== self::ATTEMPT_CAP) {
			throw new RuntimeException('Requests sent differ from the attempt cap');
		}
	}

	/**
	 * @Then honor supported retry information
	 */
	public function retryAfterHonoured(): void {
		if ($this->sleepsAfterRetry !== array(5, 5)) {
			throw new RuntimeException('Retry-After was not the recorded sleep');
		}
		if (max($this->world->sleeper()->slept) > 30) {
			throw new RuntimeException('A sleep exceeded the 30 second bound');
		}
	}

	/**
	 * @Then defer collection when the retry budget is exhausted
	 */
	public function collectionDeferred(): void {
		$job = $this->job();
		if ($job['last_error_class'] !== ErrorClass::RATE_LIMITED) {
			throw new RuntimeException('The job did not record the rate limit');
		}
		if ((int) $job['next_run_at'] <= World::NOW) {
			throw new RuntimeException('The next run was not pushed out');
		}
		$this->assertTunnelsUnchanged();

		/* Beyond the sleep bound the collector must not wait at all. */
		$this->makeDue();
		$requests = count($this->world->transport()->requests());
		$this->world->transport()->queue(FakeTransport::fixture('error-envelope', 429, array('Retry-After' => '600')));
		$this->runDiscovery('failed');

		if (count($this->world->transport()->requests()) - $requests !== 1) {
			throw new RuntimeException('A long Retry-After was retried');
		}
		if ($this->world->sleeper()->slept !== $this->sleepsAfterRetry) {
			throw new RuntimeException('The collector slept for a long Retry-After');
		}
		if ((int) $this->job()['next_run_at'] !== World::NOW + 600) {
			throw new RuntimeException('The next run did not honour Retry-After');
		}
		/* One 429 adds to the outage streak by design; tunnels go unavailable only at three. */
		if ((int) $this->job()['outage_failures'] >= 3) {
			throw new RuntimeException('A single rate limited run reached the unavailable threshold');
		}
		$this->assertTunnelsUnchanged();
	}

	/**
	 * @Then it must not block the Cacti user interface
	 */
	public function interfaceDoesNotCallCloudflare(): void {
		$this->bootViewer();
		$before = count($this->world->transport()->requests());

		ob_start();

		try {
			cloudflare_viewer_overview();
			cloudflare_viewer_tunnels();
			$_REQUEST['tunnel_id'] = (string) $this->tunnels()[0]['id'];
			cloudflare_viewer_connectors();
		} finally {
			$page = (string) ob_get_clean();
		}

		/* The glue stubs record cells instead of printing them. */
		foreach ($GLOBALS['cloudflare_glue']['calls'] as $call) {
			if ($call[0] === 'form_selectable_cell') {
				$page .= ' ' . (string) $call[1][0];
			}
		}

		/* A page that failed to read says so instead of listing the account and its tunnels. */
		if (strpos($page, 'Production') === false || strpos($page, 'blog') === false) {
			throw new RuntimeException('The viewer pages did not list the account and its tunnels');
		}
		if (strpos($page, cloudflare_viewer_unreadable_text()) !== false) {
			throw new RuntimeException('A viewer page reported unreadable data');
		}
		if (count($this->world->transport()->requests()) !== $before) {
			throw new RuntimeException('A viewer page sent a Cloudflare request');
		}
	}

	private function bootViewer(): void {
		$root = dirname(__DIR__, 2);
		require_once $root . '/tests/Support/CactiGlueStubs.php';
		require_once $root . '/setup.php';
		require_once $root . '/includes/viewer.php';

		cloudflare_glue_reset();
		$GLOBALS['database_hostname'] = 'cacti-db';
		$GLOBALS['database_port']     = '3306';
		$GLOBALS['database_default']  = 'cacti';
		$GLOBALS['database_sessions'] = array(self::SESSION => TestDatabase::pdo());
		$GLOBALS['config']            = array('url_path' => '/cacti/');
		$GLOBALS['item_rows']         = array(10 => '10', 25 => '25');
		$_REQUEST                     = array();
		$_SESSION                     = array();
	}

	private function queueDiscovery(): void {
		$this->world->transport()->queue(FakeTransport::fixture('tunnel-list'));
		foreach (self::TUNNELS as $unused) {
			$this->world->transport()->queue(FakeTransport::fixture('tunnel-connections'));
		}
	}

	private function runDiscovery(string $expected): void {
		$outcomes = $this->world->discovery()->runDue();

		if (!isset($outcomes[$this->accountId]) || $outcomes[$this->accountId]['status'] !== $expected) {
			throw new RuntimeException('Discovery did not finish as ' . $expected);
		}
	}

	private function makeDue(): void {
		$this->world->database()->execute(
			'UPDATE plugin_cloudflare_jobs SET next_run_at = 0 WHERE account_id = ?',
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
	private function job(): array {
		$row = $this->world->database()->fetchRow(
			'SELECT * FROM plugin_cloudflare_jobs WHERE account_id = ?',
			array($this->accountId)
		);

		return is_array($row) ? $row : array();
	}

	private function assertTunnelsUnchanged(): void {
		if ($this->tunnels() !== $this->tunnelsBefore) {
			throw new RuntimeException('A rate limited run changed the stored tunnels');
		}
	}
}
