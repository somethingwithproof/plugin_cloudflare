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

use Cacti\PluginCloudflare\Secret\SecretException;
use Cacti\PluginCloudflare\Secret\TokenFile;
use Cacti\PluginCloudflare\Secret\TokenPolicy;
use Cacti\PluginCloudflare\Secret\TokenVault;
use OutOfBoundsException;

/**
 * Returns an account's API token from wherever it is stored.
 *
 * Performs no authorization. Its callers are AccountService::verify, which
 * has already checked the right, and the background collector, which runs in
 * system context and is not reachable from a page. It never logs.
 */
final class CredentialResolver {
	/** @var AccountRepository */
	private $repo;

	/** @var TokenVault|null */
	private $vault;

	/**
	 * @param TokenVault|null $vault null when the key file is missing; only the file source is then accepted
	 */
	public function __construct(AccountRepository $repo, ?TokenVault $vault) {
		$this->repo  = $repo;
		$this->vault = $vault;
	}

	/**
	 * A decrypt can legitimately return '' and a file can hold anything, so
	 * the policy check runs here whatever the source.
	 *
	 * @throws SecretException
	 * @throws OutOfBoundsException when $accountId names no account
	 */
	public function resolve(int $accountId): string {
		return $this->resolveMaterial($this->repo->tokenMaterial($accountId));
	}

	/**
	 * For a caller that already holds the material and must act on the same
	 * read, rather than on a second one that may see a changed row.
	 *
	 * @param array{source: string, ciphertext: string|null, file: string|null, cf_account_id: string} $material
	 *
	 * @throws SecretException
	 */
	public function resolveMaterial(
		#[\SensitiveParameter]
		array $material
	): string {
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
}
