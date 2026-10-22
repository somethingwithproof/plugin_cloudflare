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

namespace Cacti\PluginCloudflare\Secret;

final class TokenFile {
	private const MAX_BYTES = 4096;

	/**
	 * The mode and size checks use fstat() on the open handle so they describe
	 * the file actually read. is_file() runs first so a FIFO cannot block
	 * fopen().
	 */
	public static function read(string $path): string {
		if (strpos($path, "\0") !== false) {
			throw new SecretException(SecretException::FILE_UNAVAILABLE);
		}

		if ($path === '' || $path[0] !== '/' || is_link($path)) {
			throw new SecretException(SecretException::FILE_INSECURE, $path);
		}

		if (!is_file($path)) {
			throw new SecretException(SecretException::FILE_UNAVAILABLE, $path);
		}

		$fh = @fopen($path, 'rb');
		if ($fh === false) {
			throw new SecretException(SecretException::FILE_UNAVAILABLE, $path);
		}

		try {
			$stat = fstat($fh);
			if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] > self::MAX_BYTES) {
				throw new SecretException(SecretException::FILE_UNAVAILABLE, $path);
			}

			if (($stat['mode'] & 0006) !== 0) {
				throw new SecretException(SecretException::FILE_INSECURE, $path);
			}

			$raw = stream_get_contents($fh, self::MAX_BYTES + 1);
		} finally {
			fclose($fh);
		}

		if ($raw === false || strlen($raw) > self::MAX_BYTES) {
			throw new SecretException(SecretException::FILE_UNAVAILABLE, $path);
		}

		$token = trim($raw);
		if (function_exists('sodium_memzero')) {
			sodium_memzero($raw);
		}

		TokenPolicy::validate($token);

		return $token;
	}
}
