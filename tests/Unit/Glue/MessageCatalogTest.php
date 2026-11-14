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

namespace Cacti\PluginCloudflare\Tests\Unit\Glue;

use Cacti\PluginCloudflare\Account\AccountService;
use Cacti\PluginCloudflare\Secret\SecretException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * src/ cannot call Cacti's __(), so includes/accounts.php translates its
 * fixed sentences at display time. These tests fail when src/ gains a sentence
 * the catalog lacks, which would show in English in every language.
 */
final class MessageCatalogTest extends TestCase {
	private const ROOT = __DIR__ . '/../../..';

	public static function setUpBeforeClass(): void {
		require_once self::ROOT . '/tests/Support/CactiGlueStubs.php';
		require_once self::ROOT . '/includes/accounts.php';
	}

	/**
	 * @return list<string>
	 */
	private static function sourceSentences(): array {
		$sentences = array();

		foreach ((new ReflectionClass(AccountService::class))->getReflectionConstants() as $constant) {
			$value = $constant->getValue();

			if ($constant->getName() === 'MESSAGES') {
				$sentences = array_merge($sentences, array_values($value));
			} elseif (strpos($constant->getName(), 'MESSAGE_') === 0) {
				$sentences[] = $value;
			}
		}

		$source = (string) file_get_contents(self::ROOT . '/src/Account/AccountService.php');
		preg_match_all("/(?:\\\$errors\\[[^\\]]+\\]|'cf_account_id')\\s*=>?\\s*'([^']+)'/", $source, $m);
		$sentences = array_merge($sentences, $m[1]);

		$secret = (new ReflectionClass(SecretException::class))->getReflectionConstant('MESSAGES');
		$sentences = array_merge($sentences, array_values($secret->getValue()), array('Credential error'));

		return $sentences;
	}

	public function testEverySourceSentenceIsInTheCatalog(): void {
		$sentences = self::sourceSentences();

		$this->assertGreaterThan(25, count($sentences), 'the scan found too few sentences to be trusted');

		$catalog = cloudflare_message_catalog();

		foreach ($sentences as $sentence) {
			$this->assertArrayHasKey($sentence, $catalog);
		}
	}

	public function testCatalogHoldsNoSentenceSourceDropped(): void {
		$this->assertSame(array(), array_values(array_diff(array_keys(cloudflare_message_catalog()), self::sourceSentences())));
	}

	public function testTranslateKeepsThePathOfASecretException(): void {
		$message = (new SecretException(SecretException::FILE_INSECURE, '/etc/cloudflare/token'))->getMessage();

		$this->assertSame($message, cloudflare_translate_message($message));
		$this->assertStringEndsWith(': /etc/cloudflare/token', cloudflare_translate_message($message));
	}

	public function testTranslateReturnsAnUnknownMessageUnchanged(): void {
		$this->assertSame('Something else: /x', cloudflare_translate_message('Something else: /x'));
		$this->assertSame('Not in the catalog', cloudflare_translate_message('Not in the catalog'));
	}
}
