<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Cacti Group                                      |
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
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

namespace Cacti\PluginCloudflare\Tests\Unit\Api;

use Cacti\PluginCloudflare\Api\ApiException;
use Cacti\PluginCloudflare\Api\ErrorClass;
use Cacti\PluginCloudflare\Api\RetryPolicy;
use Cacti\PluginCloudflare\Tests\Support\FrozenClock;
use Cacti\PluginCloudflare\Tests\Support\RecordingSleeper;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase {
	/** @var RecordingSleeper */
	private $sleeper;

	/** @var int */
	private $calls = 0;

	protected function setUp(): void {
		$this->sleeper = new RecordingSleeper();
		$this->calls   = 0;
	}

	private function policy(int $maxRetries = 2): RetryPolicy {
		return new RetryPolicy(new FrozenClock(), $this->sleeper, $maxRetries, 30);
	}

	/**
	 * @return callable
	 */
	private function failing(string $class, ?int $retryAfter = null) {
		return function () use ($class, $retryAfter) {
			$this->calls++;

			throw new ApiException($class, 503, array(), '', $retryAfter);
		};
	}

	public function testSuccessReturnsWithoutSleeping(): void {
		$this->assertSame('ok', $this->policy()->run(function () {
			$this->calls++;

			return 'ok';
		}));
		$this->assertSame(1, $this->calls);
		$this->assertSame(array(), $this->sleeper->slept);
	}

	public function testTransientIsRetriedWithBackoffThenRethrown(): void {
		try {
			$this->policy()->run($this->failing(ErrorClass::TRANSIENT));
			$this->fail('expected ApiException');
		} catch (ApiException $e) {
			$this->assertSame(ErrorClass::TRANSIENT, $e->getErrorClass());
		}

		$this->assertSame(3, $this->calls);
		$this->assertSame(array(1, 2), $this->sleeper->slept);
	}

	public function testRecoversOnSecondAttempt(): void {
		$result = $this->policy()->run(function () {
			$this->calls++;
			if ($this->calls < 2) {
				throw new ApiException(ErrorClass::TRANSIENT, 502);
			}

			return 'ok';
		});

		$this->assertSame('ok', $result);
		$this->assertSame(array(1), $this->sleeper->slept);
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public static function nonRetryable(): array {
		return array(
			'auth'       => array(ErrorClass::AUTH),
			'permission' => array(ErrorClass::PERMISSION),
			'not_found'  => array(ErrorClass::NOT_FOUND),
			'malformed'  => array(ErrorClass::MALFORMED),
			'config'     => array(ErrorClass::CONFIG),
		);
	}

	/**
	 * @dataProvider nonRetryable
	 */
	public function testNonRetryableClassesAreAttemptedOnce(string $class): void {
		try {
			$this->policy()->run($this->failing($class));
			$this->fail('expected ApiException');
		} catch (ApiException $e) {
			$this->assertSame($class, $e->getErrorClass());
		}

		$this->assertSame(1, $this->calls);
		$this->assertSame(array(), $this->sleeper->slept);
	}

	public function testRetryAfterWithinLimitIsHonoured(): void {
		try {
			$this->policy(1)->run($this->failing(ErrorClass::RATE_LIMITED, 7));
		} catch (ApiException $e) {
			$this->assertSame(ErrorClass::RATE_LIMITED, $e->getErrorClass());
		}

		$this->assertSame(array(7), $this->sleeper->slept);
		$this->assertSame(2, $this->calls);
	}

	public function testRetryAfterOverLimitRethrowsImmediately(): void {
		try {
			$this->policy()->run($this->failing(ErrorClass::RATE_LIMITED, 31));
			$this->fail('expected ApiException');
		} catch (ApiException $e) {
			$this->assertSame(31, $e->getRetryAfter());
		}

		$this->assertSame(1, $this->calls);
		$this->assertSame(array(), $this->sleeper->slept);
	}

	public function testRateLimitWithoutRetryAfterUsesBackoff(): void {
		try {
			$this->policy()->run($this->failing(ErrorClass::RATE_LIMITED));
		} catch (ApiException $e) {
			$this->assertNull($e->getRetryAfter());
		}

		$this->assertSame(array(1, 2), $this->sleeper->slept);
	}

	public function testAttemptsNeverExceedMaxRetriesPlusOne(): void {
		try {
			$this->policy(0)->run($this->failing(ErrorClass::TRANSIENT));
		} catch (ApiException $e) {
			$this->assertSame(1, $this->calls);
		}

		$this->assertSame(array(), $this->sleeper->slept);
	}
}
