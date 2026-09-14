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
	private PropertyCalculations $forwarding;
	private CalculationAnnotationValidator $validator;

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

	public function testAForwardedDeclarationIsLiftedAndMaterialisesByDefault(): void {
		$collected = $this->forwarding->fromProperties(
			$this->properties(
				['dateAdd' => ['date' => ['prop' => 'ontvangstdatum'], 'amount' => 6, 'unit' => 'weeks']]
			)
		);

		$this->assertArrayHasKey('uiterlijkeDatum', $collected);
		$this->assertTrue($collected['uiterlijkeDatum']['materialise']);
		$this->assertSame('date', $collected['uiterlijkeDatum']['type']);
	}

	public function testAnAuthorMaySayTheValueIsNotStored(): void {
		$properties = $this->properties(['prop' => 'ontvangstdatum']);
		$properties['uiterlijkeDatum']['calculation']['materialise'] = false;

		$collected = $this->forwarding->fromProperties($properties);
		$this->assertFalse($collected['uiterlijkeDatum']['materialise']);
	}

	public function testAPropertyWithoutACalculationIsNotCollected(): void {
		$this->assertSame(
			[],
			$this->forwarding->fromProperties(['naam' => ['type' => 'string']])
		);
	}

	public function testAForwardedDeclarationIsValidatedLikeAWrittenOne(): void {
		$properties = $this->properties(
			['dateAdd' => ['date' => ['prop' => 'ontvangstdatum'], 'amount' => 6, 'unit' => 'weeks']]
		);

		$errors = $this->validator->validate(
			[
				'properties' => $properties,
				'x-openregister-calculations' => $this->forwarding->merge([], $properties),
			]
		);

		$this->assertSame([], $errors);
	}

	public function testAnUnknownOperatorIsReportedAndBlocksTheSave(): void {
		$properties = $this->properties(['frobnicate' => [['prop' => 'ontvangstdatum']]]);

		$errors = $this->validator->validate(
			[
				'properties' => $properties,
				'x-openregister-calculations' => $this->forwarding->merge([], $properties),
			]
		);

		$codes = array_column($errors, 'code');
		$this->assertContains('calculation-unknown-op', $codes);

		$blocking = $this->forwarding->blockingErrors($errors, ['uiterlijkeDatum']);
		$this->assertNotSame([], $blocking, 'a forwarded declaration must refuse the save');
		$this->assertStringContainsString('frobnicate', $blocking[0]['message']);
		$this->assertStringContainsString('uiterlijkeDatum', $blocking[0]['message']);
	}

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
		$this->assertCount(1, $cycles);
		$this->assertStringContainsString('a', $cycles[0]['message']);
		$this->assertStringContainsString('b', $cycles[0]['message']);

		$blocking = $this->forwarding->blockingErrors($errors, ['a', 'b']);
		$this->assertNotSame([], $blocking, 'a cycle between two forwarded declarations must refuse the save');
	}

	public function testAnAnnotationOnlyErrorStaysAdvisory(): void {
		$errors = [
			['code' => 'calculation-unknown-op', 'message' => 'Calculation "legacyTotaal": unknown operator "frobnicate".'],
		];

		$this->assertSame([], $this->forwarding->blockingErrors($errors, ['uiterlijkeDatum']));
	}

	public function testADependsOnNoteNeverBlocks(): void {
		$errors = [
			['code' => 'calculation-dependson-ignored', 'message' => 'Calculation "uiterlijkeDatum": "dependsOn" is not read.'],
		];

		$this->assertSame([], $this->forwarding->blockingErrors($errors, ['uiterlijkeDatum']));
	}

	public function testADuplicateDeclarationIsRefused(): void {
		$properties = $this->properties(['prop' => 'ontvangstdatum']);
		$duplicates = $this->forwarding->duplicates(
			['uiterlijkeDatum' => ['type' => 'date', 'expression' => ['prop' => 'ontvangstdatum']]],
			$properties
		);

		$this->assertSame(['uiterlijkeDatum'], $duplicates);
	}
}
