<?php

/**
 * A materialised calculation needs a property of its own name.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calculation
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://OpenRegister.app
 *
 * @spec openspec/changes/aggregation-dialect-repair/specs/computed-fields/spec.md#requirement-a-materialised-calculations-declared-name-must-also-be-a-declared-schema-property
 */

declare(strict_types=1);

namespace Unit\Service\Calculation;

use OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Without the property the value would evaluate and then be dropped at save time.
 *
 * @covers \OCA\OpenRegister\Service\Calculation\CalculationAnnotationValidator
 */
class MaterialisedOutputPropertyTest extends TestCase {

	/**
	 * A schema with one `total` calculation.
	 *
	 * @param array<string, mixed> $properties The schema's properties.
	 * @param bool|null            $materialise The calculation's `materialise`, or null to leave it out.
	 *
	 * @return array<string, mixed>
	 */
	private function schema(array $properties, ?bool $materialise): array {
		$calculation = ['type' => 'number', 'expression' => ['*' => [['prop' => 'price'], 2]]];
		if ($materialise !== null) {
			$calculation['materialise'] = $materialise;
		}

		return [
			'properties' => $properties,
			'x-openregister-calculations' => ['total' => $calculation],
		];
	}//end schema()

	/**
	 * A materialised `total` without a `total` property is refused, naming it.
	 *
	 * @return void
	 */
	public function testAMaterialisedCalculationWithoutItsPropertyIsRefused(): void {
		$errors = (new CalculationAnnotationValidator())->validate($this->schema(properties: ['price' => ['type' => 'number']], materialise: true));

		$this->assertSame(
			[
				[
					'code' => 'calculation-output-not-in-properties',
					'message' => 'Calculation "total" is materialised but the schema has no property "total"; add that property, or its result is never stored.',
				],
			],
			$errors
		);
	}//end testAMaterialisedCalculationWithoutItsPropertyIsRefused()

	/**
	 * With the property in place the calculation validates.
	 *
	 * @return void
	 */
	public function testAMaterialisedCalculationWithItsPropertyValidates(): void {
		$properties = ['price' => ['type' => 'number'], 'total' => ['type' => 'number']];

		$this->assertSame([], (new CalculationAnnotationValidator())->validate($this->schema(properties: $properties, materialise: true)));
	}//end testAMaterialisedCalculationWithItsPropertyValidates()

	/**
	 * A virtual calculation is computed on read and needs no property.
	 *
	 * @return void
	 */
	public function testAVirtualCalculationNeedsNoProperty(): void {
		$validator = new CalculationAnnotationValidator();
		$properties = ['price' => ['type' => 'number']];

		$this->assertSame([], $validator->validate($this->schema(properties: $properties, materialise: false)));
		$this->assertSame([], $validator->validate($this->schema(properties: $properties, materialise: null)));
	}//end testAVirtualCalculationNeedsNoProperty()
}//end class
