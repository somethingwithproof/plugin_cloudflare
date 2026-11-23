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

namespace Somethingwithproof\Cloudflare\Storage\Migrations;

use Somethingwithproof\Cloudflare\Storage\Database;
use Somethingwithproof\Cloudflare\Storage\Migration;

final class M005Analytics implements Migration {
	public function version(): int {
		return 5;
	}

	public function statements(Database $db): array {
		return array("CREATE TABLE IF NOT EXISTS plugin_cloudflare_zones (
			account_id INT UNSIGNED NOT NULL,
			zone_id CHAR(32) NOT NULL,
			name VARCHAR(255) NOT NULL,
			listed TINYINT NOT NULL DEFAULT 1,
			status VARCHAR(20) NOT NULL DEFAULT 'never',
			attempted_at INT UNSIGNED NOT NULL DEFAULT 0,
			sampled_at INT UNSIGNED NULL,
			window_start INT UNSIGNED NULL,
			window_end INT UNSIGNED NULL,
			requests DOUBLE NULL,
			bytes DOUBLE NULL,
			errors DOUBLE NULL,
			sample_interval DOUBLE NULL,
			PRIMARY KEY (account_id, zone_id),
			CONSTRAINT fk_cf_zones_account FOREIGN KEY (account_id) REFERENCES plugin_cloudflare_accounts (id) ON DELETE CASCADE
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
	}
}
