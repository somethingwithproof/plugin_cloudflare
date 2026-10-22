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

namespace Cacti\PluginCloudflare\Discovery;

use Cacti\PluginCloudflare\Account\Account;
use Cacti\PluginCloudflare\Account\AccountRepository;
use Cacti\PluginCloudflare\Account\CredentialResolver;
use Cacti\PluginCloudflare\Api\ApiException;
use Cacti\PluginCloudflare\Api\CloudflareClient;
use Cacti\PluginCloudflare\Api\ErrorClass;
use Cacti\PluginCloudflare\Jobs\JobRepository;
use Cacti\PluginCloudflare\Jobs\Lease;
use Cacti\PluginCloudflare\Jobs\LeaseLost;
use Cacti\PluginCloudflare\Secret\SecretException;
use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Support\Clock;
use InvalidArgumentException;
use Throwable;

/**
 * Runs due discovery jobs in system context. Every write made under a lease
 * shares a transaction with a touch() of that lease, so a collector that
 * lost its lease rolls back instead of overwriting the new holder's work.
 *
 * Log lines carry fixed text, the numeric account id, an error class and
 * counts. Exception messages are never logged: they can quote Cloudflare's
 * own text, which may echo tunnel names.
 */
final class DiscoveryService {
	private const DUE_LIMIT = 500;

	private const MARK_UNAVAILABLE_AFTER = 3;

	private const NO_CHANGES = array('added' => 0, 'updated' => 0, 'deleted' => 0);

	/* The schema default; after a fault the account's own interval may be unreadable. */
	private const FAULT_INTERVAL = 300;

	/* Failures that say Cloudflare could not be read. A config or malformed failure does not. */
	private const OUTAGE_CLASSES = array(
		ErrorClass::TRANSIENT,
		ErrorClass::RATE_LIMITED,
		ErrorClass::AUTH,
		ErrorClass::PERMISSION,
	);

	/* Retrying the other tunnels would only spend more of the same quota or credential. */
	private const STOP_CLASSES = array(ErrorClass::AUTH, ErrorClass::RATE_LIMITED);

	/** @var AccountRepository */
	private $accounts;

	/** @var CredentialResolver */
	private $creds;

	/** @var CloudflareClient */
	private $client;

	/** @var Reconciler */
	private $rec;

	/** @var JobRepository */
	private $jobs;

	/** @var Database */
	private $db;

	/** @var Clock */
	private $clock;

	/** @var callable(): bool */
	private $stillEnabled;

	/** @var callable(string): void|null */
	private $log;

	/** @var int */
	private $leaseTtl;

	/** @var string */
	private $owner;

	/** @var int */
	private $connectionsPerRun;

	/** @var int set by runDue() for the run in progress */
	private $deadline = 0;

	/** @var int connections calls left in the run in progress */
	private $callsLeft = 0;

	/**
	 * @param callable(): bool            $stillEnabled false once the plugin has been disabled
	 * @param callable(string): void|null $log
	 * @param int                         $leaseTtl          seconds. touch() neither extends nor checks expiry,
	 *                                                       so this is no stop; the collector's GET_LOCK is
	 *                                                       the fence against a second run.
	 * @param int                         $connectionsPerRun connections calls allowed in one runDue()
	 */
	public function __construct(
		AccountRepository $accounts,
		CredentialResolver $creds,
		CloudflareClient $client,
		Reconciler $rec,
		JobRepository $jobs,
		Database $db,
		Clock $clock,
		callable $stillEnabled,
		?callable $log = null,
		int $leaseTtl = 120,
		int $connectionsPerRun = 200
	) {
		if ($leaseTtl < 1) {
			throw new InvalidArgumentException('invalid lease ttl');
		}
		if ($connectionsPerRun < 1) {
			throw new InvalidArgumentException('invalid connections cap');
		}

		$this->accounts     = $accounts;
		$this->creds        = $creds;
		$this->client       = $client;
		$this->rec          = $rec;
		$this->jobs         = $jobs;
		$this->db           = $db;
		$this->clock        = $clock;
		$this->stillEnabled = $stillEnabled;
		$this->log          = $log;
		$this->leaseTtl     = $leaseTtl;

		$this->connectionsPerRun = $connectionsPerRun;
		$this->owner        = JobRepository::defaultOwner();
	}

	/**
	 * Accounts not started before the budget ran out, or before the plugin
	 * was disabled, are absent from the result and stay due. The budget and
	 * the connections cap also stop an account mid-way, recorded as partial.
	 *
	 * @return array<int, array{status: string, error_class: string|null, added: int, updated: int, deleted: int}>
	 */
	public function runDue(int $budgetSeconds = 50): array {
		$this->deadline  = $this->clock->now() + $budgetSeconds;
		$this->callsLeft = $this->connectionsPerRun;
		$outcomes        = array();

		foreach ($this->jobs->due(JobRepository::TYPE_DISCOVERY, self::DUE_LIMIT) as $job) {
			if ($this->clock->now() >= $this->deadline) {
				$this->say('Cloudflare discovery: run budget reached accounts=' . count($outcomes));

				break;
			}
			if (!($this->stillEnabled)()) {
				$this->say('Cloudflare discovery: plugin disabled, stopping accounts=' . count($outcomes));

				break;
			}

			$accountId = (int) $job['account_id'];
			$lease     = $this->jobs->lease((int) $job['id'], $this->owner, $this->leaseTtl);
			if ($lease === null) {
				$outcomes[$accountId] = self::outcome('skipped');

				continue;
			}

			$failures = (int) $job['consecutive_failures'];

			try {
				$outcomes[$accountId] = $this->runAccount($lease, $accountId, $failures);
			} catch (Throwable $e) {
				/* A DatabaseException, an account deleted mid-run, or anything unforeseen. */
				$outcome = $this->fault($lease, $accountId, $failures, $e);
				if ($outcome === null) {
					break;
				}

				$outcomes[$accountId] = $outcome;
			}
		}

		return $outcomes;
	}

	/**
	 * A local fault says nothing about Cloudflare, so it is recorded as
	 * transient without the unavailable check. Only the class name is
	 * logged: an exception's string form carries a trace with the token.
	 *
	 * @return array{status: string, error_class: string|null, added: int, updated: int, deleted: int}|null
	 *         null when even the release failed and the connection is likely gone
	 */
	private function fault(Lease $lease, int $accountId, int $failures, Throwable $e): ?array {
		$this->say('Cloudflare discovery: local fault account=' . $accountId . ' error=' . get_class($e));

		$next = JobRepository::nextRun(
			$this->clock->now(),
			self::FAULT_INTERVAL,
			ErrorClass::TRANSIENT,
			null,
			$failures + 1
		);

		try {
			$this->jobs->fail($lease, ErrorClass::TRANSIENT, $next);

			return self::outcome('failed', ErrorClass::TRANSIENT);
		} catch (LeaseLost $lost) {
			return self::outcome('lease_lost');
		} catch (Throwable $failError) {
			$this->say(
				'Cloudflare discovery: fault not recorded account=' . $accountId . ' error=' . get_class($failError)
			);
		}

		try {
			$this->jobs->release($lease);
		} catch (LeaseLost $lost) {
			return self::outcome('lease_lost');
		} catch (Throwable $releaseError) {
			$this->say('Cloudflare discovery: stopping, lease not released account=' . $accountId);

			return null;
		}

		return self::outcome('failed', ErrorClass::TRANSIENT);
	}

	/**
	 * @return array{status: string, error_class: string|null, added: int, updated: int, deleted: int}
	 */
	private function runAccount(Lease $lease, int $accountId, int $failures): array {
		$counts = self::NO_CHANGES;

		try {
			$account = $this->accounts->find($accountId);
			if ($account === null) {
				$this->jobs->release($lease);

				return self::outcome('skipped');
			}

			try {
				$token = $this->creds->resolve($accountId);
			} catch (SecretException $e) {
				$this->say('Cloudflare discovery: token unavailable account=' . $accountId . ' reason=' . $e->getReason());

				return $this->failed($lease, $account, $failures, ErrorClass::CONFIG, null, $counts);
			}

			try {
				return $this->discover($lease, $account, $token, $failures, $counts);
			} finally {
				if (function_exists('sodium_memzero')) {
					sodium_memzero($token);
				}
			}
		} catch (LeaseLost $e) {
			$this->say('Cloudflare discovery: lease lost account=' . $accountId);

			return self::outcome('lease_lost', null, $counts);
		}
	}

	/**
	 * @param array{added: int, updated: int, deleted: int} $counts filled in once the listing is stored
	 *
	 * @return array{status: string, error_class: string|null, added: int, updated: int, deleted: int}
	 */
	private function discover(
		Lease $lease,
		Account $account,
		#[\SensitiveParameter]
		string $token,
		int $failures,
		array &$counts
	): array {
		$accountId = $account->id();

		try {
			$tunnels = $this->client->listTunnels($account->cfAccountId(), $token);
		} catch (ApiException $e) {
			$this->say('Cloudflare discovery: listing failed account=' . $accountId . ' class=' . $e->getErrorClass());

			return $this->failed($lease, $account, $failures, $e->getErrorClass(), $e->getRetryAfter(), $counts);
		}

		$counts = $this->db->transactional(function () use ($lease, $accountId, $tunnels): array {
			$this->jobs->listed($lease);

			return $this->rec->applyListing($accountId, $tunnels);
		});

		$skipped = 0;
		$partial = false;

		/* One row past the remaining cap tells a finished account from a cut-off one. */
		$repo  = new TunnelRepository($this->db);
		$queue = $repo->activeForRefresh($accountId, $this->callsLeft + 1);
		foreach ($queue as $row) {
			if ($this->callsLeft < 1 || $this->clock->now() >= $this->deadline) {
				$partial = true;

				break;
			}

			$this->callsLeft--;
			$tunnelRowId = (int) $row['id'];
			$cfTunnelId  = (string) $row['cf_tunnel_id'];

			try {
				$connectors = $this->client->listConnections($account->cfAccountId(), $token, $cfTunnelId);
			} catch (ApiException $e) {
				$this->db->transactional(function () use ($lease, $repo, $tunnelRowId): void {
					$this->jobs->touch($lease);
					$repo->markConnectionsAttempted($tunnelRowId, $this->clock->now());
				});

				$class = $e->getErrorClass();
				if ($class === ErrorClass::NOT_FOUND) {
					continue;
				}

				$this->say('Cloudflare discovery: connections failed account=' . $accountId . ' class=' . $class);
				if (in_array($class, self::STOP_CLASSES, true)) {
					return $this->failed($lease, $account, $failures, $class, $e->getRetryAfter(), $counts, false);
				}

				$skipped++;

				continue;
			}

			$this->db->transactional(function () use ($lease, $tunnelRowId, $connectors): void {
				$this->jobs->touch($lease);
				$this->rec->applyConnections($tunnelRowId, $connectors);
			});
		}

		/* A cut-off run resumes on the normal schedule; the stalest tunnels go first next time. */
		$next   = JobRepository::nextRun($this->clock->now(), $account->discoveryInterval(), null, null, 0);
		$status = $partial ? 'partial' : 'ok';
		if ($partial) {
			$this->jobs->partial($lease, $next);
		} else {
			$this->jobs->succeed($lease, $next);
		}

		$this->say(
			'Cloudflare discovery: ' . $status . ' account=' . $accountId . ' added=' . $counts['added'] . ' updated='
			. $counts['updated'] . ' deleted=' . $counts['deleted'] . ' connection_failures=' . $skipped
		);

		return self::outcome($status, null, $counts);
	}

	/**
	 * fail() checks the lease, so marking unavailable in the same
	 * transaction cannot outlive a lost lease. A failure after the listing
	 * committed passes $outage false: Cloudflare answered that run, so the
	 * outage streak does not grow.
	 *
	 * @param array{added: int, updated: int, deleted: int} $counts
	 *
	 * @return array{status: string, error_class: string|null, added: int, updated: int, deleted: int}
	 */
	private function failed(
		Lease $lease,
		Account $account,
		int $failures,
		string $class,
		?int $retryAfter,
		array $counts,
		bool $outage = true
	): array {
		$next = JobRepository::nextRun(
			$this->clock->now(),
			$account->discoveryInterval(),
			$class,
			$retryAfter,
			$failures + 1
		);

		$outage = $outage && in_array($class, self::OUTAGE_CLASSES, true);
		$marked = $this->db->transactional(function () use ($lease, $account, $class, $next, $outage): ?int {
			$this->jobs->fail($lease, $class, $next, $outage);
			if (!$outage || $this->jobs->outageStreak($lease) < self::MARK_UNAVAILABLE_AFTER) {
				return null;
			}

			return $this->rec->markUnavailable($account->id());
		});

		if ($marked !== null) {
			$this->say('Cloudflare discovery: marked unavailable account=' . $account->id() . ' tunnels=' . $marked);
		}

		return self::outcome('failed', $class, $counts);
	}

	/**
	 * @param array{added: int, updated: int, deleted: int} $counts
	 *
	 * @return array{status: string, error_class: string|null, added: int, updated: int, deleted: int}
	 */
	private static function outcome(string $status, ?string $class = null, array $counts = self::NO_CHANGES): array {
		return array(
			'status'      => $status,
			'error_class' => $class,
			'added'       => $counts['added'],
			'updated'     => $counts['updated'],
			'deleted'     => $counts['deleted'],
		);
	}

	private function say(string $line): void {
		if ($this->log !== null) {
			($this->log)($line);
		}
	}
}
