<?php

/**
 * ObjectEntity surfaces the reader's read-only properties in the `@self`
 * envelope as `readOnlyProperties`, and omits them when none were derived.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-field-access-editor/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\ObjectEntity
 */
final class ObjectEntityReadOnlyPropertiesTest extends TestCase {

	/**
	 * A set list of read-only properties rides the `@self` envelope.
	 *
	 * @return void
	 */
	public function testReadOnlyPropertiesAreSurfacedInSelf(): void {
		$entity = new ObjectEntity();
		$entity->setReadOnlyProperties(['salaris', 'besluitdatum']);

		$this->assertSame(['salaris', 'besluitdatum'], $entity->getReadOnlyProperties());
		$this->assertSame(['salaris', 'besluitdatum'], $entity->getObjectArray()['readOnlyProperties'] ?? null);
	}//end testReadOnlyPropertiesAreSurfacedInSelf()

	/**
	 * An entity that never had them derived carries no `readOnlyProperties` key,
	 * so absence and an empty list are not confused.
	 *
	 * @return void
	 */
	public function testUnsetReadOnlyPropertiesAreOmitted(): void {
		$entity = new ObjectEntity();

		$this->assertNull($entity->getReadOnlyProperties());
		$this->assertArrayNotHasKey('readOnlyProperties', $entity->getObjectArray());
	}//end testUnsetReadOnlyPropertiesAreOmitted()
}//end class
