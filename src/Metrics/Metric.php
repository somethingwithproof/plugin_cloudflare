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

final class Metric {
	public const GAUGE   = 'GAUGE';
	public const COUNTER = 'COUNTER';

	public const KIND_STATE      = 'state';
	public const KIND_COLLECTION = 'collection';

	/** @var string */
	private $name;

	/** @var string */
	private $queryField;

	/** @var string */
	private $dsName;

	/** @var string */
	private $type;

	/** @var string */
	private $unit;

	/** @var int */
	private $min;

	/** @var int|null */
	private $max;

	/** @var string */
	private $kind;

	/**
	 * @param int|null $max null is rrdtool's U, no upper bound
	 */
	public function __construct(
		string $name,
		string $queryField,
		string $dsName,
		string $type,
		string $unit,
		int $min,
		?int $max,
		string $kind
	) {
		$this->name       = $name;
		$this->queryField = $queryField;
		$this->dsName     = $dsName;
		$this->type       = $type;
		$this->unit       = $unit;
		$this->min        = $min;
		$this->max        = $max;
		$this->kind       = $kind;
	}

	public function name(): string {
		return $this->name;
	}

	public function queryField(): string {
		return $this->queryField;
	}

	public function dsName(): string {
		return $this->dsName;
	}

	public function type(): string {
		return $this->type;
	}

	public function unit(): string {
		return $this->unit;
	}

	public function min(): int {
		return $this->min;
	}

	public function max(): ?int {
		return $this->max;
	}

	public function kind(): string {
		return $this->kind;
	}
}
