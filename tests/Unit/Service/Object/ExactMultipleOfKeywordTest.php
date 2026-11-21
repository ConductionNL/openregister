<?php

/**
 * Tests for the exact-decimal multipleOf check.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/object-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\ExactMultipleOfKeyword;
use OCA\OpenRegister\Service\Object\RefLoopGuardParser;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * multipleOf through the parser both object and OAS validation use.
 */
class ExactMultipleOfKeywordTest extends TestCase {
	/**
	 * Values and divisors with the expected verdict.
	 *
	 * @return array<string, array{0: int|float, 1: int|float, 2: bool}>
	 */
	public static function cases(): array {
		return [
			'cents opis refuses' => [1234.56, 0.01, true],
			'cents with a trailing zero' => [100.10, 0.01, true],
			'interest amount from a seed' => [51.39, 0.01, true],
			'ten million and a cent' => [10000000.01, 0.01, true],
			'micro units' => [0.000003, 0.000001, true],
			'integer divisor' => [45, 15, true],
			'zero' => [0, 0.01, true],
			'negative cents' => [-1234.56, 0.01, true],
			'half a cent' => [1234.565, 0.01, false],
			'a tenth of a micro unit' => [0.0000035, 0.000001, false],
			'integer remainder' => [46, 15, false],
			'divisor with a fraction' => [0.75, 0.25, true],
			'not a multiple of a quarter' => [0.8, 0.25, false],
		];
	}//end cases()

	/**
	 * The validator accepts exactly the multiples.
	 *
	 * @param integer|float $value    The value.
	 * @param integer|float $divisor  The multipleOf.
	 * @param boolean       $expected Whether it is a multiple.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testValidatorJudgesMultiplesExactly(int|float $value, int|float $divisor, bool $expected): void {
		$validator = new Validator();
		$validator->setParser((new RefLoopGuardParser(keyword: '$ref'))->schemaParser());
		$schema = json_decode((string) json_encode(['type' => 'object', 'properties' => ['amount' => ['type' => 'number', 'multipleOf' => $divisor]]]));
		$data = json_decode((string) json_encode(['amount' => $value]));

		$result = $validator->validate($data, $schema);

		self::assertSame(expected: $expected, actual: $result->isValid());
		if ($expected === false) {
			self::assertSame(expected: 'multipleOf', actual: $result->error()?->subErrors()[0]?->keyword() ?? $result->error()?->keyword());
		}
	}//end testValidatorJudgesMultiplesExactly()

	/**
	 * The comparison itself, outside the validator.
	 *
	 * @return void
	 */
	public function testIsMultipleOfComparesDecimalsNotFloats(): void {
		self::assertTrue(condition: ExactMultipleOfKeyword::isMultipleOf(value: 1234.56, divisor: 0.01));
		self::assertFalse(condition: ExactMultipleOfKeyword::isMultipleOf(value: 1234.561, divisor: 0.01));
	}//end testIsMultipleOfComparesDecimalsNotFloats()
}//end class
