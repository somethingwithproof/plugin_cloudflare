<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_cloudflare, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_cloudflare
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

namespace Somethingwithproof\Cloudflare\Storage;

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

	/**
	 * Initialize DatabaseException.
	 *
	 * @param string $sqlState
	 * @param int $driverCode
	 */
	public function __construct(string $sqlState, int $driverCode) {
		$this->sqlState   = $sqlState;
		$this->driverCode = $driverCode;

		parent::__construct('SQLSTATE[' . $sqlState . '] driver error ' . $driverCode);
	}

	/**
	 * Get SQL state.
	 *
	 * @return string
	 */
	public function getSqlState(): string {
		return $this->sqlState;
	}

	/**
	 * Get driver code.
	 *
	 * @return int
	 */
	public function getDriverCode(): int {
		return $this->driverCode;
	}
}
