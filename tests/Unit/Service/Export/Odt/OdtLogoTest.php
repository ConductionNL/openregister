<?php

/**
 * OdtLogo tests.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export\Odt
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/export-pdf-house-style/specs/export-pdf-format/spec.md#requirement-a-pdf-export-carries-the-organisations-house-style
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Export\Odt;

use OCA\OpenRegister\Service\Export\Odt\OdtLogo;
use PHPUnit\Framework\TestCase;

/**
 * The logo check: size and type caps, SVG safety, and the frame size.
 *
 * @covers \OCA\OpenRegister\Service\Export\Odt\OdtLogo
 */
class OdtLogoTest extends TestCase {

	/**
	 * The header of a PNG of the given size: enough for its type and size to be read.
	 */
	public static function png(int $width, int $height): string {
		$ihdr = 'IHDR' . pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);

		return "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr))
			. pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));
	}

	/**
	 * The markers of a baseline JPEG of the given size.
	 */
	private static function jpeg(int $width, int $height): string {
		return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00"
			. "\xFF\xC0\x00\x11\x08" . pack('n', $height) . pack('n', $width)
			. "\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01\xFF\xD9";
	}

	public function testAPngIsSizedToTheHeaderHeight(): void {
		$logo = OdtLogo::fromBytes(self::png(300, 150));

		$this->assertNotNull($logo);
		$this->assertSame('image/png', $logo->mime);
		$this->assertSame(3.0, $logo->widthCm);
		$this->assertSame(1.5, $logo->heightCm);
		$this->assertSame('Pictures/logo.png', $logo->packagePath());
	}

	public function testAWideLogoIsCappedInWidthAndKeepsItsProportions(): void {
		$logo = OdtLogo::fromBytes(self::jpeg(1000, 50));

		$this->assertSame('image/jpeg', $logo?->mime);
		$this->assertSame(6.0, $logo->widthCm);
		$this->assertSame(0.3, $logo->heightCm);
		$this->assertSame('Pictures/logo.jpg', $logo->packagePath());
	}

	public function testTheTypeComesFromTheBytesNotFromAName(): void {
		$this->assertNull(OdtLogo::fromBytes("GIF89a\x01\x00\x01\x00"));
		$this->assertNull(OdtLogo::fromBytes('not an image'));
		$this->assertNull(OdtLogo::fromBytes(''));
	}

	public function testALogoOverOneMegabyteIsRefused(): void {
		$this->assertNull(OdtLogo::fromBytes(self::png(10, 10) . str_repeat("\0", OdtLogo::MAX_BYTES)));
	}

	public function testAnSvgIsSizedFromItsViewBoxOrItsSize(): void {
		$byViewBox = OdtLogo::fromBytes('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 50"><rect width="10" height="10"/></svg>');
		$bySize = OdtLogo::fromBytes('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="40px" height="40"><use href="#a"/></svg>');
		$unsized = OdtLogo::fromBytes('<svg xmlns="http://www.w3.org/2000/svg" width="10mm" height="5mm"/>');

		$this->assertSame('image/svg+xml', $byViewBox?->mime);
		$this->assertSame(6.0, $byViewBox->widthCm);
		$this->assertSame(1.5, $bySize?->widthCm);
		$this->assertSame(1.5, $unsized?->widthCm);
		$this->assertSame(1.5, $unsized->heightCm);
		$this->assertSame('Pictures/logo.svg', $bySize->packagePath());
	}

	public function testAnSvgWithActiveContentOrAnOutsideReferenceIsRefused(): void {
		$svg = static fn (string $body, string $attributes = ''): string =>
			'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="10" height="10"' . $attributes . '>' . $body . '</svg>';

		$this->assertNull(OdtLogo::fromBytes($svg('<script>alert(1)</script>')));
		$this->assertNull(OdtLogo::fromBytes($svg('', ' onload="alert(1)"')));
		$this->assertNull(OdtLogo::fromBytes($svg('<foreignObject/>')));
		$this->assertNull(OdtLogo::fromBytes($svg('<image xlink:href="https://example.org/a.png"/>')));
		$this->assertNull(OdtLogo::fromBytes($svg('<a href="javascript:x()"/>')));
		$this->assertNull(OdtLogo::fromBytes('<!DOCTYPE svg [<!ENTITY x "y">]>' . $svg('&x;')));
		$this->assertNull(OdtLogo::fromBytes('<html><svg/></html>'));
		$this->assertNull(OdtLogo::fromBytes('<svg xmlns="http://www.w3.org/2000/svg"><rect>'));
		$this->assertNotNull(OdtLogo::fromBytes($svg('<image xlink:href="data:image/png;base64,AAAA"/>')));
	}
}//end class
