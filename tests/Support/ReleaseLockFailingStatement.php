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

namespace Cacti\PluginCloudflare\Tests\Support;

use PDOException;
use PDOStatement;

/**
 * Install with PDO::ATTR_STATEMENT_CLASS to make RELEASE_LOCK fail the way
 * a dropped connection does, while every other statement runs normally.
 */
final class ReleaseLockFailingStatement extends PDOStatement {
	/* PDO refuses a statement class with a public constructor. */
	protected function __construct() {
	}

	/**
	 * @param array<int|string, mixed>|null $params
	 */
	public function execute($params = null): bool {
		if (strpos($this->queryString, 'RELEASE_LOCK') !== false) {
			$e = new PDOException('MySQL server has gone away');
			$e->errorInfo = array('HY000', 2006, 'MySQL server has gone away');

			throw $e;
		}

		return parent::execute($params);
	}
}
