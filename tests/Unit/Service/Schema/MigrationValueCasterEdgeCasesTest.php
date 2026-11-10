<?php

/**
 * MigrationValueCaster edge cases: native booleans and 0/1, boolean words with
 * padding, non-scalar strings, and dates that do not parse or match their format.
 *
 * MigrationValueCasterTest covers one cast and one refusal per type; this file
 * covers the remaining branches.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Schema\CastTarget;
use OCA\OpenRegister\Service\Schema\MigrationValueCaster;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Schema\MigrationValueCaster
 */
class MigrationValueCasterEdgeCasesTest extends TestCase {

	private function refusalOf(mixed $value, string $toType, ?string $format = null): string {
		try {
			(new MigrationValueCaster())->cast($value, new CastTarget($toType, $format));
		} catch (RuntimeException $refused) {
			return $refused->getMessage();
		}

		$this->fail('Expected the cast to be refused.');
	}

	public function testBooleansPassThroughAndZeroOneAreBooleans(): void {
		$caster = new MigrationValueCaster();

		$this->assertTrue($caster->cast(true, new CastTarget('boolean', null)));
		$this->assertFalse($caster->cast(false, new CastTarget('boolean', null)));
		$this->assertTrue($caster->cast(1, new CastTarget('boolean', null)));
		$this->assertFalse($caster->cast(0, new CastTarget('boolean', null)));
	}

	public function testBooleanWordsAreTrimmedAndCaseInsensitive(): void {
		$caster = new MigrationValueCaster();

		$this->assertTrue($caster->cast('  YES ', new CastTarget('boolean', null)));
		$this->assertTrue($caster->cast('On', new CastTarget('boolean', null)));
		$this->assertFalse($caster->cast('0', new CastTarget('boolean', null)));
		$this->assertFalse($caster->cast('No', new CastTarget('boolean', null)));
	}

	public function testANonWordOrNonScalarIsNotABoolean(): void {
		$this->assertSame('Cannot cast "maybe" to boolean.', $this->refusalOf('maybe', 'boolean'));
		$this->assertSame('Cannot cast "1.5" to boolean.', $this->refusalOf(1.5, 'boolean'));
		$this->assertSame('Cannot cast "NULL" to boolean.', $this->refusalOf(null, 'boolean'));
	}

	public function testANonScalarIsNotAString(): void {
		$this->assertSame('Cannot cast non-scalar value to string.', $this->refusalOf(['a'], 'string'));
	}

	public function testIntegerTruncatesAndNumberKeepsTheFraction(): void {
		$caster = new MigrationValueCaster();

		$this->assertSame(3, $caster->cast('3.9', new CastTarget('integer', null)));
		$this->assertSame(3.9, $caster->cast('3.9', new CastTarget('number', null)));
		$this->assertSame(0, $caster->cast(false, new CastTarget('integer', null)));
	}

	public function testAnEmptyFormatMeansFreeFormAndAnEpochValueIsUtc(): void {
		$this->assertSame('1970-01-02T00:00:00+00:00', (new MigrationValueCaster())->cast('@86400', new CastTarget('date', '')));
	}

	public function testAnUnparseableFreeFormDateIsRefused(): void {
		$this->assertSame('Value "not a date" is not a parseable date.', $this->refusalOf('not a date', 'date'));
	}

	public function testADateNotMatchingItsFormatIsRefused(): void {
		$this->assertSame(
			'Value "2024-03-05" does not match date format "d/m/Y".',
			$this->refusalOf('2024-03-05', 'date', 'd/m/Y')
		);
	}
}
