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

	/** @var int Precision parameter p in [4, 16]. */
	private $precision;

	/** @var int Register count m = 2^p. */
	private $registerCount;

	/** @var int Remaining hash width (32 - p). */
	private $remainingBits;

	/** @var float Bias correction constant alpha_m. */
	private $alpha;

	/** @var array<int, int> Register array indexed 0..(m - 1). */
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
		$this->remainingBits = 32 - $precision;
		$this->alpha         = self::alphaFor($this->registerCount);
		$this->registers     = array_fill(0, $this->registerCount, 0);
	}

	/**
	 * Observe a stream value and update its target register.
	 *
	 * @param string $value Distinct key (such as client IP, ASN, or URL)
	 * @return void
	 */
	public function add(string $value): void {
		$hash   = self::hash32($value);
		$index  = ($hash >> $this->remainingBits) & ($this->registerCount - 1);
		$suffix = $hash & ((1 << $this->remainingBits) - 1);
		$rank   = self::rho($suffix, $this->remainingBits);

		if ($rank > $this->registers[$index]) {
			$this->registers[$index] = $rank;
		}
	}

	/**
	 * Estimate the distinct cardinality using harmonic mean plus Flajolet et al.
	 * small-range LinearCounting and large-range collision corrections.
	 *
	 * @return float Estimated distinct cardinality
	 */
	public function count(): float {
		$m            = (float) $this->registerCount;
		$harmonicSum  = 0.0;
		$zeroCount    = 0;

		for ($i = 0; $i < $this->registerCount; $i++) {
			$reg = $this->registers[$i];
			$harmonicSum += pow(2.0, (float) (-$reg));

			if ($reg === 0) {
				$zeroCount++;
			}
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

		$merged = new self($this->precision);

		for ($i = 0; $i < $this->registerCount; $i++) {
			$merged->registers[$i] = $this->registers[$i] >= $other->registers[$i]
				? $this->registers[$i]
				: $other->registers[$i];
		}

		return $merged;
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
		return array(
			'precision' => $this->precision,
			'registers' => $this->registers,
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

		$registers = array();

		for ($i = 0; $i < $expected; $i++) {
			if (!array_key_exists($i, $data['registers']) || !is_int($data['registers'][$i])) {
				throw new InvalidArgumentException('Serialized HyperLogLog registers must be contiguous integers');
			}

			$val = $data['registers'][$i];

			if ($val < 0 || $val > $maxRank) {
				throw new InvalidArgumentException('Serialized HyperLogLog register value out of range 0..' . $maxRank);
			}

			$registers[$i] = $val;
		}

		$hll->registers = $registers;

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
		$parts  = unpack('Nh', substr($digest, 0, 4));

		if (!is_array($parts) || !isset($parts['h'])) {
			// @codeCoverageIgnoreStart
			return 0;
			// @codeCoverageIgnoreEnd
		}

		return (int) $parts['h'];
	}

	/**
	 * Compute rho(w): the 1-based position of the leftmost 1-bit in a $bits-wide
	 * unsigned integer (returns $bits + 1 when $w === 0).
	 *
	 * @param int $w    Suffix bits of the 32-bit hash
	 * @param int $bits Number of bits in $w (32 - p)
	 * @return int Rank in [1, $bits + 1]
	 */
	private static function rho(int $w, int $bits): int {
		if ($w === 0) {
			return $bits + 1;
		}

		$rank = 1;
		$mask = 1 << ($bits - 1);

		while (($w & $mask) === 0 && $mask > 0) {
			$rank++;
			$mask >>= 1;
		}

		return $rank;
	}
}
