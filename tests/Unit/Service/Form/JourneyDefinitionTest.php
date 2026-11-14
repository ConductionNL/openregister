<?php

/**
 * A journey body reads as ordered steps, their fields and writes, branching and access.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Form;

use OCA\OpenRegister\Service\Form\JourneyDefinition;
use OCA\OpenRegister\Service\Form\LocalConditionEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Steps, field scope, next rules and access of a stored journey.
 *
 * @covers \OCA\OpenRegister\Service\Form\JourneyDefinition
 * @uses \OCA\OpenRegister\Service\Form\LocalConditionEvaluator
 */
class JourneyDefinitionTest extends TestCase {

	/**
	 * A four-step journey.
	 *
	 * @param array<string, mixed> $overrides Top-level keys to replace.
	 *
	 * @return JourneyDefinition The journey.
	 */
	private function journey(array $overrides=[]): JourneyDefinition {
		$body = array_merge(
			[
				'status' => 'published',
				'access' => 'anonymous',
				'steps' => [
					['id' => 'one', 'type' => 'form', 'fields' => ['a', ['field' => 'b'], ['name' => 'c'], 42], 'next' => [
						['when' => ['field' => 'a', 'value' => 'skip'], 'step' => 'three'],
						['when' => ['field' => 'a', 'value' => 'ghost'], 'step' => 'missing'],
					]],
					['id' => 'two', 'type' => 'form', 'writes' => [['register' => 'r', 'schema' => 's', 'mapping' => ['fields' => [['field' => 'd', 'property' => 'x']]]], 'not-a-write']],
					'not-a-step',
					['id' => 'three', 'type' => 'review', 'fields' => ['e'], 'next' => [['step' => 'one']]],
					['id' => 'two', 'type' => 'form', 'fields' => ['duplicate']],
					['id' => 'four', 'type' => 'confirmation'],
				],
			],
			$overrides
		);

		return new JourneyDefinition(id: 'j', body: $body);
	}//end journey()

	/**
	 * Steps keep declared order; non-arrays and repeated ids are skipped.
	 *
	 * @return void
	 */
	public function testStepsKeepDeclaredOrder(): void {
		$journey = $this->journey();

		$this->assertSame(['one', 'two', 'three', 'four'], $journey->stepIds());
		$this->assertTrue($journey->hasStep(stepId: 'two'));
		$this->assertFalse($journey->hasStep(stepId: 'five'));
		$this->assertSame([], $journey->step(stepId: 'five'));
	}//end testStepsKeepDeclaredOrder()

	/**
	 * A step's fields are its own names and its write mappings' fields.
	 *
	 * @return void
	 */
	public function testAStepsFieldsIncludeItsMappedFields(): void {
		$journey = $this->journey();

		$this->assertSame(['a', 'b', 'c'], $journey->fieldsOf(stepId: 'one'));
		$this->assertSame(['d'], $journey->fieldsOf(stepId: 'two'));
		$this->assertCount(1, $journey->writesOf(stepId: 'two'));
	}//end testAStepsFieldsIncludeItsMappedFields()

	/**
	 * The scope at a step is that step and every step before it, never a later one.
	 *
	 * @return void
	 */
	public function testTheScopeStopsAtTheCurrentStep(): void {
		$journey = $this->journey();

		$this->assertSame(['a', 'b', 'c'], $journey->fieldsInScope(stepId: 'one'));
		$this->assertSame(['a', 'b', 'c', 'd', 'e'], $journey->fieldsInScope(stepId: 'three'));
		$this->assertSame([], $journey->fieldsInScope(stepId: 'five'));
	}//end testTheScopeStopsAtTheCurrentStep()

	/**
	 * The first holding rule decides; a rule to an undeclared step is skipped; otherwise the next declared step.
	 *
	 * @return void
	 */
	public function testNextFollowsTheFirstHoldingRule(): void {
		$journey    = $this->journey();
		$conditions = new LocalConditionEvaluator();

		$this->assertSame('three', $journey->nextAfter(stepId: 'one', answers: ['a' => 'skip'], conditions: $conditions));
		$this->assertSame('two', $journey->nextAfter(stepId: 'one', answers: ['a' => 'ghost'], conditions: $conditions));
		$this->assertSame('one', $journey->nextAfter(stepId: 'three', answers: [], conditions: $conditions));
		$this->assertNull($journey->nextAfter(stepId: 'four', answers: [], conditions: $conditions));
		$this->assertNull($journey->nextAfter(stepId: 'five', answers: [], conditions: $conditions));
	}//end testNextFollowsTheFirstHoldingRule()

	/**
	 * Access is read, never inferred; the object form `{ level }` is accepted.
	 *
	 * @return void
	 */
	public function testAccessIsNeverInferred(): void {
		$this->assertSame('anonymous', $this->journey()->access());
		$this->assertSame('minTrust', $this->journey(overrides: ['access' => ['level' => 'minTrust']])->access());
		$this->assertNull($this->journey(overrides: ['access' => null])->access());
		$this->assertNull($this->journey(overrides: ['access' => 'public'])->access());
	}//end testAccessIsNeverInferred()

	/**
	 * Published by status or flag, otherwise not.
	 *
	 * @return void
	 */
	public function testPublishedByStatusOrFlag(): void {
		$this->assertTrue($this->journey()->isPublished());
		$this->assertTrue($this->journey(overrides: ['status' => 'draft', 'published' => true])->isPublished());
		$this->assertFalse($this->journey(overrides: ['status' => 'draft'])->isPublished());
	}//end testPublishedByStatusOrFlag()
}//end class
