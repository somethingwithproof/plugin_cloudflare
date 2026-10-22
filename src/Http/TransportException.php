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

use RuntimeException;

/**
 * getMessage() is built only from the error class and a reason identifier
 * such as CURLE_OPERATION_TIMEDOUT, so it is always safe to log. The curl
 * error text goes to getDetail(), which the transport has already passed
 * through Redactor.
 */
final class TransportException extends RuntimeException {
	public const TRANSIENT = 'transient';
	public const CONFIG    = 'config';

	private const MESSAGES = array(
		self::TRANSIENT => 'Cloudflare API request failed at the network level',
		self::CONFIG    => 'Cloudflare API request refused before any network I/O',
	);

	/** @var string */
	private $errorClass;

	/** @var string */
	private $detail;

	public function __construct(string $errorClass, string $reason, string $detail = '') {
		if (!isset(self::MESSAGES[$errorClass])) {
			$errorClass = self::CONFIG;
		}
		if (preg_match('/^[A-Za-z0-9_]{1,64}\z/', $reason) !== 1) {
			$reason = 'unknown';
		}

		$this->errorClass = $errorClass;
		$this->detail     = $reason . ($detail === '' ? '' : ': ' . $detail);

		parent::__construct(self::MESSAGES[$errorClass] . ' (' . $reason . ')');
	}

	public function getErrorClass(): string {
		return $this->errorClass;
	}

	public function getDetail(): string {
		return $this->detail;
	}
}
