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
use Somethingwithproof\Cloudflare\Metrics\CountMinSketch;

/**
 * Unit tests for CountMinSketch (Cormode & Muthukrishnan, 2005).
 */
final class CountMinSketchTest extends TestCase {
	public function testFromErrorBoundsSizesDimensionsAccordingToTheory(): void {
		$sketch = CountMinSketch::fromErrorBounds(0.01, 0.001);

		$this->assertSame((int) ceil(M_E / 0.01), $sketch->width());
		$this->assertSame((int) ceil(log(1.0 / 0.001)), $sketch->depth());
		$this->assertLessThanOrEqual(0.01, $sketch->epsilon());
		$this->assertLessThanOrEqual(0.001, $sketch->delta());
		$this->assertSame(0, $sketch->totalCount());
		$this->assertSame(0, $sketch->estimate('AS13335'));
	}

	public function testHeavyHittersNeverUnderestimateAndStayWithinEpsilonBound(): void {
		$epsilon = 0.01;
		$delta   = 0.001;
		$sketch  = CountMinSketch::fromErrorBounds($epsilon, $delta);
		$truth   = array();

		// Heavy hitters (top ASNs / URL paths)
		$heavy = array(
			'AS13335:/api/v1/login' => 1200,
			'AS15169:/graphql'      => 800,
			'AS16509:/health'       => 500,
		);

		foreach ($heavy as $key => $count) {
			$sketch->add($key, $count);
			$truth[$key] = $count;
		}

		// Long tail noise
		for ($i = 0; $i < 500; $i++) {
			$key = 'AS' . (20000 + $i) . ':/path/' . $i;
			$sketch->add($key, 1);
			$truth[$key] = 1;
		}

		// Zero increment is a no-op
		$sketch->add('AS13335:/api/v1/login', 0);

		$total = $sketch->totalCount();
		$this->assertSame(3000, $total);
		$maxError = (int) ceil($epsilon * $total);

		foreach ($truth as $key => $expected) {
			$est = $sketch->estimate($key);
			$this->assertGreaterThanOrEqual($expected, $est, 'Count-Min Sketch must never underestimate ' . $key);
			$this->assertLessThanOrEqual($expected + $maxError, $est, 'Estimate exceeded epsilon*N bound for ' . $key);
		}
	}

	public function testMergeIsLinearAndImmutable(): void {
		$a        = new CountMinSketch(256, 5);
		$b        = new CountMinSketch(256, 5);
		$combined = new CountMinSketch(256, 5);

		$streamA = array('AS13335' => 40, 'AS15169' => 15, 'AS64512' => 3);
		$streamB = array('AS13335' => 25, 'AS16509' => 30, 'AS64512' => 7);

		foreach ($streamA as $k => $v) {
			$a->add($k, $v);
			$combined->add($k, $v);
		}

		foreach ($streamB as $k => $v) {
			$b->add($k, $v);
			$combined->add($k, $v);
		}

		$merged = $a->merge($b);

		// Originals are unchanged
		$this->assertSame(58, $a->totalCount());
		$this->assertSame(62, $b->totalCount());
		$this->assertSame(120, $merged->totalCount());
		$this->assertSame($combined->toArray(), $merged->toArray());

		foreach (array('AS13335', 'AS15169', 'AS16509', 'AS64512', 'AS99999') as $key) {
			$this->assertSame($combined->estimate($key), $merged->estimate($key));
		}

		// mergeInPlace matches immutable merge across multiple pollers
		$inPlace = new CountMinSketch(256, 5);
		$inPlace->mergeInPlace($a);
		$inPlace->mergeInPlace($b);
		$this->assertSame($combined->toArray(), $inPlace->toArray());
	}

	public function testSerializationRoundTripPreservesState(): void {
		$sketch = new CountMinSketch(64, 4);
		$sketch->add('/waf/block', 17);
		$sketch->add('/cdn-cgi/trace', 42);

		$payload  = $sketch->toArray();
		$restored = CountMinSketch::fromArray($payload);

		$this->assertSame($payload, $restored->toArray());
		$this->assertSame($sketch->totalCount(), $restored->totalCount());
		$this->assertSame($sketch->estimate('/waf/block'), $restored->estimate('/waf/block'));
		$this->assertSame($sketch->estimate('/cdn-cgi/trace'), $restored->estimate('/cdn-cgi/trace'));
	}

	public function testRejectsInvalidDimensionsAndParameters(): void {
		foreach (array(array(0, 4), array(-1, 4), array(CountMinSketch::MAX_WIDTH + 1, 1), array(4, 0), array(4, CountMinSketch::MAX_DEPTH + 1), array(1048576, 8)) as $pair) {
			try {
				new CountMinSketch($pair[0], $pair[1]);
				$this->fail('Expected InvalidArgumentException for dimensions ' . $pair[0] . 'x' . $pair[1]);
			} catch (InvalidArgumentException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}

		foreach (array(array(0.0, 0.1), array(1.0, 0.1), array(-0.1, 0.1), array(NAN, 0.1), array(0.1, 0.0), array(0.1, 1.0), array(0.1, INF)) as $bounds) {
			try {
				CountMinSketch::fromErrorBounds($bounds[0], $bounds[1]);
				$this->fail('Expected InvalidArgumentException for error bounds');
			} catch (InvalidArgumentException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}
	}

	public function testRejectsNegativeIncrementAndMismatchedMerge(): void {
		$a = new CountMinSketch(32, 3);
		$b = new CountMinSketch(64, 3);

		try {
			$a->add('bad', -1);
			$this->fail('Expected InvalidArgumentException');
		} catch (InvalidArgumentException $e) {
			$this->assertStringContainsString('non-negative', $e->getMessage());
		}

		$this->expectException(InvalidArgumentException::class);
		$a->merge($b);
	}

	public function testFromArrayRejectsMalformedPayloads(): void {
		$cases = array(
			array(),
			array('width' => '4', 'depth' => 2, 'total_count' => 0, 'rows' => array()),
			array('width' => 2, 'depth' => 1, 'total_count' => -1, 'rows' => array(array(0, 0))),
			array('width' => 2, 'depth' => 2, 'total_count' => 0, 'rows' => array(array(0, 0))),
			array('width' => 2, 'depth' => 1, 'total_count' => 0, 'rows' => array(array(0))),
			array('width' => 2, 'depth' => 1, 'total_count' => 0, 'rows' => array(array(0, -3))),
			array('width' => 2, 'depth' => 1, 'total_count' => 0, 'rows' => array(array(0, 'x'))),
		);

		foreach ($cases as $idx => $payload) {
			try {
				CountMinSketch::fromArray($payload);
				$this->fail('Expected InvalidArgumentException for case ' . $idx);
			} catch (InvalidArgumentException $e) {
				$this->assertNotSame('', $e->getMessage());
			}
		}
	}
}
