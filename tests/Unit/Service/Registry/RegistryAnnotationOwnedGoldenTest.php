<?php

/**
 * Golden test for the `owned` findings of RegistryAnnotationValidator.
 *
 * Pins every finding, its code, its message and their order, so the owned
 * check can be split without changing what a schema author is told.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Registry
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Registry;

use OCA\OpenRegister\Service\Registry\RegistryAnnotationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Golden `owned` findings of RegistryAnnotationValidator::validate().
 */
class RegistryAnnotationOwnedGoldenTest extends TestCase {

	/**
	 * The findings for a schema whose only problem is its `owned` list.
	 *
	 * @param mixed $owned The `owned` value.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 */
	private function findingsFor(mixed $owned): array {
		return (new RegistryAnnotationValidator())->validate(
			[
				'properties' => [
					'bsn' => ['type' => 'string'],
					'givenNames' => ['type' => 'string'],
				],
				'x-openregister-registry' => [
					'registry' => 'brp',
					'identity' => 'bsn',
					'owned' => $owned,
				],
			]
		);
	}//end findingsFor()

	public function testMalformedAndUndeclaredEntriesAreEachReportedInOrder(): void {
		$this->assertSame(
			[
				[
					'code' => 'registry-owned-malformed',
					'message' => 'x-openregister-registry.owned[0] must be a non-empty string.',
				],
				[
					'code' => 'registry-owned-undeclared',
					'message' => 'x-openregister-registry.owned property "birthDate" is not declared in this schema\'s properties.',
				],
				[
					'code' => 'registry-owned-malformed',
					'message' => 'x-openregister-registry.owned[k] must be a non-empty string.',
				],
			],
			$this->findingsFor([0 => 123, 1 => 'givenNames', 2 => 'birthDate', 'k' => ''])
		);
	}//end testMalformedAndUndeclaredEntriesAreEachReportedInOrder()

	public function testAMissingOrEmptyListIsOneFinding(): void {
		$missing = [
			[
				'code' => 'registry-owned-missing',
				'message' => 'x-openregister-registry.owned must be a non-empty array of property names.',
			],
		];

		$this->assertSame($missing, $this->findingsFor([]));
		$this->assertSame($missing, $this->findingsFor('givenNames'));
		$this->assertSame([], $this->findingsFor(['givenNames', 'bsn']));
	}//end testAMissingOrEmptyListIsOneFinding()
}//end class
