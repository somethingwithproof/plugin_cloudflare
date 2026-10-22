<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 Thomas Vincent                                       |
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
 | Cloudflare plugin for Cacti                                             |
 | https://github.com/somethingwithproof/plugin_cloudflare                 |
 +-------------------------------------------------------------------------+
*/

namespace Cacti\PluginCloudflare\Features;

use Behat\Behat\Context\Context;
use Cacti\PluginCloudflare\Auth\AccessDenied;
use Cacti\PluginCloudflare\Http\Response;
use Cacti\PluginCloudflare\Tests\Support\FakeTransport;
use RuntimeException;

/**
 * Failure messages are fixed strings. A step must never put the token, or a
 * value that could contain it, into an exception.
 */
final class AccountContext implements Context {
	/** @var World */
	private $world;

	/** @var int|null */
	private $accountId = null;

	/** @var string|null */
	private $status = null;

	/** @var bool */
	private $tokenRejected = false;

	/** @var bool */
	private $denied = false;

	/** @var string */
	private $before = '';

	/** @var array<string, mixed>|null */
	private $details = null;

	public function __construct() {
		$this->world = new World();
	}

	/**
	 * @AfterScenario
	 */
	public function cleanUp(): void {
		$this->world->close();
	}

	/**
	 * @Given I am authenticated as a Cacti administrator
	 */
	public function iAmAnAdministrator(): void {
		$this->world->setAllowed(true);
	}

	/**
	 * @Given I have a valid scoped Cloudflare API token
	 */
	public function iHaveAValidToken(): void {
		$this->tokenRejected = false;
	}

	/**
	 * @Given I have an invalid Cloudflare API token
	 */
	public function iHaveAnInvalidToken(): void {
		$this->tokenRejected = true;
	}

	/**
	 * @Given I am authenticated without Cloudflare configuration privileges
	 */
	public function iAmAuthenticatedWithoutPrivileges(): void {
		$this->seedAccountThenRevoke();
	}

	/**
	 * @Given I have permission to view Cloudflare graphs
	 */
	public function iCanViewGraphs(): void {
		// Viewing graphs is not an AccountService right; the account to be
		// protected is created here while configure is still granted.
		$this->world->setAllowed(true);
		$this->accountId = $this->world->service()->save(null, $this->world->accountInput());
	}

	/**
	 * @Given I do not have permission to administer Cloudflare accounts
	 */
	public function iCannotAdministerAccounts(): void {
		$this->world->setAllowed(false);
	}

	/**
	 * @When I configure a Cloudflare account
	 */
	public function iConfigureAnAccount(): void {
		$this->accountId = $this->world->service()->save(null, $this->world->accountInput());
	}

	/**
	 * @When I verify the connection
	 */
	public function iVerifyTheConnection(): void {
		$transport = $this->world->transport();
		if ($this->tokenRejected) {
			$transport->queue(FakeTransport::fixture('auth-error', 401));
			$transport->queue(FakeTransport::fixture('auth-error', 401));
		} else {
			$transport->queue(FakeTransport::fixture('token-verify-active'));
			$transport->queue(new Response(200, array(), World::OK_BODY));
			$transport->queue(new Response(200, array(), World::OK_BODY));
		}

		$this->status = $this->world->service()->verify($this->requireId())->status();
	}

	/**
	 * @When I attempt to configure an account
	 */
	public function iAttemptToConfigureAnAccount(): void {
		$this->before = serialize($this->world->rows());

		$input         = $this->world->accountInput();
		$input['name'] = 'Changed';
		try {
			$this->world->service()->save($this->accountId, $input);
		} catch (AccessDenied $e) {
			$this->denied = true;
		}
	}

	/**
	 * @When I request account credential details
	 */
	public function iRequestCredentialDetails(): void {
		$this->before = serialize($this->world->rows());

		try {
			$this->details = $this->world->service()->credentialDetails($this->requireId());
		} catch (AccessDenied $e) {
			$this->denied = true;
		}
	}

	/**
	 * @Then the account should be saved
	 */
	public function theAccountShouldBeSaved(): void {
		$rows = $this->world->rows();
		if (count($rows) !== 1 || (int) $rows[0]['id'] !== $this->requireId()) {
			throw new RuntimeException('expected exactly one saved account row');
		}
	}

	/**
	 * @Then the connection status should indicate success
	 */
	public function theStatusShouldIndicateSuccess(): void {
		$this->assertStatus('ok');
	}

	/**
	 * @Then the connection status should indicate authentication failure
	 */
	public function theStatusShouldIndicateAuthFailure(): void {
		$this->assertStatus('auth_failed');
	}

	/**
	 * @Then the token must be stored encrypted
	 */
	public function theTokenMustBeStoredEncrypted(): void {
		$rows = $this->world->rows();
		if (count($rows) !== 1) {
			throw new RuntimeException('expected exactly one account row');
		}
		if (strpos(serialize($rows), $this->world->token()) !== false) {
			throw new RuntimeException('plaintext token found in the account row');
		}
		if (substr((string) $rows[0]['token_ciphertext'], 0, 3) !== 'v1:') {
			throw new RuntimeException('token ciphertext is missing or not versioned');
		}
	}

	/**
	 * @Then the token must not appear in application logs
	 */
	public function theTokenMustNotAppearInLogs(): void {
		$this->assertLogsClean();
		if (strpos(serialize($this->world->rows()), $this->world->token()) !== false) {
			throw new RuntimeException('plaintext token found in the account row');
		}
	}

	/**
	 * @Then the request must be rejected
	 */
	public function theRequestMustBeRejected(): void {
		$this->assertDenied();
	}

	/**
	 * @Then the request must be denied
	 */
	public function theRequestMustBeDenied(): void {
		$this->assertDenied();
	}

	/**
	 * @Then no configuration should change
	 */
	public function noConfigurationShouldChange(): void {
		if ($this->before === '' || serialize($this->world->rows()) !== $this->before) {
			throw new RuntimeException('account table changed');
		}
	}

	/**
	 * @Then no credential material must be returned
	 */
	public function noCredentialMaterialMustBeReturned(): void {
		if ($this->details !== null) {
			throw new RuntimeException('credential details were returned');
		}
		$this->assertLogsClean();
	}

	private function seedAccountThenRevoke(): void {
		$this->world->setAllowed(true);
		$this->accountId = $this->world->service()->save(null, $this->world->accountInput());
		$this->world->setAllowed(false);
	}

	private function assertStatus(string $expected): void {
		if ($this->status !== $expected) {
			throw new RuntimeException('account status differs from the expected status');
		}
	}

	private function assertDenied(): void {
		if (!$this->denied) {
			throw new RuntimeException('request was not rejected with AccessDenied');
		}
	}

	private function assertLogsClean(): void {
		$logs = implode("\n", $this->world->logLines());
		if (strpos($logs, $this->world->token()) !== false) {
			throw new RuntimeException('token found in application logs');
		}
		if (strpos($logs, 'v1:') !== false) {
			throw new RuntimeException('ciphertext found in application logs');
		}
	}

	private function requireId(): int {
		if ($this->accountId === null) {
			throw new RuntimeException('no account has been configured');
		}

		return $this->accountId;
	}
}
