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

final class M002Discovery implements Migration {
	public function version(): int {
		return 2;
	}

	public function statements(Database $db): array {
		$statements = array(
			'CREATE TABLE IF NOT EXISTS plugin_cloudflare_tunnels (
				id INT UNSIGNED NOT NULL AUTO_INCREMENT,
				account_id INT UNSIGNED NOT NULL,
				cf_tunnel_id CHAR(36) NOT NULL,
				name VARCHAR(255) NOT NULL,
				cf_status VARCHAR(16) NOT NULL,
				tun_type VARCHAR(32) NOT NULL,
				lifecycle ENUM(\'active\',\'unavailable\',\'deleted\') NOT NULL DEFAULT \'active\',
				created_at_cf INT UNSIGNED NULL,
				conns_active_at INT UNSIGNED NULL,
				conns_inactive_at INT UNSIGNED NULL,
				connector_count INT UNSIGNED NULL,
				connection_count INT UNSIGNED NULL,
				connections_refreshed_at INT UNSIGNED NULL,
				first_seen_at INT UNSIGNED NOT NULL,
				last_seen_at INT UNSIGNED NOT NULL,
				lifecycle_changed_at INT UNSIGNED NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY account_tunnel (account_id, cf_tunnel_id),
				KEY lifecycle (lifecycle),
				KEY account_refresh (account_id, lifecycle, connections_refreshed_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
		);

		if (!$this->foreignKeyExists($db, 'plugin_cloudflare_tunnels', 'fk_cf_tunnels_account')) {
			$statements[] = 'ALTER TABLE plugin_cloudflare_tunnels
				ADD CONSTRAINT fk_cf_tunnels_account FOREIGN KEY (account_id)
				REFERENCES plugin_cloudflare_accounts (id) ON DELETE CASCADE';
		}

		$statements[] = 'CREATE TABLE IF NOT EXISTS plugin_cloudflare_connectors (
				id INT UNSIGNED NOT NULL AUTO_INCREMENT,
				tunnel_id INT UNSIGNED NOT NULL,
				cf_connector_id CHAR(36) NOT NULL,
				version VARCHAR(32) NOT NULL,
				arch VARCHAR(32) NOT NULL,
				run_at INT UNSIGNED NULL,
				connection_count INT UNSIGNED NOT NULL,
				colos VARCHAR(255) NOT NULL,
				origin_ips VARCHAR(255) NOT NULL,
				last_seen_at INT UNSIGNED NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY tunnel_connector (tunnel_id, cf_connector_id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

		if (!$this->foreignKeyExists($db, 'plugin_cloudflare_connectors', 'fk_cf_connectors_tunnel')) {
			$statements[] = 'ALTER TABLE plugin_cloudflare_connectors
				ADD CONSTRAINT fk_cf_connectors_tunnel FOREIGN KEY (tunnel_id)
				REFERENCES plugin_cloudflare_tunnels (id) ON DELETE CASCADE';
		}

		return $statements;
	}

	/**
	 * The check runs when statements() is built, before the CREATE TABLE
	 * above has run on a fresh install. A missing table has no constraint,
	 * so the ALTER is queued and applied after the CREATE.
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
