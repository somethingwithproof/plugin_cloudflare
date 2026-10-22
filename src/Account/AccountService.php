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

namespace Cacti\PluginCloudflare\Account;

use Cacti\PluginCloudflare\Api\ApiException;
use Cacti\PluginCloudflare\Api\CloudflareClient;
use Cacti\PluginCloudflare\Api\ErrorClass;
use Cacti\PluginCloudflare\Auth\AccessDenied;
use Cacti\PluginCloudflare\Auth\Authorizer;
use Cacti\PluginCloudflare\Secret\SecretException;
use Cacti\PluginCloudflare\Secret\TokenFile;
use Cacti\PluginCloudflare\Secret\TokenPolicy;
use Cacti\PluginCloudflare\Secret\TokenVault;
use Cacti\PluginCloudflare\Storage\DatabaseException;
use Cacti\PluginCloudflare\Support\Clock;
use Cacti\PluginCloudflare\Support\Ids;
use OutOfBoundsException;

/**
 * Status messages are fixed sentences. API error text is never copied into
 * them because Cloudflare's own messages are not under our control; the log
 * line carries only the error class or the SecretException reason.
 */
final class AccountService {
	private const MESSAGES = array(
		'ok'                => 'Connection verified',
		'token_inactive'    => 'Token is not active; it may be expired or disabled',
		'auth_failed'       => 'Token is invalid or expired',
		'permission_denied' => 'Token lacks the Account Cloudflare Tunnel Read permission',
		'unreachable'       => 'Cloudflare API is unreachable; try again later',
		'rate_limited'      => 'Cloudflare API rate limit reached; try again later',
		'error'             => 'Cloudflare API request failed unexpectedly',
	);

	private const MESSAGE_FORBIDDEN     = 'Token lacks the permissions needed to verify it';
	private const MESSAGE_UNDECRYPTABLE = 'Stored token cannot be decrypted';
	private const MESSAGE_KEY_MISSING   = 'Token vault key file is missing or unusable';
	private const MESSAGE_FILE          = 'Token file is missing or insecure';
	private const MESSAGE_VAULT_MISSING = 'Encrypted storage is unavailable; configure the key file or use a token file';

	/** Schema defaults, used when an insert omits the field. */
	private const DEFAULT_FLAGS    = array('enabled' => 1, 'collect_tunnels' => 1, 'collect_analytics' => 0);
	private const DEFAULT_INTERVAL = 300;

	/** @var AccountRepository */
	private $repo;

	/** @var Authorizer */
	private $auth;

	/** @var CloudflareClient */
	private $client;

	/** @var TokenVault|null */
	private $vault;

	/** @var Clock */
	private $clock;

	/** @var callable(string): void|null */
	private $log;

	/**
	 * @param TokenVault|null $vault null when the key file is missing; only the file source is then accepted
	 * @param callable(string): void|null $log
	 */
	public function __construct(
		AccountRepository $repo,
		Authorizer $auth,
		CloudflareClient $client,
		?TokenVault $vault,
		Clock $clock,
		?callable $log = null
	) {
		$this->repo   = $repo;
		$this->auth   = $auth;
		$this->client = $client;
		$this->vault  = $vault;
		$this->clock  = $clock;
		$this->log    = $log;
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @throws AccessDenied
	 * @throws ValidationException
	 * @throws OutOfBoundsException when $id names no account
	 */
	public function save(
		?int $id,
		#[\SensitiveParameter]
		array $input
	): int {
		$this->requireConfigure();

		$existing = null;
		$current  = null;
		if ($id !== null) {
			$existing = $this->requireAccount($id);
			$current  = $this->repo->tokenMaterial($id);
		}

		$errors = array();

		$cfAccountId = Ids::normalizeAccountId(self::text($input, 'cf_account_id'));
		if (!Ids::isAccountId($cfAccountId)) {
			$errors['cf_account_id'] = 'Enter the 32-character hexadecimal Cloudflare account ID';
		}

		$name = trim(self::text($input, 'name'));
		if (preg_match('/^[^\p{Cc}]{1,100}\z/u', $name) !== 1) {
			$errors['name'] = 'Enter a name of 1 to 100 characters without control characters';
		}

		$source = self::text($input, 'token_source');
		if ($source !== 'vault' && $source !== 'file') {
			$errors['token_source'] = 'Choose an encrypted token or a token file';
		} elseif ($source === 'vault' && $this->vault === null) {
			$errors['token_source'] = self::MESSAGE_VAULT_MISSING;
		}

		$token      = $source === 'vault' ? trim(self::text($input, 'token')) : '';
		$ciphertext = null;
		if ($source === 'vault' && !isset($errors['token_source'])) {
			if ($token !== '') {
				try {
					TokenPolicy::validate($token);
				} catch (SecretException $e) {
					$errors['token'] = $e->getMessage();
				}
			} elseif ($current === null || $current['source'] !== 'vault' || (string) $current['ciphertext'] === '') {
				$errors['token'] = 'Enter an API token';
			} elseif (Ids::isAccountId($cfAccountId) && $current['cf_account_id'] !== $cfAccountId) {
				/* The AAD binds the stored ciphertext to the old account ID. */
				$errors['token'] = 'Re-enter the token when changing the account ID';
			} else {
				$ciphertext = $current['ciphertext'];
			}
		}

		$tokenFile = null;
		if ($source === 'file') {
			$tokenFile = trim(self::text($input, 'token_file'));
			if (preg_match('#^/[^\x00-\x1f\x7f]{0,254}\z#', $tokenFile) !== 1) {
				$errors['token_file'] = 'Enter an absolute path of at most 255 characters';
			}
		}

		$flags = array();
		foreach (self::DEFAULT_FLAGS as $field => $default) {
			if (!array_key_exists($field, $input)) {
				$flags[$field] = $existing === null ? $default : self::existingFlag($existing, $field);
				continue;
			}

			$flag = self::flag($input[$field]);
			if ($flag === null) {
				$errors[$field] = 'Must be 0 or 1';
			} else {
				$flags[$field] = $flag;
			}
		}

		$interval = $existing === null ? self::DEFAULT_INTERVAL : $existing->discoveryInterval();
		if (array_key_exists('discovery_interval', $input)) {
			$parsed = self::interval($input['discovery_interval']);
			if ($parsed === null) {
				$errors['discovery_interval'] = 'Enter a whole number of seconds from 60 to 86400';
			} else {
				$interval = $parsed;
			}
		}

		if ($errors !== array()) {
			throw new ValidationException($errors);
		}

		if ($token !== '' && $this->vault !== null) {
			try {
				$ciphertext = $this->vault->encrypt($token, TokenVault::aadFor($cfAccountId));
			} catch (SecretException $e) {
				throw new ValidationException(array('token' => $e->getMessage()));
			}
		}

		$now = $this->clock->now();
		$row = array(
			'cf_account_id'      => $cfAccountId,
			'name'               => $name,
			'token_source'       => $source,
			'token_ciphertext'   => $ciphertext,
			'token_file'         => $tokenFile,
			'enabled'            => $flags['enabled'],
			'collect_tunnels'    => $flags['collect_tunnels'],
			'collect_analytics'  => $flags['collect_analytics'],
			'discovery_interval' => $interval,
			'updated_at'         => $now,
		);

		$resetStatus = false;
		if ($existing !== null && $current !== null) {
			$credentialsChanged = $token !== ''
				|| $current['source'] !== $source
				|| $current['file'] !== $tokenFile
				|| $current['cf_account_id'] !== $cfAccountId;

			/* A verify result describes the old credentials, not the new ones. */
			if ($credentialsChanged && $existing->status() !== 'unknown') {
				$row['status']         = 'unknown';
				$row['status_message'] = '';
				$row['permissions']    = null;
				$resetStatus           = true;
			}
		} else {
			$row['created_at'] = $now;
		}

		try {
			if ($existing === null) {
				$id = $this->repo->insert($row);
			} else {
				$this->repo->update($existing->id(), $row);
				$id = $existing->id();
			}
		} catch (DatabaseException $e) {
			if ($e->getSqlState() === '23000' && $e->getDriverCode() === 1062) {
				throw new ValidationException(array('cf_account_id' => 'This Cloudflare account ID is already configured'));
			}

			throw $e;
		}

		if ($resetStatus && $existing !== null) {
			$this->logTransition($existing, 'unknown', 'credentials changed');
		}

		return $id;
	}

	/**
	 * Job rows go with it through the ON DELETE CASCADE foreign key.
	 *
	 * @throws AccessDenied
	 * @throws DatabaseException
	 */
	public function delete(int $id): void {
		$this->requireConfigure();

		$this->repo->delete($id);
	}

	/**
	 * Touches only the flag, so it works for a vault account while the key
	 * file is missing and never invalidates a verify result.
	 *
	 * @throws AccessDenied
	 * @throws OutOfBoundsException
	 * @throws DatabaseException
	 */
	public function setEnabled(int $id, bool $on): void {
		$this->requireConfigure();
		$this->requireAccount($id);
		$this->repo->update($id, array('enabled' => $on ? 1 : 0, 'updated_at' => $this->clock->now()));
	}

	/**
	 * Calls Cloudflare and records the outcome on the account row. Failures
	 * are recorded as a status rather than thrown, so the caller always gets
	 * the updated account back.
	 *
	 * @throws AccessDenied
	 * @throws OutOfBoundsException
	 * @throws DatabaseException
	 */
	public function verify(int $id): Account {
		$this->requireConfigure();

		$account  = $this->requireAccount($id);
		$material = $this->repo->tokenMaterial($id);
		$now      = $this->clock->now();

		try {
			$token = $this->loadToken($material);
		} catch (SecretException $e) {
			if ($material['source'] === 'file') {
				$message = self::MESSAGE_FILE;
			} elseif ($e->getReason() === 'key_unavailable') {
				$message = self::MESSAGE_KEY_MISSING;
			} else {
				$message = self::MESSAGE_UNDECRYPTABLE;
			}

			return $this->record($account, $now, 'error', $message, null, 'reason=' . $e->getReason());
		}

		try {
			list($status, $message, $permissions, $detail) = $this->check($material['cf_account_id'], $token);
		} finally {
			if (function_exists('sodium_memzero')) {
				sodium_memzero($token);
			}
		}

		return $this->record($account, $now, $status, $message, $permissions, $detail);
	}

	/**
	 * Where an account's token lives, never the token or its ciphertext.
	 *
	 * @return array{token_source: string, token_file: string|null, has_token: bool}
	 *
	 * @throws AccessDenied
	 * @throws OutOfBoundsException
	 */
	public function credentialDetails(int $id): array {
		$this->requireConfigure();

		$account  = $this->requireAccount($id);
		$material = $this->repo->tokenMaterial($id);

		return array(
			'token_source' => $material['source'],
			'token_file'   => $material['file'],
			'has_token'    => $account->hasToken(),
		);
	}

	/**
	 * Needs no configure right: an Account carries no credential and no
	 * token location.
	 *
	 * @return array<int, Account>
	 */
	public function list(): array {
		return $this->repo->all();
	}

	private function requireConfigure(): void {
		if (!$this->auth->canConfigure()) {
			throw new AccessDenied();
		}
	}

	private function requireAccount(int $id): Account {
		$account = $this->repo->find($id);
		if ($account === null) {
			throw new OutOfBoundsException('Cloudflare account not found');
		}

		return $account;
	}

	/**
	 * A decrypt can legitimately return '' and a file can hold anything, so
	 * the policy check runs here whatever the source.
	 *
	 * @param array{source: string, ciphertext: string|null, file: string|null, cf_account_id: string} $material
	 *
	 * @throws SecretException
	 */
	private function loadToken(array $material): string {
		if ($material['source'] === 'file') {
			$token = TokenFile::read((string) $material['file']);
		} elseif ($this->vault === null) {
			throw new SecretException(SecretException::KEY_UNAVAILABLE);
		} elseif ($material['ciphertext'] === null) {
			throw new SecretException(SecretException::DECRYPT_FAILED);
		} else {
			$token = $this->vault->decrypt($material['ciphertext'], TokenVault::aadFor($material['cf_account_id']));
		}

		TokenPolicy::validate($token);

		return $token;
	}

	/**
	 * @return array{0: string, 1: string, 2: array<string, bool>|null, 3: string} status, message, permissions, log detail
	 */
	private function check(
		string $cfAccountId,
		#[\SensitiveParameter]
		string $token
	): array {
		try {
			if (!$this->client->verifyToken($cfAccountId, $token)->isActive()) {
				return array('token_inactive', self::MESSAGES['token_inactive'], null, '');
			}

			$tunnelRead = $this->client->probe(
				$cfAccountId,
				$token,
				'/accounts/' . $cfAccountId . '/cfd_tunnel',
				array('per_page' => 1)
			);
			$zoneRead   = $this->client->probe(
				$cfAccountId,
				$token,
				'/zones',
				array('account.id' => $cfAccountId, 'per_page' => 5)
			);
		} catch (ApiException $e) {
			$class  = $e->getErrorClass();
			$status = self::statusFor($class);

			/* The probes turn a 403 into false, so one here came from both
			 * verify endpoints refusing the token, not from the tunnel probe. */
			$message = $class === ErrorClass::PERMISSION ? self::MESSAGE_FORBIDDEN : self::MESSAGES[$status];

			return array($status, $message, null, 'class=' . $class);
		}

		$permissions = array('tunnel_read' => $tunnelRead, 'zone_read' => $zoneRead);
		$status      = $tunnelRead ? 'ok' : 'permission_denied';

		/* Zone read only feeds hostname mapping, so its absence is not a failure. */
		return array($status, self::MESSAGES[$status], $permissions, $tunnelRead ? '' : 'tunnel_read=false');
	}

	private static function statusFor(string $errorClass): string {
		switch ($errorClass) {
			case ErrorClass::AUTH:
				return 'auth_failed';
			case ErrorClass::PERMISSION:
				return 'permission_denied';
			case ErrorClass::TRANSIENT:
				return 'unreachable';
			case ErrorClass::RATE_LIMITED:
				return 'rate_limited';
		}

		return 'error';
	}

	/**
	 * @param array<string, bool>|null $permissions written only when both probes ran
	 */
	private function record(
		Account $account,
		int $now,
		string $status,
		string $message,
		?array $permissions,
		string $detail
	): Account {
		$row = array(
			'status'          => $status,
			'status_message'  => $message,
			'last_checked_at' => $now,
		);
		if ($permissions !== null) {
			$row['permissions'] = (string) json_encode($permissions);
		}
		if ($status === 'ok') {
			$row['last_verified_at'] = $now;
		}

		$this->repo->update($account->id(), $row);
		$this->logTransition($account, $status, $detail);

		return $this->requireAccount($account->id());
	}

	private function logTransition(Account $account, string $status, string $detail): void {
		if ($this->log === null || $account->status() === $status) {
			return;
		}

		$line = 'CLOUDFLARE: account ' . $account->id() . ' (' . $account->cfAccountId() . ') status '
			. $account->status() . ' -> ' . $status;
		if ($detail !== '') {
			$line .= ' [' . $detail . ']';
		}

		($this->log)($line);
	}

	/**
	 * @param array<string, mixed> $input
	 */
	private static function text(
		#[\SensitiveParameter]
		array $input,
		string $key
	): string {
		$value = $input[$key] ?? '';

		if (is_int($value)) {
			return (string) $value;
		}

		return is_string($value) ? $value : '';
	}

	/**
	 * @param mixed $value
	 */
	private static function flag($value): ?int {
		if ($value === true || $value === 1 || $value === '1') {
			return 1;
		}
		if ($value === false || $value === 0 || $value === '0') {
			return 0;
		}

		return null;
	}

	/**
	 * @param mixed $value
	 */
	private static function interval($value): ?int {
		if (is_string($value) && preg_match('/^\s*[0-9]{1,6}\s*\z/', $value) === 1) {
			$value = (int) trim($value);
		}

		if (!is_int($value) || $value < 60 || $value > 86400) {
			return null;
		}

		return $value;
	}

	private static function existingFlag(Account $account, string $field): int {
		switch ($field) {
			case 'enabled':
				return $account->isEnabled() ? 1 : 0;
			case 'collect_tunnels':
				return $account->collectsTunnels() ? 1 : 0;
		}

		return $account->collectsAnalytics() ? 1 : 0;
	}
}
