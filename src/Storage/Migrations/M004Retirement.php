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

/** M004 retirement component for plugin_cloudflare. */
final class M004Retirement implements Migration {
	/**
	 * Version.
	 *
	 * @return int
	 */
	public function version(): int {
		return 4;
	}

	/**
	 * Statements.
	 *
	 * @param Database $db
	 */
	public function statements(Database $db): array {
		$exists = (int) $db->fetchCell('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', array('plugin_cloudflare_tunnels', 'retired_at'));

		return $exists > 0 ? array() : array('ALTER TABLE plugin_cloudflare_tunnels ADD COLUMN retired_at INT UNSIGNED NULL');
	}
}
