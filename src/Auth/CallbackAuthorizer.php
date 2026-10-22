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

namespace Cacti\PluginCloudflare\Auth;

/**
 * The plugin glue passes a closure over api_user_realm_auth() so src/ never
 * calls Cacti directly.
 */
final class CallbackAuthorizer implements Authorizer {
	/** @var callable(): bool */
	private $check;

	/**
	 * @param callable(): bool $check
	 */
	public function __construct(callable $check) {
		$this->check = $check;
	}

	public function canConfigure(): bool {
		return ($this->check)() === true;
	}
}
