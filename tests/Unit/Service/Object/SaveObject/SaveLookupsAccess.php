<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reaches the lookups a SaveObject builds, so a test can seed or read their caches.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObject\SaveLookups;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Seeds and reads the caches of a SaveObject's lookups.
 */
final class SaveLookupsAccess {

	/**
	 * The caches SaveLookups keeps.
	 */
	public const CACHES = ['schemaCache', 'registerCache', 'schemaReferenceCache'];

	/**
	 * Set one cache.
	 *
	 * @param SaveObject $handler The save handler.
	 * @param string     $cache   The cache name.
	 * @param array      $value   The cache contents.
	 *
	 * @return void
	 */
	public static function set(SaveObject $handler, string $cache, array $value): void {
		(new ReflectionProperty(SaveLookups::class, $cache))->setValue(self::of(handler: $handler), $value);
	}//end set()

	/**
	 * Read one cache.
	 *
	 * @param SaveObject $handler The save handler.
	 * @param string     $cache   The cache name.
	 *
	 * @return array The cache contents.
	 */
	public static function get(SaveObject $handler, string $cache): array {
		return (new ReflectionProperty(SaveLookups::class, $cache))->getValue(self::of(handler: $handler));
	}//end get()

	/**
	 * The lookups of a save handler; a handler built without its constructor gets one of its own.
	 *
	 * @param SaveObject $handler The save handler.
	 *
	 * @return SaveLookups
	 */
	public static function of(SaveObject $handler): SaveLookups {
		$schemaMapper = new ReflectionProperty(SaveObject::class, 'schemaMapper');
		$lookups = new ReflectionProperty(SaveObject::class, 'lookups');
		if ($schemaMapper->isInitialized($handler) === false && $lookups->getValue($handler) === null) {
			$lookups->setValue($handler, self::withHandlersMappers(handler: $handler));
		}

		return (new ReflectionMethod(SaveObject::class, 'lookups'))->invoke($handler);
	}//end of()

	/**
	 * Lookups holding whichever mappers a constructorless handler was given.
	 *
	 * @param SaveObject $handler The save handler.
	 *
	 * @return SaveLookups
	 */
	private static function withHandlersMappers(SaveObject $handler): SaveLookups {
		$built = (new ReflectionClass(SaveLookups::class))->newInstanceWithoutConstructor();
		foreach (['schemaMapper', 'registerMapper'] as $name) {
			$mapper = new ReflectionProperty(SaveObject::class, $name);
			if ($mapper->isInitialized($handler) === true) {
				(new ReflectionProperty(SaveLookups::class, $name))->setValue($built, $mapper->getValue($handler));
			}
		}

		return $built;
	}//end withHandlersMappers()
}//end class
