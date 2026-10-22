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
 * The message carries the SQLSTATE and the driver error code. Driver text is
 * omitted for SQLSTATE classes 22 (data exception) and 23 (integrity
 * constraint), whose messages echo the offending value, for example
 * "Duplicate entry '<value>'". For every other class the text is appended with
 * each quoted literal replaced by '?', since any driver message may quote
 * statement content. Bound values are never passed in.
 */
final class DatabaseException extends RuntimeException {
	/** @var string */
	private $sqlState;

	public function __construct(string $sqlState, int $driverCode, string $driverMessage) {
		$this->sqlState = $sqlState;

		$message = 'SQLSTATE[' . $sqlState . '] driver error ' . $driverCode;

		if (!in_array(substr($sqlState, 0, 2), array('22', '23'), true)) {
			$masked = preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/s', "'?'", $driverMessage);
			$message .= ': ' . ($masked === null ? '?' : $masked);
		}

		parent::__construct($message);
	}

	public function getSqlState(): string {
		return $this->sqlState;
	}
}
