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

namespace Somethingwithproof\Cloudflare\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;

/**
 * Checks every catalog entry against its template entry. msgfmt --check only
 * compares placeholders of entries flagged php-format, so entries without the
 * flag, HTML tags and edge whitespace are compared here, and each compiled
 * catalog is read back the way Cacti's gettext reader reads it.
 */
final class CatalogTest extends TestCase {
	private const LOCALES = array(
		'af-ZA'  => 'nplurals=2; plural=(n != 1);',
		'am-ET'  => 'nplurals=2; plural=(n > 1);',
		'ar-EG'  => 'nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 && n%100<=99 ? 4 : 5);',
		'ar-MA'  => 'nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 && n%100<=99 ? 4 : 5);',
		'ar-SA'  => 'nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 && n%100<=99 ? 4 : 5);',
		'bn-BD'  => 'nplurals=2; plural=(n > 1);',
		'de-DE'  => 'nplurals=2; plural=(n != 1);',
		'es-419' => 'nplurals=2; plural=(n != 1);',
		'es-ES'  => 'nplurals=2; plural=(n != 1);',
		'fa-IR'  => 'nplurals=2; plural=(n > 1);',
		'fil-PH' => 'nplurals=2; plural=(n != 1 && n != 2 && n != 3 && (n % 10 == 4 || n % 10 == 6 || n % 10 == 9));',
		'fr-FR'  => 'nplurals=2; plural=(n > 1);',
		'fr-MA'  => 'nplurals=2; plural=(n > 1);',
		'fr-SN'  => 'nplurals=2; plural=(n > 1);',
		'ha-NG'  => 'nplurals=2; plural=(n != 1);',
		'he-IL'  => 'nplurals=4; plural=(n == 1) ? 0 : ((n == 2) ? 1 : ((n > 10 && n % 10 == 0) ? 2 : 3));',
		'hi-IN'  => 'nplurals=2; plural=(n != 1);',
		'id-ID'  => 'nplurals=1; plural=0;',
		'ig-NG'  => 'nplurals=1; plural=0;',
		'it-IT'  => 'nplurals=2; plural=(n != 1);',
		'ja-JP'  => 'nplurals=1; plural=0;',
		'ko-KR'  => 'nplurals=1; plural=0;',
		'mg-MG'  => 'nplurals=2; plural=(n > 1);',
		'mr-IN'  => 'nplurals=2; plural=(n != 1);',
		'ms-MY'  => 'nplurals=1; plural=0;',
		'nl-NL'  => 'nplurals=2; plural=(n != 1);',
		'pl-PL'  => 'nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : 2);',
		'pt-BR'  => 'nplurals=2; plural=(n > 1);',
		'ru-RU'  => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : 2);',
		'rw-RW'  => 'nplurals=2; plural=(n != 1);',
		'so-SO'  => 'nplurals=2; plural=(n != 1);',
		'sv-SE'  => 'nplurals=2; plural=(n != 1);',
		'sw-KE'  => 'nplurals=2; plural=(n != 1);',
		'ta-IN'  => 'nplurals=2; plural=(n != 1);',
		'te-IN'  => 'nplurals=2; plural=(n != 1);',
		'th-TH'  => 'nplurals=1; plural=0;',
		'tr-TR'  => 'nplurals=2; plural=(n != 1);',
		'uk-UA'  => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : 2);',
		'ur-PK'  => 'nplurals=2; plural=(n != 1);',
		'vi-VN'  => 'nplurals=1; plural=0;',
		'xh-ZA'  => 'nplurals=2; plural=(n != 1);',
		'yo-NG'  => 'nplurals=1; plural=0;',
		'zh-CN'  => 'nplurals=1; plural=0;',
		'zh-TW'  => 'nplurals=1; plural=0;',
		'zu-ZA'  => 'nplurals=2; plural=(n > 1);',
	);

	private const ROOT = __DIR__ . '/../../..';

	/**
	 * Entries are separated by blank lines; the entry with an empty msgid is
	 * the header.
	 *
	 * @return array{header: string, entries: array<string, array{plural: string|null, msgstr: list<string>}>}
	 */
	private static function parse(string $path): array {
		$entries = array();
		$header  = '';

		foreach (preg_split('/\n\s*\n/', trim((string) file_get_contents($path))) ?: array() as $block) {
			$msgid  = '';
			$plural = null;
			$msgstr = array();
			$target = null;

			foreach (explode("\n", $block) as $line) {
				if ($line === '' || $line[0] === '#') {
					continue;
				}

				if (preg_match('/^(msgid_plural|msgid|msgstr)(?:\[(\d+)\])?\s+"(.*)"$/', $line, $m) === 1) {
					$target = $m[1] === 'msgstr' ? (int) ($m[2] === '' ? 0 : $m[2]) : $m[1];
					$text   = stripcslashes($m[3]);
				} elseif (preg_match('/^"(.*)"$/', $line, $m) === 1 && $target !== null) {
					$text = stripcslashes($m[1]);
				} else {
					continue;
				}

				if (is_int($target)) {
					$msgstr[$target] = ($msgstr[$target] ?? '') . $text;
				} elseif ($target === 'msgid_plural') {
					$plural = ($plural ?? '') . $text;
				} else {
					$msgid .= $text;
				}
			}

			if ($msgid === '') {
				$header = (string) ($msgstr[0] ?? '');
			} else {
				$entries[$msgid] = array('plural' => $plural, 'msgstr' => array_values($msgstr));
			}
		}

		return array('header' => $header, 'entries' => $entries);
	}

	/**
	 * @return list<string>
	 */
	private static function tokens(string $text): array {
		preg_match_all('/%(?:\d+\$)?[-+ 0#]*\d*(?:\.\d+)?[sdfuxXcoeEgGi]|%%|<[^>]*>/', $text, $m);
		sort($m[0]);

		return $m[0];
	}

	private static function edges(string $text): string {
		return substr($text, 0, strlen($text) - strlen(ltrim($text))) . '|' . substr($text, strlen(rtrim($text)));
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function locales(): array {
		$rows = array();

		foreach (self::LOCALES as $locale => $_) {
			$rows[$locale] = array($locale);
		}

		return $rows;
	}

	public function testEveryCatalogIsListed(): void {
		$found = array();

		foreach ((array) glob(self::ROOT . '/locales/po/*.po') as $path) {
			$found[] = basename((string) $path, '.po');
		}

		sort($found);
		$expected = array_keys(self::LOCALES);
		sort($expected);

		$this->assertSame($expected, $found, 'add a new catalog to CatalogTest::LOCALES');
	}

	/**
	 * @dataProvider locales
	 */
	public function testCatalogMatchesTheTemplate(string $locale): void {
		$pot = self::parse(self::ROOT . '/locales/po/cacti.pot');
		$po  = self::parse(self::ROOT . '/locales/po/' . $locale . '.po');

		$this->assertStringContainsString("Language: $locale\n", $po['header']);
		$this->assertStringContainsString('Plural-Forms: ' . self::LOCALES[$locale] . "\n", $po['header']);
		$this->assertStringContainsString("charset=UTF-8\n", $po['header']);

		$this->assertGreaterThan(100, count($pot['entries']));
		$this->assertSame(array_keys($pot['entries']), array_keys($po['entries']));

		preg_match('/nplurals=(\d+);/', self::LOCALES[$locale], $matches);
		$forms = (int) $matches[1];

		foreach ($po['entries'] as $msgid => $entry) {
			$this->assertSame($pot['entries'][$msgid]['plural'], $entry['plural'], $msgid);

			$expected = $entry['plural'] === null ? 1 : $forms;
			$this->assertCount($expected, $entry['msgstr'], $msgid);

			foreach ($entry['msgstr'] as $form) {
				$this->assertNotSame('', trim($form), 'untranslated: ' . $msgid);
				$this->assertSame(self::tokens($msgid), self::tokens($form), 'placeholders or tags differ: ' . $msgid);
				$this->assertSame(self::edges($msgid), self::edges($form), 'edge whitespace differs: ' . $msgid);
			}
		}

		$source = (string) file_get_contents(self::ROOT . '/locales/po/' . $locale . '.po');

		// A flag line can list several flags: "#, fuzzy, php-format".
		$this->assertSame(0, preg_match('/^#,.*\bfuzzy\b/m', $source), 'fuzzy entry in ' . $locale);

		// Cacti's __() formats a message only when it holds a plain placeholder,
		// so a positional one (%1$s) would be printed literally.
		$this->assertSame(0, preg_match('/^msgstr(\[\d+\])? ".*%\d+\$/m', $source), 'positional placeholder in ' . $locale);
	}

	/**
	 * @dataProvider locales
	 */
	public function testCompiledCatalogHoldsTheSameMessages(string $locale): void {
		$po       = self::parse(self::ROOT . '/locales/po/' . $locale . '.po');
		$compiled = self::readMo(self::ROOT . '/locales/LC_MESSAGES/' . $locale . '.mo');

		foreach ($po['entries'] as $msgid => $entry) {
			$key = $entry['plural'] === null ? $msgid : $msgid . "\0" . $entry['plural'];

			$this->assertArrayHasKey($key, $compiled, $msgid);
			$this->assertSame(implode("\0", $entry['msgstr']), $compiled[$key], $msgid);
		}
	}

	/**
	 * @return array<string, string>
	 */
	private static function readMo(string $path): array {
		$data = (string) file_get_contents($path);
		$head = unpack('Vmagic/Vrev/Vcount/Vorig/Vtrans', substr($data, 0, 20));

		if ($head['magic'] !== 0x950412de) {
			self::fail($path . ' is not a little-endian message catalog');
		}

		$messages = array();

		for ($i = 0; $i < $head['count']; $i++) {
			$o = unpack('Vlen/Voff', substr($data, $head['orig'] + 8 * $i, 8));
			$t = unpack('Vlen/Voff', substr($data, $head['trans'] + 8 * $i, 8));

			$messages[substr($data, $o['off'], $o['len'])] = substr($data, $t['off'], $t['len']);
		}

		return $messages;
	}
}
