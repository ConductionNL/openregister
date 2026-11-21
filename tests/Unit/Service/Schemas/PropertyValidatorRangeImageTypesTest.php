<?php

/**
 * calendar-range and image-url are in the published type list, so a schema declaring them imports.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use Exception;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the calendar-range and image-url entries of the property vocabulary.
 */
class PropertyValidatorRangeImageTypesTest extends TestCase {

	public function testBothTypesAreListedWithTheirCategoryAndConversion(): void {
		$this->assertSame('temporal', PropertyValidatorHandler::TYPES['calendar-range']['category']);
		$this->assertSame('unsupported', PropertyValidatorHandler::TYPES['calendar-range']['conversion']);
		$this->assertSame('presentation', PropertyValidatorHandler::TYPES['image-url']['category']);
		$this->assertSame('conditional', PropertyValidatorHandler::TYPES['image-url']['conversion']);
		$this->assertNotSame('', PropertyValidatorHandler::TYPES['calendar-range']['description']);
		$this->assertNotSame('', PropertyValidatorHandler::TYPES['image-url']['description']);
	}//end testBothTypesAreListedWithTheirCategoryAndConversion()

	public function testASchemaDeclaringThemImports(): void {
		$validator = new PropertyValidatorHandler();

		$this->assertTrue(
			$validator->validateProperties(
				[
					'period' => ['type' => 'calendar-range', 'format' => 'date'],
					'window' => ['type' => 'calendar-range'],
					'cover' => ['type' => 'image-url', 'title' => 'Cover'],
				],
				''
			)
		);
	}//end testASchemaDeclaringThemImports()

	public function testAnUnknownTypeIsRefusedNamingTheProperty(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage("Invalid type 'calendar-period' at '/period'");

		(new PropertyValidatorHandler())->validateProperties(['period' => ['type' => 'calendar-period']], '');
	}//end testAnUnknownTypeIsRefusedNamingTheProperty()
}//end class
