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

namespace Cacti\PluginCloudflare\Tests\Support;

use Cacti\PluginCloudflare\Http\HttpTransport;
use Cacti\PluginCloudflare\Http\Request;
use Cacti\PluginCloudflare\Http\Response;
use Cacti\PluginCloudflare\Http\TransportException;
use InvalidArgumentException;
use LogicException;

final class FakeTransport implements HttpTransport {
	/** @var array<int, Response|TransportException> */
	private $queue = array();

	/** @var array<int, Request> */
	private $requests = array();

	/**
	 * @param Response|TransportException $next
	 */
	public function queue($next): void {
		$this->queue[] = $next;
	}

	public function send(Request $r, int $connectTimeout, int $totalTimeout): Response {
		$this->requests[] = $r;

		$next = array_shift($this->queue);
		if ($next === null) {
			throw new LogicException('FakeTransport received an unexpected request');
		}
		if ($next instanceof TransportException) {
			throw $next;
		}

		return $next;
	}

	/**
	 * @return array<int, Request>
	 */
	public function requests(): array {
		return $this->requests;
	}

	/**
	 * @param array<string, string> $headers
	 */
	public static function fixture(string $name, int $status = 200, array $headers = array()): Response {
		if (preg_match('/^[a-z0-9-]+\z/', $name) !== 1) {
			throw new InvalidArgumentException('invalid fixture name');
		}

		$body = file_get_contents(dirname(__DIR__) . '/fixtures/cloudflare/' . $name . '.json');
		if ($body === false) {
			throw new InvalidArgumentException('fixture not found: ' . $name);
		}

		return new Response($status, $headers, $body);
	}
}
