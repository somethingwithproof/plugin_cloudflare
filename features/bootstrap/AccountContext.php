<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Cacti Group                                      |
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
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

namespace Cacti\PluginCloudflare\Features;

use Behat\Behat\Context\Context;
use Cacti\PluginCloudflare\Account\Account;
use Cacti\PluginCloudflare\Account\AccountService;
use Cacti\PluginCloudflare\Auth\AccessDenied;
use Cacti\PluginCloudflare\Http\Response;
use Cacti\PluginCloudflare\Tests\Support\FakeTransport;
use ReflectionClass;
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

	/** @var Account|null */
	private $account = null;

	/** @var bool */
	private $denied = false;

	/** @var string */
	private $before = '';

	/** @var string */
	private $exposed = '';

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
	 * @Given I am authenticated as a user without Cloudflare configuration privileges
	 */
	public function iAmAnUnprivilegedUser(): void {
		$this->world->setAllowed(false);
	}

	/**
	 * @Given a Cloudflare account is already configured
	 */
	public function anAccountIsConfigured(): void {
		$this->world->setAllowed(true);
		$this->accountId = $this->world->service()->save(null, $this->world->accountInput());
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
		$transport->queue(FakeTransport::fixture('token-verify-active'));
		$transport->queue(new Response(200, array(), World::OK_BODY));
		$transport->queue(new Response(200, array(), World::OK_BODY));

		$this->account = $this->world->service()->verify($this->requireId());
	}

	/**
	 * @When Cloudflare rejects the token during verification
	 */
	public function cloudflareRejectsTheToken(): void {
		$transport = $this->world->transport();
		$transport->queue(FakeTransport::fixture('auth-error', 401));
		$transport->queue(FakeTransport::fixture('auth-error', 401));

		$this->account = $this->world->service()->verify($this->requireId());
	}

	/**
	 * @When I attempt to configure a Cloudflare account
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
		// AccountService has no find(); the repository's find() is the read
		// path the pages use for a single account.
		$accounts = $this->world->service()->list();
		$found    = $this->world->repository()->find($this->requireId());

		$this->exposed = var_export($accounts, true) . print_r($accounts, true)
			. var_export($found, true) . print_r($found, true);
	}

	/**
	 * @Then the account status should be :status
	 */
	public function theAccountStatusShouldBe(string $status): void {
		if ($this->account === null || $this->account->status() !== $status) {
			throw new RuntimeException('account status differs from the expected status');
		}
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
		if (strpos(implode("\n", $this->world->logLines()), $this->world->token()) !== false) {
			throw new RuntimeException('token found in application logs');
		}
		if (strpos(serialize($this->world->rows()), $this->world->token()) !== false) {
			throw new RuntimeException('plaintext token found in the account row');
		}
	}

	/**
	 * @Then the request must be rejected
	 */
	public function theRequestMustBeRejected(): void {
		if (!$this->denied) {
			throw new RuntimeException('request was not rejected with AccessDenied');
		}
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
	 * @Then no credential material must be exposed
	 */
	public function noCredentialMaterialMustBeExposed(): void {
		if ($this->exposed === '') {
			throw new RuntimeException('no account data was requested');
		}
		if (strpos($this->exposed, $this->world->token()) !== false) {
			throw new RuntimeException('token found in returned account data');
		}
		foreach (array('v1:', 'ciphertext') as $marker) {
			if (strpos($this->exposed, $marker) !== false) {
				throw new RuntimeException('credential marker found in returned account data');
			}
		}
	}

	/**
	 * @Then token material must not be reachable through the public service
	 */
	public function tokenMaterialMustNotBeReachable(): void {
		$class = new ReflectionClass(AccountService::class);
		if ($class->hasMethod('tokenMaterial') && $class->getMethod('tokenMaterial')->isPublic()) {
			throw new RuntimeException('tokenMaterial is public on AccountService');
		}
		if ($class->hasMethod('__call')) {
			throw new RuntimeException('AccountService forwards unknown calls');
		}
	}

	private function requireId(): int {
		if ($this->accountId === null) {
			throw new RuntimeException('no account has been configured');
		}

		return $this->accountId;
	}
}
