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

use Cacti\PluginCloudflare\Account\AccountRepository;
use Cacti\PluginCloudflare\Account\AccountService;
use Cacti\PluginCloudflare\Api\CloudflareClient;
use Cacti\PluginCloudflare\Api\RetryPolicy;
use Cacti\PluginCloudflare\Auth\CallbackAuthorizer;
use Cacti\PluginCloudflare\Secret\TokenVault;
use Cacti\PluginCloudflare\Storage\Database;
use Cacti\PluginCloudflare\Storage\Migrator;
use Cacti\PluginCloudflare\Tests\Support\FakeTransport;
use Cacti\PluginCloudflare\Tests\Support\FrozenClock;
use Cacti\PluginCloudflare\Tests\Support\RecordingSleeper;
use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use PDOException;
use RuntimeException;

/**
 * One scenario's collaborators: a real database, the real AccountService and
 * a fake Cloudflare transport. Construction mirrors AccountServiceTest.
 */
final class World {
	const ACCOUNT = 'ed17574386854bf78a67040be0a770b0';
	const NOW     = 1800000000;
	const OK_BODY = '{"success":true,"errors":[],"messages":[],"result":[]}';

	/** @var Database */
	private $db;

	/** @var string */
	private $dir;

	/** @var TokenVault */
	private $vault;

	/** @var FakeTransport */
	private $transport;

	/** @var string */
	private $token;

	/** @var bool */
	private $allowed = true;

	/** @var array<int, string> */
	private $logged = array();

	/** @var AccountService */
	private $service;

	/** @var AccountRepository */
	private $repo;

	public function __construct() {
		try {
			TestDatabase::reset();
			$this->db = new Database(TestDatabase::pdo());
		} catch (PDOException $e) {
			throw new RuntimeException('Test database unreachable (run make db-up)');
		}
		(new Migrator($this->db, Migrator::defaultMigrations()))->migrate();

		$this->dir = sys_get_temp_dir() . '/cf-bdd-' . bin2hex(random_bytes(6));
		mkdir($this->dir, 0700);
		$key = $this->dir . '/key';
		file_put_contents($key, random_bytes(32));
		chmod($key, 0600);

		$this->vault     = TokenVault::fromKeyFile($key);
		$this->transport = new FakeTransport();
		$this->token     = 'cfut_' . bin2hex(random_bytes(20));

		$clock         = new FrozenClock(self::NOW);
		$client        = new CloudflareClient($this->transport, new RetryPolicy($clock, new RecordingSleeper()));
		$this->repo    = new AccountRepository($this->db);
		$this->service = new AccountService(
			$this->repo,
			new CallbackAuthorizer(function (): bool {
				return $this->allowed;
			}),
			$client,
			$this->vault,
			$clock,
			function (string $line): void {
				$this->logged[] = $line;
			}
		);
	}

	public function close(): void {
		foreach ((array) scandir($this->dir) as $name) {
			if ($name !== '.' && $name !== '..') {
				unlink($this->dir . '/' . $name);
			}
		}
		rmdir($this->dir);

		TestDatabase::reset();
	}

	public function service(): AccountService {
		return $this->service;
	}

	public function repository(): AccountRepository {
		return $this->repo;
	}

	public function transport(): FakeTransport {
		return $this->transport;
	}

	public function setAllowed(bool $allowed): void {
		$this->allowed = $allowed;
	}

	public function token(): string {
		return $this->token;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function accountInput(): array {
		return array(
			'cf_account_id'      => self::ACCOUNT,
			'name'               => 'Production',
			'token_source'       => 'vault',
			'token'              => $this->token,
			'token_file'         => '',
			'enabled'            => 1,
			'collect_tunnels'    => 1,
			'collect_analytics'  => 0,
			'discovery_interval' => 300,
		);
	}

	/**
	 * @return array<int, string>
	 */
	public function logLines(): array {
		return $this->logged;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function rows(): array {
		return $this->db->fetchAll('SELECT * FROM plugin_cloudflare_accounts ORDER BY id');
	}
}
