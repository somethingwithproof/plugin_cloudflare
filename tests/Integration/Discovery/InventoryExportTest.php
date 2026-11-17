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

namespace Somethingwithproof\Cloudflare\Tests\Integration\Discovery;

use Somethingwithproof\Cloudflare\Storage\Database;
use Somethingwithproof\Cloudflare\Storage\Migrator;
use Somethingwithproof\Cloudflare\Tests\Support\TestDatabase;
use Somethingwithproof\Cloudflare\Tests\Integration\IntegrationTestCase;

final class InventoryExportTest extends IntegrationTestCase {
	public function testExportIncludesAllFilteredRowsAcrossPagesAndPreservesSort(): void {
		TestDatabase::skipIfUnavailable($this);
		TestDatabase::reset();
		require_once dirname(__DIR__, 2) . '/Support/CactiGlueStubs.php';
		require_once dirname(__DIR__, 3) . '/includes/viewer.php';
		cloudflare_glue_reset();
		$db = new Database(TestDatabase::pdo());
		(new Migrator($db, Migrator::defaultMigrations()))->migrate();
		$db->execute("INSERT INTO plugin_cloudflare_accounts (id, cf_account_id, name, token_source, created_at, updated_at) VALUES (1, ?, '=account', 'file', 1, 1)", array(str_repeat('a', 32)));

		for ($i = 1; $i <= 503; $i++) {
			$db->execute("INSERT INTO plugin_cloudflare_tunnels (account_id, cf_tunnel_id, name, lifecycle, cf_status, tun_type, first_seen_at, last_seen_at) VALUES (1, ?, ?, 'active', 'healthy', 'cfd_tunnel', 1, 1)", array(sprintf('%08d-0000-4000-8000-000000000000', $i), sprintf('web-%04d', $i)));
		}

		$_REQUEST = array('account_id' => '1', 'filter' => 'web', 'sort_column' => 'name', 'sort_direction' => 'DESC', 'rows' => '2', 'page' => '3');
		$_SESSION = array();
		$stream = cloudflare_viewer_export_file('tunnels', $db);
		$rows = array();

		while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
			$rows[] = $row;
		}

		fclose($stream);
		$this->assertCount(504, $rows);
		$this->assertSame("'=account", $rows[1][0]);
		$this->assertSame('web-0503', $rows[1][2]);
		$this->assertSame('web-0001', $rows[503][2]);
		$this->assertSame('', $rows[1][5]);
		$_REQUEST['filter'] = 'missing';
		$stream = cloudflare_viewer_export_file('tunnels', $db);
		$this->assertIsArray(fgetcsv($stream));
		$this->assertFalse(fgetcsv($stream));
		fclose($stream);
		$_REQUEST = array();
		$_SESSION = array();
		TestDatabase::reset();
	}
}
