<?php

/**
 * DependentValueDeclaration: reading a property's dependent-values table, its
 * controlling property's enum and its own enum out of a schema's properties.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Rules\DependentValueDeclaration;
use OCA\OpenRegister\Service\Rules\DependentValueTable;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rules\DependentValueDeclaration
 */
class DependentValueDeclarationTest extends TestCase {

	/**
	 * Properties where `city` depends on `country`.
	 *
	 * @param mixed $table The dependent-values annotation on `city`.
	 * @param mixed $countryEnum The enum of `country`.
	 * @param mixed $cityEnum The enum of `city`.
	 *
	 * @return DependentValueDeclaration
	 */
	private static function declaration(mixed $table, mixed $countryEnum = ['NL', 'BE'], mixed $cityEnum = ['Utrecht']): DependentValueDeclaration {
		return new DependentValueDeclaration('city', [
			'country' => ['type' => 'string', 'enum' => $countryEnum],
			'city' => ['type' => 'string', 'enum' => $cityEnum, DependentValueTable::ANNOTATION => $table],
		]);
	}

	public function testEntriesAndAllowedValuesAreReadFromTheTable(): void {
		$declaration = self::declaration(['controlledBy' => 'country', 'allowed' => ['NL' => ['Utrecht'], 'BE' => ['Gent']]]);

		$this->assertSame('country', $declaration->entry('controlledBy'));
		$this->assertNull($declaration->entry('absent'));
		$this->assertSame(['Gent'], $declaration->valuesFor('BE'));
	}

	public function testANonArrayTableHasNoEntries(): void {
		$declaration = self::declaration('not a table');

		$this->assertSame('not a table', $declaration->table());
		$this->assertNull($declaration->entry('controlledBy'));
	}

	public function testControllingEnumIsTheControllingPropertysScalarValuesAsStrings(): void {
		$declaration = self::declaration(['controlledBy' => 'country'], ['NL', 2, ['nested'], null, true]);

		$this->assertSame(['NL', '2', '1'], $declaration->controllingEnum());
	}

	public function testAnUnknownControllingPropertyHasNoEnum(): void {
		$this->assertNull(self::declaration(['controlledBy' => 'planet'])->controllingEnum());
		$this->assertNull(self::declaration([])->controllingEnum());
	}

	public function testAnEnumWithoutScalarsOrNotAListCountsAsNoEnum(): void {
		$this->assertNull(self::declaration(['controlledBy' => 'country'], [['a'], null])->controllingEnum());
		$this->assertNull(self::declaration(['controlledBy' => 'country'], 'NL')->controllingEnum());
	}

	public function testOwnEnumReadsTheDeclaredPropertysEnum(): void {
		$this->assertSame(['Utrecht', 'Gent'], self::declaration([], cityEnum: ['Utrecht', 'Gent'])->ownEnum());
		$this->assertNull(self::declaration([], cityEnum: [])->ownEnum());
	}
}
