<?php

/**
 * Characterisation of ObjectEntity::hydrate(): which setters it calls, that an
 * empty array on a JSON field is stored as null, and that unknown keys are skipped.
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

use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Golden hydration for ObjectEntity.
 *
 * @covers \OCA\OpenRegister\Db\ObjectEntity
 */
final class ObjectEntityHydrateGoldenTest extends TestCase {

	/**
	 * Empty JSON arrays become null, non-JSON values pass through, unknown keys are skipped.
	 *
	 * @return void
	 */
	public function testHydrateSetsKnownFieldsAndNullsEmptyJson(): void {
		$entity = new ObjectEntity();
		$result = $entity->hydrate(['files' => [], 'object' => ['a' => 1], 'name' => 'n', 'size' => '', 'bogus' => 1, 'groups' => []]);

		$this->assertSame($entity, $result);
		$this->assertSame(['files' => true, 'object' => true, 'name' => true, 'size' => true, 'groups' => true], $entity->getUpdatedFields());
		$this->assertNull($this->raw(entity: $entity, name: 'files'));
		$this->assertNull($this->raw(entity: $entity, name: 'groups'));
		$this->assertSame(['a' => 1], $this->raw(entity: $entity, name: 'object'));
		$this->assertSame('', $this->raw(entity: $entity, name: 'size'));
	}//end testHydrateSetsKnownFieldsAndNullsEmptyJson()

	/**
	 * Read a stored property without going through the getter's defaults.
	 *
	 * @param ObjectEntity $entity The entity.
	 * @param string       $name   The property.
	 *
	 * @return mixed The stored value.
	 */
	private function raw(ObjectEntity $entity, string $name): mixed {
		$property = new ReflectionProperty(ObjectEntity::class, $name);
		$property->setAccessible(true);

		return $property->getValue($entity);
	}//end raw()
}//end class
