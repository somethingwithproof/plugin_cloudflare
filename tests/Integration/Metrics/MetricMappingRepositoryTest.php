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

namespace Cacti\PluginCloudflare\Tests\Integration\Metrics;

use Cacti\PluginCloudflare\Metrics\Catalog;
use Cacti\PluginCloudflare\Metrics\Metric;
use Cacti\PluginCloudflare\Metrics\MetricMappingRepository;
use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Storage\Migrator;
use Cacti\PluginCloudflare\Tests\Integration\IntegrationTestCase;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use Cacti\PluginCloudflare\Version;

final class MetricMappingRepositoryTest extends IntegrationTestCase {
	private const DATA_TEMPLATE = '0a8e0fd46e03c70b32a5908fc0463e17';

	/** @var Database */
	private $db;

	/** @var MetricMappingRepository */
	private $repository;

	protected function setUp(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();

		$this->db = new Database(TestDatabase::pdo());
		(new Migrator($this->db, Migrator::defaultMigrations()))->migrate();

		$this->repository = new MetricMappingRepository($this->db);
	}

	protected function tearDown(): void {
		TestDatabase::reset();
	}

	/**
	 * @param array<int, Metric> $metrics
	 * @return array<int, array<string, int|string>>
	 */
	private static function expected(array $metrics, string $hash): array {
		$rows = array();

		foreach ($metrics as $metric) {
			$rows[] = array(
				'metric_name'        => $metric->name(),
				'schema_version'     => Version::METRIC_SCHEMA,
				'ds_type'            => $metric->type(),
				'unit'               => $metric->unit(),
				'query_field'        => $metric->queryField(),
				'data_template_hash' => $hash,
				'rrd_ds_name'        => $metric->dsName(),
			);
		}

		usort($rows, function (array $a, array $b): int {
			return strcmp((string) $a['metric_name'], (string) $b['metric_name']);
		});

		return $rows;
	}

	public function testMappingsSyncFromCatalog(): void {
		/* A row from a catalog that no longer has this metric, and a stale
		 * copy of a current one that reuses a DS name another row will take. */
		$this->db->execute(
			'INSERT INTO plugin_cloudflare_metric_mappings
			(metric_name, schema_version, ds_type, unit, query_field, data_template_hash, rrd_ds_name)
			VALUES (?, 1, ?, ?, ?, ?, ?), (?, 1, ?, ?, ?, ?, ?)',
			array(
				'cloudflare_retired', 'GAUGE', 'things', 'retired', self::DATA_TEMPLATE, 'health',
				'cloudflare_tunnel_connectors', 'COUNTER', 'old', 'old', str_repeat('f', 32), 'old_ds',
			)
		);

		$this->repository->sync(Catalog::all(), self::DATA_TEMPLATE);

		$this->assertSame(self::expected(Catalog::all(), self::DATA_TEMPLATE), $this->repository->all());

		$this->repository->sync(Catalog::all(), self::DATA_TEMPLATE);

		$this->assertSame(self::expected(Catalog::all(), self::DATA_TEMPLATE), $this->repository->all());
	}

	public function testSyncWithFewerMetricsDeletesTheRest(): void {
		$this->repository->sync(Catalog::all(), self::DATA_TEMPLATE);

		$kept = array_slice(Catalog::all(), 0, 2);
		$this->repository->sync($kept, self::DATA_TEMPLATE);

		$this->assertSame(self::expected($kept, self::DATA_TEMPLATE), $this->repository->all());
	}
}
