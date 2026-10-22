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

namespace Cacti\PluginCloudflare\Api;

use RuntimeException;

/**
 * getMessage() carries the error class, HTTP status, API codes and the API's
 * own messages. The caller passes those messages through Redactor first.
 */
final class ApiException extends RuntimeException {
	private const MAX_DETAIL = 240;

	/** @var string */
	private $errorClass;

	/** @var int|null */
	private $httpStatus;

	/** @var array<int, int> */
	private $apiCodes;

	/** @var int|null */
	private $retryAfter;

	/**
	 * @param array<int, int> $apiCodes
	 */
	public function __construct(
		string $errorClass,
		?int $httpStatus = null,
		array $apiCodes = array(),
		string $detail = '',
		?int $retryAfter = null
	) {
		$this->errorClass = $errorClass;
		$this->httpStatus = $httpStatus;
		$this->apiCodes   = array_values($apiCodes);
		$this->retryAfter = $retryAfter;

		$message = 'Cloudflare API error: class=' . $errorClass;
		if ($httpStatus !== null) {
			$message .= ' http=' . $httpStatus;
		}
		if ($this->apiCodes !== array()) {
			$message .= ' codes=' . implode(',', $this->apiCodes);
		}

		$detail = (string) preg_replace('/[^\x20-\x7e]/', '?', $detail);
		if ($detail !== '') {
			$message .= ' (' . substr($detail, 0, self::MAX_DETAIL) . ')';
		}

		parent::__construct($message);
	}

	public function getErrorClass(): string {
		return $this->errorClass;
	}

	public function getHttpStatus(): ?int {
		return $this->httpStatus;
	}

	/**
	 * @return array<int, int>
	 */
	public function getApiCodes(): array {
		return $this->apiCodes;
	}

	/** Seconds from a 429 retry-after header; null when absent or not an integer. */
	public function getRetryAfter(): ?int {
		return $this->retryAfter;
	}
}
