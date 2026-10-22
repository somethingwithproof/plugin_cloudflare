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

namespace Cacti\PluginCloudflare\Http;

/**
 * The only gate between a built URL and the network. It accepts a URL only
 * when scheme, host and port equal one configured origin, and refuses any
 * input on which parse_url() and curl's own parser could disagree about the
 * authority: userinfo, '@', backslashes, fragments, whitespace, control
 * bytes and non-ASCII bytes.
 */
final class OutboundPolicy {
	private const PRODUCTION_ORIGIN = 'https://api.cloudflare.com';

	private const DEFAULT_PORTS = array('https' => 443, 'http' => 80);

	/** @var array<int, array{scheme: string, host: string, port: int}> */
	private $origins = array();

	/**
	 * @param array<int, string> $origins
	 */
	public function __construct(array $origins) {
		foreach ($origins as $origin) {
			$parts = self::parse($origin);
			if ($parts === null || isset($parts['query']) || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
				throw new TransportException(TransportException::CONFIG, 'invalid_origin');
			}

			$this->origins[] = array('scheme' => $parts['scheme'], 'host' => $parts['host'], 'port' => $parts['port']);
		}
	}

	public static function production(): self {
		return new self(array(self::PRODUCTION_ORIGIN));
	}

	/**
	 * The URL itself is never placed in the exception: a caller that built
	 * it from a misplaced token would otherwise get that token logged.
	 */
	public function assertAllowed(string $url): void {
		$parts = self::parse($url);
		if ($parts === null) {
			throw new TransportException(TransportException::CONFIG, 'url_rejected');
		}

		foreach ($this->origins as $origin) {
			if ($origin['scheme'] === $parts['scheme'] && $origin['host'] === $parts['host'] && $origin['port'] === $parts['port']) {
				return;
			}
		}

		throw new TransportException(TransportException::CONFIG, 'origin_not_allowed');
	}

	/**
	 * @return array{scheme: string, host: string, port: int, path?: string, query?: string}|null
	 */
	private static function parse(string $url): ?array {
		if (preg_match('/[\x00-\x20\x7f-\xff@\\\\#]/', $url) === 1 || preg_match('#^https?://#i', $url) !== 1) {
			return null;
		}

		$parts = parse_url($url);
		if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
			return null;
		}
		if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
			return null;
		}

		$scheme = strtolower($parts['scheme']);
		if (!isset(self::DEFAULT_PORTS[$scheme])) {
			return null;
		}

		$out = array(
			'scheme' => $scheme,
			'host'   => strtolower($parts['host']),
			'port'   => $parts['port'] ?? self::DEFAULT_PORTS[$scheme],
		);
		if (isset($parts['path'])) {
			$out['path'] = $parts['path'];
		}
		if (isset($parts['query'])) {
			$out['query'] = $parts['query'];
		}

		return $out;
	}
}
