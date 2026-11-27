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

namespace Somethingwithproof\Cloudflare\Jobs;

/**
 * Proof of a lease taken by JobRepository::lease(). Writes through it match
 * the row only while it still carries this owner and expiry.
 */
final class Lease {
	/** @var int */
	private $jobId;

	/** @var string */
	private $owner;

	/** @var int */
	private $leaseUntil;

	/**
	 * Initialize Lease.
	 *
	 * @param int $jobId
	 * @param string $owner
	 * @param int $leaseUntil
	 */
	public function __construct(int $jobId, string $owner, int $leaseUntil) {
		$this->jobId      = $jobId;
		$this->owner      = $owner;
		$this->leaseUntil = $leaseUntil;
	}

	/**
	 * Job ID.
	 *
	 * @return int
	 */
	public function jobId(): int {
		return $this->jobId;
	}

	/**
	 * Owner.
	 *
	 * @return string
	 */
	public function owner(): string {
		return $this->owner;
	}

	/**
	 * Lease until.
	 *
	 * @return int
	 */
	public function leaseUntil(): int {
		return $this->leaseUntil;
	}
}
