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

use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Version;

/**
 * Records which RRD data source of which data template holds each catalog
 * metric, so later engines can find a metric without parsing templates.
 */
final class MetricMappingRepository {
	private const TABLE = 'plugin_cloudflare_metric_mappings';

	/** @var Database */
	private $db;

	public function __construct(Database $db) {
		$this->db = $db;
	}

	/**
	 * Deletes first: a retired row may hold the (template, DS name) pair a
	 * current metric needs. Select-then-write rather than ON DUPLICATE KEY,
	 * because a matched-rows count depends on MYSQL_ATTR_FOUND_ROWS.
	 *
	 * @param array<int, Metric> $metrics
	 */
	public function sync(array $metrics, string $dataTemplateHash): void {
		$this->db->transactional(function () use ($metrics, $dataTemplateHash): void {
			$names = array();

			foreach ($metrics as $metric) {
				$names[] = $metric->name();
			}

			if ($names === array()) {
				$this->db->execute('DELETE FROM ' . self::TABLE);
			} else {
				$this->db->execute(
					'DELETE FROM ' . self::TABLE . ' WHERE metric_name NOT IN ('
					. implode(', ', array_fill(0, count($names), '?')) . ')',
					$names
				);
			}

			foreach ($metrics as $metric) {
				$values = array(
					Version::METRIC_SCHEMA,
					$metric->type(),
					$metric->unit(),
					$metric->queryField(),
					$dataTemplateHash,
					$metric->dsName(),
					$metric->name(),
				);

				$exists = $this->db->fetchCell(
					'SELECT 1 FROM ' . self::TABLE . ' WHERE metric_name = ?',
					array($metric->name())
				);

				if ($exists === null) {
					$this->db->execute(
						'INSERT INTO ' . self::TABLE . ' (schema_version, ds_type, unit, query_field, data_template_hash,
						rrd_ds_name, metric_name) VALUES (?, ?, ?, ?, ?, ?, ?)',
						$values
					);
				} else {
					$this->db->execute(
						'UPDATE ' . self::TABLE . ' SET schema_version = ?, ds_type = ?, unit = ?, query_field = ?,
						data_template_hash = ?, rrd_ds_name = ? WHERE metric_name = ?',
						$values
					);
				}
			}
		});
	}

	/**
	 * @return array<int, array{
	 *         metric_name: string,
	 *         schema_version: int,
	 *         ds_type: string,
	 *         unit: string,
	 *         query_field: string,
	 *         data_template_hash: string,
	 *         rrd_ds_name: string
	 *         }> ordered by metric name
	 */
	public function all(): array {
		$rows = array();

		foreach ($this->db->fetchAll(
			'SELECT metric_name, schema_version, ds_type, unit, query_field, data_template_hash, rrd_ds_name
			FROM ' . self::TABLE . ' ORDER BY metric_name'
		) as $row) {
			$rows[] = array(
				'metric_name'        => (string) $row['metric_name'],
				'schema_version'     => (int) $row['schema_version'],
				'ds_type'            => (string) $row['ds_type'],
				'unit'               => (string) $row['unit'],
				'query_field'        => (string) $row['query_field'],
				'data_template_hash' => (string) $row['data_template_hash'],
				'rrd_ds_name'        => (string) $row['rrd_ds_name'],
			);
		}

		return $rows;
	}
}
