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

/**
 * The single source for metric names, data source names and RRD bounds.
 * Existing RRD files depend on them, so renaming a row or changing its type
 * or bounds needs a new Version::METRIC_SCHEMA.
 */
final class Catalog {
	public const HEALTH = array('inactive' => 0, 'down' => 1, 'degraded' => 2, 'healthy' => 3);

	/** @var array<int, Metric>|null */
	private static $all = null;

	/**
	 * @return array<int, Metric>
	 */
	public static function all(): array {
		if (self::$all === null) {
			self::$all = array(
				new Metric('cloudflare_tunnel_health', 'health', 'health', Metric::GAUGE, 'state', 0, 3, Metric::KIND_STATE),
				new Metric(
					'cloudflare_tunnel_connectors',
					'connectors',
					'connectors',
					Metric::GAUGE,
					'connectors',
					0,
					null,
					Metric::KIND_STATE
				),
				new Metric(
					'cloudflare_tunnel_connections',
					'connections',
					'connections',
					Metric::GAUGE,
					'connections',
					0,
					null,
					Metric::KIND_STATE
				),
				new Metric(
					'cloudflare_tunnel_connector_versions',
					'versions',
					'versions',
					Metric::GAUGE,
					'versions',
					0,
					null,
					Metric::KIND_STATE
				),
				new Metric(
					'cloudflare_collection_age',
					'collectAge',
					'collect_age',
					Metric::GAUGE,
					'seconds',
					0,
					null,
					Metric::KIND_COLLECTION
				),
				new Metric(
					'cloudflare_collection_failures',
					'collectFailures',
					'collect_fail',
					Metric::GAUGE,
					'failures',
					0,
					null,
					Metric::KIND_COLLECTION
				),
			);
		}

		return self::$all;
	}

	public static function byField(string $field): ?Metric {
		foreach (self::all() as $metric) {
			if ($metric->queryField() === $field) {
				return $metric;
			}
		}

		return null;
	}
}
