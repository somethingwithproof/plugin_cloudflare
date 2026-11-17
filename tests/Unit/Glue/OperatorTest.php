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

namespace Somethingwithproof\Cloudflare\Tests\Unit\Glue;

use PHPUnit\Framework\TestCase;
use Somethingwithproof\Cloudflare\OperatorPage;

final class OperatorTest extends TestCase {
	protected function setUp(): void {
		require_once __DIR__ . '/../../Support/CactiGlueStubs.php';
		require_once __DIR__ . '/../../../setup.php';
		cloudflare_glue_reset();
		$GLOBALS['current_user'] = ['id' => 2, 'enabled' => 'on'];
	}

	protected function tearDown(): void {
		unset($GLOBALS['cloudflare_test_realms'], $GLOBALS['user_auth_realm_filenames'], $GLOBALS['current_user']);
		$_SESSION = [];
	}

	public function testRolesOnlyInheritLessPrivilegedPages(): void {
		foreach ([[101, [true, false, false]], [102, [true, true, false]], [103, [true, true, true]], [0, [false, false, false]]] as $case) {
			$_SESSION = ['sess_user_id' => 2];
			$GLOBALS['user_auth_realm_filenames'] = ['cloudflare.php' => 101, 'cloudflare_operator.php' => 102, 'cloudflare_accounts.php' => 103];
			$GLOBALS['cloudflare_test_realms'] = [$case[0]];
			cloudflare_auth_alternate_realms();
			$this->assertSame($case[1], [api_plugin_user_realm_auth('cloudflare.php'), api_plugin_user_realm_auth('cloudflare_operator.php'), api_plugin_user_realm_auth('cloudflare_accounts.php')]);
		}
		cloudflare_glue_reset();
		$GLOBALS['cloudflare_glue']['enabled'] = false;
		$GLOBALS['cloudflare_test_realms'] = [103];
		cloudflare_auth_alternate_realms();
		$this->assertFalse(api_plugin_user_realm_auth('cloudflare_operator.php'));
	}

	public function testFirstRequestRechecksRolesAfterAuthenticationRestoresTheUser(): void {
		foreach ([101 => [true, false, false], 102 => [true, true, false], 103 => [true, true, true], 0 => [false, false, false]] as $grant => $expected) {
			foreach (['cloudflare.php', 'cloudflare_operator.php', 'cloudflare_accounts.php'] as $index => $page) {
				$_SESSION = [];
				$GLOBALS['user_auth_realm_filenames'] = ['cloudflare.php' => 101, 'cloudflare_operator.php' => 102, 'cloudflare_accounts.php' => 103];
				$GLOBALS['cloudflare_test_realms'] = [$grant];
				$GLOBALS['cloudflare_glue']['page'] = $page;
				cloudflare_auth_alternate_realms();
				$this->assertSame($index < 2, $GLOBALS['user_auth_realm_filenames'][$page] === -1);
				// Cookie restore and Basic auth both establish the user after the hook.
				$_SESSION = ['sess_user_id' => 2];
				$this->assertSame($expected[$index], cloudflare_page_allowed());
				$this->assertGreaterThan(0, $GLOBALS['user_auth_realm_filenames'][$page]);
			}
		}
	}

	public function testDeferredPagesFailClosedWithoutAuthenticationOrWhileDisabled(): void {
		$GLOBALS['user_auth_realm_filenames'] = ['cloudflare.php' => 101, 'cloudflare_operator.php' => 102, 'cloudflare_accounts.php' => 103];
		$GLOBALS['cloudflare_test_realms'] = [103];
		$GLOBALS['cloudflare_glue']['page'] = 'cloudflare_operator.php';
		$_SESSION = [];
		$this->assertFalse(cloudflare_page_allowed());
		$_SESSION = ['sess_user_id' => 2];
		cloudflare_glue_reset();
		$GLOBALS['cloudflare_glue']['enabled'] = false;
		$this->assertFalse(cloudflare_page_allowed());
		$this->assertSame(102, $GLOBALS['user_auth_realm_filenames']['cloudflare_operator.php']);
	}

	public function testDisabledMissingAndMismatchedUserRowsCannotAuthorizeAPage(): void {
		$_SESSION = ['sess_user_id' => 2];
		$GLOBALS['cloudflare_glue']['page'] = 'cloudflare_operator.php';
		$GLOBALS['user_auth_realm_filenames'] = ['cloudflare.php' => 101, 'cloudflare_operator.php' => 102, 'cloudflare_accounts.php' => 103];
		$GLOBALS['cloudflare_test_realms'] = [103];

		foreach ([['id' => 2, 'enabled' => ''], [], ['id' => 3, 'enabled' => 'on'], ['id' => '2invalid', 'enabled' => 'on'], ['id' => true, 'enabled' => 'on'], ['id' => [], 'enabled' => 'on']] as $user) {
			$GLOBALS['current_user'] = $user;
			$this->assertFalse(cloudflare_page_allowed());
		}
		$GLOBALS['current_user'] = ['id' => '2', 'enabled' => 'on'];
		$this->assertTrue(cloudflare_page_allowed());
		$_SESSION['sess_user_id'] = '2invalid';
		$this->assertFalse(cloudflare_page_allowed());
	}

	public function testActionsRequireRolePostAndCsrf(): void {
		$this->assertSame(403, OperatorPage::actionStatus(false, 'acknowledge', 'POST', true, ['acknowledge']));
		$this->assertSame(400, OperatorPage::actionStatus(true, 'delete', 'POST', true, ['acknowledge']));
		$this->assertSame(405, OperatorPage::actionStatus(true, 'acknowledge', 'GET', true, ['acknowledge']));
		$this->assertSame(403, OperatorPage::actionStatus(true, 'acknowledge', 'POST', false, ['acknowledge']));
		$this->assertSame(200, OperatorPage::actionStatus(true, 'acknowledge', 'POST', true, ['acknowledge']));
	}

	public function testAcknowledgmentExpiresWithUserOrEvidence(): void {
		$row = ['id' => '7', 'name' => '<script>alert(1)</script>', 'state' => 'failed', 'observed' => 900, 'interval' => 60, 'detail' => 'timeout'];
		$session = [];
		$fingerprint = OperatorPage::fingerprint($row);
		$this->assertTrue(OperatorPage::acknowledge($session, $row, $fingerprint, 2, 1000));
		$this->assertTrue(OperatorPage::acknowledged($session, $row, 2, 1000));
		$this->assertFalse(OperatorPage::acknowledged($session, $row, 3, 1000));
		$this->assertFalse(OperatorPage::acknowledged($session, $row, 2, 1021));
		$this->assertFalse(OperatorPage::acknowledged($session, array_replace($row, ['state' => 'ok']), 2, 1000));
		$this->assertFalse(OperatorPage::acknowledge($session, $row, 'forged', 2, 1000));
		$this->assertFalse(OperatorPage::acknowledge($session, $row, $fingerprint, 0, 1000));
		$this->assertFalse(OperatorPage::acknowledge($session, $row, $fingerprint, 2, 800));
		ob_start();
		OperatorPage::render([$row], $session, 2, 1000, []);
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString('<script>', $html);
		$this->assertStringContainsString('&lt;script&gt;', $html);
		$this->assertStringContainsString('Acknowledged; health unchanged.', $html);
		$this->assertSame('failed', $row['state']);
	}

	public function testFreshRowsOfferEscapedFormsForTheCurrentObservation(): void {
		$row = ['id' => '7"<&', 'name' => '<b>Account</b>', 'state' => 'failed', 'observed' => 900, 'interval' => 60, 'detail' => '<img src=x>'];
		ob_start();
		OperatorPage::render([$row], [], 2, 1000, ['inspect"<tag>' => 'Inspect <status>']);
		$html = (string) ob_get_clean();

		$this->assertSame([['cloudflare_operator.php', '', false], ['cloudflare_operator.php', '', false]], cloudflare_glue_calls('form_start'));
		$this->assertSame([[false], [false]], cloudflare_glue_calls('form_end'));
		$this->assertStringNotContainsString('<b>Account</b>', $html);
		$this->assertStringNotContainsString('<img src=x>', $html);
		$this->assertStringContainsString('&lt;img src=x&gt;', $html);
		$this->assertStringContainsString('1970-01-01 00:15:00', $html);
		$this->assertStringNotContainsString('Unknown or stale', $html);

		$document = new \DOMDocument();
		$document->loadHTML('<html><body>' . $html . '</body></html>');
		$xpath = new \DOMXPath($document);
		$this->assertSame('acknowledge', $xpath->evaluate('string((//input[@name="action"])[1]/@value)'));
		$this->assertSame('inspect"<tag>', $xpath->evaluate('string((//input[@name="action"])[2]/@value)'));
		$this->assertSame($row['id'], $xpath->evaluate('string((//input[@name="id"])[1]/@value)'));
		$this->assertSame(OperatorPage::fingerprint($row), $xpath->evaluate('string((//input[@name="fingerprint"])[1]/@value)'));
		$this->assertSame('Acknowledge', $xpath->evaluate('string((//button)[1])'));
		$this->assertSame('Inspect <status>', $xpath->evaluate('string((//button)[2])'));
		$this->assertSame(0, $xpath->query('//b | //img | //tag | //status')->length);
	}

	public function testStaleMissingAndFutureObservationsStayVisibleWithoutAcknowledgmentForms(): void {
		$rows = [];
		foreach ([0, 1, 1001] as $observed) {
			$rows[] = ['id' => (string) $observed, 'name' => 'Account', 'state' => 'failed', 'observed' => $observed, 'interval' => 60, 'detail' => 'Check collection'];
		}
		ob_start();
		OperatorPage::render($rows, [], 2, 1000, []);
		$html = (string) ob_get_clean();
		$this->assertSame(3, substr_count($html, 'failed / Unknown or stale'));
		$this->assertStringContainsString('Never', $html);
		$this->assertSame([], cloudflare_glue_calls('form_start'));
		$this->assertStringNotContainsString('<button', $html);

		ob_start();
		OperatorPage::render([], [], 2, 1000, []);
		$empty = (string) ob_get_clean();
		$this->assertStringContainsString('No observations are available for devices you may access.', $empty);
		$this->assertStringNotContainsString('<button', $empty);
	}

	public function testAcknowledgmentDropsInvalidEntriesAndExpiresAfterOneHour(): void {
		$row = ['id' => '7', 'name' => 'Account', 'state' => 'failed', 'observed' => 4900, 'interval' => 7200, 'detail' => 'timeout'];
		$session = ['user' => 2, 'entries' => [
			'expired' => ['at' => 1000],
			'future' => ['at' => 6000],
			'malformed' => 'invalid',
			'missing' => [],
			'wrong_type' => ['at' => '4900'],
			'kept' => ['at' => 4900, 'fingerprint' => 'unchanged'],
		]];
		$this->assertTrue(OperatorPage::acknowledge($session, $row, OperatorPage::fingerprint($row), 2, 5000));
		$this->assertSame(['kept', 7], array_keys($session['entries']));
		$this->assertTrue(OperatorPage::acknowledged($session, $row, 2, 8600));
		$this->assertFalse(OperatorPage::acknowledged($session, $row, 2, 8601));
		$this->assertFalse(OperatorPage::acknowledged(['user' => 2, 'entries' => 'invalid'], $row, 2, 5000));
		$this->assertTrue(OperatorPage::acknowledge($session, $row, OperatorPage::fingerprint($row), 3, 5000));
		$this->assertSame([7], array_keys($session['entries']));
		$this->assertSame(3, $session['user']);
	}
}
