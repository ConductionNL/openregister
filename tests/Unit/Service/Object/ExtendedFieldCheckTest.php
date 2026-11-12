<?php

/**
 * The extended-field check refuses an invalid color or recurrence value and passes everything else.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Service\Object\ExtendedFieldCheck;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\ExtendedFieldCheck
 * @uses \OCA\OpenRegister\Formats\FormatFactory
 * @uses \OCA\OpenRegister\Formats\ExtendedFieldTypeValidator
 * @uses \OCA\OpenRegister\Formats\FormattedProperty
 * @uses \OCA\OpenRegister\Exception\CustomValidationException
 */
class ExtendedFieldCheckTest extends TestCase {

	public function testNonStringColorIsRefusedWithThePropertyAsErrorKey(): void {
		$check  = new ExtendedFieldCheck(object: ['tint' => 42]);
		$schema = (object) ['properties' => (object) ['tint' => (object) ['type' => 'color']]];

		try {
			$check->assertValid($schema);
			$this->fail('Expected a CustomValidationException.');
		} catch (CustomValidationException $e) {
			$this->assertSame("Property 'tint': color value must be a string", $e->getMessage());
			$this->assertSame(['tint' => "Property 'tint': color value must be a string"], $e->getErrors());
		}
	}//end testNonStringColorIsRefusedWithThePropertyAsErrorKey()

	public function testInvalidRecurrenceIsRefusedFromAnArraySchema(): void {
		$check  = new ExtendedFieldCheck(object: ['repeat' => 'FREQ=NEVERLY']);
		$schema = (object) ['properties' => ['repeat' => ['type' => 'recurrence']]];

		$this->expectException(CustomValidationException::class);
		$this->expectExceptionMessage("Property 'repeat': invalid RRULE 'FREQ=NEVERLY' (sabre/vobject parse error)");

		$check->assertValid($schema);
	}//end testInvalidRecurrenceIsRefusedFromAnArraySchema()

	public function testValidValuesNullValuesAndOtherTypesPass(): void {
		$check  = new ExtendedFieldCheck(object: ['repeat' => 'FREQ=WEEKLY;COUNT=3', 'tint' => null, 'name' => 42]);
		$schema = (object) [
			'properties' => [
				'repeat' => ['type' => 'recurrence'],
				'tint' => ['type' => 'color'],
				'name' => ['type' => 'string'],
				'odd' => 'not a declaration',
			],
		];

		$check->assertValid($schema);
		$this->addToAssertionCount(1);
	}//end testValidValuesNullValuesAndOtherTypesPass()

	public function testSchemaWithoutPropertiesIsNotChecked(): void {
		$check = new ExtendedFieldCheck(object: ['tint' => 42]);

		$check->assertValid((object) []);
		$check->assertValid((object) ['properties' => 'tint']);
		$this->addToAssertionCount(1);
	}//end testSchemaWithoutPropertiesIsNotChecked()
}//end class
