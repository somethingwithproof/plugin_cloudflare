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

namespace Somethingwithproof\Cloudflare\Ecosystem;

use Somethingwithproof\Cloudflare\Config\ConfigCodec;
use Somethingwithproof\Cloudflare\Config\ConfigService;

/**
 * Registers the Cloudflare CasC provider through casc_register_providers.
 *
 * Bridges directly to ConfigService so accounts (using vault/file secret
 * references), zones, tunnels, and metric mappings export, validate, plan,
 * and apply cleanly without ever exposing raw tokens.
 */
final class CascProvider {
	/** @var string Slug. */
	public const SLUG = 'cloudflare';

	/** @var ConfigService */
	private $service;

	/**
	 * Initialize CascProvider.
	 *
	 * @param ConfigService $service
	 */
	public function __construct(ConfigService $service) {
		$this->service = $service;
	}

	/**
	 * @param mixed $providers
	 * @return mixed
	 */
	public function register($providers) {
		$descriptor = $this->descriptor();

		if (is_array($providers)) {
			$providers[self::SLUG] = $descriptor;

			return $providers;
		}

		if (is_object($providers) && method_exists($providers, 'register')) {
			$providers->register(self::SLUG, $descriptor);
		}

		return $providers;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function descriptor(): array {
		return array(
			'name'           => self::SLUG,
			'schema_version' => ConfigCodec::SCHEMA_VERSION,
			'formats'        => array(ConfigCodec::FORMAT_YAML, ConfigCodec::FORMAT_XML),
			'export'         => function (string $format = ConfigCodec::FORMAT_YAML): string {
				return $this->service->export($format);
			},
			'validate' => function (string $content, string $format = ConfigCodec::FORMAT_YAML): array {
				$result = $this->service->validate($content, $format);

				return array('valid' => $result['valid'], 'errors' => $result['errors']);
			},
			'plan' => function (string $content, string $format = ConfigCodec::FORMAT_YAML): array {
				return $this->service->plan($content, $format);
			},
			'apply' => function (string $content, string $confirmHash, string $format = ConfigCodec::FORMAT_YAML): array {
				return $this->service->apply($content, $confirmHash, $format);
			},
		);
	}
}
