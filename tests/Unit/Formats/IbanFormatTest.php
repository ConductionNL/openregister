<?php

/**
 * Unit tests for IbanFormat: ISO 13616 shape, country lengths and the mod-97 checksum.
 *
 * The valid examples are the published example IBANs of each country's bank
 * association, as listed in the SWIFT IBAN registry.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Formats
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Formats;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Formats\FormatFactory;
use OCA\OpenRegister\Formats\IbanFormat;
use OCA\OpenRegister\Formats\UserFormat;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IbanFormat::class)]
#[CoversClass(FormatFactory::class)]
#[UsesClass(UserFormat::class)]
final class IbanFormatTest extends TestCase {

	/**
	 * Valid IBANs, as printed and compact.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function validIbans(): array {
		return [
			'NL printed' => ['NL91 ABNA 0417 1643 00'],
			'NL compact' => ['NL91ABNA0417164300'],
			'NL lower case' => ['nl91abna0417164300'],
			'BE' => ['BE68 5390 0754 7034'],
			'DE' => ['DE89 3704 0044 0532 0130 00'],
			'GB' => ['GB29 NWBK 6016 1331 9268 19'],
			'FR' => ['FR14 2004 1010 0505 0001 3M02 606'],
			'NO, the shortest' => ['NO93 8601 1117 947'],
			'MT, a country without a registered length here' => ['MT84 MALT 0110 0001 2345 MTLC AST0 01S'],
		];
	}

	/**
	 * Invalid values and why.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function invalidValues(): array {
		return [
			'one check digit wrong' => ['NL92ABNA0417164300'],
			'two digits swapped' => ['NL91ABNA0417163400'],
			'NL one character short' => ['NL91ABNA041716430'],
			'NL one character long' => ['NL91ABNA04171643001'],
			'no country letters' => ['9191ABNA0417164300'],
			'letters as check digits' => ['NLAAABNA0417164300'],
			'a punctuation mark' => ['NL91-ABNA-0417-1643-00'],
			'too short for any country' => ['NL9112345678'],
			'empty' => [''],
			'an integer' => [918000417164300],
			'null' => [null],
			'an array' => [['NL91ABNA0417164300']],
		];
	}

	#[DataProvider('validIbans')]
	public function testAValidIbanPasses(string $iban): void {
		$this->assertTrue((new IbanFormat())->validate($iban));
	}

	#[DataProvider('invalidValues')]
	public function testAnInvalidValueFails(mixed $value): void {
		$this->assertFalse((new IbanFormat())->validate($value));
	}

	public function testACountryFormatAcceptsOnlyThatCountry(): void {
		$dutch = new IbanFormat(country: 'NL');

		$this->assertTrue($dutch->validate('NL91 ABNA 0417 1643 00'));
		$this->assertFalse($dutch->validate('DE89 3704 0044 0532 0130 00'), 'a valid German IBAN is not Dutch');
		$this->assertTrue((new IbanFormat(country: 'nl'))->validate('NL91ABNA0417164300'), 'the country is case-insensitive');
	}

	public function testTheRegisteredLengthIsEnforced(): void {
		// Valid checksum, but 19 characters where NL has 18.
		$this->assertSame(18, IbanFormat::LENGTHS['NL']);
		$this->assertFalse((new IbanFormat())->validate('NL06ABNA04171643001'));
	}

	public function testNormaliseRemovesWhitespaceAndUpperCases(): void {
		$this->assertSame('NL91ABNA0417164300', IbanFormat::normalise("nl91 abna\t0417 1643 00"));
	}

	public function testTheSchemaFormatIsRegisteredUnderIban(): void {
		$formats = FormatFactory::customStringFormats(
			userManager: $this->createMock(IUserManager::class),
			planned: static fn (string $uid): bool => false
		);

		$this->assertInstanceOf(IbanFormat::class, $formats['iban']);
		$this->assertTrue($formats['iban']->validate('NL91 ABNA 0417 1643 00'));
	}
}
