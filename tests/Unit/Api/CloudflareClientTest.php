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
use Cacti\PluginCloudflare\Api\CloudflareClient;
use Cacti\PluginCloudflare\Api\ErrorClass;
use Cacti\PluginCloudflare\Api\RetryPolicy;
use Cacti\PluginCloudflare\Http\HttpTransport;
use Cacti\PluginCloudflare\Http\Request;
use Cacti\PluginCloudflare\Http\Response;
use Cacti\PluginCloudflare\Http\TransportException;
use Cacti\PluginCloudflare\Tests\Support\FakeTransport;
use Cacti\PluginCloudflare\Tests\Support\FrozenClock;
use Cacti\PluginCloudflare\Tests\Support\RecordingSleeper;
use Cacti\PluginCloudflare\Tests\Support\SecretAssertions;
use PHPUnit\Framework\TestCase;

final class CloudflareClientTest extends TestCase {
	use SecretAssertions;

	private const ACCOUNT = 'ed17574386854bf78a67040be0a770b0';
	private const BASE    = 'https://api.cloudflare.com/client/v4';

	/** @var FakeTransport */
	private $transport;

	/** @var RecordingSleeper */
	private $sleeper;

	/** @var string */
	private $token;

	protected function setUp(): void {
		$this->transport = new FakeTransport();
		$this->sleeper   = new RecordingSleeper();
		$this->token     = 'cfut_' . bin2hex(random_bytes(16));
	}

	private function client(?HttpTransport $transport = null): CloudflareClient {
		return new CloudflareClient(
			$transport === null ? $this->transport : $transport,
			new RetryPolicy(new FrozenClock(), $this->sleeper)
		);
	}

	private function expectFailure(callable $call, string $class): ApiException {
		try {
			$call();
		} catch (ApiException $e) {
			$this->assertSame($class, $e->getErrorClass());
			$this->assertNotLeaked($this->token, $e->getMessage(), 'exception message');

			return $e;
		}

		$this->fail('expected ApiException of class ' . $class);
	}

	/**
	 * @param array<string, string> $headers
	 */
	private function json(int $status, string $body, array $headers = array()): Response {
		return new Response($status, $headers, $body);
	}

	public function testVerifyActiveToken(): void {
		$this->transport->queue(FakeTransport::fixture('token-verify-active'));

		$status = $this->client()->verifyToken(self::ACCOUNT, $this->token);

		$this->assertTrue($status->isActive());
		$this->assertSame('ed17574386854bf78a67040be0a770b0', $status->id());
		$this->assertSame(4070908800, $status->expiresOn());
		$this->assertCount(1, $this->transport->requests());
		$this->assertSame(
			self::BASE . '/accounts/' . self::ACCOUNT . '/tokens/verify',
			$this->transport->requests()[0]->url()
		);
	}

	public function testVerifyFallsBackToUserEndpoint(): void {
		$this->transport->queue(FakeTransport::fixture('auth-error', 401));
		$this->transport->queue(FakeTransport::fixture('user-verify'));

		$status = $this->client()->verifyToken(self::ACCOUNT, $this->token);

		$this->assertTrue($status->isActive());
		$this->assertNull($status->expiresOn());
		$urls = array_map(function (Request $r): string {
			return $r->url();
		}, $this->transport->requests());
		$this->assertSame(array(
			self::BASE . '/accounts/' . self::ACCOUNT . '/tokens/verify',
			self::BASE . '/user/tokens/verify',
		), $urls);
	}

	public function testUserTokenFallsBackAfterAccount1000(): void {
		$this->transport->queue(FakeTransport::fixture('account-verify-1000', 401));
		$this->transport->queue(FakeTransport::fixture('user-verify'));

		$status = $this->client()->verifyToken(self::ACCOUNT, $this->token);

		$this->assertTrue($status->isActive());
		$this->assertCount(2, $this->transport->requests());
		$this->assertSame(self::BASE . '/user/tokens/verify', $this->transport->requests()[1]->url());
	}

	public function testFallbackFailureIsReported(): void {
		$this->transport->queue(FakeTransport::fixture('account-verify-1000', 401));
		$this->transport->queue(FakeTransport::fixture('account-verify-1000', 401));

		$e = $this->expectFailure(function () {
			$this->client()->verifyToken(self::ACCOUNT, $this->token);
		}, ErrorClass::AUTH);

		$this->assertSame(array(1000), $e->getApiCodes());
	}

	public function testVerifyDoesNotFallBackOnTransient(): void {
		$this->transport->queue(new TransportException(TransportException::TRANSIENT, 'CURLE_OPERATION_TIMEDOUT'));
		$this->transport->queue(new TransportException(TransportException::TRANSIENT, 'CURLE_OPERATION_TIMEDOUT'));
		$this->transport->queue(new TransportException(TransportException::TRANSIENT, 'CURLE_OPERATION_TIMEDOUT'));

		$this->expectFailure(function () {
			$this->client()->verifyToken(self::ACCOUNT, $this->token);
		}, ErrorClass::TRANSIENT);

		$this->assertCount(3, $this->transport->requests());
	}

	public function testExpiredTokenStatus(): void {
		$this->transport->queue(FakeTransport::fixture('token-verify-expired'));

		$status = $this->client()->verifyToken(self::ACCOUNT, $this->token);

		$this->assertFalse($status->isActive());
		$this->assertSame('expired', $status->status());
		$this->assertSame(1577836800, $status->expiresOn());
	}

	public function testUnparseableExpiryIsNull(): void {
		$this->transport->queue($this->json(200, '{"success":true,"errors":[],"result":{"id":"x","status":"active","expires_on":"soon"}}'));

		$this->assertNull($this->client()->verifyToken(self::ACCOUNT, $this->token)->expiresOn());
	}

	public function testVerifyResultOfWrongShapeIsMalformed(): void {
		$this->transport->queue($this->json(200, '{"success":true,"errors":[],"result":[]}'));

		$this->expectFailure(function () {
			$this->client()->verifyToken(self::ACCOUNT, $this->token);
		}, ErrorClass::MALFORMED);
	}

	public function testSuccessFalseWith200IsError(): void {
		$this->transport->queue(FakeTransport::fixture('error-envelope', 200));

		$e = $this->expectFailure(function () {
			$this->client()->request('GET', '/accounts', $this->token);
		}, ErrorClass::MALFORMED);

		$this->assertSame(array(7003), $e->getApiCodes());
		$this->assertSame(200, $e->getHttpStatus());
	}

	public function testAuthCode10000IsAuth(): void {
		$this->transport->queue(FakeTransport::fixture('auth-error', 200));

		$this->expectFailure(function () {
			$this->client()->request('GET', '/accounts', $this->token);
		}, ErrorClass::AUTH);
	}

	public function testMalformedTokenHeaderIsAuth(): void {
		$this->transport->queue(FakeTransport::fixture('malformed-token-6003', 400));

		$e = $this->expectFailure(function () {
			$this->client()->request('GET', '/accounts', $this->token);
		}, ErrorClass::AUTH);

		$this->assertSame(array(6003, 6111), $e->getApiCodes());
		$this->assertCount(1, $this->transport->requests());
	}

	public function testOther400IsMalformed(): void {
		$this->transport->queue($this->json(400, '{"success":false,"errors":[{"code":9109,"message":"bad"}],"result":null}'));

		$this->expectFailure(function () {
			$this->client()->request('GET', '/accounts', $this->token);
		}, ErrorClass::MALFORMED);
	}

	public function testHttp401IsAuthEvenWithoutJson(): void {
		$this->transport->queue($this->json(401, '<html>nope</html>'));

		$this->expectFailure(function () {
			$this->client()->request('GET', '/accounts', $this->token);
		}, ErrorClass::AUTH);
	}

	public function testHttp403IsPermission(): void {
		$this->transport->queue(FakeTransport::fixture('auth-error', 403));

		$this->expectFailure(function () {
			$this->client()->request('GET', '/accounts', $this->token);
		}, ErrorClass::PERMISSION);
	}

	public function testHttp404IsNotFoundAndNotRetried(): void {
		$this->transport->queue(FakeTransport::fixture('error-envelope', 404));

		$this->expectFailure(function () {
			$this->client()->request('GET', '/accounts', $this->token);
		}, ErrorClass::NOT_FOUND);

		$this->assertCount(1, $this->transport->requests());
	}

	public function testHttp429HonoursRetryAfter(): void {
		$this->transport->queue($this->json(429, '{}', array('Retry-After' => '3')));
		$this->transport->queue(FakeTransport::fixture('tunnel-list-page1'));

		$out = $this->client()->request('GET', '/accounts/' . self::ACCOUNT . '/cfd_tunnel', $this->token, array('page' => 1));

		$this->assertTrue($out['success']);
		$this->assertSame(array(3), $this->sleeper->slept);
	}

	public function testHttp429RetryAfterTooLongIsNotSlept(): void {
		$this->transport->queue($this->json(429, '{}', array('retry-after' => '120')));

		$e = $this->expectFailure(function () {
			$this->client()->request('GET', '/accounts', $this->token);
		}, ErrorClass::RATE_LIMITED);

		$this->assertSame(120, $e->getRetryAfter());
		$this->assertSame(array(), $this->sleeper->slept);
		$this->assertCount(1, $this->transport->requests());
	}

	public function testHttp429WithNonIntegerRetryAfterUsesBackoff(): void {
		for ($i = 0; $i < 3; $i++) {
			$this->transport->queue($this->json(429, '{}', array('retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT')));
		}

		$e = $this->expectFailure(function () {
			$this->client()->request('GET', '/accounts', $this->token);
		}, ErrorClass::RATE_LIMITED);

		$this->assertNull($e->getRetryAfter());
		$this->assertSame(array(1, 2), $this->sleeper->slept);
	}

	public function testRetryBudgetExhausted(): void {
		for ($i = 0; $i < 3; $i++) {
			$this->transport->queue($this->json(503, 'busy'));
		}

		$e = $this->expectFailure(function () {
			$this->client()->request('GET', '/accounts', $this->token);
		}, ErrorClass::TRANSIENT);

		$this->assertSame(503, $e->getHttpStatus());
		$this->assertSame(array(1, 2), $this->sleeper->slept);
		$this->assertCount(3, $this->transport->requests());
	}

	public function testTransportTimeoutIsTransient(): void {
		$this->transport->queue(new TransportException(TransportException::TRANSIENT, 'CURLE_OPERATION_TIMEDOUT'));
		$this->transport->queue(FakeTransport::fixture('tunnel-list-page1'));

		$out = $this->client()->request('GET', '/accounts', $this->token);

		$this->assertTrue($out['success']);
		$this->assertSame(array(1), $this->sleeper->slept);
	}

	public function testTransportConfigFailureIsConfigAndNotRetried(): void {
		$this->transport->queue(new TransportException(TransportException::CONFIG, 'host_not_allowed'));

		$this->expectFailure(function () {
			$this->client()->request('GET', '/accounts', $this->token);
		}, ErrorClass::CONFIG);

		$this->assertSame(array(), $this->sleeper->slept);
	}

	public function testMalformedJson(): void {
		$this->transport->queue($this->json(200, '{not json'));

		$this->expectFailure(function () {
			$this->client()->request('GET', '/accounts', $this->token);
		}, ErrorClass::MALFORMED);
	}

	public function testMissingSuccessField(): void {
		$this->transport->queue($this->json(200, '{"errors":[],"result":[]}'));

		$this->expectFailure(function () {
			$this->client()->request('GET', '/accounts', $this->token);
		}, ErrorClass::MALFORMED);
	}

	public function testNonArrayErrorsIsMalformed(): void {
		$this->transport->queue($this->json(200, '{"success":true,"errors":"none","result":[]}'));

		$this->expectFailure(function () {
			$this->client()->request('GET', '/accounts', $this->token);
		}, ErrorClass::MALFORMED);
	}

	public function testInvalidAccountIdNeverSends(): void {
		$bad = array('', 'XYZ', self::ACCOUNT . '/../x', strtoupper(self::ACCOUNT));

		foreach ($bad as $id) {
			$this->expectFailure(function () use ($id) {
				$this->client()->verifyToken($id, $this->token);
			}, ErrorClass::CONFIG);
			$this->expectFailure(function () use ($id) {
				$this->client()->probe($id, $this->token, '/zones');
			}, ErrorClass::CONFIG);
		}

		$this->assertCount(0, $this->transport->requests());
	}

	public function testBadPathNeverSends(): void {
		foreach (array('accounts', '/a b', '/a?x=1', '/a/../b', "/a\r\nHost: x", '/a%2f') as $path) {
			$this->expectFailure(function () use ($path) {
				$this->client()->request('GET', $path, $this->token);
			}, ErrorClass::CONFIG);
		}

		$this->assertCount(0, $this->transport->requests());
	}

	public function testHeaderInjectionInTokenIsConfig(): void {
		$bad = $this->token . "\r\nX-Evil: 1";

		try {
			$this->client()->request('GET', '/accounts', $bad);
			$this->fail('expected ApiException');
		} catch (ApiException $e) {
			$this->assertSame(ErrorClass::CONFIG, $e->getErrorClass());
			$this->assertNotLeaked($this->token, $e->getMessage(), 'exception message');
		}

		$this->assertCount(0, $this->transport->requests());
	}

	public function testTokenNeverInExceptionMessage(): void {
		$echo = '{"success":false,"errors":[{"code":1000,"message":"bad token ' . $this->token . '","error_chain":[{"code":1,"message":"Bearer ' . $this->token . '"}]}],"result":null}';
		$responses = array(
			$this->json(401, $echo),
			$this->json(403, $echo),
			$this->json(404, $echo),
			$this->json(400, $echo),
			$this->json(200, $echo),
			$this->json(200, 'garbage ' . $this->token),
			$this->json(429, $echo, array('retry-after' => '999')),
		);

		foreach ($responses as $response) {
			$transport = new FakeTransport();
			$transport->queue($response);
			try {
				$this->client($transport)->request('GET', '/accounts', $this->token);
				$this->fail('expected ApiException');
			} catch (ApiException $e) {
				$this->assertNotLeaked($this->token, $e->getMessage(), 'exception message');
			}
		}

		$transport = new FakeTransport();
		for ($i = 0; $i < 3; $i++) {
			$transport->queue($this->json(503, $echo));
		}
		$this->expectFailure(function () use ($transport) {
			$this->client($transport)->request('GET', '/accounts', $this->token);
		}, ErrorClass::TRANSIENT);
	}

	public function testAuthorizationHeaderIsBearer(): void {
		$this->transport->queue(FakeTransport::fixture('tunnel-list-page1'));

		$this->client()->request('GET', '/accounts', $this->token);

		$headers = $this->transport->requests()[0]->headers();
		$this->assertSecretEquals('Bearer ' . $this->token, $headers['Authorization']);
		$this->assertSame('GET', $this->transport->requests()[0]->method());
		$this->assertNull($this->transport->requests()[0]->body());
	}

	public function testQueryIsAppendedAndEncoded(): void {
		$this->transport->queue(FakeTransport::fixture('tunnel-list-page1'));

		$this->client()->request('GET', '/accounts/' . self::ACCOUNT . '/cfd_tunnel', $this->token, array('page' => 2, 'name' => 'a b&c'));

		$this->assertSame(
			self::BASE . '/accounts/' . self::ACCOUNT . '/cfd_tunnel?page=2&name=a%20b%26c',
			$this->transport->requests()[0]->url()
		);
	}

	public function testTimeoutsArePassedToTransport(): void {
		$spy = new class () implements HttpTransport {
			/** @var array<int, int> */
			public $seen = array();

			public function send(Request $r, int $connectTimeout, int $totalTimeout): Response {
				$this->seen = array($connectTimeout, $totalTimeout);

				return FakeTransport::fixture('tunnel-list-page1');
			}
		};
		$client = new CloudflareClient($spy, new RetryPolicy(new FrozenClock(), $this->sleeper), self::BASE, 7, 19);

		$client->request('GET', '/accounts', $this->token);

		$this->assertSame(array(7, 19), $spy->seen);
	}

	public function testProbeReturnsTrueOnSuccess(): void {
		$this->transport->queue(FakeTransport::fixture('tunnel-list-page1'));

		$this->assertTrue($this->client()->probe(self::ACCOUNT, $this->token, '/accounts/' . self::ACCOUNT . '/cfd_tunnel', array('per_page' => 1)));
		$this->assertStringEndsWith('/cfd_tunnel?per_page=1', $this->transport->requests()[0]->url());
	}

	public function testProbeReturnsFalseOnPermissionAndAuth(): void {
		$this->transport->queue(FakeTransport::fixture('auth-error', 403));
		$this->transport->queue(FakeTransport::fixture('auth-error', 401));

		$this->assertFalse($this->client()->probe(self::ACCOUNT, $this->token, '/zones'));
		$this->assertFalse($this->client()->probe(self::ACCOUNT, $this->token, '/zones'));
	}

	public function testProbeRethrowsOtherFailures(): void {
		$this->transport->queue(FakeTransport::fixture('error-envelope', 404));

		$this->expectFailure(function () {
			$this->client()->probe(self::ACCOUNT, $this->token, '/zones');
		}, ErrorClass::NOT_FOUND);
	}
}
