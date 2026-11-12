<?php

/**
 * The input-schema predicates object actions use: whether any property
 * passes a test, defaults read from the object, and write-only inputs.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectAction;

use OCA\OpenRegister\Service\ObjectAction\ObjectActionInputSchema;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see ObjectActionInputSchema}.
 */
class ObjectActionInputSchemaTest extends TestCase {

	/**
	 * A non-array schema and a schema without a passing property answer
	 * false; one passing property answers true; non-array properties are
	 * never tested.
	 *
	 * @return void
	 */
	public function testAnyPropertyAnswersWhetherOnePropertyPasses(): void {
		$tested = [];
		$test = static function (array $property) use (&$tested): bool {
			$tested[] = $property;

			return ($property['writeOnly'] ?? false) === true;
		};

		$this->assertFalse(ObjectActionInputSchema::anyProperty(inputs: 'not a schema', test: $test));
		$this->assertFalse(ObjectActionInputSchema::anyProperty(inputs: ['properties' => ['a' => ['type' => 'string'], 'b' => 'scalar']], test: $test));
		$this->assertTrue(ObjectActionInputSchema::anyProperty(inputs: ['properties' => ['a' => ['type' => 'string'], 'c' => ['writeOnly' => true]]], test: $test));
		$this->assertFalse(ObjectActionInputSchema::anyProperty(inputs: [], test: $test));
		$this->assertSame([['type' => 'string'], ['type' => 'string'], ['writeOnly' => true]], $tested);
	}//end testAnyPropertyAnswersWhetherOnePropertyPasses()

	/**
	 * Only a string default starting with `@object` defaults from the object.
	 *
	 * @return void
	 */
	public function testDefaultsFromObjectNeedsAnObjectReference(): void {
		$this->assertTrue(ObjectActionInputSchema::defaultsFromObject(property: ['default' => '@object.title']));
		$this->assertFalse(ObjectActionInputSchema::defaultsFromObject(property: ['default' => 'title @object']));
		$this->assertFalse(ObjectActionInputSchema::defaultsFromObject(property: ['default' => ['@object']]));
		$this->assertFalse(ObjectActionInputSchema::defaultsFromObject(property: []));
	}//end testDefaultsFromObjectNeedsAnObjectReference()

	/**
	 * Only a literal true marks a property write-only.
	 *
	 * @return void
	 */
	public function testIsWriteOnlyNeedsLiteralTrue(): void {
		$this->assertTrue(ObjectActionInputSchema::isWriteOnly(property: ['writeOnly' => true]));
		$this->assertFalse(ObjectActionInputSchema::isWriteOnly(property: ['writeOnly' => 'true']));
		$this->assertFalse(ObjectActionInputSchema::isWriteOnly(property: []));
	}//end testIsWriteOnlyNeedsLiteralTrue()
}//end class
