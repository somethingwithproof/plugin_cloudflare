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

namespace Cacti\PluginCloudflare\Tests\Unit\Http;

use Cacti\PluginCloudflare\Http\Request;
use Cacti\PluginCloudflare\Http\Response;
use Cacti\PluginCloudflare\Http\TransportException;
use Cacti\PluginCloudflare\Tests\Support\FakeTransport;
use Cacti\PluginCloudflare\Tests\Support\SecretAssertions;
use LogicException;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase {
	use SecretAssertions;

	private function token(): string {
		return 'cfut_' . str_repeat('Kp3', 14);
	}

	public function testGetters(): void {
		$r = new Request('POST', 'https://api.cloudflare.com/client/v4/graphql', array('Content-Type' => 'application/json'), '{}');

		$this->assertSame('POST', $r->method());
		$this->assertSame('https://api.cloudflare.com/client/v4/graphql', $r->url());
		$this->assertSame(array('Content-Type' => 'application/json'), $r->headers());
		$this->assertSame('{}', $r->body());
	}

	public function testDebugOutputHidesHeaderValues(): void {
		$token = $this->token();
		$r = new Request('GET', 'https://api.cloudflare.com/client/v4/user/tokens/verify', array('Authorization' => 'Bearer ' . $token));

		$dump = print_r($r, true);
		ob_start();
		var_dump($r);
		$dump .= (string) ob_get_clean();

		$this->assertNotLeaked($token, $dump, 'debug output');
		$this->assertStringContainsString('Authorization', $dump);
		$this->assertStringContainsString('[REDACTED]', $dump);
		$this->assertStringContainsString('tokens/verify', $dump);
	}

	public function testCannotBeSerialized(): void {
		$r = new Request('GET', 'https://api.cloudflare.com/', array('Authorization' => 'Bearer ' . $this->token()));

		$this->expectException(LogicException::class);
		serialize($r);
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, string>}>
	 */
	public function invalidRequests(): array {
		return array(
			'lowercase method' => array('get', array()),
			'method with space' => array('GET /', array()),
			'crlf in value' => array('GET', array('X-A' => "a\r\nHost: evil.test")),
			'lf in value' => array('GET', array('X-A' => "a\nb")),
			'nul in value' => array('GET', array('X-A' => "a\0b")),
			'colon in name' => array('GET', array('X-A:' => 'b')),
			'empty name' => array('GET', array('' => 'b')),
		);
	}

	/**
	 * @dataProvider invalidRequests
	 * @param array<string, string> $headers
	 */
	public function testRejectsInvalidMethodOrHeaders(string $method, array $headers): void {
		try {
			new Request($method, 'https://api.cloudflare.com/', $headers);
			$this->fail('request was accepted');
		} catch (TransportException $e) {
			$this->assertSame('config', $e->getErrorClass());
			$this->assertStringNotContainsString('evil', $e->getMessage() . $e->getDetail());
		}
	}

	public function testResponseHeaderLookupIgnoresCase(): void {
		$r = new Response(429, array('Retry-After' => '7', 'cf-ray' => 'abc'), 'x');

		$this->assertSame(429, $r->status());
		$this->assertSame('7', $r->header('retry-after'));
		$this->assertSame('abc', $r->header('CF-Ray'));
		$this->assertNull($r->header('x-missing'));
		$this->assertSame('x', $r->body());
	}

	public function testFakeTransportReplaysQueueAndRecordsRequests(): void {
		$fake = new FakeTransport();
		$fake->queue(FakeTransport::fixture('token-verify'));
		$fake->queue(new TransportException('transient', 'CURLE_OPERATION_TIMEDOUT', 'timed out'));

		$first = new Request('GET', 'https://api.cloudflare.com/client/v4/user/tokens/verify');
		$this->assertSame(200, $fake->send($first, 5, 10)->status());

		try {
			$fake->send(new Request('GET', 'https://api.cloudflare.com/x'), 5, 10);
			$this->fail('queued exception was not thrown');
		} catch (TransportException $e) {
			$this->assertSame('transient', $e->getErrorClass());
		}

		$this->assertCount(2, $fake->requests());
		$this->assertSame($first, $fake->requests()[0]);
		$this->assertNotSame('', FakeTransport::fixture('token-verify')->body());
	}

	public function testFakeTransportFailsWhenQueueIsEmpty(): void {
		$this->expectException(LogicException::class);
		(new FakeTransport())->send(new Request('GET', 'https://api.cloudflare.com/'), 5, 10);
	}
}
