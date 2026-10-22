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

final class TokenPolicy {
	/**
	 * Callers trim; surrounding whitespace is rejected here. The Global API
	 * Key check runs first so an operator gets the specific reason.
	 */
	public static function validate(
		#[\SensitiveParameter]
		string $token
	): void {
		if (preg_match('/^[0-9a-f]{37}\z/', $token) === 1) {
			throw new SecretException(SecretException::GLOBAL_KEY);
		}

		if (preg_match('/^[A-Za-z0-9_-]{40,256}\z/', $token) !== 1) {
			throw new SecretException(SecretException::INVALID);
		}
	}
}
