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

namespace Cacti\PluginCloudflare\Api;

use Cacti\PluginCloudflare\Support\Clock;
use Cacti\PluginCloudflare\Support\Sleeper;

final class RetryPolicy {
	/** @var Sleeper */
	private $sleeper;

	/** @var int */
	private $maxRetries;

	/** @var int */
	private $maxRetryAfter;

	/**
	 * The attempt budget is fixed, so $clock is not read yet; it is part of
	 * the constructor so a deadline can be added without changing callers.
	 *
	 * @phpstan-ignore-next-line
	 */
	public function __construct(Clock $clock, Sleeper $sleeper, int $maxRetries = 2, int $maxRetryAfter = 30) {
		$this->sleeper       = $sleeper;
		$this->maxRetries    = max(0, $maxRetries);
		$this->maxRetryAfter = max(0, $maxRetryAfter);
	}

	/**
	 * Calls $attempt at most maxRetries + 1 times. Only transient and
	 * rate_limited failures are retried; backoff is 1s, then 2s.
	 *
	 * @return mixed
	 *
	 * @throws ApiException
	 */
	public function run(callable $attempt) {
		$retries = 0;

		while (true) {
			try {
				return $attempt();
			} catch (ApiException $e) {
				$class = $e->getErrorClass();

				if ($class !== ErrorClass::TRANSIENT && $class !== ErrorClass::RATE_LIMITED) {
					throw $e;
				}
				if ($retries >= $this->maxRetries) {
					throw $e;
				}

				$delay = 1 << $retries;
				if ($class === ErrorClass::RATE_LIMITED && $e->getRetryAfter() !== null) {
					if ($e->getRetryAfter() > $this->maxRetryAfter) {
						throw $e;
					}
					$delay = $e->getRetryAfter();
				}

				$this->sleeper->sleep($delay);
				$retries++;
			}
		}
	}
}
