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

namespace Cacti\PluginCloudflare\Tests\Unit\Support;

use Cacti\PluginCloudflare\Support\Ids;
use Eris\Generators;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

final class IdsTest extends TestCase {
	use TestTrait;

	public function testAccountIdAcceptsLowercaseHex32(): void {
		$this->assertTrue(Ids::isAccountId('0123456789abcdef0123456789abcdef'));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function badAccountIds(): array {
		return array(
			'uppercase'        => array('0123456789ABCDEF0123456789ABCDEF'),
			'31 chars'         => array(str_repeat('a', 31)),
			'33 chars'         => array(str_repeat('a', 33)),
			'non-hex'          => array(str_repeat('g', 32)),
			'trailing newline' => array(str_repeat('a', 32) . "\n"),
			'empty'            => array(''),
		);
	}

	/**
	 * @dataProvider badAccountIds
	 */
	public function testAccountIdRejects(string $value): void {
		$this->assertFalse(Ids::isAccountId($value));
	}

	public function testUuidAcceptsFixtureIds(): void {
		$file = __DIR__ . '/../../fixtures/cloudflare/tunnel-list.json';
		$json = json_decode((string) file_get_contents($file), true);
		$this->assertIsArray($json);
		$this->assertNotEmpty($json['result']);

		foreach ($json['result'] as $tunnel) {
			$this->assertTrue(Ids::isUuid($tunnel['id']), $tunnel['id']);
		}
	}

	public function testUuidRejectsUppercaseAndBraces(): void {
		$this->assertFalse(Ids::isUuid('1BEDC50D-42B3-473C-B108-FF3D10C0D925'));
		$this->assertFalse(Ids::isUuid('{1bedc50d-42b3-473c-b108-ff3d10c0d925}'));
		$this->assertFalse(Ids::isUuid("1bedc50d-42b3-473c-b108-ff3d10c0d925\n"));
		$this->assertFalse(Ids::isUuid('1bedc50d42b3473cb108ff3d10c0d925'));
	}

	public function testNormalizeTrimsAndLowercases(): void {
		$this->assertSame('abc123', Ids::normalizeAccountId("  ABC123 \n"));
	}

	public function testRandomHex32AlwaysValid(): void {
		$this->limitTo(50)
			->forAll(Generators::vector(32, Generators::elements(str_split('0123456789abcdef'))))
			->then(function (array $chars): void {
				$this->assertTrue(Ids::isAccountId(implode('', $chars)));
			});
	}
}
