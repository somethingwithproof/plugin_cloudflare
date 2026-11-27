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

namespace Somethingwithproof\Cloudflare\Api;

/** Error class component for plugin_cloudflare. */
final class ErrorClass {
	/** @var string Auth. */
	public const AUTH         = 'auth';
	/** @var string Permission. */
	public const PERMISSION   = 'permission';
	/** @var string Not found. */
	public const NOT_FOUND    = 'not_found';
	/** @var string Rate limited. */
	public const RATE_LIMITED = 'rate_limited';
	/** @var string Transient. */
	public const TRANSIENT    = 'transient';
	/** @var string Malformed. */
	public const MALFORMED    = 'malformed';
	/** @var string Config. */
	public const CONFIG       = 'config';

	/** Initialize ErrorClass. */
	private function __construct() {
	}
}
