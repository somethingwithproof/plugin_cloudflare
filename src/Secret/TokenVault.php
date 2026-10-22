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

namespace Cacti\PluginCloudflare\Secret;

use LogicException;

/**
 * Stored format: 'v1:' . base64(nonce[12] . tag[16] . ciphertext). The AAD
 * binds a ciphertext to its account row, so a blob copied to another row
 * fails authentication instead of decrypting.
 */
final class TokenVault {
	private const CIPHER = 'aes-256-gcm';
	private const PREFIX = 'v1:';
	private const NONCE_BYTES = 12;
	private const TAG_BYTES = 16;
	private const KEY_BYTES = 32;

	/**
	 * Keys live here, indexed by spl_object_id(), rather than in an instance
	 * property: var_export(), (array) casts and get_object_vars() expose
	 * private properties and no magic method can stop them.
	 *
	 * @var array<int, string>
	 */
	private static $keys = array();

	private function __construct(string $key) {
		self::$keys[spl_object_id($this)] = $key;
	}

	public function __destruct() {
		$id = spl_object_id($this);

		if (isset(self::$keys[$id])) {
			if (function_exists('sodium_memzero')) {
				sodium_memzero(self::$keys[$id]);
			}
			unset(self::$keys[$id]);
		}
	}

	private function __clone() {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function __sleep(): array {
		throw new LogicException('TokenVault cannot be serialized');
	}

	public function __wakeup(): void {
		throw new LogicException('TokenVault cannot be unserialized');
	}

	/**
	 * @return array<string, mixed>
	 */
	public function __serialize(): array {
		throw new LogicException('TokenVault cannot be serialized');
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function __unserialize(array $data): void {
		throw new LogicException('TokenVault cannot be unserialized');
	}

	/**
	 * @return array<string, string>
	 */
	public function __debugInfo(): array {
		return array('key' => '[REDACTED]');
	}

	/**
	 * Accepts exactly 32 raw bytes, or 64 hex characters with at most one
	 * trailing newline. Anything else is refused rather than guessed at,
	 * including 32 hex characters, which is a 16-byte key written as hex.
	 */
	public static function fromKeyFile(string $path): TokenVault {
		if (strpos($path, "\0") !== false || $path === '' || $path[0] !== '/' || is_link($path) || !is_file($path)) {
			throw new SecretException(SecretException::KEY_UNAVAILABLE, $path);
		}

		$fh = @fopen($path, 'rb');
		if ($fh === false) {
			throw new SecretException(SecretException::KEY_UNAVAILABLE, $path);
		}

		try {
			$stat = fstat($fh);
			if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 0006) !== 0 || $stat['size'] > 65) {
				throw new SecretException(SecretException::KEY_UNAVAILABLE, $path);
			}

			$raw = stream_get_contents($fh, 66);
		} finally {
			fclose($fh);
		}

		if ($raw === false) {
			throw new SecretException(SecretException::KEY_UNAVAILABLE, $path);
		}

		$key = false;
		if (strlen($raw) === self::KEY_BYTES && preg_match('/^[0-9A-Fa-f]{32}\z/', $raw) !== 1) {
			$key = $raw;
		} elseif (preg_match('/^[0-9A-Fa-f]{64}\n?\z/', $raw) === 1) {
			$key = hex2bin(substr($raw, 0, 64));
		}
		if (function_exists('sodium_memzero')) {
			sodium_memzero($raw);
		}

		if (!is_string($key) || strlen($key) !== self::KEY_BYTES) {
			throw new SecretException(SecretException::KEY_UNAVAILABLE, $path);
		}

		return new TokenVault($key);
	}

	/**
	 * An instance built without the constructor has no key. Failing here
	 * matters because openssl pads a short or empty key with zero bytes.
	 */
	private function key(int $reason): string {
		$id = spl_object_id($this);

		if (!isset(self::$keys[$id])) {
			throw new SecretException($reason);
		}

		return self::$keys[$id];
	}

	public static function aadFor(string $cfAccountId): string {
		return 'plugin_cloudflare:account:' . $cfAccountId;
	}

	public function encrypt(
		#[\SensitiveParameter]
		string $token,
		string $aad
	): string {
		$nonce = random_bytes(self::NONCE_BYTES);
		$tag = '';
		$ciphertext = openssl_encrypt($token, self::CIPHER, $this->key(SecretException::ENCRYPT_FAILED), OPENSSL_RAW_DATA, $nonce, $tag, $aad, self::TAG_BYTES);

		if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
			throw new SecretException(SecretException::ENCRYPT_FAILED);
		}

		return self::PREFIX . base64_encode($nonce . $tag . $ciphertext);
	}

	/**
	 * Tag comparison is left to openssl_decrypt(), which fails closed on any
	 * mismatch in key, nonce, tag, ciphertext or AAD.
	 */
	public function decrypt(string $blob, string $aad): string {
		if (strncmp($blob, self::PREFIX, strlen(self::PREFIX)) !== 0) {
			throw new SecretException(SecretException::DECRYPT_FAILED);
		}

		$raw = base64_decode(substr($blob, strlen(self::PREFIX)), true);
		if ($raw === false || strlen($raw) < self::NONCE_BYTES + self::TAG_BYTES) {
			throw new SecretException(SecretException::DECRYPT_FAILED);
		}

		$nonce = substr($raw, 0, self::NONCE_BYTES);
		$tag = substr($raw, self::NONCE_BYTES, self::TAG_BYTES);
		$ciphertext = substr($raw, self::NONCE_BYTES + self::TAG_BYTES);

		$token = openssl_decrypt($ciphertext, self::CIPHER, $this->key(SecretException::DECRYPT_FAILED), OPENSSL_RAW_DATA, $nonce, $tag, $aad);
		if ($token === false) {
			throw new SecretException(SecretException::DECRYPT_FAILED);
		}

		return $token;
	}
}
