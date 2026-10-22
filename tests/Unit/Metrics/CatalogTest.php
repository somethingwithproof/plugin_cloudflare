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

namespace Cacti\PluginCloudflare\Tests\Unit\Metrics;

use Cacti\PluginCloudflare\Metrics\Catalog;
use Cacti\PluginCloudflare\Metrics\Metric;
use Cacti\PluginCloudflare\Metrics\Observation;
use Cacti\PluginCloudflare\Metrics\ObservationReader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase {
	public function testCatalogMatchesTheSpecifiedRows(): void {
		$rows = array_map(static function (Metric $m): array {
			return array($m->name(), $m->queryField(), $m->dsName(), $m->type(), $m->unit(), $m->min(), $m->max(), $m->kind());
		}, Catalog::all());

		$this->assertSame(array(
			array('cloudflare_tunnel_health', 'health', 'health', 'GAUGE', 'state', 0, 3, 'state'),
			array('cloudflare_tunnel_connectors', 'connectors', 'connectors', 'GAUGE', 'connectors', 0, null, 'state'),
			array('cloudflare_tunnel_connections', 'connections', 'connections', 'GAUGE', 'connections', 0, null, 'state'),
			array('cloudflare_tunnel_connector_versions', 'versions', 'versions', 'GAUGE', 'versions', 0, null, 'state'),
			array('cloudflare_collection_age', 'collectAge', 'collect_age', 'GAUGE', 'seconds', 0, null, 'collection'),
			array(
				'cloudflare_collection_failures',
				'collectFailures',
				'collect_fail',
				'GAUGE',
				'failures',
				0,
				null,
				'collection',
			),
		), $rows);
	}

	public function testCatalogNamesAndDsNamesAreUniqueAndShort(): void {
		$names  = array();
		$ds     = array();
		$fields = array();

		foreach (Catalog::all() as $metric) {
			$names[]  = $metric->name();
			$ds[]     = $metric->dsName();
			$fields[] = $metric->queryField();

			/* rrdtool caps a DS name at 19 characters; the mapping column is VARCHAR(19). */
			$this->assertMatchesRegularExpression('/^[a-z_]{1,19}$/', $metric->dsName());
			$this->assertLessThanOrEqual(64, strlen($metric->name()));
			$this->assertLessThanOrEqual(32, strlen($metric->queryField()));
		}

		$this->assertSame($names, array_values(array_unique($names)));
		$this->assertSame($ds, array_values(array_unique($ds)));
		$this->assertSame($fields, array_values(array_unique($fields)));
	}

	public function testByFieldFindsMetricOrNull(): void {
		$metric = Catalog::byField('collectAge');

		$this->assertNotNull($metric);
		$this->assertSame('cloudflare_collection_age', $metric->name());
		$this->assertNull(Catalog::byField('collect_age'));
		$this->assertNull(Catalog::byField(''));
	}

	/**
	 * @return array<string, array{string, int}>
	 */
	public function healthStatuses(): array {
		return array(
			'inactive' => array('inactive', 0),
			'down'     => array('down', 1),
			'degraded' => array('degraded', 2),
			'healthy'  => array('healthy', 3),
		);
	}

	/**
	 * @dataProvider healthStatuses
	 */
	public function testHealthMapping(string $status, int $value): void {
		$this->assertSame($value, Catalog::HEALTH[$status]);
		$this->assertLessThanOrEqual(Catalog::byField('health')->max(), $value);
	}

	public function testRrdValuePrintsIntegerOrU(): void {
		$this->assertSame('0', (new Observation('health', 0, 1, null, 1))->rrdValue());
		$this->assertSame('42', (new Observation('health', 42, 1, null, 1))->rrdValue());
		$this->assertSame('U', (new Observation('health', null, null, 'stale', 1))->rrdValue());
	}

	public function testObservationNeedsValueOrReason(): void {
		$this->expectException(InvalidArgumentException::class);

		new Observation('health', null, null, null, 1);
	}

	public function testObservationRejectsValueWithReason(): void {
		$this->expectException(InvalidArgumentException::class);

		new Observation('health', 0, 1, 'stale', 1);
	}

	public function testWindowIsTwoIntervalsPlusAMinute(): void {
		$this->assertSame(660, ObservationReader::window(300));
		$this->assertSame(60, ObservationReader::window(0));
	}
}
