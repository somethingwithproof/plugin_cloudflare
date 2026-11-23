<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_cloudflare, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_cloudflare
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

namespace Somethingwithproof\Cloudflare\Tests\Unit\Api;

use Somethingwithproof\Cloudflare\Api\CloudflareClient;
use Somethingwithproof\Cloudflare\Api\ZoneAnalytics;
use Somethingwithproof\Cloudflare\Api\ApiException;
use Somethingwithproof\Cloudflare\Api\RetryPolicy;
use Somethingwithproof\Cloudflare\Http\Response;
use Somethingwithproof\Cloudflare\Tests\Support\FakeTransport;
use Somethingwithproof\Cloudflare\Tests\Support\FrozenClock;
use Somethingwithproof\Cloudflare\Tests\Support\RecordingSleeper;
use PHPUnit\Framework\TestCase;

final class ZoneAnalyticsTest extends TestCase {
	/** @var FakeTransport */
	private $transport;
	/** @var ZoneAnalytics */
	private $api;

	protected function setUp(): void {
		$this->transport = new FakeTransport();
		$this->api = new ZoneAnalytics(new CloudflareClient($this->transport, new RetryPolicy(new FrozenClock(), new RecordingSleeper(), 0)));
	}

	/** @param array<string, mixed> $settings */
	private function settings(array $settings = array()): void {
		$this->transport->queue(new Response(200, array(), json_encode(array('data' => array('viewer' => array('zones' => array(array('settings' => array('httpRequestsAdaptiveGroups' => array_merge(array('enabled' => true, 'maxDuration' => 86400, 'notOlderThan' => 86400, 'maxPageSize' => 100, 'maxNumberOfFields' => 40), $settings)))))), 'errors' => null), JSON_THROW_ON_ERROR)));
	}

	public function testCapabilityDenialNeverQueriesTheDataset(): void {
		$this->settings(array('enabled' => false));
		$this->assertNull($this->api->sample(str_repeat('a', 32), 'fixture-token', 1000, 1300, function (): void {}));
		$this->assertCount(1, $this->transport->requests());
	}

	public function testSampleUsesFixedWindowsAndPreservesSamplingMetadata(): void {
		$this->settings();
		$this->transport->queue(new Response(200, array(), '{"data":{"viewer":{"zones":[{"total":[{"count":600,"sum":{"edgeResponseBytes":9000},"avg":{"sampleInterval":10}}],"errors":[{"count":30}]}]}},"errors":null}'));
		$result = $this->api->sample(str_repeat('a', 32), 'fixture-token', 1000, 1300, function (): void {});
		$this->assertSame(array('requests' => 600.0, 'bytes' => 9000.0, 'errors' => 30.0, 'sample_interval' => 10.0), $result);
		$request = $this->transport->requests()[1];
		$this->assertSame('POST', $request->method());
		$body = json_decode((string) $request->body(), true);
		$this->assertSame('1970-01-01T00:16:40Z', $body['variables']['start']);
		$this->assertStringContainsString('edgeResponseStatus_geq: 500', $body['query']);
		$this->assertStringNotContainsString('dimensions', $body['query']);
	}

	/** @return array<string, array<int>> */
	public function httpFailures(): array {
		return array('auth' => array(401), 'rate limit' => array(429), 'provider outage' => array(503));
	}

	/** @dataProvider httpFailures */
	public function testHttpFailureNeverBecomesAZeroSample(int $status): void {
		$this->transport->queue(new Response($status, array('retry-after' => '60'), '{}'));
		$this->expectException(ApiException::class);
		$this->api->sample(str_repeat('a', 32), 'fixture-token', 1000, 1300, function (): void {});
	}

	public function testPartialGraphqlResponseIsRejectedEvenWithSuccessfulHttp(): void {
		$this->transport->queue(new Response(200, array(), '{"data":{"viewer":{"zones":[]}},"errors":[{"message":"secret-token"}]}'));
		$this->expectException(ApiException::class);
		$this->expectExceptionMessage('partial data');
		$this->api->sample(str_repeat('a', 32), 'secret-token', 1000, 1300, function (): void {});
	}

	public function testEmptySuccessfulAggregateIsZeroTraffic(): void {
		$this->settings();
		$this->transport->queue(new Response(200, array(), '{"data":{"viewer":{"zones":[{"total":[],"errors":[]}]}},"errors":null}'));
		$result = $this->api->sample(str_repeat('a', 32), 'fixture-token', 1000, 1300, function (): void {});
		$this->assertNotNull($result);
		$this->assertSame(0.0, $result['requests']);
		$this->assertSame(0.0, $result['bytes']);
	}

	public function testMissingAggregateFieldsNeverBecomeZero(): void {
		$this->settings();
		$this->transport->queue(new Response(200, array(), '{"data":{"viewer":{"zones":[{"total":[{"count":0}],"errors":[]}]}},"errors":null}'));
		$this->expectException(ApiException::class);
		$this->api->sample(str_repeat('a', 32), 'fixture-token', 1000, 1300, function (): void {});
	}
}
