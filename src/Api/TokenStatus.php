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

final class TokenStatus {
	/** @var string */
	private $id;

	/** @var string */
	private $status;

	/** @var int|null */
	private $expiresOn;

	public function __construct(string $id, string $status, ?int $expiresOn) {
		$this->id        = $id;
		$this->status    = $status;
		$this->expiresOn = $expiresOn;
	}

	public function id(): string {
		return $this->id;
	}

	public function status(): string {
		return $this->status;
	}

	/** Epoch seconds, or null when Cloudflare reports no usable expiry. */
	public function expiresOn(): ?int {
		return $this->expiresOn;
	}

	public function isActive(): bool {
		return $this->status === 'active';
	}
}
