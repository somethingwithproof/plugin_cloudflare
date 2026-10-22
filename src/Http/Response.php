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

final class Response {
	/** @var int */
	private $status;

	/** @var array<string, string> keyed by lowercase name */
	private $headers = array();

	/** @var string */
	private $body;

	/**
	 * @param array<string, string> $headers
	 */
	public function __construct(int $status, array $headers, string $body) {
		$this->status = $status;
		$this->body   = $body;

		foreach ($headers as $name => $value) {
			$this->headers[strtolower((string) $name)] = (string) $value;
		}
	}

	public function status(): int {
		return $this->status;
	}

	public function header(string $name): ?string {
		return $this->headers[strtolower($name)] ?? null;
	}

	public function body(): string {
		return $this->body;
	}
}
