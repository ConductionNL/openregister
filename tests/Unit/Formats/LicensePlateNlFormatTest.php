<?php

/**
 * Unit tests for LicensePlateNlFormat: the fourteen sidecodes and their letter rules.
 *
 * Source of the sidecodes and the letter rules: https://nl.wikipedia.org/wiki/Nederlands_kenteken,
 * read 2026-09-27, as the change's design records.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Formats
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/detection-dutch-licence-plates/specs/pii-entity-detection/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Formats;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Formats\FormatFactory;
use OCA\OpenRegister\Formats\IdentifierFormats;
use OCA\OpenRegister\Formats\LicensePlateNlFormat;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LicensePlateNlFormat::class)]
#[CoversClass(IdentifierFormats::class)]
#[CoversClass(FormatFactory::class)]
final class LicensePlateNlFormatTest extends TestCase {

	/**
	 * One valid plate per sidecode.
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function plates(): array {
		return [
			'1 XX-99-99' => ['AB-12-34', 1],
			'2 99-99-XX' => ['12-34-AB', 2],
			'3 99-XX-99' => ['12-AB-34', 3],
			'4 XX-99-XX' => ['AB-12-DE', 4],
			'5 XX-XX-99' => ['AB-DE-12', 5],
			'6 99-XX-XX' => ['12-AB-DE', 6],
			'7 99-XXX-9' => ['12-GBK-3', 7],
			'8 9-XXX-99' => ['1-GBK-23', 8],
			'9 XX-999-X' => ['GB-123-K', 9],
			'10 X-999-XX' => ['G-123-BK', 10],
			'11 XXX-99-X' => ['GBK-12-D', 11],
			'12 X-99-XXX' => ['G-12-BKD', 12],
			'13 9-XX-999' => ['1-GB-234', 13],
			'14 999-XX-9' => ['123-GB-4', 14],
		];
	}

	#[DataProvider('plates')]
	public function testEverySidecodeIsRecognisedAndNamed(string $plate, int $sidecode): void {
		$format = new LicensePlateNlFormat();

		$this->assertSame($sidecode, $format->sidecode($plate));
		$this->assertTrue($format->validate($plate));
		$this->assertSame($sidecode, $format->sidecode(str_replace('-', '', $plate)), 'without hyphens');
		$this->assertSame($sidecode, $format->sidecode(strtolower($plate)), 'in lower case');
	}

	public function testTheFourteenSidecodesAreTheData(): void {
		$this->assertCount(14, LicensePlateNlFormat::SIDECODES);
		$this->assertSame(range(1, 14), array_keys(LicensePlateNlFormat::SIDECODES));
	}

	public function testCAndQNeverAppear(): void {
		$format = new LicensePlateNlFormat();

		$this->assertNull($format->sidecode('AC-12-34'));
		$this->assertNull($format->sidecode('12-34-QB'));
		$this->assertNull($format->sidecode('12-GCK-3'));
	}

	public function testVowelsAreRefusedFromSidecodeSevenOn(): void {
		$format = new LicensePlateNlFormat();

		$this->assertSame(4, $format->sidecode('AE-12-IO'), 'vowels are allowed up to sidecode 6');
		$this->assertNull($format->sidecode('12-GAK-3'));
		$this->assertNull($format->sidecode('G-12-BKE'));
		$this->assertNull($format->sidecode('1-GU-234'));
	}

	public function testAValueOfNoSidecodeIsRefused(): void {
		$format = new LicensePlateNlFormat();

		foreach (['AB-12', 'AB-12-34-56', '123-456-7', 'ABC-DEF-G', 'AB_12_34', '', 'AB-12-3'] as $value) {
			$this->assertNull($format->sidecode($value), $value);
		}

		$this->assertFalse($format->validate(1234));
		$this->assertFalse($format->validate(null));
	}

	public function testTheFormatIsRegisteredAsLicensePlateNl(): void {
		$formats = FormatFactory::customStringFormats($this->createMock(\OCP\IUserManager::class), static fn (string $uid): bool => false);

		$this->assertInstanceOf(LicensePlateNlFormat::class, $formats['license-plate-nl']);
		$this->assertInstanceOf(LicensePlateNlFormat::class, IdentifierFormats::licensePlateNl());
	}
}
