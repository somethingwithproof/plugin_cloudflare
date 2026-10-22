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

namespace Cacti\PluginCloudflare\Storage\Migrations;

use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Storage\Migration;

final class M003Metrics implements Migration {
	public function version(): int {
		return 3;
	}

	public function statements(Database $db): array {
		/*
		 * tunnel_id has no foreign key: tunnel rows are never deleted, only
		 * cascaded with the account. The device row stores 0 and an empty
		 * template_hash so account_resource stays unique without NULLs.
		 */
		$statements = array(
			'CREATE TABLE IF NOT EXISTS plugin_cloudflare_resources (
				id INT UNSIGNED NOT NULL AUTO_INCREMENT,
				account_id INT UNSIGNED NOT NULL,
				kind ENUM(\'device\',\'graph\') NOT NULL,
				tunnel_id INT UNSIGNED NOT NULL DEFAULT 0,
				template_hash CHAR(32) NOT NULL DEFAULT \'\',
				host_id INT UNSIGNED NOT NULL,
				local_graph_id INT UNSIGNED NULL,
				local_data_id INT UNSIGNED NULL,
				created_at INT UNSIGNED NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY account_resource (account_id, kind, tunnel_id, template_hash),
				UNIQUE KEY graph (local_graph_id),
				KEY host (host_id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
		);

		if (!$this->foreignKeyExists($db, 'plugin_cloudflare_resources', 'fk_cf_resources_account')) {
			$statements[] = 'ALTER TABLE plugin_cloudflare_resources
				ADD CONSTRAINT fk_cf_resources_account FOREIGN KEY (account_id)
				REFERENCES plugin_cloudflare_accounts (id) ON DELETE CASCADE';
		}

		$statements[] = 'CREATE TABLE IF NOT EXISTS plugin_cloudflare_metric_mappings (
				metric_name VARCHAR(64) NOT NULL,
				schema_version SMALLINT UNSIGNED NOT NULL,
				ds_type ENUM(\'GAUGE\',\'COUNTER\') NOT NULL,
				unit VARCHAR(16) NOT NULL,
				query_field VARCHAR(32) NOT NULL,
				data_template_hash CHAR(32) NOT NULL,
				rrd_ds_name VARCHAR(19) NOT NULL,
				PRIMARY KEY (metric_name),
				UNIQUE KEY template_ds (data_template_hash, rrd_ds_name)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

		if (!$this->columnExists($db, 'plugin_cloudflare_tunnels', 'connections_error_class')) {
			$statements[] = 'ALTER TABLE plugin_cloudflare_tunnels
				ADD COLUMN connections_error_class VARCHAR(20) NULL AFTER connections_attempted_at';
		}

		if (!$this->columnExists($db, 'plugin_cloudflare_accounts', 'create_graphs')) {
			$statements[] = 'ALTER TABLE plugin_cloudflare_accounts
				ADD COLUMN create_graphs TINYINT(1) NOT NULL DEFAULT 0 AFTER collect_analytics';
		}

		return $statements;
	}

	private function columnExists(Database $db, string $table, string $column): bool {
		return (int) $db->fetchCell(
			'SELECT COUNT(*) FROM information_schema.columns
			WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
			array($table, $column)
		) > 0;
	}

	/**
	 * Checked while statements() is built, before the CREATE TABLE has run on
	 * a fresh install; a missing table has no constraint, so the ALTER is
	 * queued and applied after the CREATE.
	 */
	private function foreignKeyExists(Database $db, string $table, string $name): bool {
		return (int) $db->fetchCell(
			'SELECT COUNT(*) FROM information_schema.table_constraints
			WHERE table_schema = DATABASE() AND table_name = ?
			AND constraint_name = ? AND constraint_type = \'FOREIGN KEY\'',
			array($table, $name)
		) > 0;
	}
}
