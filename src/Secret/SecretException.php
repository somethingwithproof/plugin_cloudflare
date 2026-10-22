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

namespace Cacti\PluginCloudflare\Secret;

use RuntimeException;

/**
 * Messages are fixed per reason so no token, key or file content can reach a
 * log through getMessage(). A path is appended only when it is absolute and
 * free of control characters: a token never contains '/', so an operator who
 * pastes a token into the path field does not get it echoed back.
 */
final class SecretException extends RuntimeException {
	public const GLOBAL_KEY       = 1;
	public const INVALID          = 2;
	public const KEY_UNAVAILABLE  = 3;
	public const DECRYPT_FAILED   = 4;
	public const FILE_UNAVAILABLE = 5;
	public const FILE_INSECURE    = 6;
	public const ENCRYPT_FAILED   = 7;

	private const REASONS = array(
		self::GLOBAL_KEY       => 'global_key',
		self::INVALID          => 'invalid',
		self::KEY_UNAVAILABLE  => 'key_unavailable',
		self::DECRYPT_FAILED   => 'decrypt_failed',
		self::FILE_UNAVAILABLE => 'file_unavailable',
		self::FILE_INSECURE    => 'file_insecure',
		self::ENCRYPT_FAILED   => 'encrypt_failed',
	);

	private const MESSAGES = array(
		self::GLOBAL_KEY       => 'Global API Keys are not accepted; use a scoped API token',
		self::INVALID          => 'API token format is invalid',
		self::KEY_UNAVAILABLE  => 'Token vault key file is missing, unreadable, insecure or malformed',
		self::DECRYPT_FAILED   => 'Stored API token could not be decrypted',
		self::FILE_UNAVAILABLE => 'Token file is missing, unreadable, not a regular file or too large',
		self::FILE_INSECURE    => 'Token file path is not absolute, is a symlink, or the file is world-readable or world-writable',
		self::ENCRYPT_FAILED   => 'API token could not be encrypted',
	);

	public function __construct(int $code, string $path = '') {
		$message = self::MESSAGES[$code] ?? 'Credential error';

		if (preg_match('#^/[^\x00-\x1f\x7f]{0,1023}\z#', $path) === 1) {
			$message .= ': ' . $path;
		}

		parent::__construct($message, $code);
	}

	public function getReason(): string {
		return self::REASONS[$this->getCode()] ?? 'unknown';
	}
}
