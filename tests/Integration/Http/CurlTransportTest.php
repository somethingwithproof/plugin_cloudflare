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

namespace Cacti\PluginCloudflare\Tests\Integration\Http;

use Cacti\PluginCloudflare\Http\CurlTransport;
use Cacti\PluginCloudflare\Http\OutboundPolicy;
use Cacti\PluginCloudflare\Http\Request;
use Cacti\PluginCloudflare\Http\TransportException;
use Cacti\PluginCloudflare\Tests\Support\SecretAssertions;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CurlTransportTest extends TestCase {
	use SecretAssertions;

	/** @var resource|null */
	private static $server;

	/** @var int */
	private static $port = 0;

	/** @var string */
	private static $log = '';

	public static function setUpBeforeClass(): void {
		if (!extension_loaded('curl')) {
			return;
		}

		self::$port = self::freePort();
		self::$log  = (string) tempnam(sys_get_temp_dir(), 'cf-stub-');

		// No PHP_CLI_SERVER_WORKERS: forked workers outlive proc_terminate()
		// on the parent. One worker is enough; a request still sleeping in
		// /slow only delays the next test, whose timeout covers it.
		$env = array(
			'CF_STUB_LOG' => self::$log,
			'PATH'        => (string) getenv('PATH'),
		);

		$process = proc_open(
			array(PHP_BINARY, '-S', '127.0.0.1:' . self::$port, dirname(__DIR__, 2) . '/Support/stub-server.php'),
			array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')),
			$pipes,
			null,
			$env
		);
		if (!is_resource($process)) {
			throw new RuntimeException('could not start stub server');
		}
		self::$server = $process;

		$deadline = microtime(true) + 3;
		while (microtime(true) < $deadline) {
			$fp = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
			if ($fp !== false) {
				fclose($fp);

				return;
			}
			usleep(50000);
		}

		throw new RuntimeException('stub server did not start listening within 3 seconds');
	}

	public static function tearDownAfterClass(): void {
		if (is_resource(self::$server)) {
			proc_terminate(self::$server, 9);
			proc_close(self::$server);
		}
		self::$server = null;

		if (self::$log !== '' && is_file(self::$log)) {
			unlink(self::$log);
		}
	}

	protected function setUp(): void {
		if (!extension_loaded('curl')) {
			$this->markTestSkipped('ext-curl is not loaded');
		}

		file_put_contents(self::$log, '');
	}

	private static function freePort(): int {
		$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
		if ($sock === false) {
			throw new RuntimeException('could not bind a free port');
		}
		$name = (string) stream_socket_get_name($sock, false);
		fclose($sock);

		return (int) substr($name, strrpos($name, ':') + 1);
	}

	private function origin(): string {
		return 'http://127.0.0.1:' . self::$port;
	}

	private function transport(int $maxBytes = 5242880): CurlTransport {
		return new CurlTransport(new OutboundPolicy(array($this->origin())), $maxBytes);
	}

	private function token(): string {
		return 'cfut_' . str_repeat('Wx7', 14);
	}

	/**
	 * @return array<string, string>
	 */
	private function authHeaders(): array {
		return array('Authorization' => 'Bearer ' . $this->token(), 'Accept' => 'application/json');
	}

	/**
	 * @return array<int, string>
	 */
	private function hits(): array {
		return array_values(array_filter(explode("\n", (string) file_get_contents(self::$log))));
	}

	private function assertTokenAbsent(TransportException $e): void {
		$token = $this->token();

		$this->assertNotLeaked($token, $e->getMessage(), 'message');
		$this->assertNotLeaked($token, $e->getDetail(), 'detail');
		$this->assertNotLeaked($token, (string) $e, '__toString');
		$this->assertNotLeaked($token, print_r($e->getTrace(), true), 'trace');
	}

	private function sendExpectingFailure(CurlTransport $t, Request $r, int $connect, int $total): TransportException {
		try {
			$t->send($r, $connect, $total);
		} catch (TransportException $e) {
			$this->assertTokenAbsent($e);

			return $e;
		}

		$this->fail('request did not throw');
	}

	public function testGetOk(): void {
		$response = $this->transport()->send(new Request('GET', $this->origin() . '/ok', $this->authHeaders()), 2, 5);

		$this->assertSame(200, $response->status());
		$this->assertSame('ok', $response->header('X-STUB'));
		$this->assertSame('application/json', $response->header('content-type'));
		$this->assertSame(array('success' => true, 'method' => 'GET', 'auth' => true), json_decode($response->body(), true));
	}

	public function testPostSendsBody(): void {
		$response = $this->transport()->send(new Request('POST', $this->origin() . '/ok', array('Content-Type' => 'application/json'), '{"q":1}'), 2, 5);

		$this->assertSame('POST', json_decode($response->body(), true)['method']);
	}

	public function testDoesNotFollowRedirect(): void {
		$response = $this->transport()->send(new Request('GET', $this->origin() . '/redirect', $this->authHeaders()), 2, 5);

		$this->assertSame(302, $response->status());
		$this->assertSame('/target', $response->header('location'));
		$this->assertSame(array('/redirect'), $this->hits());
	}

	public function testTotalTimeoutIsTransient(): void {
		$start = microtime(true);
		$e = $this->sendExpectingFailure($this->transport(), new Request('GET', $this->origin() . '/slow?s=3', $this->authHeaders()), 1, 1);

		$this->assertLessThan(2.5, microtime(true) - $start);
		$this->assertSame('transient', $e->getErrorClass());
		$this->assertStringContainsString('CURLE_OPERATION_TIMEDOUT', $e->getDetail());
	}

	public function testConnectionRefusedIsTransient(): void {
		$port = self::freePort();
		$t = new CurlTransport(new OutboundPolicy(array('http://127.0.0.1:' . $port)));

		$e = $this->sendExpectingFailure($t, new Request('GET', 'http://127.0.0.1:' . $port . '/ok', $this->authHeaders()), 2, 5);

		$this->assertSame('transient', $e->getErrorClass());
		$this->assertStringContainsString('CURLE_COULDNT_CONNECT', $e->getDetail());
	}

	public function testOversizedBodyIsRejected(): void {
		$e = $this->sendExpectingFailure($this->transport(), new Request('GET', $this->origin() . '/big', $this->authHeaders()), 2, 10);

		$this->assertSame('transient', $e->getErrorClass());
		$this->assertStringContainsString('too large', $e->getDetail());
	}

	public function testBodyAtTheCapIsAccepted(): void {
		$response = $this->transport(6 * 1024 * 1024)->send(new Request('GET', $this->origin() . '/big'), 2, 10);

		$this->assertSame(6 * 1024 * 1024, strlen($response->body()));
	}

	public function testNonAllowlistedUrlNeverConnects(): void {
		$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
		$this->assertNotFalse($listener);
		$name = (string) stream_socket_get_name($listener, false);
		$port = (int) substr($name, strrpos($name, ':') + 1);

		try {
			$e = $this->sendExpectingFailure($this->transport(), new Request('GET', 'http://127.0.0.1:' . $port . '/ok', $this->authHeaders()), 2, 5);
			$this->assertSame('config', $e->getErrorClass());

			stream_set_blocking($listener, false);
			$this->assertFalse(@stream_socket_accept($listener, 0.3), 'transport connected to a disallowed origin');
		} finally {
			fclose($listener);
		}

		$this->assertSame(array(), $this->hits());
	}

	public function testHttpErrorStatusReturnedNotThrown(): void {
		$response = $this->transport()->send(new Request('GET', $this->origin() . '/status?code=503', $this->authHeaders()), 2, 5);

		$this->assertSame(503, $response->status());
		$this->assertSame('{"success":false}', $response->body());
	}

	public function testTlsHandshakeFailureIsTransient(): void {
		if ((curl_version()['features'] & CURL_VERSION_SSL) === 0) {
			$this->markTestSkipped('curl was built without TLS support');
		}

		$origin = 'https://127.0.0.1:' . self::$port;
		$t = new CurlTransport(new OutboundPolicy(array($origin)));

		$e = $this->sendExpectingFailure($t, new Request('GET', $origin . '/ok', $this->authHeaders()), 2, 5);

		$this->assertSame('transient', $e->getErrorClass());
		$this->assertSame(array(), $this->hits(), 'request reached the server without a TLS session');
	}

	public function testSelfSignedCertificateIsRejected(): void {
		if ((curl_version()['features'] & CURL_VERSION_SSL) === 0) {
			$this->markTestSkipped('curl was built without TLS support');
		}

		$dir = sys_get_temp_dir() . '/cf-tls-' . bin2hex(random_bytes(6));
		mkdir($dir, 0700);
		$pem = $dir . '/server.pem';
		$log = $dir . '/requests.log';
		file_put_contents($log, '');

		$key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
		$this->assertNotFalse($key);
		$csr = openssl_csr_new(array('commonName' => '127.0.0.1'), $key);
		$this->assertNotFalse($csr);
		$this->assertNotTrue($csr);
		$cert = openssl_csr_sign($csr, null, $key, 1);
		$this->assertNotFalse($cert);
		$this->assertTrue(openssl_x509_export($cert, $certPem));
		$this->assertTrue(openssl_pkey_export($key, $keyPem));
		file_put_contents($pem, $certPem . $keyPem);

		$port = self::freePort();
		$process = proc_open(
			array(PHP_BINARY, dirname(__DIR__, 2) . '/Support/tls-server.php', (string) $port, $pem, $log),
			array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')),
			$pipes
		);
		$this->assertIsResource($process);

		try {
			$deadline = microtime(true) + 3;
			$listening = false;
			while (!$listening && microtime(true) < $deadline) {
				$fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
				if ($fp !== false) {
					fclose($fp);
					$listening = true;
				} else {
					usleep(50000);
				}
			}
			$this->assertTrue($listening, 'TLS server did not start listening within 3 seconds');

			$origin = 'https://127.0.0.1:' . $port;
			$t = new CurlTransport(new OutboundPolicy(array($origin)));
			$e = $this->sendExpectingFailure($t, new Request('GET', $origin . '/ok', $this->authHeaders()), 2, 5);

			$this->assertSame('transient', $e->getErrorClass());
			$this->assertStringContainsString('CURLE_PEER_FAILED_VERIFICATION', $e->getDetail());
			$this->assertSame('', (string) file_get_contents($log), 'an HTTP request was sent over an unverified TLS session');
		} finally {
			proc_terminate($process, 9);
			proc_close($process);
			foreach (array($pem, $log) as $file) {
				if (is_file($file)) {
					unlink($file);
				}
			}
			rmdir($dir);
		}
	}

	public function testEnvironmentProxyIsIgnored(): void {
		$dead = 'http://127.0.0.1:' . self::freePort();
		$names = array('http_proxy', 'HTTPS_PROXY', 'https_proxy', 'ALL_PROXY', 'all_proxy');
		$saved = array();
		foreach ($names as $name) {
			$saved[$name] = getenv($name);
			putenv($name . '=' . $dead);
		}

		try {
			$response = $this->transport()->send(new Request('GET', $this->origin() . '/ok'), 2, 5);
		} finally {
			foreach ($saved as $name => $value) {
				putenv($value === false ? $name : $name . '=' . $value);
			}
		}

		$this->assertSame(200, $response->status());
		$this->assertSame(array('/ok'), $this->hits());
	}

	public function testRejectsNonPositiveTimeouts(): void {
		$e = $this->sendExpectingFailure($this->transport(), new Request('GET', $this->origin() . '/ok'), 0, 5);

		$this->assertSame('config', $e->getErrorClass());
	}
}
