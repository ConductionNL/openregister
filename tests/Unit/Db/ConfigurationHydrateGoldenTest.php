<?php

/**
 * Characterisation of Configuration::hydrate(): which setters it calls, that an
 * empty array on a JSON field is stored as null, that unknown keys are skipped
 * and that `application` is taken as `app` only when `app` is absent.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Configuration;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Golden hydration for Configuration.
 *
 * @covers \OCA\OpenRegister\Db\Configuration
 */
final class ConfigurationHydrateGoldenTest extends TestCase {

	/**
	 * Empty JSON arrays become null, non-JSON values pass through, unknown keys are skipped.
	 *
	 * @return void
	 */
	public function testHydrateSetsKnownFieldsAndNullsEmptyJson(): void {
		$entity = new Configuration();
		$result = $entity->hydrate(['registers' => [], 'application' => 'from-application', 'title' => 't', 'schemas' => ['s'], 'bogus' => 1]);

		$this->assertSame($entity, $result);
		$this->assertSame(['registers' => true, 'title' => true, 'schemas' => true, 'app' => true], $entity->getUpdatedFields());
		$this->assertNull($this->raw(entity: $entity, name: 'registers'));
		$this->assertSame(['s'], $this->raw(entity: $entity, name: 'schemas'));
		$this->assertSame('from-application', $this->raw(entity: $entity, name: 'app'));
	}//end testHydrateSetsKnownFieldsAndNullsEmptyJson()

	/**
	 * An explicit `app` wins over `application`, and a null `application` maps nothing.
	 *
	 * @return void
	 */
	public function testApplicationOnlyFillsAMissingApp(): void {
		$entity = new Configuration();
		$entity->hydrate(['app' => 'explicit', 'application' => 'ignored']);
		$this->assertSame('explicit', $this->raw(entity: $entity, name: 'app'));

		$other = new Configuration();
		$other->hydrate(['application' => null, 'title' => 't']);
		$this->assertSame(['title' => true], $other->getUpdatedFields());
	}//end testApplicationOnlyFillsAMissingApp()

	/**
	 * Read a stored property without going through the getter's defaults.
	 *
	 * @param Configuration $entity The entity.
	 * @param string       $name   The property.
	 *
	 * @return mixed The stored value.
	 */
	private function raw(Configuration $entity, string $name): mixed {
		$property = new ReflectionProperty(Configuration::class, $name);
		$property->setAccessible(true);

		return $property->getValue($entity);
	}//end raw()
}//end class
