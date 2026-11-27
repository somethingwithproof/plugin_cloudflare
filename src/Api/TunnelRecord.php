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

namespace Somethingwithproof\Cloudflare\Api;

/** Tunnel record component for plugin_cloudflare. */
final class TunnelRecord {
	/** @var string */
	public $id;

	/** @var string */
	public $name;

	/** @var string */
	public $status;

	/** @var string */
	public $tunType;

	/** @var int|null */
	public $createdAt;

	/** @var int|null */
	public $connsActiveAt;

	/** @var int|null */
	public $connsInactiveAt;

	/** PHP 7.4 has no readonly properties; the constructor is the only writer. */
	public function __construct(
		string $id,
		string $name,
		string $status,
		string $tunType,
		?int $createdAt,
		?int $connsActiveAt,
		?int $connsInactiveAt
	) {
		$this->id              = $id;
		$this->name            = $name;
		$this->status          = $status;
		$this->tunType         = $tunType;
		$this->createdAt       = $createdAt;
		$this->connsActiveAt   = $connsActiveAt;
		$this->connsInactiveAt = $connsInactiveAt;
	}
}
