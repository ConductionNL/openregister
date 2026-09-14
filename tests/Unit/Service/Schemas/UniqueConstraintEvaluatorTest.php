<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Schemas;

use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator;
use PHPUnit\Framework\TestCase;

class UniqueConstraintEvaluatorTest extends TestCase {

	private UniqueConstraintEvaluator $evaluator;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->evaluator = new UniqueConstraintEvaluator();
	}//end setUp()

	/**
	 * Both actions are read, because both are real.
	 *
	 * @return void
	 */
	public function testBothActionsAreRead(): void {
		$constraints = $this->evaluator->constraints(
			configuration: [
				'uniqueConstraints' => [
					['name' => 'een-bezwaar', 'properties' => ['besluit', 'indiener'], 'action' => 'refuse'],
					['name' => 'dubbel-adres', 'properties' => ['email'], 'action' => 'report'],
				],
			]
		);

		$this->assertCount(2, $constraints);
		$this->assertSame('refuse', $constraints[0]['action']);
		$this->assertSame(['besluit', 'indiener'], $constraints[0]['properties']);
		$this->assertSame('report', $constraints[1]['action']);
	}//end testBothActionsAreRead()

	/**
	 * A malformed constraint is dropped rather than guessed at: one that
	 * quietly became a report would be a refusal someone believes they
	 * configured and did not get.
	 *
	 * @return void
	 */
	public function testAMalformedConstraintIsDroppedRatherThanGuessedAt(): void {
		$constraints = $this->evaluator->constraints(
			configuration: [
				'uniqueConstraints' => [
					['name' => 'geen-velden', 'properties' => [], 'action' => 'refuse'],
					['name' => 'rare-actie', 'properties' => ['email'], 'action' => 'shout'],
					['properties' => ['bsn']],
				],
			]
		);

		$this->assertCount(1, $constraints);
		$this->assertSame('bsn', $constraints[0]['name']);
		$this->assertSame('refuse', $constraints[0]['action'], 'refuse is the default action.');
	}//end testAMalformedConstraintIsDroppedRatherThanGuessedAt()

	/**
	 * The pre-existing `unique` key is NOT read unless asked for, so adding
	 * this evaluator does not give one mistake two different messages.
	 *
	 * @return void
	 */
	public function testThePreExistingUniqueKeyIsOptIn(): void {
		$configuration = ['unique' => ['bsn']];

		$this->assertSame([], $this->evaluator->constraints(configuration: $configuration));
		$this->assertCount(
			1,
			$this->evaluator->constraints(configuration: $configuration, includeLegacy: true)
		);
	}//end testThePreExistingUniqueKeyIsOptIn()

	/**
	 * An incomplete combination cannot be breached, so it produces no filter
	 * rather than a filter on null that would match every other incomplete
	 * object.
	 *
	 * @return void
	 */
	public function testAnIncompleteCombinationProducesNoFilter(): void {
		$constraint = ['name' => 'x', 'properties' => ['besluit', 'indiener'], 'action' => 'refuse'];

		$this->assertNull(
			$this->evaluator->filtersFor(constraint: $constraint, object: ['besluit' => 'B-1'])
		);
		$this->assertNull(
			$this->evaluator->filtersFor(
				constraint: $constraint,
				object: ['besluit' => 'B-1', 'indiener' => '']
			)
		);
		$this->assertSame(
			['besluit' => 'B-1', 'indiener' => 'P-9'],
			$this->evaluator->filtersFor(
				constraint: $constraint,
				object: ['besluit' => 'B-1', 'indiener' => 'P-9', 'toelichting' => 'x']
			)
		);
	}//end testAnIncompleteCombinationProducesNoFilter()

	/**
	 * The breach names the combination and the conflicting object.
	 *
	 * @return void
	 */
	public function testTheBreachNamesTheCombinationAndTheConflictingObject(): void {
		$message = $this->evaluator->describeBreach(
			constraint: ['name' => 'een-bezwaar', 'properties' => ['besluit', 'indiener'], 'action' => 'refuse'],
			object: ['besluit' => 'B-1', 'indiener' => 'P-9'],
			conflictingId: 'abc-123'
		);

		$this->assertStringContainsString('besluit and indiener', $message);
		$this->assertStringContainsString('besluit=B-1', $message);
		$this->assertStringContainsString('indiener=P-9', $message);
		$this->assertStringContainsString('abc-123', $message);
	}//end testTheBreachNamesTheCombinationAndTheConflictingObject()
}//end class
