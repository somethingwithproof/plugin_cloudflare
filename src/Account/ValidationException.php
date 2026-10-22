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

namespace Cacti\PluginCloudflare\Account;

use RuntimeException;

/**
 * Error texts are fixed sentences or SecretException messages, neither of
 * which can contain submitted values.
 */
final class ValidationException extends RuntimeException {
	/** @var array<string, string> */
	private $errors;

	/**
	 * @param array<string, string> $errors keyed by input field
	 */
	public function __construct(array $errors) {
		$this->errors = $errors;

		parent::__construct('Account settings are invalid: ' . implode(', ', array_keys($errors)));
	}

	/**
	 * @return array<string, string>
	 */
	public function getErrors(): array {
		return $this->errors;
	}
}
