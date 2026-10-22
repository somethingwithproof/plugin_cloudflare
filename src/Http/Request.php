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

namespace Cacti\PluginCloudflare\Http;

use LogicException;

final class Request {
	/** @var string */
	private $method;

	/** @var string */
	private $url;

	/** @var array<string, string> */
	private $headers;

	/** @var string|null */
	private $body;

	/**
	 * Header names must be RFC 7230 tokens and values may not hold CR, LF or
	 * NUL, so a caller cannot smuggle an extra header or a second request
	 * into CURLOPT_HTTPHEADER.
	 *
	 * @param array<string, string> $headers
	 */
	public function __construct(
		string $method,
		string $url,
		#[\SensitiveParameter]
		array $headers = array(),
		?string $body = null
	) {
		if (preg_match('/^[A-Z]{1,16}\z/', $method) !== 1) {
			throw new TransportException(TransportException::CONFIG, 'invalid_method');
		}

		foreach ($headers as $name => $value) {
			if (!is_string($name) || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]{1,128}\z/', $name) !== 1) {
				throw new TransportException(TransportException::CONFIG, 'invalid_header_name');
			}
			if (!is_string($value) || preg_match('/[\r\n\0]/', $value) === 1) {
				throw new TransportException(TransportException::CONFIG, 'invalid_header_value');
			}
		}

		$this->method  = $method;
		$this->url     = $url;
		$this->headers = $headers;
		$this->body    = $body;
	}

	public function method(): string {
		return $this->method;
	}

	public function url(): string {
		return $this->url;
	}

	/**
	 * @return array<string, string>
	 */
	public function headers(): array {
		return $this->headers;
	}

	public function body(): ?string {
		return $this->body;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function __debugInfo(): array {
		return array(
			'method'  => $this->method,
			'url'     => $this->url,
			'headers' => array_fill_keys(array_keys($this->headers), '[REDACTED]'),
			'body'    => $this->body === null ? null : '[REDACTED]',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function __sleep(): array {
		throw new LogicException('Request cannot be serialized');
	}

	public function __wakeup(): void {
		throw new LogicException('Request cannot be unserialized');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function __serialize(): array {
		throw new LogicException('Request cannot be serialized');
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function __unserialize(array $data): void {
		throw new LogicException('Request cannot be unserialized');
	}
}
