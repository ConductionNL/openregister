<?php

declare(strict_types=1);

namespace Unit\Service\Calculation;

use OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator;
use OCA\OpenRegister\Service\Calculation\PropertyCalculations;
use PHPUnit\Framework\TestCase;

/**
 * The forwarding contract: a calculation written on a property.
 *
 * Covers the spec scenarios "an invalid operator is refused at schema save"
 * and "a cycle is caught on the derived list", at the layer that decides
 * whether the save refuses or merely warns.
 */
class PropertyCalculationsTest extends TestCase {
	/**
	 * @var PropertyCalculations The forwarding contract under test.
	 */
	private PropertyCalculations $forwarding;
	/**
	 * @var CalculationAnnotationValidator The validator a forwarded declaration passes through.
	 */
	private CalculationAnnotationValidator $validator;

	/**
	 * Wire the collaborators this suite needs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->forwarding = new PropertyCalculations();
		$this->validator = new CalculationAnnotationValidator();
	}

	/**
	 * A schema whose `uiterlijkeDatum` is six weeks after `ontvangstdatum`.
	 *
	 * @param array<string, mixed> $expression The expression to declare.
	 *
	 * @return array<string, mixed> The properties map.
	 */
	private function properties(array $expression): array {
		return [
			'ontvangstdatum' => ['type' => 'string', 'format' => 'date'],
			'uiterlijkeDatum' => [
				'type' => 'string',
				'format' => 'date',
				'calculation' => ['type' => 'date', 'expression' => $expression],
			],
		];
	}

	/**
	 * A forwarded declaration is lifted and materialises by default.
	 *
	 * @return void
	 */
	public function testAForwardedDeclarationIsLiftedAndMaterialisesByDefault(): void {
		$collected = $this->forwarding->fromProperties(
			$this->properties(expression:
				['dateAdd' => ['date' => ['prop' => 'ontvangstdatum'], 'amount' => 6, 'unit' => 'weeks']]
			)
		);

		$this->assertArrayHasKey(key: 'uiterlijkeDatum', array: $collected);
		$this->assertTrue(condition: $collected['uiterlijkeDatum']['materialise']);
		$this->assertSame(expected: 'date', actual: $collected['uiterlijkeDatum']['type']);
	}

	/**
	 * An author may say the value is not stored.
	 *
	 * @return void
	 */
	public function testAnAuthorMaySayTheValueIsNotStored(): void {
		$properties = $this->properties(expression: ['prop' => 'ontvangstdatum']);
		$properties['uiterlijkeDatum']['calculation']['materialise'] = false;

		$collected = $this->forwarding->fromProperties($properties);
		$this->assertFalse(condition: $collected['uiterlijkeDatum']['materialise']);
	}

	/**
	 * A property without A calculation is not collected.
	 *
	 * @return void
	 */
	public function testAPropertyWithoutACalculationIsNotCollected(): void {
		$this->assertSame(expected: [], actual: $this->forwarding->fromProperties(['naam' => ['type' => 'string']]));
	}

	/**
	 * A forwarded declaration is validated like A written one.
	 *
	 * @return void
	 */
	public function testAForwardedDeclarationIsValidatedLikeAWrittenOne(): void {
		$properties = $this->properties(expression:
			['dateAdd' => ['date' => ['prop' => 'ontvangstdatum'], 'amount' => 6, 'unit' => 'weeks']]
		);

		$errors = $this->validator->validate(
			[
				'properties' => $properties,
				'x-openregister-calculations' => $this->forwarding->merge([], $properties),
			]
		);

		$this->assertSame(expected: [], actual: $errors);
	}

	/**
	 * An unknown operator is reported and blocks the save.
	 *
	 * @return void
	 */
	public function testAnUnknownOperatorIsReportedAndBlocksTheSave(): void {
		$properties = $this->properties(expression: ['frobnicate' => [['prop' => 'ontvangstdatum']]]);

		$errors = $this->validator->validate(
			[
				'properties' => $properties,
				'x-openregister-calculations' => $this->forwarding->merge([], $properties),
			]
		);

		$codes = array_column($errors, 'code');
		$this->assertContains(needle: 'calculation-unknown-op', haystack: $codes);

		$blocking = $this->forwarding->blockingErrors($errors, ['uiterlijkeDatum']);
		$this->assertNotSame(expected: [], actual: $blocking, message: 'a forwarded declaration must refuse the save');
		$this->assertStringContainsString(needle: 'frobnicate', haystack: $blocking[0]['message']);
		$this->assertStringContainsString(needle: 'uiterlijkeDatum', haystack: $blocking[0]['message']);
	}

	/**
	 * A cycle between two computed properties names both.
	 *
	 * @return void
	 */
	public function testACycleBetweenTwoComputedPropertiesNamesBoth(): void {
		$properties = [
			'a' => ['type' => 'integer', 'calculation' => ['type' => 'integer', 'expression' => ['prop' => 'b']]],
			'b' => ['type' => 'integer', 'calculation' => ['type' => 'integer', 'expression' => ['prop' => 'a']]],
		];

		$errors = $this->validator->validate(
			[
				'properties' => $properties,
				'x-openregister-calculations' => $this->forwarding->merge([], $properties),
			]
		);

		$cycles = array_values(array_filter($errors, static fn (array $e) => $e['code'] === 'calculation-cycle'));
		$this->assertCount(expectedCount: 1, haystack: $cycles);
		$this->assertStringContainsString(needle: 'a', haystack: $cycles[0]['message']);
		$this->assertStringContainsString(needle: 'b', haystack: $cycles[0]['message']);

		$blocking = $this->forwarding->blockingErrors($errors, ['a', 'b']);
		$this->assertNotSame(expected: [], actual: $blocking, message: 'a cycle between two forwarded declarations must refuse the save');
	}

	/**
	 * An annotation only error stays advisory.
	 *
	 * @return void
	 */
	public function testAnAnnotationOnlyErrorStaysAdvisory(): void {
		$errors = [
			['code' => 'calculation-unknown-op', 'message' => 'Calculation "legacyTotaal": unknown operator "frobnicate".'],
		];

		$this->assertSame(expected: [], actual: $this->forwarding->blockingErrors($errors, ['uiterlijkeDatum']));
	}

	/**
	 * A depends on note never blocks.
	 *
	 * @return void
	 */
	public function testADependsOnNoteNeverBlocks(): void {
		$errors = [
			['code' => 'calculation-dependson-ignored', 'message' => 'Calculation "uiterlijkeDatum": "dependsOn" is not read.'],
		];

		$this->assertSame(expected: [], actual: $this->forwarding->blockingErrors($errors, ['uiterlijkeDatum']));
	}

	/**
	 * A duplicate declaration is refused.
	 *
	 * @return void
	 */
	public function testADuplicateDeclarationIsRefused(): void {
		$properties = $this->properties(expression: ['prop' => 'ontvangstdatum']);
		$duplicates = $this->forwarding->duplicates(
			['uiterlijkeDatum' => ['type' => 'date', 'expression' => ['prop' => 'ontvangstdatum']]],
			$properties
		);

		$this->assertSame(expected: ['uiterlijkeDatum'], actual: $duplicates);
	}
}
