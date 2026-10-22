<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Cacti Group                                      |
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
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

namespace Cacti\PluginCloudflare\Storage\Migrations;

use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Storage\Migration;

final class M001Foundation implements Migration {
	public function version(): int {
		return 1;
	}

	public function statements(Database $db): array {
		$statements = array(
			'CREATE TABLE IF NOT EXISTS plugin_cloudflare_accounts (
				id INT UNSIGNED NOT NULL AUTO_INCREMENT,
				cf_account_id CHAR(32) NOT NULL,
				name VARCHAR(100) NOT NULL,
				token_source ENUM(\'vault\',\'file\') NOT NULL,
				token_ciphertext TEXT NULL,
				token_file VARCHAR(255) NULL,
				enabled TINYINT(1) NOT NULL DEFAULT 1,
				status VARCHAR(20) NOT NULL DEFAULT \'unknown\',
				status_message VARCHAR(255) NOT NULL DEFAULT \'\',
				permissions TEXT NULL,
				last_checked_at INT UNSIGNED NULL,
				last_verified_at INT UNSIGNED NULL,
				collect_tunnels TINYINT(1) NOT NULL DEFAULT 1,
				collect_analytics TINYINT(1) NOT NULL DEFAULT 0,
				discovery_interval INT UNSIGNED NOT NULL DEFAULT 300,
				created_at INT UNSIGNED NOT NULL,
				updated_at INT UNSIGNED NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY cf_account_id (cf_account_id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',

			'CREATE TABLE IF NOT EXISTS plugin_cloudflare_jobs (
				id INT UNSIGNED NOT NULL AUTO_INCREMENT,
				job_type VARCHAR(32) NOT NULL,
				account_id INT UNSIGNED NOT NULL,
				lease_owner VARCHAR(64) NULL,
				lease_until INT UNSIGNED NULL,
				next_run_at INT UNSIGNED NOT NULL DEFAULT 0,
				last_started_at INT UNSIGNED NULL,
				last_finished_at INT UNSIGNED NULL,
				last_status VARCHAR(20) NOT NULL DEFAULT \'never\',
				last_error_class VARCHAR(20) NULL,
				consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				UNIQUE KEY job_type_account (job_type, account_id)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
		);

		if (!$this->foreignKeyExists($db, 'plugin_cloudflare_jobs', 'fk_cf_jobs_account')) {
			$statements[] = 'ALTER TABLE plugin_cloudflare_jobs
				ADD CONSTRAINT fk_cf_jobs_account FOREIGN KEY (account_id)
				REFERENCES plugin_cloudflare_accounts (id) ON DELETE CASCADE';
		}

		return $statements;
	}

	private function foreignKeyExists(Database $db, string $table, string $name): bool {
		return (int) $db->fetchCell(
			'SELECT COUNT(*) FROM information_schema.table_constraints
			WHERE table_schema = DATABASE() AND table_name = ?
			AND constraint_name = ? AND constraint_type = \'FOREIGN KEY\'',
			array($table, $name)
		) > 0;
	}
}
