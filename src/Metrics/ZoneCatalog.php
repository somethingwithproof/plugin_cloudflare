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

/** Zone catalog component for plugin_cloudflare. */
final class ZoneCatalog {
	/** @return array<int, Metric> */
	public static function all(): array {
		return array(
			new Metric('cloudflare_zone_requests', 'requests', 'requests', Metric::GAUGE, 'requests/s', 0, null, Metric::KIND_STATE),
			new Metric('cloudflare_zone_bytes', 'bytes', 'bytes', Metric::GAUGE, 'bytes/s', 0, null, Metric::KIND_STATE),
			new Metric('cloudflare_zone_errors', 'errorPct', 'error_pct', Metric::GAUGE, 'percent', 0, 100, Metric::KIND_STATE),
			new Metric('cloudflare_zone_sampling', 'sampleInterval', 'sample_interval', Metric::GAUGE, 'ratio', 1, null, Metric::KIND_STATE),
			new Metric('cloudflare_zone_age', 'age', 'age', Metric::GAUGE, 'seconds', 0, null, Metric::KIND_COLLECTION),
		);
	}
}
