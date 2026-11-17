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
}
