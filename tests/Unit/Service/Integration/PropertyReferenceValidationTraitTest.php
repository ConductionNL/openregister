<?php

/**
 * PropertyReferenceValidationTrait: validating every property and phrasing a refusal.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
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

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\Service\Integration\PropertyReferenceValidationTrait;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Integration\PropertyReferenceValidationTrait
 */
class PropertyReferenceValidationTraitTest extends TestCase {

	/**
	 * A validator that records each validate() call and refuses a property carrying `problem`.
	 *
	 * @return object
	 */
	private function validator(): object {
		return new class {
			use PropertyReferenceValidationTrait;

			/**
			 * Each validate() call as [property, propertyName].
			 *
			 * @var array
			 */
			public array $calls = [];

			public function validate(array $property, ?string $propertyName = null): void {
				$this->calls[] = [$property, $propertyName];
				$this->refuse(propertyName: $propertyName, problem: ($property['problem'] ?? null));
			}
		};
	}

	public function testValidateAllValidatesEveryArrayDefinitionWithItsStringName(): void {
		$validator = $this->validator();
		$validator->validateAll(['title' => ['type' => 'string'], 'skip' => 'not-a-definition', 3 => ['type' => 'integer']]);

		$this->assertSame(
			[[['type' => 'string'], 'title'], [['type' => 'integer'], null]],
			$validator->calls
		);
	}

	public function testANamedRefusalIsPrefixedWithThePropertyName(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Property 'owner' must reference a schema");

		$this->validator()->validateAll(['owner' => ['problem' => 'must reference a schema']]);
	}

	public function testAnUnnamedRefusalKeepsTheBareProblem(): void {
		try {
			$this->validator()->validateAll([0 => ['problem' => 'must reference a schema']]);
			$this->fail('Expected a refusal.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('must reference a schema', $e->getMessage());
		}
	}

	public function testNoProblemIsNoRefusalAndValidationContinues(): void {
		$validator = $this->validator();
		$validator->validateAll(['a' => [], 'b' => ['problem' => null]]);

		$this->assertCount(2, $validator->calls);
	}
}
