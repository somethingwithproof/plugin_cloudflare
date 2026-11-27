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

namespace Somethingwithproof\Cloudflare\Tests\Unit\Metrics;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Somethingwithproof\Cloudflare\Metrics\HyperLogLog;

/**
 * Unit tests for HyperLogLog (Flajolet, Fusy, Gandouet & Meunier, 2007).
 */
final class HyperLogLogTest extends TestCase {
	public function testEmptySketchEstimatesZeroAndReportsParameters(): void {
		$hll = new HyperLogLog(12);

		$this->assertSame(12, $hll->precision());
		$this->assertSame(4096, $hll->registerCount());
		$this->assertEqualsWithDelta(1.04 / 64.0, $hll->relativeError(), 1e-9);
		$this->assertEqualsWithDelta(0.0, $hll->count(), 1e-9);
		$this->assertSame(0, $hll->estimate());
	}

	public function testSmallRangeLinearCountingAndDuplicateIdempotence(): void {
		$hll = new HyperLogLog(10);

		for ($i = 0; $i < 150; $i++) {
			$ip = '198.51.100.' . ($i % 256) . ':' . $i;
			$hll->add($ip);
			// Duplicate additions must not inflate cardinality
			$hll->add($ip);
			$hll->add($ip);
		}

		$est = $hll->count();
		$this->assertGreaterThan(125.0, $est);
		$this->assertLessThan(175.0, $est);
	}

	public function testMediumRangeAccuracyWithinStandardErrorBounds(): void {
		$hll = new HyperLogLog(12);
		$n   = 15000;

		for ($i = 0; $i < $n; $i++) {
			$hll->add('client-ip-' . $i);
		}

		// 3 * RSE at p=12 is ~4.9%; allow 6% deterministic margin
		$relErr = abs($hll->count() - (float) $n) / (float) $n;
		$this->assertLessThan(0.06, $relErr, 'Estimated ' . $hll->count() . ' for N=' . $n);
	}

	public function testSmallPrecisionsCoverAllAlphaConstants(): void {
		foreach (array(4, 5, 6, 7) as $p) {
			$hll = new HyperLogLog($p);
			for ($i = 0; $i < 500; $i++) {
				$hll->add('item-' . $i);
			}
			$this->assertGreaterThan(100, $hll->estimate(), 'precision ' . $p);
		}
	}

	public function testMergeComputesUnionWithoutMutatingInputs(): void {
		$a        = new HyperLogLog(10);
		$b        = new HyperLogLog(10);
		$expected = new HyperLogLog(10);

		for ($i = 0; $i < 600; $i++) {
			$a->add('key-' . $i);
			$expected->add('key-' . $i);
		}

		// 200 overlap + 400 new = 1000 distinct total
		for ($i = 400; $i < 1000; $i++) {
			$b->add('key-' . $i);
			$expected->add('key-' . $i);
		}

		$merged = $a->merge($b);

		$this->assertSame($expected->toArray(), $merged->toArray());
		$this->assertEqualsWithDelta($expected->count(), $merged->count(), 1e-9);
		$this->assertLessThan(750, $a->estimate());
		$this->assertLessThan(750, $b->estimate());
		$this->assertGreaterThan(850, $merged->estimate());
		$this->assertLessThan(1150, $merged->estimate());
	}

	public function testLargeRangeAndNoZeroSmallRangeCorrectionBranches(): void {
		// All registers equal to 1: raw E <= 2.5*m with V == 0 returns raw E directly
		$denseSmall = HyperLogLog::fromArray(array(
			'precision' => 4,
			'registers' => array_fill(0, 16, 1),
		));
		$this->assertGreaterThan(0.0, $denseSmall->count());

		// Large-range correction: raw E in ((1/30)*2^32, 2^32)
		$large = HyperLogLog::fromArray(array(
			'precision' => 4,
			'registers' => array_fill(0, 16, 26),
		));
		$this->assertGreaterThan(4294967296.0 / 30.0, $large->count());

		// Saturated registers: raw E >= 2^32 clamps cleanly instead of returning NaN
		$saturated = HyperLogLog::fromArray(array(
			'precision' => 4,
			'registers' => array_fill(0, 16, 29),
		));
		$this->assertSame(4294967296.0, $saturated->count());
	}

	public function testSerializationRoundTripAndValidation(): void {
		$hll = new HyperLogLog(8);
		for ($i = 0; $i < 250; $i++) {
			$hll->add('zone-visitor-' . $i);
		}

		$payload  = $hll->toArray();
		$restored = HyperLogLog::fromArray($payload);

		$this->assertSame($payload, $restored->toArray());
		$this->assertSame($hll->estimate(), $restored->estimate());
	}

	public function testRejectsInvalidPrecisionMergeAndPayloads(): void {
		foreach (array(3, 0, -2, 17, 32) as $badP) {
			try {
				new HyperLogLog($badP);
				$this->fail('Expected InvalidArgumentException for precision ' . $badP);
			} catch (InvalidArgumentException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}

		$a = new HyperLogLog(8);
		$b = new HyperLogLog(10);

		try {
			$a->merge($b);
			$this->fail('Expected InvalidArgumentException for mismatched merge');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('precision', $e->getMessage());
		}

		$badPayloads = array(
			array(),
			array('precision' => '4', 'registers' => array_fill(0, 16, 0)),
			array('precision' => 4, 'registers' => array(0, 0)),
			array('precision' => 4, 'registers' => array_merge(array('bad'), array_fill(0, 15, 0))),
			array('precision' => 4, 'registers' => array_merge(array(-1), array_fill(0, 15, 0))),
			array('precision' => 4, 'registers' => array_merge(array(30), array_fill(0, 15, 0))),
		);

		foreach ($badPayloads as $idx => $payload) {
			try {
				HyperLogLog::fromArray($payload);
				$this->fail('Expected InvalidArgumentException for payload ' . $idx);
			} catch (InvalidArgumentException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}
	}
}
