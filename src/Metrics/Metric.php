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

/** Metric component for plugin_cloudflare. */
final class Metric {
	/** @var string Gauge. */
	public const GAUGE   = 'GAUGE';
	/** @var string Counter. */
	public const COUNTER = 'COUNTER';

	/** @var string Kind state. */
	public const KIND_STATE      = 'state';
	/** @var string Kind collection. */
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

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Query field.
	 *
	 * @return string
	 */
	public function queryField(): string {
		return $this->queryField;
	}

	/**
	 * DS name.
	 *
	 * @return string
	 */
	public function dsName(): string {
		return $this->dsName;
	}

	/**
	 * Type.
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Unit.
	 *
	 * @return string
	 */
	public function unit(): string {
		return $this->unit;
	}

	/**
	 * Min.
	 *
	 * @return int
	 */
	public function min(): int {
		return $this->min;
	}

	/**
	 * Max.
	 *
	 * @return ?int
	 */
	public function max(): ?int {
		return $this->max;
	}

	/**
	 * Kind.
	 *
	 * @return string
	 */
	public function kind(): string {
		return $this->kind;
	}
}
