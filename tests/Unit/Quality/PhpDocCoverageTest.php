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

namespace Somethingwithproof\Cloudflare\Tests\Unit\Quality;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Enforces 100% PHPDoc coverage across every production PHP file in the plugin
 * using PHP's native tokenizer.
 */
final class PhpDocCoverageTest extends TestCase {
	/** @var string Repository root directory. */
	private const ROOT = __DIR__ . '/../../..';

	/**
	 * Verify every class, interface, trait, class constant, property, method,
	 * and named function in production PHP files carries a T_DOC_COMMENT.
	 */
	public function testEveryProductionDeclarationHasPhpDoc(): void {
		$root  = (string) realpath(self::ROOT);
		$files = self::productionPhpFiles($root);

		$this->assertGreaterThan(60, count($files), 'Expected to scan all production PHP files');

		$missing = array();
		$checked = 0;

		foreach ($files as $file) {
			$relative = substr($file, strlen($root) + 1);
			$result   = self::scanFile($file, $relative);
			$checked += $result['checked'];

			foreach ($result['missing'] as $item) {
				$missing[] = $item;
			}
		}

		$this->assertGreaterThan(500, $checked, 'Expected to check all production declarations');
		$this->assertSame(
			array(),
			$missing,
			"Production PHP declarations missing PHPDoc:\n" . implode("\n", $missing)
		);
	}

	/**
	 * Collect all production PHP files (root entry points, includes/, cli/, src/).
	 *
	 * @param string $root Absolute repository root path
	 * @return array<int, string>
	 */
	private static function productionPhpFiles(string $root): array {
		$files = array();

		foreach (array($root, $root . '/includes', $root . '/cli') as $dir) {
			if (!is_dir($dir)) {
				continue;
			}

			$entries = scandir($dir);
			if (!is_array($entries)) {
				continue;
			}

			foreach ($entries as $entry) {
				if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
					continue;
				}

				$path = $dir . '/' . $entry;
				if (is_file($path) && substr($entry, -4) === '.php') {
					$files[] = $path;
				}
			}
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($root . '/src', RecursiveDirectoryIterator::SKIP_DOTS)
		);

		foreach ($iterator as $info) {
			if ($info instanceof SplFileInfo && $info->isFile() && substr($info->getFilename(), -4) === '.php') {
				$files[] = $info->getPathname();
			}
		}

		sort($files);

		return $files;
	}

	/**
	 * Tokenize a PHP file and verify every declaration has a preceding T_DOC_COMMENT.
	 *
	 * @param string $path     Absolute file path
	 * @param string $relative Repository-relative path for reporting
	 * @return array{checked: int, missing: array<int, string>}
	 */
	private static function scanFile(string $path, string $relative): array {
		$source          = (string) file_get_contents($path);
		$tokens          = token_get_all($source);
		$count           = count($tokens);
		$braceDepth      = 0;
		$classDepthStack = array();
		$checked         = 0;
		$missing         = array();

		for ($i = 0; $i < $count; $i++) {
			$tok = $tokens[$i];

			if ($tok === '{') {
				$braceDepth++;
				continue;
			}

			if ($tok === '}') {
				$braceDepth--;
				if ($classDepthStack !== array() && end($classDepthStack) === $braceDepth) {
					array_pop($classDepthStack);
				}
				continue;
			}

			if (!is_array($tok)) {
				continue;
			}

			$id = $tok[0];

			if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
				$braceDepth++;
				continue;
			}

			$inClassBody = $classDepthStack !== array() && (end($classDepthStack) + 1) === $braceDepth;

			if ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT) {
				$prev = self::previousSignificant($tokens, $i);
				if ($prev !== null && is_array($tokens[$prev]) && ($tokens[$prev][0] === T_DOUBLE_COLON || $tokens[$prev][0] === T_NEW)) {
					continue;
				}

				$name              = self::nextName($tokens, $i + 1, $count);
				$classDepthStack[] = $braceDepth;
				$checked++;

				if (!self::hasPrecedingDocComment($tokens, $i, false)) {
					$missing[] = sprintf('%s:%d %s %s', $relative, $tok[2], $tok[1], $name);
				}
			} elseif ($id === T_FUNCTION) {
				$k = self::nextSignificant($tokens, $i + 1, $count);
				if ($k !== null && $tokens[$k] === '&') {
					$k = self::nextSignificant($tokens, $k + 1, $count);
				}

				if ($k === null || !is_array($tokens[$k]) || $tokens[$k][0] !== T_STRING) {
					// Anonymous closure
					continue;
				}

				$name = $tokens[$k][1];
				$checked++;

				if (!self::hasPrecedingDocComment($tokens, $i, false)) {
					$missing[] = sprintf('%s:%d function %s', $relative, $tok[2], $name);
				}
			} elseif ($id === T_CONST && $inClassBody) {
				$name = self::nextName($tokens, $i + 1, $count);
				$checked++;

				if (!self::hasPrecedingDocComment($tokens, $i, false)) {
					$missing[] = sprintf('%s:%d const %s', $relative, $tok[2], $name);
				}
			} elseif ($id === T_VARIABLE && $inClassBody) {
				if (!self::isPropertyDeclaration($tokens, $i)) {
					continue;
				}

				$checked++;

				if (!self::hasPrecedingDocComment($tokens, $i, true)) {
					$missing[] = sprintf('%s:%d property %s', $relative, $tok[2], $tok[1]);
				}
			}
		}

		return array('checked' => $checked, 'missing' => $missing);
	}

	/**
	 * Determine whether a T_VARIABLE inside a class body is a property declaration.
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens Token list
	 * @param int                                                 $index  Variable token index
	 * @return bool
	 */
	private static function isPropertyDeclaration(array $tokens, int $index): bool {
		for ($j = $index - 1; $j >= 0; $j--) {
			$t = $tokens[$j];

			if ($t === '?') {
				continue;
			}

			if (!is_array($t)) {
				return false;
			}

			if (in_array($t[0], array(T_WHITESPACE, T_COMMENT, T_STRING, T_NS_SEPARATOR, T_ARRAY, T_CALLABLE), true)) {
				continue;
			}

			return in_array($t[0], array(T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_VAR), true);
		}

		return false;
	}

	/**
	 * Check whether a declaration token is immediately preceded by a T_DOC_COMMENT
	 * (allowing modifiers, optional typed property tokens, whitespace, and inline comments).
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens     Token list
	 * @param int                                                 $index      Declaration token index
	 * @param bool                                                $isProperty True when checking a class property
	 * @return bool
	 */
	private static function hasPrecedingDocComment(array $tokens, int $index, bool $isProperty): bool {
		$allowed = array(
			T_WHITESPACE,
			T_COMMENT,
			T_PUBLIC,
			T_PROTECTED,
			T_PRIVATE,
			T_STATIC,
			T_FINAL,
			T_ABSTRACT,
			T_VAR,
		);

		if ($isProperty) {
			$allowed[] = T_STRING;
			$allowed[] = T_NS_SEPARATOR;
			$allowed[] = T_ARRAY;
			$allowed[] = T_CALLABLE;
		}

		for ($j = $index - 1; $j >= 0; $j--) {
			$t = $tokens[$j];

			if ($isProperty && $t === '?') {
				continue;
			}

			if (!is_array($t)) {
				return false;
			}

			if ($t[0] === T_DOC_COMMENT) {
				return true;
			}

			if (!in_array($t[0], $allowed, true)) {
				return false;
			}
		}

		return false;
	}

	/**
	 * Find the previous non-whitespace, non-comment token index.
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens Token list
	 * @param int                                                 $index  Starting index
	 * @return int|null
	 */
	private static function previousSignificant(array $tokens, int $index): ?int {
		for ($j = $index - 1; $j >= 0; $j--) {
			$t = $tokens[$j];
			if (is_array($t) && ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
				continue;
			}

			return $j;
		}

		return null;
	}

	/**
	 * Find the next non-whitespace, non-comment token index.
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens Token list
	 * @param int                                                 $start  Starting index
	 * @param int                                                 $count  Token count
	 * @return int|null
	 */
	private static function nextSignificant(array $tokens, int $start, int $count): ?int {
		for ($k = $start; $k < $count; $k++) {
			$t = $tokens[$k];
			if (is_array($t) && ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
				continue;
			}

			return $k;
		}

		return null;
	}

	/**
	 * Extract the identifier name following a declaration keyword.
	 *
	 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens Token list
	 * @param int                                                 $start  Index after keyword
	 * @param int                                                 $count  Token count
	 * @return string
	 */
	private static function nextName(array $tokens, int $start, int $count): string {
		$k = self::nextSignificant($tokens, $start, $count);

		if ($k !== null && is_array($tokens[$k]) && $tokens[$k][0] === T_STRING) {
			return $tokens[$k][1];
		}

		return '?';
	}
}
