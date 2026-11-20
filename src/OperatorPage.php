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

namespace Somethingwithproof\Cloudflare;

/** Personal triage of current observations. It never changes measured health. */
final class OperatorPage {
	/** @param list<string> $actions */
	public static function actionStatus(bool $allowed, string $action, string $method, bool $csrf, array $actions): int {
		if (!$allowed) {
			return 403;
		}
		if (!in_array($action, $actions, true)) {
			return 400;
		}
		if ($method !== 'POST') {
			return 405;
		}
		return $csrf ? 200 : 403;
	}

	/** @param array{id: string, name: string, state: string, observed: int, interval: int, detail: string} $row */
	public static function fingerprint(array $row): string {
		return hash('sha256', (string) json_encode($row));
	}

	/** @param array{id: string, name: string, state: string, observed: int, interval: int, detail: string} $row */
	public static function fresh(array $row, int $now): bool {
		return $row['observed'] > 0 && $row['observed'] <= $now && $now - $row['observed'] <= max(60, $row['interval']) * 2;
	}

	/**
	 * Session-only acknowledgments expire with the evidence or after one hour.
	 * Only rows freshly reloaded from the account-wide observation list may be supplied.
	 * @param array<string, mixed> $session
	 * @param array{id: string, name: string, state: string, observed: int, interval: int, detail: string} $row
	 */
	public static function acknowledge(array &$session, array $row, string $fingerprint, int $user, int $now): bool {
		if ($user <= 0 || !self::fresh($row, $now) || !hash_equals(self::fingerprint($row), $fingerprint)) {
			return false;
		}
		$entries = isset($session['entries'], $session['user']) && $session['user'] === $user && is_array($session['entries']) ? $session['entries'] : [];
		foreach ($entries as $id => $entry) {
			if (!is_array($entry) || !isset($entry['at']) || !is_int($entry['at']) || $entry['at'] > $now || $now - $entry['at'] > 3600) {
				unset($entries[$id]);
			}
		}
		unset($entries[$row['id']]);
		$entries[$row['id']] = ['fingerprint' => $fingerprint, 'at' => $now];
		$session = ['user' => $user, 'entries' => array_slice($entries, -100, null, true)];
		return true;
	}

	/**
	 * @param array<string, mixed> $session
	 * @param array{id: string, name: string, state: string, observed: int, interval: int, detail: string} $row
	 */
	public static function acknowledged(array $session, array $row, int $user, int $now): bool {
		$entries = $session['entries'] ?? [];
		$entry = is_array($entries) ? ($entries[$row['id']] ?? null) : null;
		return ($session['user'] ?? 0) === $user && is_array($entry)
			&& isset($entry['fingerprint'], $entry['at']) && is_string($entry['fingerprint']) && is_int($entry['at'])
			&& $entry['at'] <= $now && $now - $entry['at'] <= 3600
			&& self::fresh($row, $now) && hash_equals(self::fingerprint($row), $entry['fingerprint']);
	}

	/** @param array{id: string, name: string, state: string, observed: int, interval: int, detail: string} $row */
	public static function form(array $row, string $action, string $label): void {
		form_start('cloudflare_operator.php');
		print '<input type="hidden" name="action" value="' . html_escape($action) . '">';
		print '<input type="hidden" name="id" value="' . html_escape($row['id']) . '">';
		print '<input type="hidden" name="fingerprint" value="' . self::fingerprint($row) . '">';
		print '<button class="ui-button ui-corner-all ui-widget" type="submit">' . html_escape($label) . '</button>';
		form_end(false);
	}

	/**
	 * @param list<array{id: string, name: string, state: string, observed: int, interval: int, detail: string}> $rows
	 * @param array<string, mixed> $session
	 * @param array<string, string> $actions
	 */
	public static function render(array $rows, array $session, int $user, int $now, array $actions): void {
		print '<p>' . html_escape(__('Review the recorded observations below. Acknowledgments are personal to this session, expire when observations change, and never suppress alerts or change health.', 'cloudflare')) . '</p>';
		print '<div class="operator-observations" style="overflow-x: auto;"><div style="min-width: 48em;">';
		html_start_box(__('Cloudflare Operations', 'cloudflare'), '100%', '', '3', 'center', '');
		print '<tr class="tableHeader"><th>' . implode('</th><th>', array_map('html_escape', [__('Object', 'cloudflare'), __('State', 'cloudflare'), __('Observed (UTC)', 'cloudflare'), __('Investigation', 'cloudflare'), __('Actions', 'cloudflare')])) . '</th></tr>';
		foreach ($rows as $row) {
			$fresh = self::fresh($row, $now);
			$state = $row['state'] . ($fresh ? '' : ' / ' . __('Unknown or stale', 'cloudflare'));
			print '<tr class="tableRow"><td>' . html_escape($row['name']) . '</td><td>' . html_escape($state) . '</td><td>' . html_escape($row['observed'] > 0 ? gmdate('Y-m-d H:i:s', $row['observed']) : __('Never', 'cloudflare')) . '</td><td>' . html_escape($row['detail']) . '</td><td>';
			if (self::acknowledged($session, $row, $user, $now)) {
				print html_escape(__('Acknowledged; health unchanged.', 'cloudflare'));
			} elseif ($fresh) {
				self::form($row, 'acknowledge', __('Acknowledge', 'cloudflare'));
			}
			foreach ($actions as $action => $label) {
				self::form($row, $action, $label);
			}
			print '</td></tr>';
		}
		if ($rows === []) {
			print '<tr><td colspan="5">' . html_escape(__('No observations are available.', 'cloudflare')) . '</td></tr>';
		}
		html_end_box();
		print '</div></div>';
	}
}
