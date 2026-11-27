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
 * Bounded-memory frequency sketch for streaming telemetry (top ASNs, URL paths,
 * and WAF traffic spikes) implementing the Count-Min Sketch of Cormode and
 * Muthukrishnan (Journal of Algorithms, 2005).
 *
 * For non-negative updates, estimates never underestimate the true count and
 * satisfy estimate(x) <= true(x) + epsilon * N with probability at least
 * 1 - delta when sized via fromErrorBounds(epsilon, delta). Uses Kirsch and
 * Mitzenmacher double hashing over a FIPS-safe SHA-256 digest.
 */
final class CountMinSketch {
	/** @var int Maximum supported sketch width (columns per row). */
	public const MAX_WIDTH = 1048576;

	/** @var int Maximum supported sketch depth (independent hash rows). */
	public const MAX_DEPTH = 64;

	/** @var int Maximum total cell count allowed per sketch instance. */
	public const MAX_CELLS = 4194304;

	/** @var int Number of columns (w). */
	private $width;

	/** @var int Number of rows (d). */
	private $depth;

	/** @var int Sum of all counts added to the sketch (L1 norm N). */
	private $totalCount = 0;

	/** @var array<int, array<int, int>> d x w counter matrix. */
	private $rows;

	/**
	 * Create a Count-Min Sketch with explicit table dimensions.
	 *
	 * @param int $width Number of columns (w >= 1)
	 * @param int $depth Number of hash rows (d >= 1)
	 * @throws InvalidArgumentException When dimensions are out of valid bounds
	 */
	public function __construct(int $width, int $depth) {
		if ($width < 1 || $width > self::MAX_WIDTH) {
			throw new InvalidArgumentException('Sketch width must be between 1 and ' . self::MAX_WIDTH);
		}

		if ($depth < 1 || $depth > self::MAX_DEPTH) {
			throw new InvalidArgumentException('Sketch depth must be between 1 and ' . self::MAX_DEPTH);
		}

		if ($width * $depth > self::MAX_CELLS) {
			throw new InvalidArgumentException('Sketch cell count exceeds maximum of ' . self::MAX_CELLS);
		}

		$this->width = $width;
		$this->depth = $depth;
		$this->rows  = array();

		for ($row = 0; $row < $depth; $row++) {
			$this->rows[$row] = array_fill(0, $width, 0);
		}
	}

	/**
	 * Construct a sketch sized from Cormode-Muthukrishnan error parameters:
	 * width w = ceil(e / epsilon) and depth d = ceil(ln(1 / delta)).
	 *
	 * @param float $epsilon Additive error factor in (0, 1)
	 * @param float $delta   Failure probability in (0, 1)
	 * @return self
	 * @throws InvalidArgumentException When epsilon or delta is outside (0, 1)
	 */
	public static function fromErrorBounds(float $epsilon, float $delta): self {
		if (!is_finite($epsilon) || $epsilon <= 0.0 || $epsilon >= 1.0) {
			throw new InvalidArgumentException('epsilon must be strictly between 0 and 1');
		}

		if (!is_finite($delta) || $delta <= 0.0 || $delta >= 1.0) {
			throw new InvalidArgumentException('delta must be strictly between 0 and 1');
		}

		$width = max(1, (int) ceil(M_E / $epsilon));
		$depth = max(1, (int) ceil(log(1.0 / $delta)));

		return new self($width, $depth);
	}

	/**
	 * Increment the frequency counter for a key.
	 *
	 * @param string $key   Stream key (such as an ASN, IP prefix, or URL path)
	 * @param int    $count Non-negative increment
	 * @return void
	 * @throws InvalidArgumentException When $count is negative
	 */
	public function add(string $key, int $count = 1): void {
		if ($count < 0) {
			throw new InvalidArgumentException('Count increment must be non-negative');
		}

		if ($count === 0) {
			return;
		}

		$pair = self::hashPair($key);
		$h1   = $pair[0] % $this->width;
		$h2   = $pair[1] % $this->width;

		for ($row = 0; $row < $this->depth; $row++) {
			$col = ($h1 + (($row * $h2) % $this->width)) % $this->width;
			$this->rows[$row][$col] += $count;
		}

		$this->totalCount += $count;
	}

	/**
	 * Return the Count-Min point estimate (minimum across all d rows) for a key.
	 *
	 * @param string $key Stream key
	 * @return int Estimated frequency (>= true count)
	 */
	public function estimate(string $key): int {
		$pair = self::hashPair($key);
		$h1   = $pair[0] % $this->width;
		$h2   = $pair[1] % $this->width;
		$min  = null;

		for ($row = 0; $row < $this->depth; $row++) {
			$col   = ($h1 + (($row * $h2) % $this->width)) % $this->width;
			$value = $this->rows[$row][$col];

			if ($min === null || $value < $min) {
				$min = $value;
			}
		}

		return $min === null ? 0 : $min;
	}

	/**
	 * Return a new sketch representing the element-wise sum of this sketch and another.
	 *
	 * @param self $other Sketch with matching width and depth
	 * @return self Merged sketch instance
	 * @throws InvalidArgumentException When dimensions do not match
	 */
	public function merge(self $other): self {
		if ($this->width !== $other->width || $this->depth !== $other->depth) {
			throw new InvalidArgumentException('Cannot merge sketches with different dimensions');
		}

		$merged             = new self($this->width, $this->depth);
		$merged->totalCount = $this->totalCount + $other->totalCount;

		for ($row = 0; $row < $this->depth; $row++) {
			for ($col = 0; $col < $this->width; $col++) {
				$merged->rows[$row][$col] = $this->rows[$row][$col] + $other->rows[$row][$col];
			}
		}

		return $merged;
	}

	/**
	 * Return the number of columns (w).
	 *
	 * @return int
	 */
	public function width(): int {
		return $this->width;
	}

	/**
	 * Return the number of hash rows (d).
	 *
	 * @return int
	 */
	public function depth(): int {
		return $this->depth;
	}

	/**
	 * Return the sum of all added counts (total stream weight N).
	 *
	 * @return int
	 */
	public function totalCount(): int {
		return $this->totalCount;
	}

	/**
	 * Return the effective additive error bound e / w.
	 *
	 * @return float
	 */
	public function epsilon(): float {
		return M_E / (float) $this->width;
	}

	/**
	 * Return the effective failure probability bound exp(-d).
	 *
	 * @return float
	 */
	public function delta(): float {
		return exp((float) (-$this->depth));
	}

	/**
	 * Serialize the sketch into a deterministic plain array.
	 *
	 * @return array{width: int, depth: int, total_count: int, rows: array<int, array<int, int>>}
	 */
	public function toArray(): array {
		return array(
			'width'       => $this->width,
			'depth'       => $this->depth,
			'total_count' => $this->totalCount,
			'rows'        => $this->rows,
		);
	}

	/**
	 * Reconstruct a sketch from an array previously produced by toArray().
	 *
	 * @param array<string, mixed> $data Serialized sketch payload
	 * @return self
	 * @throws InvalidArgumentException When required fields or dimensions are invalid
	 */
	public static function fromArray(array $data): self {
		if (!isset($data['width'], $data['depth'], $data['total_count'], $data['rows'])) {
			throw new InvalidArgumentException('Serialized CountMinSketch is missing required fields');
		}

		if (!is_int($data['width']) || !is_int($data['depth']) || !is_int($data['total_count']) || !is_array($data['rows'])) {
			throw new InvalidArgumentException('Serialized CountMinSketch fields have invalid types');
		}

		if ($data['total_count'] < 0) {
			throw new InvalidArgumentException('Serialized CountMinSketch total_count must be non-negative');
		}

		$sketch = new self($data['width'], $data['depth']);

		if (count($data['rows']) !== $sketch->depth) {
			throw new InvalidArgumentException('Serialized CountMinSketch row count does not match depth');
		}

		$rows = array();

		for ($r = 0; $r < $sketch->depth; $r++) {
			if (!isset($data['rows'][$r]) || !is_array($data['rows'][$r]) || count($data['rows'][$r]) !== $sketch->width) {
				throw new InvalidArgumentException('Serialized CountMinSketch row ' . $r . ' does not match width');
			}

			$row = array();

			for ($c = 0; $c < $sketch->width; $c++) {
				if (!array_key_exists($c, $data['rows'][$r]) || !is_int($data['rows'][$r][$c]) || $data['rows'][$r][$c] < 0) {
					throw new InvalidArgumentException('Serialized CountMinSketch cells must be non-negative integers');
				}

				$row[$c] = $data['rows'][$r][$c];
			}

			$rows[$r] = $row;
		}

		$sketch->rows       = $rows;
		$sketch->totalCount = $data['total_count'];

		return $sketch;
	}

	/**
	 * Derive two deterministic 31-bit non-negative hashes from SHA-256 for
	 * Kirsch-Mitzenmacher double hashing without 32-bit sign overflow.
	 *
	 * @param string $key Stream key
	 * @return array{0: int, 1: int} Pair of non-negative 31-bit integers (second is odd)
	 */
	private static function hashPair(string $key): array {
		$digest = hash('sha256', $key, true);
		$parts  = unpack('N2h', substr($digest, 0, 8));

		if (!is_array($parts) || !isset($parts['h1'], $parts['h2'])) {
			// @codeCoverageIgnoreStart
			return array(0, 1);
			// @codeCoverageIgnoreEnd
		}

		$h1 = ((int) $parts['h1']) & 0x7fffffff;
		$h2 = (((int) $parts['h2']) & 0x7fffffff) | 1;

		return array($h1, $h2);
	}
}
