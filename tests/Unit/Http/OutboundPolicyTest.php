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

use Cacti\PluginCloudflare\Http\OutboundPolicy;
use Cacti\PluginCloudflare\Http\TransportException;
use PHPUnit\Framework\TestCase;

final class OutboundPolicyTest extends TestCase {
	public function testProductionAllowsOnlyCloudflareApi(): void {
		$policy = OutboundPolicy::production();

		$policy->assertAllowed('https://api.cloudflare.com/client/v4/user/tokens/verify');
		$policy->assertAllowed('https://API.Cloudflare.com:443/client/v4/accounts?page=1');
		$policy->assertAllowed('HTTPS://api.cloudflare.com');

		$this->expectException(TransportException::class);
		$policy->assertAllowed('https://dash.cloudflare.com/');
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function rejectedUrls(): array {
		return array(
			'plain http'          => array('http://api.cloudflare.com/client/v4'),
			'suffix host'         => array('https://api.cloudflare.com.evil.test/client/v4'),
			'userinfo'            => array('https://user@api.cloudflare.com/client/v4'),
			'userinfo with pass'  => array('https://user:pw@api.cloudflare.com/client/v4'),
			'authority confusion' => array('https://api.cloudflare.com:443@evil.test/'),
			'other port'          => array('https://api.cloudflare.com:8443/client/v4'),
			'file scheme'         => array('file:///etc/passwd'),
			'gopher scheme'       => array('gopher://x'),
			'no host'             => array('https:///client/v4'),
			'single slash'        => array('https:/api.cloudflare.com/client/v4'),
			'fragment'            => array('https://api.cloudflare.com/client/v4#x'),
			'fragment authority'  => array('https://evil.test#@api.cloudflare.com/'),
			'backslash'           => array('https://evil.test\\@api.cloudflare.com/'),
			'trailing dot'        => array('https://api.cloudflare.com./client/v4'),
			'space'               => array('https://api.cloudflare.com/client v4'),
			'crlf'                => array("https://api.cloudflare.com/\r\nHost: evil.test"),
			'nul'                 => array("https://api.cloudflare.com\0.evil.test/"),
			'tab'                 => array("https://api.cloudflare.com/\tx"),
			'non ascii'           => array("https://api.cloudflare.com\xE3\x80\x82evil.test/"),
			'port with suffix'    => array('https://api.cloudflare.com:443x/client/v4'),
			'signed port'         => array('https://api.cloudflare.com:+443/client/v4'),
			'empty port'          => array('https://api.cloudflare.com:/client/v4'),
			'empty'               => array(''),
			'relative'            => array('/client/v4'),
		);
	}

	/**
	 * @dataProvider rejectedUrls
	 */
	public function testRejects(string $url): void {
		try {
			OutboundPolicy::production()->assertAllowed($url);
			$this->fail('URL was allowed');
		} catch (TransportException $e) {
			$this->assertSame('config', $e->getErrorClass());
		}
	}

	public function testRejectionDoesNotEchoTheUrl(): void {
		$url = 'https://evil.test/?token=cfut_' . str_repeat('Zq8', 14);

		try {
			OutboundPolicy::production()->assertAllowed($url);
			$this->fail('URL was allowed');
		} catch (TransportException $e) {
			$this->assertStringNotContainsString('cfut_', $e->getMessage());
			$this->assertStringNotContainsString('cfut_', $e->getDetail());
		}
	}

	public function testTestOriginMatchesPortExactly(): void {
		$policy = new OutboundPolicy(array('http://127.0.0.1:8123'));

		$policy->assertAllowed('http://127.0.0.1:8123/ok');

		foreach (array('http://127.0.0.1/ok', 'http://127.0.0.1:8124/ok', 'https://127.0.0.1:8123/ok', 'http://localhost:8123/ok') as $url) {
			try {
				$policy->assertAllowed($url);
				$this->fail('URL was allowed: ' . $url);
			} catch (TransportException $e) {
				$this->assertSame('config', $e->getErrorClass());
			}
		}
	}

	public function testDefaultPortsAreImplied(): void {
		$policy = new OutboundPolicy(array('http://example.test'));

		$policy->assertAllowed('http://example.test:80/x');
		$this->expectException(TransportException::class);
		$policy->assertAllowed('http://example.test:8080/x');
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function badOrigins(): array {
		return array(
			'with path'     => array('https://api.cloudflare.com/client'),
			'ftp'           => array('ftp://api.cloudflare.com'),
			'userinfo'      => array('https://u@api.cloudflare.com'),
			'no scheme'     => array('api.cloudflare.com'),
			'with query'    => array('https://api.cloudflare.com?x=1'),
		);
	}

	/**
	 * @dataProvider badOrigins
	 */
	public function testConstructorRejectsMalformedOrigin(string $origin): void {
		try {
			new OutboundPolicy(array($origin));
			$this->fail('origin was accepted');
		} catch (TransportException $e) {
			$this->assertSame('config', $e->getErrorClass());
		}
	}

	public function testEmptyPolicyAllowsNothing(): void {
		$this->expectException(TransportException::class);
		(new OutboundPolicy(array()))->assertAllowed('https://api.cloudflare.com/');
	}
}
