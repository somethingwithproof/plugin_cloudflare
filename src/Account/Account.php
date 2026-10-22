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

/**
 * Read model of one account row. Neither the ciphertext nor where the token
 * lives is kept in it, so dumping, exporting or rendering an Account cannot
 * expose a credential; AccountService::credentialDetails() holds the rest
 * behind the configure right.
 */
final class Account {
	/** @var int */
	private $id;

	/** @var string */
	private $cfAccountId;

	/** @var string */
	private $name;

	/** @var bool */
	private $hasToken;

	/** @var bool */
	private $enabled;

	/** @var string */
	private $status;

	/** @var string */
	private $statusMessage;

	/** @var array<string, bool>|null */
	private $permissions;

	/** @var int|null */
	private $lastCheckedAt;

	/** @var int|null */
	private $lastVerifiedAt;

	/** @var bool */
	private $collectTunnels;

	/** @var bool */
	private $collectAnalytics;

	/** @var int */
	private $discoveryInterval;

	/** @var int */
	private $createdAt;

	/** @var int */
	private $updatedAt;

	/**
	 * @param array<string, mixed> $row a repository row with has_ciphertext in place of token_ciphertext
	 */
	public static function fromRow(array $row): Account {
		$account = new Account();

		$account->id                = (int) $row['id'];
		$account->cfAccountId       = (string) $row['cf_account_id'];
		$account->name              = (string) $row['name'];
		$account->enabled           = (int) $row['enabled'] === 1;
		$account->status            = (string) $row['status'];
		$account->statusMessage     = (string) $row['status_message'];
		$account->permissions       = self::decodePermissions($row['permissions']);
		$account->lastCheckedAt     = $row['last_checked_at'] === null ? null : (int) $row['last_checked_at'];
		$account->lastVerifiedAt    = $row['last_verified_at'] === null ? null : (int) $row['last_verified_at'];
		$account->collectTunnels    = (int) $row['collect_tunnels'] === 1;
		$account->collectAnalytics  = (int) $row['collect_analytics'] === 1;
		$account->discoveryInterval = (int) $row['discovery_interval'];
		$account->createdAt         = (int) $row['created_at'];
		$account->updatedAt         = (int) $row['updated_at'];

		if ((string) $row['token_source'] === 'file') {
			$account->hasToken = $row['token_file'] !== null && (string) $row['token_file'] !== '';
		} else {
			$account->hasToken = (int) $row['has_ciphertext'] === 1;
		}

		return $account;
	}

	private function __construct() {
	}

	public function id(): int {
		return $this->id;
	}

	public function cfAccountId(): string {
		return $this->cfAccountId;
	}

	public function name(): string {
		return $this->name;
	}

	public function hasToken(): bool {
		return $this->hasToken;
	}

	public function isEnabled(): bool {
		return $this->enabled;
	}

	public function status(): string {
		return $this->status;
	}

	public function statusMessage(): string {
		return $this->statusMessage;
	}

	/**
	 * @return array<string, bool>|null null until a verify has run both probes
	 */
	public function permissions(): ?array {
		return $this->permissions;
	}

	public function lastCheckedAt(): ?int {
		return $this->lastCheckedAt;
	}

	public function lastVerifiedAt(): ?int {
		return $this->lastVerifiedAt;
	}

	public function collectsTunnels(): bool {
		return $this->collectTunnels;
	}

	public function collectsAnalytics(): bool {
		return $this->collectAnalytics;
	}

	public function discoveryInterval(): int {
		return $this->discoveryInterval;
	}

	public function createdAt(): int {
		return $this->createdAt;
	}

	public function updatedAt(): int {
		return $this->updatedAt;
	}

	/**
	 * @param mixed $json
	 * @return array<string, bool>|null
	 */
	private static function decodePermissions($json): ?array {
		if (!is_string($json) || $json === '') {
			return null;
		}

		$decoded = json_decode($json, true);
		if (!is_array($decoded)) {
			return null;
		}

		$permissions = array();
		foreach ($decoded as $name => $granted) {
			if (is_string($name) && is_bool($granted)) {
				$permissions[$name] = $granted;
			}
		}

		return $permissions;
	}
}
