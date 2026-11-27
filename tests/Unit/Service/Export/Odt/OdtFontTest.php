<?php

/**
 * OdtFont test.
 *
 * Pins which house-style fonts the ODT may embed: TrueType and OpenType by
 * their bytes, WOFF 1.0 unwrapped to the font it carries, and everything else
 * (WOFF2, a collection, a non-font, an oversized or malformed file) refused, so
 * that role keeps the default font (export-pdf-house-style, task 2.2).
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

use OCA\OpenRegister\Service\Export\Odt\OdtFont;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Export\Odt\OdtFont
 */
class OdtFontTest extends TestCase {

	/**
	 * A minimal sfnt font: the offset table, a directory and the tables, each padded to four bytes.
	 *
	 * @param array<string, string> $tables Tag => table bytes.
	 * @param string                $flavor The sfnt version tag.
	 *
	 * @return string
	 */
	public static function sfnt(array $tables = ['head' => 'HEADTABLEDATA', 'name' => 'Huisstijl Sans'], string $flavor = "\x00\x01\x00\x00"): string {
		$count = count($tables);
		$power = 1;
		$selector = 0;
		while (($power * 2) <= $count) {
			$power *= 2;
			$selector++;
		}

		$directory = $flavor . pack('nnnn', $count, ($power * 16), $selector, (($count * 16) - ($power * 16)));
		$body = '';
		$offset = (12 + (16 * $count));
		foreach ($tables as $tag => $data) {
			$directory .= $tag . pack('NNN', crc32($data), $offset, strlen($data));
			$padded = $data . str_repeat("\x00", ((4 - (strlen($data) % 4)) % 4));
			$body .= $padded;
			$offset += strlen($padded);
		}

		return $directory . $body;
	}//end sfnt()

	/**
	 * The WOFF 1.0 wrapping of the tables of {@see sfnt()}: the first table stored, the others compressed.
	 *
	 * @param array<string, string> $tables Tag => table bytes.
	 * @param string                $flavor The sfnt version tag.
	 *
	 * @return string
	 */
	public static function woff(array $tables = ['head' => 'HEADTABLEDATA', 'name' => 'Huisstijl Sans'], string $flavor = "\x00\x01\x00\x00"): string {
		$count = count($tables);
		$offset = (44 + (20 * $count));
		$directory = '';
		$body = '';
		$first = true;
		foreach ($tables as $tag => $data) {
			$stored = $data;
			if ($first === false) {
				$stored = gzcompress(str_repeat($data, 1), 9);
				if (strlen($stored) >= strlen($data)) {
					$stored = $data;
				}
			}

			$first = false;
			$directory .= $tag . pack('NNNN', $offset, strlen($stored), strlen($data), crc32($data));
			$padded = $stored . str_repeat("\x00", ((4 - (strlen($stored) % 4)) % 4));
			$body .= $padded;
			$offset += strlen($padded);
		}

		$length = (44 + strlen($directory) + strlen($body));
		$header = 'wOFF' . $flavor . pack('NnnNnnNNNNN', $length, $count, 0, strlen(self::sfnt(tables: $tables, flavor: $flavor)), 1, 0, 0, 0, 0, 0, 0);

		return $header . $directory . $body;
	}//end woff()

	public function testATrueTypeFontIsAcceptedAsItIs(): void {
		$bytes = self::sfnt();

		$font = OdtFont::fromBytes(role: 'body', family: 'Huisstijl Sans', bytes: $bytes);

		$this->assertNotNull($font);
		$this->assertSame($bytes, $font->bytes);
		$this->assertSame('truetype', $font->format);
		$this->assertSame('body', $font->role);
		$this->assertSame('Huisstijl Sans', $font->family);
		$this->assertSame('Fonts/body.ttf', $font->packagePath());
		$this->assertSame('application/x-font-ttf', $font->mediaType());
	}//end testATrueTypeFontIsAcceptedAsItIs()

	public function testAnOpenTypeFontIsAcceptedAsOpenType(): void {
		$font = OdtFont::fromBytes(role: 'heading', family: 'Kop', bytes: self::sfnt(flavor: 'OTTO'));

		$this->assertSame('opentype', $font?->format);
		$this->assertSame('Fonts/heading.otf', $font->packagePath());
		$this->assertSame('application/vnd.ms-opentype', $font->mediaType());
	}//end testAnOpenTypeFontIsAcceptedAsOpenType()

	public function testAWoffFontIsUnwrappedToTheFontItCarries(): void {
		$tables = ['head' => 'HEADTABLEDATA', 'name' => str_repeat('Huisstijl Sans ', 20), 'post' => 'POST'];

		$font = OdtFont::fromBytes(role: 'body', family: 'Huisstijl Sans', bytes: self::woff(tables: $tables));

		$this->assertNotNull($font);
		$this->assertSame(self::sfnt(tables: $tables), $font->bytes);
		$this->assertSame('truetype', $font->format);
	}//end testAWoffFontIsUnwrappedToTheFontItCarries()

	public function testAWoff2FontIsRefused(): void {
		$this->assertNull(OdtFont::fromBytes(role: 'body', family: 'Huisstijl Sans', bytes: 'wOF2' . substr(self::sfnt(), 4)));
	}//end testAWoff2FontIsRefused()

	public function testAMalformedWoffIsRefused(): void {
		$woff = self::woff(tables: ['head' => 'HEADTABLEDATA', 'name' => str_repeat('Huisstijl Sans ', 20)]);

		// A wrong total length, a truncated file and a corrupted compressed table.
		$this->assertNull(OdtFont::fromBytes(role: 'body', family: 'A', bytes: substr($woff, 0, 8) . pack('N', 1) . substr($woff, 12)));
		$this->assertNull(OdtFont::fromBytes(role: 'body', family: 'A', bytes: substr($woff, 0, 40)));
		$this->assertNull(OdtFont::fromBytes(role: 'body', family: 'A', bytes: substr($woff, 0, -8) . str_repeat("\xFF", 8)));
	}//end testAMalformedWoffIsRefused()

	public function testBytesThatAreNotASingleFontAreRefused(): void {
		$this->assertNull(OdtFont::fromBytes(role: 'body', family: 'A', bytes: ''));
		$this->assertNull(OdtFont::fromBytes(role: 'body', family: 'A', bytes: 'ttcf' . substr(self::sfnt(), 4)));
		$this->assertNull(OdtFont::fromBytes(role: 'body', family: 'A', bytes: "\x89PNG\r\n\x1a\n" . str_repeat('x', 40)));
		// A header that claims more tables than the file holds.
		$this->assertNull(OdtFont::fromBytes(role: 'body', family: 'A', bytes: "\x00\x01\x00\x00" . pack('n', 40) . str_repeat("\x00", 30)));
		$this->assertNull(OdtFont::fromBytes(role: 'body', family: 'A', bytes: "\x00\x01\x00\x00" . str_repeat("\x00", (OdtFont::MAX_BYTES))));
	}//end testBytesThatAreNotASingleFontAreRefused()

	public function testAnUnknownRoleOrAMissingFamilyIsRefused(): void {
		$this->assertNull(OdtFont::fromBytes(role: 'caption', family: 'A', bytes: self::sfnt()));
		$this->assertNull(OdtFont::fromBytes(role: 'body', family: null, bytes: self::sfnt()));
		$this->assertNull(OdtFont::fromBytes(role: 'body', family: ' "" ', bytes: self::sfnt()));
	}//end testAnUnknownRoleOrAMissingFamilyIsRefused()

	public function testTheFamilyIsTheFirstOfAStackWithOnlySafeCharacters(): void {
		$font = OdtFont::fromBytes(role: 'body', family: "'Fira Sans', -apple-system, sans-serif", bytes: self::sfnt());
		$this->assertSame('Fira Sans', $font?->family);

		$font = OdtFont::fromBytes(role: 'body', family: 'Gemeente"/><x a="&Sans', bytes: self::sfnt());
		$this->assertSame('Gemeentex aSans', $font?->family);
	}//end testTheFamilyIsTheFirstOfAStackWithOnlySafeCharacters()
}//end class
