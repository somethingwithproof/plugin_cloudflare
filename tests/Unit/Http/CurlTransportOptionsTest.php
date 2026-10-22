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

use Cacti\PluginCloudflare\Http\CurlTransport;
use Cacti\PluginCloudflare\Http\Request;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CurlTransportOptionsTest extends TestCase {
	/**
	 * @return array<int, mixed>
	 */
	private function options(string $url): array {
		$m = new ReflectionMethod(CurlTransport::class, 'options');
		$m->setAccessible(true);

		$options = $m->invoke(null, new Request('GET', $url), 5, 10);
		$this->assertIsArray($options);

		return $options;
	}

	protected function setUp(): void {
		if (!extension_loaded('curl')) {
			$this->markTestSkipped('ext-curl is not loaded');
		}
	}

	public function testTlsVerificationIsOn(): void {
		$options = $this->options('https://api.cloudflare.com/client/v4');

		$this->assertSame(true, $options[CURLOPT_SSL_VERIFYPEER]);
		$this->assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
	}

	public function testRedirectsProxiesAndProtocolsAreLockedDown(): void {
		$options = $this->options('https://api.cloudflare.com/client/v4');

		$this->assertSame(false, $options[CURLOPT_FOLLOWLOCATION]);
		$this->assertSame(0, $options[CURLOPT_MAXREDIRS]);
		$this->assertSame('*', $options[CURLOPT_NOPROXY]);
		$this->assertSame(5, $options[CURLOPT_CONNECTTIMEOUT]);
		$this->assertSame(10, $options[CURLOPT_TIMEOUT]);
		if (defined('CURLOPT_PROTOCOLS')) {
			$this->assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
			$this->assertSame(CURLPROTO_HTTPS, $options[CURLOPT_REDIR_PROTOCOLS]);
		}
	}

	public function testHttpTestOriginIsPinnedToHttp(): void {
		if (!defined('CURLOPT_PROTOCOLS')) {
			$this->markTestSkipped('CURLOPT_PROTOCOLS is not available');
		}

		$options = $this->options('http://127.0.0.1:8123/ok');

		$this->assertSame(CURLPROTO_HTTP, $options[CURLOPT_PROTOCOLS]);
	}
}
