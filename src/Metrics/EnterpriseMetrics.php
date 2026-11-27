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

/**
 * Immutable enterprise zone analytics snapshot covering cache efficiency,
 * HTTP 4xx/5xx error split, WAF mitigation rates, origin latency, DNS
 * analytics, and TLS certificate expiry.
 *
 * Missing inputs or zero denominators always stay null (never fabricated as 0)
 * so Cacti/Thold treat absent telemetry as unknown (U).
 */
final class EnterpriseMetrics {
	/** @var float|null Cache hit ratio by requests in [0, 100]. */
	private $cacheHitRequestsPct;

	/** @var float|null Cache hit ratio by bytes in [0, 100]. */
	private $cacheHitBytesPct;

	/** @var float|null HTTP 4xx client error percentage in [0, 100]. */
	private $error4xxPct;

	/** @var float|null HTTP 5xx server error percentage in [0, 100]. */
	private $error5xxPct;

	/** @var float|null WAF blocked events per second. */
	private $wafBlockedRate;

	/** @var float|null WAF challenged events per second. */
	private $wafChallengedRate;

	/** @var float|null Average origin time-to-first-byte / response latency in milliseconds. */
	private $originTtfbAvgMs;

	/** @var float|null 95th percentile origin response latency in milliseconds. */
	private $originTtfbP95Ms;

	/** @var float|null Total DNS queries per second. */
	private $dnsQueriesRate;

	/** @var float|null DNS NXDOMAIN responses per second. */
	private $dnsNxdomainRate;

	/** @var float|null DNS SERVFAIL responses per second. */
	private $dnsServfailRate;

	/** @var float|null Days until the soonest active TLS certificate expires (negative when expired). */
	private $tlsCertExpiryDays;

	/**
	 * Construct an immutable EnterpriseMetrics value object.
	 *
	 * @param float|null $cacheHitRequestsPct Cache hit ratio by request count (%)
	 * @param float|null $cacheHitBytesPct    Cache hit ratio by response bytes (%)
	 * @param float|null $error4xxPct         HTTP 4xx error rate (%)
	 * @param float|null $error5xxPct         HTTP 5xx error rate (%)
	 * @param float|null $wafBlockedRate      WAF blocked rate (events/s)
	 * @param float|null $wafChallengedRate   WAF challenged rate (events/s)
	 * @param float|null $originTtfbAvgMs     Origin TTFB average (ms)
	 * @param float|null $originTtfbP95Ms     Origin TTFB p95 (ms)
	 * @param float|null $dnsQueriesRate      DNS query rate (queries/s)
	 * @param float|null $dnsNxdomainRate     DNS NXDOMAIN rate (responses/s)
	 * @param float|null $dnsServfailRate     DNS SERVFAIL rate (responses/s)
	 * @param float|null $tlsCertExpiryDays   Days until soonest active TLS certificate expires
	 */
	public function __construct(
		?float $cacheHitRequestsPct,
		?float $cacheHitBytesPct,
		?float $error4xxPct,
		?float $error5xxPct,
		?float $wafBlockedRate,
		?float $wafChallengedRate,
		?float $originTtfbAvgMs,
		?float $originTtfbP95Ms,
		?float $dnsQueriesRate,
		?float $dnsNxdomainRate,
		?float $dnsServfailRate,
		?float $tlsCertExpiryDays
	) {
		$this->cacheHitRequestsPct = $cacheHitRequestsPct;
		$this->cacheHitBytesPct    = $cacheHitBytesPct;
		$this->error4xxPct         = $error4xxPct;
		$this->error5xxPct         = $error5xxPct;
		$this->wafBlockedRate      = $wafBlockedRate;
		$this->wafChallengedRate   = $wafChallengedRate;
		$this->originTtfbAvgMs     = $originTtfbAvgMs;
		$this->originTtfbP95Ms     = $originTtfbP95Ms;
		$this->dnsQueriesRate      = $dnsQueriesRate;
		$this->dnsNxdomainRate     = $dnsNxdomainRate;
		$this->dnsServfailRate     = $dnsServfailRate;
		$this->tlsCertExpiryDays   = $tlsCertExpiryDays;
	}

	/**
	 * Build an EnterpriseMetrics snapshot from raw window counters and optional readings.
	 *
	 * @param int        $windowSeconds    Sampling window length in seconds
	 * @param float      $requests         Total HTTP requests in window
	 * @param float      $bytes            Total edge response bytes in window
	 * @param float|null $cachedRequests   Cached HTTP requests in window
	 * @param float|null $cachedBytes      Cached response bytes in window
	 * @param float|null $errors4xx        HTTP 4xx error count in window
	 * @param float|null $errors5xx        HTTP 5xx error count in window
	 * @param float|null $wafBlocked       WAF blocked event count in window
	 * @param float|null $wafChallenged    WAF challenged event count in window
	 * @param float|null $originTtfbAvgMs  Origin response latency average in ms
	 * @param float|null $originTtfbP95Ms  Origin response latency p95 in ms
	 * @param float|null $dnsQueries       Total DNS queries in window
	 * @param float|null $dnsNxdomain      DNS NXDOMAIN count in window
	 * @param float|null $dnsServfail      DNS SERVFAIL count in window
	 * @param int|null   $soonestExpiresAt Soonest active TLS cert expiry timestamp
	 * @param int        $now              Current Unix timestamp for expiry calculation
	 * @return self
	 */
	public static function fromRawCounters(
		int $windowSeconds,
		float $requests,
		float $bytes,
		?float $cachedRequests,
		?float $cachedBytes,
		?float $errors4xx,
		?float $errors5xx,
		?float $wafBlocked,
		?float $wafChallenged,
		?float $originTtfbAvgMs,
		?float $originTtfbP95Ms,
		?float $dnsQueries,
		?float $dnsNxdomain,
		?float $dnsServfail,
		?int $soonestExpiresAt,
		int $now
	): self {
		return new self(
			self::ratioPercent($cachedRequests, $requests),
			self::ratioPercent($cachedBytes, $bytes),
			self::ratioPercent($errors4xx, $requests),
			self::ratioPercent($errors5xx, $requests),
			self::ratePerSecond($wafBlocked, $windowSeconds),
			self::ratePerSecond($wafChallenged, $windowSeconds),
			$originTtfbAvgMs,
			$originTtfbP95Ms,
			self::ratePerSecond($dnsQueries, $windowSeconds),
			self::ratePerSecond($dnsNxdomain, $windowSeconds),
			self::ratePerSecond($dnsServfail, $windowSeconds),
			self::daysUntilExpiry($soonestExpiresAt, $now)
		);
	}

	/**
	 * Compute a percentage (100 * numerator / denominator), returning null when
	 * the numerator is null or the denominator is not strictly positive.
	 *
	 * @param float|null $numerator   Numerator count
	 * @param float      $denominator Denominator total
	 * @return float|null Rounded percentage in [0, 100] or null
	 */
	public static function ratioPercent(?float $numerator, float $denominator): ?float {
		if ($numerator === null || !is_finite($numerator) || !is_finite($denominator) || $denominator <= 0.0 || $numerator < 0.0) {
			return null;
		}

		return round(100.0 * $numerator / $denominator, 4);
	}

	/**
	 * Compute an event rate per second (count / windowSeconds), returning null
	 * when count is null or windowSeconds is not strictly positive.
	 *
	 * @param float|null $count         Event count over window
	 * @param int        $windowSeconds Window duration in seconds
	 * @return float|null Rounded events/sec or null
	 */
	public static function ratePerSecond(?float $count, int $windowSeconds): ?float {
		if ($count === null || !is_finite($count) || $count < 0.0 || $windowSeconds <= 0) {
			return null;
		}

		return round($count / (float) $windowSeconds, 4);
	}

	/**
	 * Compute remaining days until a Unix expiry timestamp relative to $now.
	 *
	 * @param int|null $expiresAt Certificate expiry Unix timestamp
	 * @param int      $now       Current Unix timestamp
	 * @return float|null Remaining days (negative if expired), or null when unknown
	 */
	public static function daysUntilExpiry(?int $expiresAt, int $now): ?float {
		if ($expiresAt === null) {
			return null;
		}

		return round((float) ($expiresAt - $now) / 86400.0, 4);
	}

	/**
	 * Return cache hit ratio by requests (%).
	 *
	 * @return float|null
	 */
	public function cacheHitRequestsPct(): ?float {
		return $this->cacheHitRequestsPct;
	}

	/**
	 * Return cache hit ratio by response bytes (%).
	 *
	 * @return float|null
	 */
	public function cacheHitBytesPct(): ?float {
		return $this->cacheHitBytesPct;
	}

	/**
	 * Return HTTP 4xx error rate (%).
	 *
	 * @return float|null
	 */
	public function error4xxPct(): ?float {
		return $this->error4xxPct;
	}

	/**
	 * Return HTTP 5xx error rate (%).
	 *
	 * @return float|null
	 */
	public function error5xxPct(): ?float {
		return $this->error5xxPct;
	}

	/**
	 * Return WAF blocked event rate (events/s).
	 *
	 * @return float|null
	 */
	public function wafBlockedRate(): ?float {
		return $this->wafBlockedRate;
	}

	/**
	 * Return WAF challenged event rate (events/s).
	 *
	 * @return float|null
	 */
	public function wafChallengedRate(): ?float {
		return $this->wafChallengedRate;
	}

	/**
	 * Return average origin TTFB / response duration (ms).
	 *
	 * @return float|null
	 */
	public function originTtfbAvgMs(): ?float {
		return $this->originTtfbAvgMs;
	}

	/**
	 * Return 95th percentile origin TTFB / response duration (ms).
	 *
	 * @return float|null
	 */
	public function originTtfbP95Ms(): ?float {
		return $this->originTtfbP95Ms;
	}

	/**
	 * Return total DNS query rate (queries/s).
	 *
	 * @return float|null
	 */
	public function dnsQueriesRate(): ?float {
		return $this->dnsQueriesRate;
	}

	/**
	 * Return DNS NXDOMAIN response rate (responses/s).
	 *
	 * @return float|null
	 */
	public function dnsNxdomainRate(): ?float {
		return $this->dnsNxdomainRate;
	}

	/**
	 * Return DNS SERVFAIL response rate (responses/s).
	 *
	 * @return float|null
	 */
	public function dnsServfailRate(): ?float {
		return $this->dnsServfailRate;
	}

	/**
	 * Return remaining days until soonest active TLS certificate expiry.
	 *
	 * @return float|null
	 */
	public function tlsCertExpiryDays(): ?float {
		return $this->tlsCertExpiryDays;
	}

	/**
	 * Export all 12 enterprise metrics as a canonical associative array.
	 *
	 * @return array{
	 *     cache_hit_requests_pct: ?float,
	 *     cache_hit_bytes_pct: ?float,
	 *     error_4xx_pct: ?float,
	 *     error_5xx_pct: ?float,
	 *     waf_blocked_rate: ?float,
	 *     waf_challenged_rate: ?float,
	 *     origin_ttfb_avg_ms: ?float,
	 *     origin_ttfb_p95_ms: ?float,
	 *     dns_queries_rate: ?float,
	 *     dns_nxdomain_rate: ?float,
	 *     dns_servfail_rate: ?float,
	 *     tls_cert_expiry_days: ?float
	 * }
	 */
	public function toArray(): array {
		return array(
			'cache_hit_requests_pct' => $this->cacheHitRequestsPct,
			'cache_hit_bytes_pct'    => $this->cacheHitBytesPct,
			'error_4xx_pct'          => $this->error4xxPct,
			'error_5xx_pct'          => $this->error5xxPct,
			'waf_blocked_rate'       => $this->wafBlockedRate,
			'waf_challenged_rate'    => $this->wafChallengedRate,
			'origin_ttfb_avg_ms'     => $this->originTtfbAvgMs,
			'origin_ttfb_p95_ms'     => $this->originTtfbP95Ms,
			'dns_queries_rate'       => $this->dnsQueriesRate,
			'dns_nxdomain_rate'      => $this->dnsNxdomainRate,
			'dns_servfail_rate'      => $this->dnsServfailRate,
			'tls_cert_expiry_days'   => $this->tlsCertExpiryDays,
		);
	}
}
