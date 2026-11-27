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

namespace Somethingwithproof\Cloudflare\Metrics;

use InvalidArgumentException;

/**
 * One metric for one tunnel at read time. A null value always carries a
 * reason, so the caller can print U and still say why.
 */
final class Observation {
	/** @var string */
	private $metric;

	/** @var int|null */
	private $value;

	/** @var int|null */
	private $observedAt;

	/** @var string|null */
	private $reason;

	/** @var int */
	private $schemaVersion;

	/**
	 * Initialize Observation.
	 *
	 * @param string $metric
	 * @param ?int $value
	 * @param ?int $observedAt
	 * @param ?string $reason
	 * @param int $schemaVersion
	 */
	public function __construct(string $metric, ?int $value, ?int $observedAt, ?string $reason, int $schemaVersion) {
		if (($value === null) === ($reason === null)) {
			throw new InvalidArgumentException('an observation has a value or a reason, not both');
		}

		$this->metric        = $metric;
		$this->value         = $value;
		$this->observedAt    = $observedAt;
		$this->reason        = $reason;
		$this->schemaVersion = $schemaVersion;
	}

	/**
	 * Metric.
	 *
	 * @return string
	 */
	public function metric(): string {
		return $this->metric;
	}

	/**
	 * Value.
	 *
	 * @return ?int
	 */
	public function value(): ?int {
		return $this->value;
	}

	/**
	 * Observed at.
	 *
	 * @return ?int
	 */
	public function observedAt(): ?int {
		return $this->observedAt;
	}

	/**
	 * Reason.
	 *
	 * @return ?string
	 */
	public function reason(): ?string {
		return $this->reason;
	}

	/**
	 * Schema version.
	 *
	 * @return int
	 */
	public function schemaVersion(): int {
		return $this->schemaVersion;
	}

	/**
	 * RRD value.
	 *
	 * @return string
	 */
	public function rrdValue(): string {
		return $this->value === null ? 'U' : (string) $this->value;
	}
}
