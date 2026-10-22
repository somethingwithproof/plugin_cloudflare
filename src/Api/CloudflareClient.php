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
			return (new DateTimeImmutable($value))->getTimestamp();
		} catch (Exception $e) {
			return null;
		}
	}
}
