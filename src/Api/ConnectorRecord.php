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

namespace Cacti\PluginCloudflare\Api;

final class ConnectorRecord {
	/** @var string */
	public $id;

	/** @var string */
	public $version;

	/** @var string */
	public $arch;

	/** @var int|null */
	public $runAt;

	/** @var int */
	public $connectionCount;

	/** @var array<int, string> */
	public $colos;

	/** @var array<int, string> */
	public $originIps;

	/**
	 * @param array<int, string> $colos     sorted, unique
	 * @param array<int, string> $originIps sorted, unique, valid IPs only
	 */
	public function __construct(
		string $id,
		string $version,
		string $arch,
		?int $runAt,
		int $connectionCount,
		array $colos,
		array $originIps
	) {
		$this->id              = $id;
		$this->version         = $version;
		$this->arch            = $arch;
		$this->runAt           = $runAt;
		$this->connectionCount = $connectionCount;
		$this->colos           = $colos;
		$this->originIps       = $originIps;
	}
}
