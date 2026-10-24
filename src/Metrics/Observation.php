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

namespace Cacti\PluginCloudflare\Metrics;

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

	public function metric(): string {
		return $this->metric;
	}

	public function value(): ?int {
		return $this->value;
	}

	public function observedAt(): ?int {
		return $this->observedAt;
	}

	public function reason(): ?string {
		return $this->reason;
	}

	public function schemaVersion(): int {
		return $this->schemaVersion;
	}

	public function rrdValue(): string {
		return $this->value === null ? 'U' : (string) $this->value;
	}
}
