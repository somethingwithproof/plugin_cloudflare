<!--
SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
SPDX-License-Identifier: GPL-2.0-or-later

This file is part of plugin_cloudflare, a plugin for Cacti.
https://github.com/somethingwithproof/plugin_cloudflare
-->

# Algorithmic Review and PHP Performance Profile

This document records the algorithmic audit, real PHP profiling measurements (`hrtime(true)` and `memory_get_peak_usage(true)`), and targeted optimizations applied to `plugin_cloudflare` hot paths while preserving PHP 7.4 compatibility and FIPS-safe SHA-256 hashing.

## Measurement Environment

- Runtime: PHP 8.4.25 (CLI, NTS, no coverage or debugger extension loaded)
- Platform: Apple Silicon macOS (arm64)
- Timing: monotonic nanosecond clock via `hrtime(true)`
- Memory: real OS-allocated peak memory via `memory_get_peak_usage(true)` and per-workload `memory_get_usage(true)` deltas

## Hot-Path Audit Summary

| Component | Baseline Bottleneck | Root Cause | Fix Applied |
|---|---|---|---|
| `CountMinSketch::add()` / `estimate()` | Inner-loop multiplication and double modulo plus redundant digest slicing | `($h1 + (($row * $h2) % $width)) % $width` ran `d` times per event; `substr($digest, 0, 8)` allocated a copy on every event | Kirsch-Mitzenmacher incremental addition (`$col += $step; if ($col >= $width) $col -= $width`) and direct `unpack('N2h', $digest)` over a single SHA-256 digest per event |
| `CountMinSketch::merge()` (100 pollers) | Wasteful zero-fill and 2D array lookups on every cell | `merge()` called `new self($w, $d)` (zero-filling every cell) before overwriting all `d * w` cells via `$this->rows[$row][$col]` | Added `mergeInPlace()` for multi-poller fan-in, rewrote immutable `merge()` to `clone` + `mergeInPlace()`, and hoisted local row slices skipping zero source cells |
| `HyperLogLog` register storage | High memory overhead across 100-poller merges | PHP `array<int, int>` uses ~32 bytes per register (~6 MB for 100 pollers at `p=12`, ~210 MB at `p=16`) | Packed 1-byte-per-register binary string (`str_repeat("\x00", m)`); 100 pollers at `p=12` drop from 6.00 MB to 0.40 MB (~15x less memory) |
| `HyperLogLog::count()` | Repeated `pow(2.0, -$reg)` calls across all `m` registers | Executed `pow()` 4,096 times per `count()` call (`409,600` calls for 100 estimates) | Replaced PHP loop and `pow()` calls with native C `count_chars($registers, 1)` byte histogram plus a static `INV_POW2` lookup table (at most 30 iterations) |
| `HyperLogLog::rho()` | Linear bit scan up to `32 - p` iterations | `while (($w & $mask) === 0)` looped one bit at a time | Replaced with 5-step binary search (`O(1)` worst case) and precomputed `indexMask`/`suffixMask` |
| `HyperLogLog::merge()` (100 pollers) | Repeated full array allocation per poller merge | Allocated a new `m`-element PHP array for each pairwise merge | Added `mergeInPlace()` operating on packed byte strings; immutable `merge()` delegates via `clone` |
| `GraphqlAnalyticsClient` / `AnalyticsCollector` / `AnalyticsSummary` | Audited, no quadratic path | Queries are strictly bounded (`limit: 1` ungrouped aggregates); `AnalyticsCollector` enforces wall-clock and request budgets; `AnalyticsSummary::summarizeZones()` uses one joined SQL query | Extended with enterprise cache, 4xx/5xx, WAF, origin TTFB, DNS, and TLS expiry metrics preserving null-on-unknown semantics |

## Hash Function Tradeoff Analysis

Non-cryptographic hashes (`crc32` and `fnv1a64`) were benchmarked against SHA-256 on PHP 7.4-compatible functions before deciding on the sketch hash:

| Hash Algorithm (PHP 7.4 compatible) | 100k hashes (ms) | HLL `p=12` error on 15k sequential keys (`client-ip-<i>`) | Decision |
|---|---:|---:|---|
| `sha256` (single digest, `unpack`) | 42.01 | 2.04% (within 1.63% RSE) | Retained: FIPS-safe and statistically unbiased on sequential keys |
| `crc32` | 13.72 | 29.51% | Rejected: severe bias on sequential ASCII prefixes |
| `fnv1a64` | 20.64 | 78.27% | Rejected: weak avalanche without 64-bit unsigned multiplication (which overflows PHP signed int to float) |

Because `CountMinSketch` already hashes once per event (Kirsch-Mitzenmacher double hashing from the first 8 bytes of one SHA-256 digest, not `d` hashes) and `HyperLogLog` requires uniform 32-bit avalanche on sequential client IPs and ASNs, retaining single-pass SHA-256 preserves both FIPS compliance and textbook accuracy guarantees.

## Measured Before and After Results

| Workload | Before (ms) | After (ms) | Speedup | Memory Note |
|---|---:|---:|---:|---|
| `CountMinSketch::add()` 100k events (`w=272, d=7`) | 93.49 | 87.20 | 1.07x | 16.00 MB peak (steady) |
| `CountMinSketch::merge()` 100-poller merge (`w=2048, d=5`) | 42.79 | 16.14 | 2.65x | Immutable `merge()` |
| `CountMinSketch::mergeInPlace()` 100-poller merge (`w=2048, d=5`) | 42.79 | 17.52 | 2.44x | Zero intermediate matrix allocations |
| `HyperLogLog::add()` 100k events (`p=12, m=4096`) | 62.08 | 59.22 | 1.05x | Packed 4 KB register buffer |
| `HyperLogLog::count()` x100 (`p=12, m=4096`) | 21.54 | 0.56 | 38.46x | Native C `count_chars()` + `INV_POW2` table |
| `HyperLogLog::merge()` 100-poller merge (`p=12, m=4096`) | 16.65 | 9.26 | 1.80x | 100-poller footprint 6.00 MB -> 0.40 MB (15x less memory); overall suite peak RSS 46.00 MB -> 34.00 MB |
| `HyperLogLog::mergeInPlace()` 100-poller merge (`p=12, m=4096`) | 16.65 | 9.99 | 1.67x | Zero intermediate string copies |

## Complexity Verification

- `CountMinSketch`: `O(d)` insert and point query with 1 SHA-256 digest and 0 inner-loop divisions/modulos; `O(d * w)` merge with zero-skipping on sparse remote sketches.
- `HyperLogLog`: `O(1)` insert (1 SHA-256 digest + 5-step `rho()`), `O(m)` byte histogram in C + `O(32)` harmonic sum in `count()`, `O(m)` register-wise max in `merge()`/`mergeInPlace()`, and `m` bytes of register storage (`4 KB` at `p=12`, `64 KB` at `p=16`).
- `EnterpriseMetrics` and `AnalyticsSummary`: `O(1)` per zone snapshot with strict null propagation on zero denominators and missing fields.
