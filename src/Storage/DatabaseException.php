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

/**
 * The message is exactly "SQLSTATE[<state>] driver error <code>". The driver
 * text is dropped for every SQLSTATE class because MySQL echoes the statement
 * text in it, and with emulated prepares that text holds the interpolated
 * bound values.
 */
final class DatabaseException extends RuntimeException {
	/** @var string */
	private $sqlState;

	/** @var int */
	private $driverCode;

	public function __construct(string $sqlState, int $driverCode) {
		$this->sqlState   = $sqlState;
		$this->driverCode = $driverCode;

		parent::__construct('SQLSTATE[' . $sqlState . '] driver error ' . $driverCode);
	}

	public function getSqlState(): string {
		return $this->sqlState;
	}

	public function getDriverCode(): int {
		return $this->driverCode;
	}
}
