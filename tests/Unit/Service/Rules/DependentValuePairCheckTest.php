<?php

/**
 * DependentValuePairCheck: each pair of a dependent-values table judged against the enums of both properties.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/unit-test-coverage/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Rules\DependentValueDeclaration;
use OCA\OpenRegister\Service\Rules\DependentValuePairCheck;
use OCA\OpenRegister\Service\Rules\DependentValueTable;
use OCA\OpenRegister\Service\Rules\DependentValueValidator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rules\DependentValuePairCheck
 * @uses   \OCA\OpenRegister\Service\Rules\DependentValueDeclaration
 */
class DependentValuePairCheckTest extends TestCase {

	/**
	 * The sentence refusing a pair without values.
	 *
	 * @var string
	 */
	private const MALFORMED = 'Property "city": "%s" must be paired with a list of allowed values.';

	/**
	 * `city` controlled by `country`, with the given allowed map and enums.
	 *
	 * @param array<string, mixed> $allowed     The allowed map.
	 * @param mixed                $countryEnum The enum of `country`.
	 * @param mixed                $cityEnum    The enum of `city`.
	 *
	 * @return DependentValueDeclaration
	 */
	private static function declaration(
		array $allowed,
		mixed $countryEnum = ['NL', 'BE'],
		mixed $cityEnum = ['Utrecht', 'Gent', '1']
	): DependentValueDeclaration {
		return new DependentValueDeclaration('city', [
			'country' => ['type' => 'string', 'enum' => $countryEnum],
			'city' => ['type' => 'string', 'enum' => $cityEnum, DependentValueTable::ANNOTATION => ['controlledBy' => 'country', 'allowed' => $allowed]],
		]);
	}

	public function testAWellFormedTableHasNoErrors(): void {
		$this->assertSame([], (new DependentValuePairCheck())->errors(self::declaration(['NL' => ['Utrecht'], 'BE' => ['Gent', 1]])));
	}

	public function testAPairWithoutAListIsMalformedButAnEmptyListIsNot(): void {
		$errors = (new DependentValuePairCheck())->errors(self::declaration(['NL' => [], 'BE' => 'Gent']));

		// An empty list is accepted (no city allowed for NL); a bare value is not a list.
		$this->assertSame([
			['code' => DependentValueValidator::CODE_MALFORMED, 'message' => sprintf(self::MALFORMED, 'BE')],
		], $errors);
	}

	public function testAControllingValueOutsideTheControllingEnumIsRefused(): void {
		$errors = (new DependentValuePairCheck())->errors(self::declaration(['DE' => ['Utrecht']]));

		$this->assertSame([
			['code' => DependentValueValidator::CODE_UNKNOWN_CONTROLLING_VALUE, 'message' => 'Property "city": "DE" is not a value "country" can take.'],
		], $errors);
	}

	public function testListedValuesOutsideTheOwnEnumAreRefusedInOrderAndNonScalarsByType(): void {
		$errors = (new DependentValuePairCheck())->errors(self::declaration(['NL' => ['Utrecht', 'Paris', ['x'], null]]));

		$this->assertSame([DependentValueValidator::CODE_UNKNOWN_VALUE], array_unique(array_column($errors, 'code')));
		$this->assertSame([
			'Property "city": "Paris" is not a value "city" can take, so pairing it with "NL" allows a value the property already refuses.',
			'Property "city": "array" is not a value "city" can take, so pairing it with "NL" allows a value the property already refuses.',
			'Property "city": "NULL" is not a value "city" can take, so pairing it with "NL" allows a value the property already refuses.',
		], array_column($errors, 'message'));
	}

	public function testBothErrorsOfOnePairAreReportedControllingFirst(): void {
		$errors = (new DependentValuePairCheck())->errors(self::declaration(['DE' => ['Berlin']]));

		$this->assertSame(
			[DependentValueValidator::CODE_UNKNOWN_CONTROLLING_VALUE, DependentValueValidator::CODE_UNKNOWN_VALUE],
			array_column($errors, 'code')
		);
	}

	public function testWithoutEnumsAnyValueIsAccepted(): void {
		$this->assertSame([], (new DependentValuePairCheck())->errors(self::declaration(['DE' => ['Berlin', ['x']]], null, null)));
	}

	public function testANumericControllingKeyIsJudgedAsAString(): void {
		$errors = (new DependentValuePairCheck())->errors(self::declaration([7 => ['Utrecht']], ['7']));

		$this->assertSame([], $errors);
	}
}
