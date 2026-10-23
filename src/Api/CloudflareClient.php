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

use Cacti\PluginCloudflare\Http\HttpTransport;
use Cacti\PluginCloudflare\Http\Request;
use Cacti\PluginCloudflare\Http\Response;
use Cacti\PluginCloudflare\Http\TransportException;
use Cacti\PluginCloudflare\Support\Ids;
use Cacti\PluginCloudflare\Support\Redactor;
use DateTimeImmutable;
use Exception;

final class CloudflareClient {
	private const AUTH_CODE         = 10000;
	private const BAD_HEADER_CODES  = array(6003, 6111);
	private const MAX_API_MESSAGES  = 3;
	private const UNKNOWN_RESOURCE  = 1001;
	private const PAGE_SIZE         = 1000;
	private const MAX_PAGES         = 50;
	private const MAX_NAME          = 255;
	private const MAX_SHORT         = 32;
	private const MAX_LIST          = 255;
	private const MAX_TIMESTAMP     = 4294967295;
	private const TUNNEL_STATUSES   = array('healthy', 'degraded', 'down', 'inactive');

	/** @var HttpTransport */
	private $transport;

	/** @var RetryPolicy */
	private $retry;

	/** @var string */
	private $baseUrl;

	/** @var int */
	private $connectTimeout;

	/** @var int */
	private $totalTimeout;

	public function __construct(
		HttpTransport $transport,
		RetryPolicy $retry,
		string $baseUrl = 'https://api.cloudflare.com/client/v4',
		int $connectTimeout = 5,
		int $totalTimeout = 20
	) {
		$this->transport      = $transport;
		$this->retry          = $retry;
		$this->baseUrl        = rtrim($baseUrl, '/');
		$this->connectTimeout = $connectTimeout;
		$this->totalTimeout   = $totalTimeout;
	}

	/**
	 * A user-owned token answers 401 (code 1000) on the account endpoint, so
	 * auth and permission failures there fall back to the user endpoint.
	 *
	 * @throws ApiException
	 */
	public function verifyToken(
		string $cfAccountId,
		#[\SensitiveParameter]
		string $token
	): TokenStatus {
		$this->requireAccountId($cfAccountId);

		try {
			$envelope = $this->request('GET', '/accounts/' . $cfAccountId . '/tokens/verify', $token);
		} catch (ApiException $e) {
			if ($e->getErrorClass() !== ErrorClass::AUTH && $e->getErrorClass() !== ErrorClass::PERMISSION) {
				throw $e;
			}

			$envelope = $this->request('GET', '/user/tokens/verify', $token);
		}

		$result = $envelope['result'] ?? null;
		if (
			!is_array($result)
			|| !isset($result['id'], $result['status'])
			|| !is_string($result['id'])
			|| !is_string($result['status'])
		) {
			throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'token verify result has an unexpected shape');
		}

		return new TokenStatus($result['id'], $result['status'], self::parseExpiry($result['expires_on'] ?? null));
	}

	/**
	 * @param array<string, scalar> $query
	 *
	 * @throws ApiException
	 */
	public function probe(
		string $cfAccountId,
		#[\SensitiveParameter]
		string $token,
		string $path,
		array $query = array()
	): bool {
		$this->requireAccountId($cfAccountId);

		try {
			$this->request('GET', $path, $token, $query);
		} catch (ApiException $e) {
			if ($e->getErrorClass() === ErrorClass::PERMISSION || $e->getErrorClass() === ErrorClass::AUTH) {
				return false;
			}

			throw $e;
		}

		return true;
	}

	/**
	 * Lists every non-deleted tunnel, or throws. A partial listing is never
	 * returned: callers treat the result as the complete account state.
	 *
	 * @return array<int, TunnelRecord>
	 *
	 * @throws ApiException
	 */
	public function listTunnels(
		string $cfAccountId,
		#[\SensitiveParameter]
		string $token
	): array {
		$this->requireAccountId($cfAccountId);

		$tunnels = array();
		$seen    = array();
		$total   = null;

		for ($page = 1; $page <= self::MAX_PAGES; $page++) {
			$envelope = $this->request('GET', '/accounts/' . $cfAccountId . '/cfd_tunnel', $token, array(
				'is_deleted' => 'false',
				'per_page'   => self::PAGE_SIZE,
				'page'       => $page,
			));

			$info  = $envelope['result_info'] ?? null;
			$items = $envelope['result'] ?? null;
			if (
				!is_array($info)
				|| !isset($info['total_count'])
				|| !is_int($info['total_count'])
				|| $info['total_count'] < 0
				|| !is_array($items)
			) {
				throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'tunnel listing has no usable result_info');
			}

			/*
			 * A delete between pages shifts later pages by one item and lowers
			 * their total, so the running count would still match and a live
			 * tunnel would be missing. Pin page 1's total instead.
			 */
			if ($total === null) {
				$total = $info['total_count'];
			} elseif ($info['total_count'] !== $total) {
				throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'tunnel listing total changed between pages');
			}

			foreach ($items as $item) {
				$tunnel = self::parseTunnel($item);
				if (isset($seen[$tunnel->id])) {
					throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'tunnel listing repeats a tunnel id');
				}

				$seen[$tunnel->id] = true;
				$tunnels[]         = $tunnel;
			}

			if (count($tunnels) >= $total) {
				return $tunnels;
			}
			if ($items === array()) {
				throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'tunnel listing ended before total_count');
			}
		}

		throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'tunnel listing exceeds the page limit');
	}

	/**
	 * Cloudflare answers an unknown tunnel with 401 and code 1001, not 404.
	 * That is scoped to this resource: it means the tunnel is gone, not that
	 * the token was revoked, so it maps to not_found. Any other 401 stays auth.
	 *
	 * @return array<int, ConnectorRecord>
	 *
	 * @throws ApiException
	 */
	public function listConnections(
		string $cfAccountId,
		#[\SensitiveParameter]
		string $token,
		string $tunnelId
	): array {
		$this->requireAccountId($cfAccountId);
		if (!Ids::isUuid($tunnelId)) {
			throw new ApiException(ErrorClass::CONFIG, null, array(), 'invalid tunnel id');
		}

		try {
			$envelope = $this->request(
				'GET',
				'/accounts/' . $cfAccountId . '/cfd_tunnel/' . $tunnelId . '/connections',
				$token
			);
		} catch (ApiException $e) {
			$codes   = $e->getApiCodes();
			$unknown = $e->getErrorClass() === ErrorClass::AUTH
				&& $e->getHttpStatus() === 401
				&& isset($codes[0])
				&& $codes[0] === self::UNKNOWN_RESOURCE;

			if ($unknown) {
				throw new ApiException(ErrorClass::NOT_FOUND, 401, $e->getApiCodes(), 'tunnel is unknown to Cloudflare');
			}

			throw $e;
		}

		$items = $envelope['result'] ?? null;
		if (!is_array($items)) {
			throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'connections result is not a list');
		}

		$connectors = array();
		foreach ($items as $item) {
			$connectors[] = self::parseConnector($item);
		}

		return $connectors;
	}

	/**
	 * @param array<string, scalar> $query
	 *
	 * @return array<string, mixed> the decoded response envelope
	 *
	 * @throws ApiException
	 */
	public function request(
		string $method,
		string $path,
		#[\SensitiveParameter]
		string $token,
		array $query = array()
	): array {
		if (preg_match('#^/[A-Za-z0-9/_.-]*\z#', $path) !== 1 || strpos($path, '..') !== false) {
			throw new ApiException(ErrorClass::CONFIG, null, array(), 'invalid request path');
		}
		if ($token === '') {
			throw new ApiException(ErrorClass::CONFIG, null, array(), 'empty token');
		}

		$url = $this->baseUrl . $path;
		if ($query !== array()) {
			$url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
		}

		/** @var array<string, mixed> $envelope */
		$envelope = $this->retry->run(function () use ($method, $url, $token): array {
			return $this->attempt($method, $url, $token);
		});

		return $envelope;
	}

	/**
	 * @return array<string, mixed>
	 *
	 * @throws ApiException
	 */
	private function attempt(
		string $method,
		string $url,
		#[\SensitiveParameter]
		string $token
	): array {
		try {
			$response = $this->transport->send(
				new Request($method, $url, array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				)),
				$this->connectTimeout,
				$this->totalTimeout
			);
		} catch (TransportException $e) {
			$class = $e->getErrorClass() === TransportException::TRANSIENT ? ErrorClass::TRANSIENT : ErrorClass::CONFIG;

			throw new ApiException($class, null, array(), Redactor::redact($e->getMessage(), array($token)));
		}

		return $this->interpret($response, $token);
	}

	/**
	 * @return array<string, mixed>
	 *
	 * @throws ApiException
	 */
	private function interpret(
		Response $response,
		#[\SensitiveParameter]
		string $token
	): array {
		$status  = $response->status();
		$decoded = json_decode($response->body(), true);
		$body    = is_array($decoded) ? $decoded : array();

		list($codes, $messages) = self::collectErrors($body);
		$detail = Redactor::redact(implode('; ', array_slice($messages, 0, self::MAX_API_MESSAGES)), array($token));

		if ($status >= 200 && $status < 300) {
			if (
				!is_array($decoded)
				|| !isset($decoded['success'])
				|| !is_bool($decoded['success'])
				|| !isset($decoded['errors'])
				|| !is_array($decoded['errors'])
			) {
				throw new ApiException(ErrorClass::MALFORMED, $status, array(), 'response is not a Cloudflare envelope');
			}
			if ($decoded['success'] === false) {
				$class = in_array(self::AUTH_CODE, $codes, true) ? ErrorClass::AUTH : ErrorClass::MALFORMED;

				throw new ApiException($class, $status, $codes, $detail);
			}

			return $decoded;
		}

		$retryAfter = null;
		if ($status === 429) {
			$header = $response->header('retry-after');
			if ($header !== null && preg_match('/^\s*[0-9]{1,6}\s*\z/', $header) === 1) {
				$retryAfter = (int) trim($header);
			}
		}

		throw new ApiException(self::classifyHttp($status, $codes), $status, $codes, $detail, $retryAfter);
	}

	/**
	 * @param array<int, int> $codes
	 */
	private static function classifyHttp(int $status, array $codes): string {
		if ($status >= 500) {
			return ErrorClass::TRANSIENT;
		}

		switch ($status) {
			case 401:
				return ErrorClass::AUTH;
			case 403:
				return ErrorClass::PERMISSION;
			case 404:
				return ErrorClass::NOT_FOUND;
			case 429:
				return ErrorClass::RATE_LIMITED;
			case 400:
				if (array_intersect(self::BAD_HEADER_CODES, $codes) !== array()) {
					return ErrorClass::AUTH;
				}
		}

		return ErrorClass::MALFORMED;
	}

	/**
	 * Codes and messages from errors[] and each entry's nested error_chain.
	 *
	 * @param array<mixed> $body
	 *
	 * @return array{0: array<int, int>, 1: array<int, string>}
	 */
	private static function collectErrors(array $body): array {
		$codes    = array();
		$messages = array();

		$errors = isset($body['errors']) && is_array($body['errors']) ? $body['errors'] : array();
		foreach ($errors as $error) {
			if (!is_array($error)) {
				continue;
			}

			$entries = array($error);
			if (isset($error['error_chain']) && is_array($error['error_chain'])) {
				foreach ($error['error_chain'] as $link) {
					if (is_array($link)) {
						$entries[] = $link;
					}
				}
			}

			foreach ($entries as $entry) {
				if (isset($entry['code']) && is_int($entry['code'])) {
					$codes[] = $entry['code'];
				}
				if (isset($entry['message']) && is_string($entry['message'])) {
					$messages[] = $entry['message'];
				}
			}
		}

		return array($codes, $messages);
	}

	/**
	 * @param mixed $item
	 *
	 * @throws ApiException
	 */
	private static function parseTunnel($item): TunnelRecord {
		if (
			!is_array($item)
			|| !isset($item['id'], $item['status'])
			|| !is_string($item['id'])
			|| !is_string($item['status'])
			|| !in_array($item['status'], self::TUNNEL_STATUSES, true)
		) {
			throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'tunnel item has an unexpected shape');
		}

		$id = strtolower($item['id']);
		if (!Ids::isUuid($id)) {
			throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'tunnel item has an unexpected shape');
		}

		$name = isset($item['name']) && is_string($item['name']) ? self::cleanName($item['name']) : $id;
		$type = isset($item['tun_type']) && is_string($item['tun_type'])
			? self::clip(self::cleanName($item['tun_type']), self::MAX_SHORT)
			: '';

		return new TunnelRecord(
			$id,
			$name,
			$item['status'],
			$type,
			self::parseExpiry($item['created_at'] ?? null),
			self::parseExpiry($item['conns_active_at'] ?? null),
			self::parseExpiry($item['conns_inactive_at'] ?? null)
		);
	}

	/**
	 * @param mixed $item
	 *
	 * @throws ApiException
	 */
	private static function parseConnector($item): ConnectorRecord {
		if (
			!is_array($item)
			|| !isset($item['id'], $item['version'], $item['arch'], $item['conns'])
			|| !is_string($item['id'])
			|| !Ids::isUuid(strtolower($item['id']))
			|| !is_string($item['version'])
			|| !is_string($item['arch'])
			|| !is_array($item['conns'])
		) {
			throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'connector item has an unexpected shape');
		}

		$colos = array();
		$ips   = array();
		$count = 0;
		foreach ($item['conns'] as $conn) {
			if (!is_array($conn)) {
				continue;
			}
			$count++;
			if (isset($conn['colo_name']) && is_string($conn['colo_name']) && $conn['colo_name'] !== '') {
				$colos[$conn['colo_name']] = true;
			}
			if (isset($conn['origin_ip']) && is_string($conn['origin_ip'])
				&& filter_var($conn['origin_ip'], FILTER_VALIDATE_IP) !== false) {
				$ips[$conn['origin_ip']] = true;
			}
		}

		$colos = array_map('strval', array_keys($colos));
		$ips   = array_map('strval', array_keys($ips));
		sort($colos, SORT_STRING);
		sort($ips, SORT_STRING);

		return new ConnectorRecord(
			strtolower($item['id']),
			self::clip($item['version'], self::MAX_SHORT),
			self::clip($item['arch'], self::MAX_SHORT),
			self::parseExpiry($item['run_at'] ?? null),
			$count,
			self::fitList($colos),
			self::fitList($ips)
		);
	}

	/**
	 * Strips control, format (bidi overrides, zero-width) and line/paragraph
	 * separator characters. The u flag makes invalid UTF-8 return null, which
	 * Cloudflare should never send.
	 *
	 * @throws ApiException
	 */
	private static function cleanName(string $name): string {
		$clean = preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]/u', '', $name);
		if ($clean === null) {
			throw new ApiException(ErrorClass::MALFORMED, 200, array(), 'tunnel text is not valid UTF-8');
		}

		return self::clip($clean, self::MAX_NAME);
	}

	/* The columns count characters, so a multibyte string is cut by character where mbstring allows. */
	private static function clip(string $text, int $max): string {
		if (function_exists('mb_substr')) {
			return mb_substr($text, 0, $max, 'UTF-8');
		}

		return substr($text, 0, $max);
	}

	/**
	 * Keeps whole entries only: a value cut mid-entry would name a colo or
	 * address that does not exist.
	 *
	 * @param array<int, string> $entries
	 * @return array<int, string>
	 */
	private static function fitList(array $entries): array {
		$kept   = array();
		$length = -1;

		foreach ($entries as $entry) {
			$length += strlen($entry) + 1;
			if ($length > self::MAX_LIST) {
				break;
			}

			$kept[] = $entry;
		}

		return $kept;
	}

	private function requireAccountId(string $cfAccountId): void {
		if (!Ids::isAccountId($cfAccountId)) {
			throw new ApiException(ErrorClass::CONFIG, null, array(), 'invalid account id');
		}
	}

	/**
	 * @param mixed $value
	 */
	private static function parseExpiry($value): ?int {
		if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T/', $value) !== 1) {
			return null;
		}

		try {
			$timestamp = (new DateTimeImmutable($value))->getTimestamp();
		} catch (Exception $e) {
			return null;
		}

		/*
		 * The columns are INT UNSIGNED and Cacti clears STRICT modes from its
		 * session, so a value outside the range would be clamped silently and
		 * shown as a real date. Go's zero time lands here too.
		 */
		return $timestamp >= 0 && $timestamp <= self::MAX_TIMESTAMP ? $timestamp : null;
	}
}
