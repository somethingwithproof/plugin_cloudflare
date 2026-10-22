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

namespace Cacti\PluginCloudflare\Tests\Unit\Glue;

use PHPUnit\Framework\TestCase;

/**
 * Each test runs in a fresh process so the class it loads is not already
 * there from another test.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class AutoloadTest extends TestCase {
	private const ROOT = __DIR__ . '/../../..';

	public function testLoaderMapsAPluginClassToItsSourceFile(): void {
		require_once self::ROOT . '/includes/autoload.php';

		$class = 'Cacti\\PluginCloudflare\\Support\\Redactor';
		$this->assertFalse(class_exists($class, false));

		cloudflare_autoload($class);

		$this->assertTrue(class_exists($class, false));
		$this->assertContains((string) realpath(self::ROOT . '/src/Support/Redactor.php'), get_included_files());
	}

	public function testLoaderIgnoresForeignMalformedAndUnknownNames(): void {
		require_once self::ROOT . '/includes/autoload.php';
		$before = get_included_files();

		cloudflare_autoload('Other\\Vendor\\Thing');
		cloudflare_autoload('Cacti\\PluginCloudflare\\..\\..\\composer');
		cloudflare_autoload('Cacti\\PluginCloudflare\\Support/Redactor');
		cloudflare_autoload('Cacti\\PluginCloudflare\\Nowhere\\Missing');

		$this->assertSame($before, get_included_files());
		$this->assertFalse(class_exists('Cacti\\PluginCloudflare\\Support\\Redactor', false));
	}
}
