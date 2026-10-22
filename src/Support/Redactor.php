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

namespace Cacti\PluginCloudflare\Support;

final class Redactor {
	private const MARK = '[REDACTED]';

	/**
	 * Longer secrets are replaced first so a secret that contains another is
	 * removed whole rather than leaving its remainder behind.
	 *
	 * @param array<int, string> $secrets
	 */
	public static function redact(string $text, array $secrets): string {
		$secrets = array_values(array_filter($secrets, function (string $s): bool {
			return $s !== '';
		}));
		usort($secrets, function (string $a, string $b): int {
			return strlen($b) - strlen($a);
		});

		foreach ($secrets as $secret) {
			$text = str_replace($secret, self::MARK, $text);
		}

		$scrubbed = preg_replace('/(Authorization:\s*Bearer\s+)\S+/i', '${1}' . self::MARK, $text);

		return $scrubbed === null ? self::MARK : $scrubbed;
	}
}
