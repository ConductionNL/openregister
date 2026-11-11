<?php

/**
 * Characterisation of Source::hydrate(): which setters it calls, that an
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

use OCA\OpenRegister\Db\Source;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Golden hydration for Source.
 *
 * @covers \OCA\OpenRegister\Db\Source
 */
final class SourceHydrateGoldenTest extends TestCase {

	/**
	 * Empty JSON arrays become null, non-JSON values pass through, unknown keys are skipped.
	 *
	 * @return void
	 */
	public function testHydrateSetsKnownFieldsAndNullsEmptyJson(): void {
		$entity = new Source();
		$entity->setAuthConfig(['stored' => true]);
		$entity->resetUpdatedFields();
		$result = $entity->hydrate(['authConfig' => [], 'title' => 't', 'description' => '', 'bogus' => 1, 'syncInterval' => 5]);

		$this->assertSame($entity, $result);
		$this->assertSame(['authConfig' => true, 'title' => true, 'description' => true, 'syncInterval' => true], $entity->getUpdatedFields());
		$this->assertNull($this->raw(entity: $entity, name: 'authConfig'));
		$this->assertSame('t', $this->raw(entity: $entity, name: 'title'));
		$this->assertSame('', $this->raw(entity: $entity, name: 'description'));
		$this->assertSame(5, $this->raw(entity: $entity, name: 'syncInterval'));
	}//end testHydrateSetsKnownFieldsAndNullsEmptyJson()

	/**
	 * Read a stored property without going through the getter's defaults.
	 *
	 * @param Source $entity The entity.
	 * @param string       $name   The property.
	 *
	 * @return mixed The stored value.
	 */
	private function raw(Source $entity, string $name): mixed {
		$property = new ReflectionProperty(Source::class, $name);
		$property->setAccessible(true);

		return $property->getValue($entity);
	}//end raw()
}//end class
