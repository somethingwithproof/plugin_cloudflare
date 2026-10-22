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

namespace Cacti\PluginCloudflare\Storage;

use RuntimeException;
use Throwable;

/**
 * The message carries the SQLSTATE and the driver text. Bound values are never
 * passed in, so they cannot leak through here.
 */
final class DatabaseException extends RuntimeException {
	/** @var string */
	private $sqlState;

	public function __construct(string $sqlState, string $driverMessage, ?Throwable $previous = null) {
		$this->sqlState = $sqlState;

		parent::__construct('SQLSTATE[' . $sqlState . ']: ' . $driverMessage, 0, $previous);
	}

	public function getSqlState(): string {
		return $this->sqlState;
	}
}
