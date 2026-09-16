<?php

/**
 * Tests for the declared match type and input control.
 *
 * A date wants a range, a zaaknummer wants exact, a description wants full
 * text. Putting that on the property means one declaration serves the list, the
 * facet, the API and the portal. Putting it in the list component means four
 * answers and three of them drift.
 *
 * The expensive half of this class is not the declaration, it is the promise
 * around it: a property that declares nothing must behave exactly as it did
 * before the class existed. Most of the cases below are that promise, because
 * breaking it changes what every saved search in the fleet matches, silently
 * and all at once.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Search;

use OCA\OpenRegister\Service\Search\PropertySearchProfile;
use PHPUnit\Framework\TestCase;

/**
 * Locks what a property says about how it wants to be searched.
 */
class PropertySearchProfileTest extends TestCase {

	/**
	 * A declared match type is applied.
	 *
	 * @return void
	 */
	public function testADeclaredMatchTypeIsApplied(): void {
		foreach (PropertySearchProfile::MATCH_TYPES as $matchType) {
			$this->assertSame(
				$matchType,
				PropertySearchProfile::matchTypeFor(
					property: ['type' => 'string', 'matchType' => $matchType]
				)
			);
		}
	}//end testADeclaredMatchTypeIsApplied()

	/**
	 * Case and surrounding space do not change what was declared.
	 *
	 * @return void
	 */
	public function testADeclarationIsReadCaseInsensitively(): void {
		$this->assertSame(
			PropertySearchProfile::MATCH_EXACT,
			PropertySearchProfile::matchTypeFor(property: ['type' => 'string', 'matchType' => '  Exact '])
		);
	}//end testADeclarationIsReadCaseInsensitively()

	/**
	 * The properties an undeclared match type is worked out for.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}> Property and expected type.
	 */
	public static function undeclaredProperties(): array {
		return [
			'a plain string is full text, as it always was' => [
				['type' => 'string'],
				PropertySearchProfile::MATCH_FULLTEXT,
			],
			'a date is a range' => [
				['type' => 'string', 'format' => 'date'],
				PropertySearchProfile::MATCH_RANGE,
			],
			'a date-time is a range' => [
				['type' => 'string', 'format' => 'date-time'],
				PropertySearchProfile::MATCH_RANGE,
			],
			'an integer is a range' => [
				['type' => 'integer'],
				PropertySearchProfile::MATCH_RANGE,
			],
			'a number is a range' => [
				['type' => 'number'],
				PropertySearchProfile::MATCH_RANGE,
			],
			'a boolean is exact' => [
				['type' => 'boolean'],
				PropertySearchProfile::MATCH_EXACT,
			],
		];
	}//end undeclaredProperties()

	/**
	 * @param array  $property The property definition.
	 * @param string $expected The match type it should get.
	 *
	 * @phpstan-param array<string, mixed> $property
	 *
	 * @psalm-param array<string, mixed> $property
	 *
	 * @dataProvider undeclaredProperties
	 *
	 * @return void
	 */
	public function testAnUndeclaredPropertyGetsTheAutoDetectedType(array $property, string $expected): void {
		$this->assertFalse(PropertySearchProfile::declaresMatchType(property: $property));
		$this->assertSame($expected, PropertySearchProfile::matchTypeFor(property: $property));
	}//end testAnUndeclaredPropertyGetsTheAutoDetectedType()

	/**
	 * An empty or non-string declaration is not a declaration.
	 *
	 * @return void
	 */
	public function testAnEmptyDeclarationIsNotADeclaration(): void {
		foreach ([['type' => 'string', 'matchType' => ''], ['type' => 'string', 'matchType' => '  ']] as $property) {
			$this->assertFalse(PropertySearchProfile::declaresMatchType(property: $property));
			$this->assertSame(
				PropertySearchProfile::MATCH_FULLTEXT,
				PropertySearchProfile::matchTypeFor(property: $property)
			);
		}
	}//end testAnEmptyDeclarationIsNotADeclaration()

	/**
	 * The regression that decides whether this change is safe: the free-text
	 * scan reads exactly the columns it read before, for every property that
	 * declares nothing.
	 *
	 * @return void
	 */
	public function testAnUndeclaredPropertyTakesPartExactlyAsItDidBefore(): void {
		// Was scanned before, is scanned now.
		$this->assertTrue(
			PropertySearchProfile::participatesInFreeText(property: ['type' => 'string'])
		);
		$this->assertTrue(
			PropertySearchProfile::participatesInFreeText(property: ['type' => 'string', 'format' => 'email'])
		);

		// Was NOT scanned before, and must not start being scanned now. An
		// auto-detected `exact` on a boolean would otherwise quietly widen what
		// every `_search` matches.
		foreach (
			[
				['type' => 'string', 'format' => 'date'],
				['type' => 'string', 'format' => 'date-time'],
				['type' => 'string', 'format' => 'time'],
				['type' => 'boolean'],
				['type' => 'integer'],
				['type' => 'number'],
				['type' => 'array'],
				['type' => 'object'],
			] as $property
		) {
			$this->assertFalse(
				PropertySearchProfile::participatesInFreeText(property: $property),
				'An undeclared ' . json_encode($property) . ' was not scanned before this change.'
			);
		}
	}//end testAnUndeclaredPropertyTakesPartExactlyAsItDidBefore()

	/**
	 * A declaration can pull a column into the scan that auto-detection leaves
	 * out, which is the point of declaring one.
	 *
	 * @return void
	 */
	public function testADeclarationDecidesParticipation(): void {
		$this->assertTrue(
			PropertySearchProfile::participatesInFreeText(
				property: ['type' => 'integer', 'matchType' => 'exact']
			)
		);

		// A range is a pair of bounds, not a term, so it never joins the scan.
		$this->assertFalse(
			PropertySearchProfile::participatesInFreeText(
				property: ['type' => 'string', 'matchType' => 'range']
			)
		);
	}//end testADeclarationDecidesParticipation()

	/**
	 * An encrypted property is never scanned, declaration or not. Its column
	 * holds ciphertext that no plaintext term can match, and may not exist.
	 *
	 * @return void
	 */
	public function testAnEncryptedPropertyIsNeverScanned(): void {
		$this->assertFalse(
			PropertySearchProfile::participatesInFreeText(
				property: [
					'type' => 'string',
					'matchType' => 'fulltext',
					'x-openregister-encrypted' => true,
				]
			)
		);
	}//end testAnEncryptedPropertyIsNeverScanned()

	/**
	 * The spec scenario: a date property declaring `range` tells the list
	 * surface to render a range control.
	 *
	 * @return void
	 */
	public function testADatePropertyDeclaringRangeOffersARangeControl(): void {
		$control = PropertySearchProfile::inputControlFor(
			property: ['type' => 'string', 'format' => 'date', 'matchType' => 'range']
		);

		$this->assertSame('date-range', $control);
		$this->assertStringContainsString('range', $control);
	}//end testADatePropertyDeclaringRangeOffersARangeControl()

	/**
	 * A declared control wins over the one the match type would imply.
	 *
	 * @return void
	 */
	public function testADeclaredInputControlWins(): void {
		$this->assertSame(
			'select',
			PropertySearchProfile::inputControlFor(
				property: ['type' => 'string', 'matchType' => 'range', 'inputControl' => 'select']
			)
		);
	}//end testADeclaredInputControlWins()

	/**
	 * An input control outside the vocabulary is ignored rather than passed
	 * through to a surface that cannot render it. The schema save refuses it
	 * first; this is the second line.
	 *
	 * @return void
	 */
	public function testAnUnknownInputControlFallsBackToTheDerivedOne(): void {
		$this->assertSame(
			'text',
			PropertySearchProfile::inputControlFor(
				property: ['type' => 'string', 'inputControl' => 'hologram']
			)
		);
	}//end testAnUnknownInputControlFallsBackToTheDerivedOne()

	/**
	 * The controls derived from the property when nothing is declared.
	 *
	 * @return void
	 */
	public function testTheDerivedControls(): void {
		$cases = [
			['text', ['type' => 'string']],
			['boolean', ['type' => 'boolean']],
			['range', ['type' => 'integer']],
			['date-range', ['type' => 'string', 'format' => 'date']],
			['select', ['type' => 'string', 'enum' => ['a', 'b']]],
			['multiselect', ['type' => 'array', 'enum' => ['a', 'b']]],
			['multiselect', ['type' => 'array']],
		];

		foreach ($cases as [$expected, $property]) {
			$this->assertSame(
				$expected,
				PropertySearchProfile::inputControlFor(property: $property),
				json_encode($property)
			);
		}
	}//end testTheDerivedControls()

	/**
	 * An unknown match type is not silently honoured. The schema save refuses
	 * it, and if one reaches here anyway the property falls back to the type it
	 * would have had, rather than to no matching at all.
	 *
	 * @return void
	 */
	public function testAnUnknownMatchTypeFallsBackToTheAutoDetectedOne(): void {
		$this->assertSame(
			PropertySearchProfile::MATCH_FULLTEXT,
			PropertySearchProfile::matchTypeFor(property: ['type' => 'string', 'matchType' => 'telepathy'])
		);
	}//end testAnUnknownMatchTypeFallsBackToTheAutoDetectedOne()
}//end class
