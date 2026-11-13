<?php

/**
 * A calendar range and an image read back in their object form, and a PATCH never turns them into strings.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Object\SchemaTypeConverter
 */
class SchemaTypeConverterRangeImageTest extends TestCase {

	public function testARangeRoundTripsUnchanged(): void {
		$range = ['start' => '2026-05-01T09:00:00Z', 'end' => '2026-05-01T17:00:00Z'];
		$converter = new SchemaTypeConverter();

		$this->assertSame($range, $converter->convertValue($range, 'calendar-range'));
		$this->assertSame($range, $converter->convertValue(json_encode($range), 'calendar-range'));
		$this->assertNull($converter->convertValue(null, 'calendar-range'));
	}//end testARangeRoundTripsUnchanged()

	public function testAnImageAlwaysReadsInItsObjectForm(): void {
		$image = ['url' => 'https://example.org/img.png', 'width' => 1200, 'height' => 630, 'altText' => 'Town hall at dusk', 'format' => 'png'];
		$converter = new SchemaTypeConverter();

		$this->assertSame($image, $converter->convertValue($image, 'image-url'));
		$this->assertSame($image, $converter->convertValue(json_encode($image), 'image-url'));
		$this->assertSame(['url' => 'https://example.org/img.png'], $converter->convertValue('https://example.org/img.png', 'image-url'));
	}//end testAnImageAlwaysReadsInItsObjectForm()

	public function testAnUntouchedRangeOrImageIsNotReEncodedAsAString(): void {
		$data = [
			'period' => ['start' => '2026-05-01', 'end' => '2026-05-03'],
			'cover' => ['url' => 'https://example.org/img.png'],
			'note' => ['a' => 1],
		];
		$properties = [
			'period' => ['type' => 'calendar-range'],
			'cover' => ['type' => 'image-url'],
			'note' => ['type' => 'string'],
		];

		$restored = (new SchemaTypeConverter())->restoreStringTypedValues($data, $properties);

		$this->assertSame($data['period'], $restored['period']);
		$this->assertSame($data['cover'], $restored['cover']);
		$this->assertSame('{"a":1}', $restored['note']);
	}//end testAnUntouchedRangeOrImageIsNotReEncodedAsAString()
}//end class
