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

namespace Cacti\PluginCloudflare\Tests\Integration;

use Cacti\PluginCloudflare\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * PHPUnit keeps every finished test instance until the run ends, so a handle
 * left in a property stays connected for the rest of the suite. The hooks are
 * annotations so a subclass tearDown() does not have to call the parent.
 */
abstract class IntegrationTestCase extends TestCase {
	/** @var int */
	private static $leakedHandles = 0;

	public static function leakedHandles(): int {
		return self::$leakedHandles;
	}

	/**
	 * Clears every property the concrete class declares: repositories and
	 * services hold the Database too, not only the PDO properties. The sample
	 * taken afterwards is what the guard in tests/Guard checks.
	 *
	 * @after
	 */
	final public function releaseConnections(): void {
		$class = new ReflectionClass($this);

		while ($class !== false && $class->getName() !== self::class) {
			foreach ($class->getProperties() as $prop) {
				if ($prop->isStatic() || $prop->getDeclaringClass()->getName() !== $class->getName()) {
					continue;
				}

				$type = $prop->getType();
				if ($type !== null && !$type->allowsNull()) {
					continue;
				}

				$prop->setAccessible(true);
				$prop->setValue($this, null);
			}

			$class = $class->getParentClass();
		}

		TestDatabase::recordThreads();
	}

	/**
	 * Anything closeAll() still finds alive here escaped releaseConnections().
	 *
	 * @afterClass
	 */
	final public static function closeConnections(): void {
		self::$leakedHandles += TestDatabase::closeAll();
	}
}
