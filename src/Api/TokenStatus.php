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

namespace Cacti\PluginCloudflare\Api;

final class TokenStatus {
	/** @var string */
	private $id;

	/** @var string */
	private $status;

	/** @var int|null */
	private $expiresOn;

	public function __construct(string $id, string $status, ?int $expiresOn) {
		$this->id        = $id;
		$this->status    = $status;
		$this->expiresOn = $expiresOn;
	}

	public function id(): string {
		return $this->id;
	}

	public function status(): string {
		return $this->status;
	}

	/** Epoch seconds, or null when Cloudflare reports no usable expiry. */
	public function expiresOn(): ?int {
		return $this->expiresOn;
	}

	public function isActive(): bool {
		return $this->status === 'active';
	}
}
