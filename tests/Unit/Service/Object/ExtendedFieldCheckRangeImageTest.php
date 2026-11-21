<?php

/**
 * The extended-field check refuses a broken calendar-range or image-url value, naming the property.
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
 * @uses \OCA\OpenRegister\Formats\CalendarRangeValue
 * @uses \OCA\OpenRegister\Formats\ImageUrlValue
 * @uses \OCA\OpenRegister\Formats\Iso8601DateTimeFormat
 * @uses \OCA\OpenRegister\Exception\CustomValidationException
 */
class ExtendedFieldCheckRangeImageTest extends TestCase {

	public function testARangeEndingBeforeItStartsIsRefusedUnderThePropertyName(): void {
		$check  = new ExtendedFieldCheck(object: ['period' => ['start' => '2026-05-01T17:00:00Z', 'end' => '2026-05-01T09:00:00Z']]);
		$schema = (object) ['properties' => (object) ['period' => (object) ['type' => 'calendar-range']]];
		$message = "Property 'period': calendar-range 'end' (2026-05-01T09:00:00Z) must be >= 'start' (2026-05-01T17:00:00Z)";

		try {
			$check->assertValid($schema);
			$this->fail('Expected a CustomValidationException.');
		} catch (CustomValidationException $e) {
			$this->assertSame($message, $e->getMessage());
			$this->assertSame(['period' => $message], $e->getErrors());
		}
	}//end testARangeEndingBeforeItStartsIsRefusedUnderThePropertyName()

	public function testTheDeclaredDateFormatIsPassedToTheRangeCheck(): void {
		$check  = new ExtendedFieldCheck(object: ['period' => ['start' => '2026-05-01T09:00:00Z', 'end' => '2026-05-03']]);
		$schema = (object) ['properties' => ['period' => ['type' => 'calendar-range', 'format' => 'date']]];

		$this->expectException(CustomValidationException::class);
		$this->expectExceptionMessage("Property 'period' declared format 'date'; 'start' must match YYYY-MM-DD");

		$check->assertValid($schema);
	}//end testTheDeclaredDateFormatIsPassedToTheRangeCheck()

	public function testAnImageThatIsNotAUrlIsRefused(): void {
		$check  = new ExtendedFieldCheck(object: ['cover' => 'not a url']);
		$schema = (object) ['properties' => ['cover' => ['type' => 'image-url']]];

		$this->expectException(CustomValidationException::class);
		$this->expectExceptionMessage("Property 'cover': 'not a url' is not a valid URL");

		$check->assertValid($schema);
	}//end testAnImageThatIsNotAUrlIsRefused()

	public function testValidRangesAndImagesPass(): void {
		$check = new ExtendedFieldCheck(
			object: [
				'period' => ['start' => '2026-05-01T09:00:00Z', 'end' => '2026-05-01T17:00:00Z'],
				'cover' => 'https://example.org/img.png',
				'banner' => ['url' => 'https://example.org/b.webp', 'width' => 1200, 'format' => 'webp'],
			]
		);
		$schema = (object) [
			'properties' => [
				'period' => ['type' => 'calendar-range'],
				'cover' => ['type' => 'image-url'],
				'banner' => ['type' => 'image-url'],
			],
		];

		$check->assertValid($schema);
		$this->addToAssertionCount(1);
	}//end testValidRangesAndImagesPass()
}//end class
