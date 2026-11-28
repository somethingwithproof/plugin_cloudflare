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
 * Bounded-memory distinct-element cardinality estimator implementing the
 * HyperLogLog algorithm of Flajolet, Fusy, Gandouet, and Meunier (DMTCS
 * Proceedings AH, AofA 2007, pp. 127-146).
 *
 * Maintains m = 2^p 6-bit-range registers (p in 4..16), applies the alpha_m
 * bias correction constant, uses LinearCounting when E <= 2.5 * m and empty
 * registers remain, and applies the 32-bit large-range collision correction
 * above (1/30) * 2^32. Uses FIPS-safe SHA-256 digests.
 */
final class HyperLogLog {
	/** @var int Minimum supported register precision p (m = 16). */
	public const MIN_PRECISION = 4;

	/** @var int Maximum supported register precision p (m = 65536). */
	public const MAX_PRECISION = 16;

	/** @var int Default register precision p (m = 4096, ~1.625% standard error). */
	public const DEFAULT_PRECISION = 12;

	/** @var float 2^32 hash space size from Flajolet et al. (2007). */
	private const TWO_TO_32 = 4294967296.0;

	/** @var array<int, float> Precomputed 2^(-r) table for register ranks 0..32 (avoids pow() in count()). */
	private const INV_POW2 = array(
		0  => 1.0,
		1  => 0.5,
		2  => 0.25,
		3  => 0.125,
		4  => 0.0625,
		5  => 0.03125,
		6  => 0.015625,
		7  => 0.0078125,
		8  => 0.00390625,
		9  => 0.001953125,
		10 => 0.0009765625,
		11 => 0.00048828125,
		12 => 0.000244140625,
		13 => 0.0001220703125,
		14 => 6.103515625e-5,
		15 => 3.0517578125e-5,
		16 => 1.52587890625e-5,
		17 => 7.62939453125e-6,
		18 => 3.814697265625e-6,
		19 => 1.9073486328125e-6,
		20 => 9.5367431640625e-7,
		21 => 4.76837158203125e-7,
		22 => 2.384185791015625e-7,
		23 => 1.1920928955078125e-7,
		24 => 5.960464477539063e-8,
		25 => 2.9802322387695312e-8,
		26 => 1.4901161193847656e-8,
		27 => 7.450580596923828e-9,
		28 => 3.725290298461914e-9,
		29 => 1.862645149230957e-9,
		30 => 9.313225746154785e-10,
		31 => 4.656612873077393e-10,
		32 => 2.3283064365386963e-10,
	);

	/** @var int Precision parameter p in [4, 16]. */
	private $precision;

	/** @var int Register count m = 2^p. */
	private $registerCount;

	/** @var int Bitmask (m - 1) for register indexing. */
	private $indexMask;

	/** @var int Remaining hash width (32 - p). */
	private $remainingBits;

	/** @var int Bitmask ((1 << (32 - p)) - 1) for suffix extraction. */
	private $suffixMask;

	/** @var float Bias correction constant alpha_m. */
	private $alpha;

	/** @var string Packed 1-byte-per-register buffer of length m = 2^p. */
	private $registers;

	/**
	 * Create a HyperLogLog sketch with precision p (m = 2^p registers).
	 *
	 * @param int $precision Precision p in [4, 16]
	 * @throws InvalidArgumentException When $precision is outside [4, 16]
	 */
	public function __construct(int $precision = self::DEFAULT_PRECISION) {
		if ($precision < self::MIN_PRECISION || $precision > self::MAX_PRECISION) {
			throw new InvalidArgumentException(
				'HyperLogLog precision must be between ' . self::MIN_PRECISION . ' and ' . self::MAX_PRECISION
			);
		}

		$this->precision     = $precision;
		$this->registerCount = 1 << $precision;
		$this->indexMask     = $this->registerCount - 1;
		$this->remainingBits = 32 - $precision;
		$this->suffixMask    = (1 << $this->remainingBits) - 1;
		$this->alpha         = self::alphaFor($this->registerCount);
		$this->registers     = str_repeat("\x00", $this->registerCount);
	}

	/**
	 * Observe a stream value and update its target register.
	 *
	 * @param string $value Distinct key (such as client IP, ASN, or URL)
	 * @return void
	 */
	public function add(string $value): void {
		$hash   = self::hash32($value);
		$index  = ($hash >> $this->remainingBits) & $this->indexMask;
		$suffix = $hash & $this->suffixMask;
		$rank   = self::rho($suffix, $this->remainingBits);

		if ($rank > ord($this->registers[$index])) {
			$this->registers[$index] = chr($rank);
		}
	}

	/**
	 * Estimate the distinct cardinality using harmonic mean plus Flajolet et al.
	 * small-range LinearCounting and large-range collision corrections.
	 *
	 * @return float Estimated distinct cardinality
	 */
	public function count(): float {
		$m           = (float) $this->registerCount;
		$histogram   = count_chars($this->registers, 1);
		$zeroCount   = $histogram[0] ?? 0;
		$harmonicSum = 0.0;

		foreach ($histogram as $reg => $freq) {
			$harmonicSum += (float) $freq * self::INV_POW2[$reg];
		}

		$raw = $this->alpha * $m * $m / $harmonicSum;

		// Small-range correction (LinearCounting)
		if ($raw <= 2.5 * $m) {
			if ($zeroCount > 0) {
				return $m * log($m / (float) $zeroCount);
			}

			return $raw;
		}

		// Intermediate range (no correction)
		if ($raw <= self::TWO_TO_32 / 30.0) {
			return $raw;
		}

		// Large-range correction (32-bit hash collision compensation)
		if ($raw >= self::TWO_TO_32) {
			return self::TWO_TO_32;
		}

		return -self::TWO_TO_32 * log(1.0 - ($raw / self::TWO_TO_32));
	}

	/**
	 * Return the rounded integer cardinality estimate.
	 *
	 * @return int
	 */
	public function estimate(): int {
		return (int) round($this->count());
	}

	/**
	 * Merge another HyperLogLog of the same precision by element-wise maximum.
	 *
	 * @param self $other Sketch with the same precision
	 * @return self New merged sketch
	 * @throws InvalidArgumentException When precisions differ
	 */
	public function merge(self $other): self {
		if ($this->precision !== $other->precision) {
			throw new InvalidArgumentException('Cannot merge HyperLogLog sketches with different precisions');
		}

		$merged = clone $this;
		$merged->mergeInPlace($other);

		return $merged;
	}

	/**
	 * Merge another HyperLogLog of the same precision into this sketch in place
	 * (for multi-poller fan-in aggregation without repeated allocations).
	 *
	 * @param self $other Sketch with the same precision
	 * @return void
	 * @throws InvalidArgumentException When precisions differ
	 */
	public function mergeInPlace(self $other): void {
		if ($this->precision !== $other->precision) {
			throw new InvalidArgumentException('Cannot merge HyperLogLog sketches with different precisions');
		}

		$target = $this->registers;
		$source = $other->registers;
		$len    = $this->registerCount;

		for ($i = 0; $i < $len; $i++) {
			if ($source[$i] > $target[$i]) {
				$target[$i] = $source[$i];
			}
		}

		$this->registers = $target;
	}

	/**
	 * Return the precision parameter p.
	 *
	 * @return int
	 */
	public function precision(): int {
		return $this->precision;
	}

	/**
	 * Return the number of registers m = 2^p.
	 *
	 * @return int
	 */
	public function registerCount(): int {
		return $this->registerCount;
	}

	/**
	 * Return the theoretical relative standard error 1.04 / sqrt(m).
	 *
	 * @return float
	 */
	public function relativeError(): float {
		return 1.04 / sqrt((float) $this->registerCount);
	}

	/**
	 * Serialize the sketch into a deterministic plain array.
	 *
	 * @return array{precision: int, registers: array<int, int>}
	 */
	public function toArray(): array {
		$unpacked = unpack('C*', $this->registers);

		return array(
			'precision' => $this->precision,
			'registers' => is_array($unpacked) ? array_values($unpacked) : array(),
		);
	}

	/**
	 * Reconstruct a HyperLogLog sketch from an array produced by toArray().
	 *
	 * @param array<string, mixed> $data Serialized sketch payload
	 * @return self
	 * @throws InvalidArgumentException When payload fields or register bounds are invalid
	 */
	public static function fromArray(array $data): self {
		if (!isset($data['precision'], $data['registers']) || !is_int($data['precision']) || !is_array($data['registers'])) {
			throw new InvalidArgumentException('Serialized HyperLogLog must include integer precision and registers array');
		}

		$hll      = new self($data['precision']);
		$expected = $hll->registerCount;
		$maxRank  = $hll->remainingBits + 1;

		if (count($data['registers']) !== $expected) {
			throw new InvalidArgumentException('Serialized HyperLogLog register count must equal 2^precision');
		}

		$buffer = str_repeat("\x00", $expected);

		for ($i = 0; $i < $expected; $i++) {
			if (!array_key_exists($i, $data['registers']) || !is_int($data['registers'][$i])) {
				throw new InvalidArgumentException('Serialized HyperLogLog registers must be contiguous integers');
			}

			$val = $data['registers'][$i];

			if ($val < 0 || $val > $maxRank) {
				throw new InvalidArgumentException('Serialized HyperLogLog register value out of range 0..' . $maxRank);
			}

			if ($val !== 0) {
				$buffer[$i] = chr($val);
			}
		}

		$hll->registers = $buffer;

		return $hll;
	}

	/**
	 * Return the Flajolet et al. (2007) bias correction constant alpha_m.
	 *
	 * @param int $m Number of registers (power of two >= 16)
	 * @return float
	 */
	private static function alphaFor(int $m): float {
		if ($m === 16) {
			return 0.673;
		}

		if ($m === 32) {
			return 0.697;
		}

		if ($m === 64) {
			return 0.709;
		}

		return 0.7213 / (1.0 + 1.079 / (float) $m);
	}

	/**
	 * Compute an unsigned 32-bit hash from a FIPS-safe SHA-256 digest.
	 *
	 * @param string $value Input string
	 * @return int Unsigned 32-bit integer in [0, 2^32 - 1]
	 */
	private static function hash32(string $value): int {
		$digest = hash('sha256', $value, true);
		$parts  = unpack('Nh', $digest);

		if (!is_array($parts) || !isset($parts['h'])) {
			// @codeCoverageIgnoreStart
			return 0;
			// @codeCoverageIgnoreEnd
		}

		return (int) $parts['h'];
	}

	/**
	 * Compute rho(w): the 1-based position of the leftmost 1-bit in a $bits-wide
	 * unsigned integer (returns $bits + 1 when $w === 0) via O(1) binary search.
	 *
	 * @param int $w    Suffix bits of the 32-bit hash
	 * @param int $bits Number of bits in $w (32 - p)
	 * @return int Rank in [1, $bits + 1]
	 */
	private static function rho(int $w, int $bits): int {
		if ($w === 0) {
			return $bits + 1;
		}

		$msb = 0;

		if ($w >= 0x10000) {
			$w >>= 16;
			$msb += 16;
		}

		if ($w >= 0x100) {
			$w >>= 8;
			$msb += 8;
		}

		if ($w >= 0x10) {
			$w >>= 4;
			$msb += 4;
		}

		if ($w >= 0x4) {
			$w >>= 2;
			$msb += 2;
		}

		if ($w >= 0x2) {
			$msb += 1;
		}

		return $bits - $msb;
	}
}
