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

namespace Somethingwithproof\Cloudflare\Secret;

final class TokenFile {
	private const MAX_BYTES = 4096;

	/**
	 * The mode and size checks use fstat() on the open handle so they describe
	 * the file actually read. is_file() runs first so a FIFO cannot block
	 * fopen().
	 *
	 * $directory, when not '', confines the path before anything else touches
	 * the filesystem, so a path outside it reveals nothing about the file.
	 */
	public static function read(string $path, string $directory = ''): string {
		if (strpos($path, "\0") !== false) {
			throw new SecretException(SecretException::FILE_UNAVAILABLE);
		}

		if ($path === '' || $path[0] !== '/') {
			throw new SecretException(SecretException::FILE_INSECURE, $path);
		}

		if ($directory !== '' && !self::inside($path, $directory)) {
			throw new SecretException(SecretException::FILE_OUTSIDE, $path);
		}

		if (is_link($path)) {
			throw new SecretException(SecretException::FILE_INSECURE, $path);
		}

		if (!is_file($path)) {
			throw new SecretException(SecretException::FILE_UNAVAILABLE, $path);
		}

		$fh = @fopen($path, 'rb');
		if ($fh === false) {
			throw new SecretException(SecretException::FILE_UNAVAILABLE, $path);
		}

		try {
			$stat = fstat($fh);
			if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] > self::MAX_BYTES) {
				throw new SecretException(SecretException::FILE_UNAVAILABLE, $path);
			}

			if (($stat['mode'] & 0006) !== 0) {
				throw new SecretException(SecretException::FILE_INSECURE, $path);
			}

			$raw = stream_get_contents($fh, self::MAX_BYTES + 1);
		} finally {
			fclose($fh);
		}

		if ($raw === false || strlen($raw) > self::MAX_BYTES) {
			throw new SecretException(SecretException::FILE_UNAVAILABLE, $path);
		}

		$token = trim($raw);
		if (function_exists('sodium_memzero')) {
			sodium_memzero($raw);
		}

		TokenPolicy::validate($token);

		return $token;
	}

	/**
	 * The parent is resolved, so a symlinked directory cannot lead out; the
	 * file itself must not be a symlink, which read() checks next.
	 */
	private static function inside(string $path, string $directory): bool {
		if (preg_match('#(^|/)\.\.?(/|\z)#', $path) === 1 || substr($path, -1) === '/') {
			return false;
		}

		$root   = realpath($directory);
		$parent = realpath(dirname($path));
		if ($root === false || $parent === false || !is_dir($root)) {
			return false;
		}

		return strpos($parent . '/', rtrim($root, '/') . '/') === 0;
	}
}
