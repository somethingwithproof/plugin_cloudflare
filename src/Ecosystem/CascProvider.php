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

use InvalidArgumentException;
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
	 * Register the canonical provider descriptor via 1-argument ProviderRegistrar
	 * or return the descriptor directly.
	 *
	 * @param mixed $providers ProviderRegistrar instance or optional accumulator array
	 * @return mixed
	 */
	public function register($providers = null) {
		$descriptor = $this->descriptor();

		if (is_object($providers) && method_exists($providers, 'register')) {
			$providers->register($descriptor);

			return $descriptor;
		}

		if (is_array($providers)) {
			$providers[self::SLUG] = $descriptor;

			return $providers;
		}

		return $descriptor;
	}

	/**
	 * Return the canonical plain-array CasC provider descriptor.
	 *
	 * @return array<string, mixed>
	 */
	public function descriptor(): array {
		return array(
			'slug'          => self::SLUG,
			'schemaVersion' => '1.0',
			'export'        => function (array $options = array()): array {
				unset($options);

				return array(
					'schemaVersion' => '1.0',
					'config'        => $this->service->exportData(),
				);
			},
			'validate' => function (array $desired): array {
				return $this->validateArray($desired);
			},
			'plan' => function (array $desired, array $options = array()): array {
				return $this->planArray($desired, $options);
			},
			'apply' => function (array $desired, string $planHash = '', array $options = array()): array {
				return $this->applyArray($desired, $planHash, $options);
			},
		);
	}

	/**
	 * Validate a plain-array CasC payload and return a list of error strings.
	 *
	 * @param array<string, mixed> $desired Desired declarative payload
	 * @return array<int, string>
	 */
	private function validateArray(array $desired): array {
		try {
			$yaml = self::encodeDesired($desired);
		} catch (InvalidArgumentException $e) {
			return array($e->getMessage());
		}

		$result = $this->service->validate($yaml, ConfigCodec::FORMAT_YAML);

		return $result['errors'];
	}

	/**
	 * Compute a declarative change plan list from a plain-array CasC payload.
	 *
	 * @param array<string, mixed> $desired Desired declarative payload
	 * @param array<string, mixed> $options Optional planner options
	 * @return array<int, array<string, mixed>>
	 */
	private function planArray(array $desired, array $options = array()): array {
		unset($options);

		try {
			$yaml = self::encodeDesired($desired);
		} catch (InvalidArgumentException $e) {
			return array(
				array(
					'operation' => 'error',
					'key'       => self::SLUG,
					'message'   => $e->getMessage(),
				),
			);
		}

		$plan = $this->service->plan($yaml, ConfigCodec::FORMAT_YAML);

		if (!$plan['valid']) {
			$items = array();
			foreach ($plan['errors'] as $error) {
				$items[] = array(
					'operation' => 'error',
					'key'       => self::SLUG,
					'message'   => $error,
				);
			}

			return $items;
		}

		if ($plan['empty']) {
			return array();
		}

		$items = array();
		foreach ($plan['changes'] as $idx => $change) {
			$op      = isset($change['op']) && $change['op'] === 'create_account' ? 'create' : 'update';
			$key     = isset($change['cf_account_id']) && is_string($change['cf_account_id']) ? $change['cf_account_id'] : self::SLUG;
			$message = isset($plan['lines'][$idx]) ? $plan['lines'][$idx] : ('Reconcile ' . $key);
			$items[] = array(
				'operation' => $op,
				'key'       => $key,
				'message'   => $message,
				'plan_hash' => $plan['plan_hash'],
			);
		}

		return $items;
	}

	/**
	 * Apply a plain-array CasC payload with plan-hash confirmation.
	 *
	 * @param array<string, mixed> $desired  Desired declarative payload
	 * @param string               $planHash Confirmed plan hash
	 * @param array<string, mixed> $options  Optional apply options
	 * @return array{ok: bool, applied: bool, plan_hash: string, errors: array<int, string>, lines: array<int, string>}
	 */
	private function applyArray(array $desired, string $planHash = '', array $options = array()): array {
		unset($options);

		try {
			$yaml = self::encodeDesired($desired);
		} catch (InvalidArgumentException $e) {
			return array(
				'ok'        => false,
				'applied'   => false,
				'plan_hash' => '',
				'errors'    => array($e->getMessage()),
				'lines'     => array(),
			);
		}

		$result = $this->service->apply($yaml, $planHash, ConfigCodec::FORMAT_YAML);

		return array(
			'ok'        => $result['applied'],
			'applied'   => $result['applied'],
			'plan_hash' => $result['plan_hash'],
			'errors'    => $result['errors'],
			'lines'     => $result['lines'],
		);
	}

	/**
	 * Unwrap an optional 'config' envelope and encode as secret-checked YAML.
	 *
	 * @param array<string, mixed> $desired Desired declarative payload
	 * @return string
	 */
	private static function encodeDesired(array $desired): string {
		$payload = array_key_exists('config', $desired) && is_array($desired['config']) ? $desired['config'] : $desired;

		return ConfigCodec::encode(ConfigCodec::FORMAT_YAML, $payload);
	}
}
