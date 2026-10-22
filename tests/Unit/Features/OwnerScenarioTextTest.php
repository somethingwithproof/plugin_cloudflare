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

namespace Cacti\PluginCloudflare\Tests\Unit\Features;

use PHPUnit\Framework\TestCase;

/**
 * The owner wrote these scenarios in docs/spec.md. Steps may be appended
 * after an owner line; no owner line may be reworded, reordered or dropped.
 */
final class OwnerScenarioTextTest extends TestCase {
	private const ROOT = __DIR__ . '/../../..';

	/**
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public static function features(): array {
		return array(
			'tunnel discovery' => array('tunnel_discovery.feature', array(
				'Feature: Tunnel discovery',
				'  Scenario: Discover tunnels successfully',
				'    Given a configured Cloudflare account',
				'    And the Cloudflare API returns three tunnels',
				'    When the discovery process executes',
				'    Then Cacti should store exactly three discovered tunnels',
				'    And each tunnel should retain its Cloudflare identifier',
				'  Scenario: Repeated discovery does not create duplicates',
				'    Given three tunnels have already been discovered',
				'    When the discovery process executes again with unchanged results',
				'    Then the tunnel count must remain three',
				'    And existing resource associations must be preserved',
			)),
			'outage'           => array('cloudflare_outage.feature', array(
				'Feature: Cloudflare outage',
				'  Scenario: Cloudflare becomes unavailable',
				'    Given Cacti is monitoring existing devices',
				'    And the Cloudflare API becomes unavailable',
				'    When the polling cycle executes',
				'    Then unrelated Cacti polling must continue',
				'    And Cloudflare observations must be classified as unavailable',
				'    And existing graph history must remain intact',
			)),
			'metrics integrity' => array('metrics_integrity.feature', array(
				'Feature: Metrics integrity',
				'  Scenario: An API request fails',
				'    Given a tunnel previously reported four connections',
				'    When the next metrics request fails',
				'    Then the observation must be marked unknown',
				'    And the system must not record zero connections',
				'    And the previous historical samples must remain unchanged',
			)),
			'rate limiting'    => array('api_rate_limiting.feature', array(
				'Feature: API rate limiting',
				'  Scenario: Cloudflare returns HTTP 429',
				'    Given the API request limit has been reached',
				'    When the Cloudflare collector receives HTTP 429',
				'    Then it must apply bounded retry handling',
				'    And honor supported retry information',
				'    And defer collection when the retry budget is exhausted',
				'    And it must not block the Cacti user interface',
			)),
		);
	}

	/**
	 * @dataProvider features
	 *
	 * @param array<int, string> $owner
	 */
	public function testOwnerLinesAppearVerbatimAndInOrder(string $file, array $owner): void {
		$lines = file(self::ROOT . '/features/' . $file, FILE_IGNORE_NEW_LINES);
		$this->assertIsArray($lines);

		$at = 0;
		foreach ($owner as $line) {
			$found = array_search($line, array_slice($lines, $at), true);
			$this->assertNotFalse($found, 'missing or out of order: ' . $line);
			$at += $found + 1;
		}
	}

	/**
	 * @dataProvider features
	 *
	 * @param array<int, string> $owner
	 */
	public function testOwnerLinesMatchTheSpecification(string $file, array $owner): void {
		$spec = file_get_contents(self::ROOT . '/docs/spec.md');
		$this->assertIsString($spec);

		foreach ($owner as $line) {
			$this->assertStringContainsString("\n" . $line . "\n", $spec, $file);
		}
	}
}
