<?php

/**
 * Unit tests for FlowRunAssignee — who may answer a suspended step.
 *
 * The rule was extracted from FlowRunController so an in-process resume (a leaf
 * app whose object completes a task) applies the SAME rule as the HTTP endpoint
 * rather than a second copy of it. These tests exist to keep the one copy
 * honest.
 *
 * Three directions matter more than the happy path, and each has a way of
 * passing while broken:
 *
 *   - the GROUP branch — a broken group lookup refuses the step's own intended
 *     audience, and reads as "the guard works", because refusing is what a
 *     guard does;
 *   - the UNASSIGNED case — deliberately open, so an implementation that
 *     treated "no assignee" like "no uid" would pass every other test here
 *     while leaving assigned steps answerable by anyone;
 *   - the ANONYMOUS case — must fail closed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-suspended-on-an-external-signal-must-be-reachable
 */

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunAssignee;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

class FlowRunAssigneeTest extends TestCase {

	/**
	 * A run whose resume slots are exactly as given.
	 *
	 * @param array $slots The per-node resume slots.
	 *
	 * @return FlowRun The run.
	 */
	private function runWithSlots(array $slots): FlowRun {
		$run = new FlowRun();
		$run->setContext([FlowResumeState::CONTEXT_KEY => $slots]);

		return $run;
	}//end runWithSlots()

	public function testAnUnaskedSlotNamesNobody(): void {
		// A slot with no askedAt has not asked anything, so its assignee is not
		// the person currently being waited on.
		$run = $this->runWithSlots(['node-a' => ['assignee' => 'alice']]);

		$this->assertSame('', (new FlowRunAssignee())->recordedFor(run: $run));
	}//end testAnUnaskedSlotNamesNobody()

	public function testTheAskingSlotNamesItsAssignee(): void {
		$run = $this->runWithSlots([
			'node-a' => ['assignee' => 'alice'],
			'node-b' => ['askedAt' => '2026-08-28T10:00:00+00:00', 'assignee' => 'bob'],
		]);

		$this->assertSame('bob', (new FlowRunAssignee())->recordedFor(run: $run));
	}//end testTheAskingSlotNamesItsAssignee()

	public function testTheAssigneeMayAnswer(): void {
		$run = $this->runWithSlots(['n' => ['askedAt' => 'now', 'assignee' => 'bob']]);

		$this->assertTrue((new FlowRunAssignee())->mayAnswer(run: $run, uid: 'bob'));
	}//end testTheAssigneeMayAnswer()

	public function testSomebodyElseMayNot(): void {
		$run = $this->runWithSlots(['n' => ['askedAt' => 'now', 'assignee' => 'bob']]);

		$this->assertFalse((new FlowRunAssignee())->mayAnswer(run: $run, uid: 'carol'));
	}//end testSomebodyElseMayNot()

	/**
	 * 🔴 The unassigned case is deliberately OPEN.
	 *
	 * Webhook and child-run signals are not human decisions and record no
	 * assignee. An implementation that fails closed here would break every one
	 * of them — and would do it while looking more secure.
	 */
	public function testAnUnassignedStepIsAnswerableByAnyone(): void {
		$run = $this->runWithSlots(['n' => ['askedAt' => 'now']]);

		$assignee = new FlowRunAssignee();

		$this->assertTrue($assignee->mayAnswer(run: $run, uid: 'anyone'));
		$this->assertTrue($assignee->mayAnswer(run: $run, uid: null));
	}//end testAnUnassignedStepIsAnswerableByAnyone()

	/**
	 * 🔴 An ASSIGNED step is never anonymous.
	 */
	public function testAnAssignedStepRefusesAnAnonymousCaller(): void {
		$run = $this->runWithSlots(['n' => ['askedAt' => 'now', 'assignee' => 'bob']]);

		$assignee = new FlowRunAssignee();

		$this->assertFalse($assignee->mayAnswer(run: $run, uid: null));
		$this->assertFalse($assignee->mayAnswer(run: $run, uid: ''));
	}//end testAnAssignedStepRefusesAnAnonymousCaller()

	/**
	 * 🔴 THE GROUP BRANCH. A group-assigned step admits its members.
	 */
	public function testAGroupMemberMayAnswerAGroupAssignedStep(): void {
		$run = $this->runWithSlots(['n' => ['askedAt' => 'now', 'assignee' => 'behandelaars']]);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->with('carol', 'behandelaars')->willReturn(true);

		$this->assertTrue(
			(new FlowRunAssignee(groupManager: $groups))->mayAnswer(run: $run, uid: 'carol')
		);
	}//end testAGroupMemberMayAnswerAGroupAssignedStep()

	public function testANonMemberMayNot(): void {
		$run = $this->runWithSlots(['n' => ['askedAt' => 'now', 'assignee' => 'behandelaars']]);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturn(false);

		$this->assertFalse(
			(new FlowRunAssignee(groupManager: $groups))->mayAnswer(run: $run, uid: 'carol')
		);
	}//end testANonMemberMayNot()

	/**
	 * With no group manager the group branch REFUSES rather than admits.
	 *
	 * Asserted explicitly so the fail-closed direction is a stated property
	 * rather than something inferred from a passing suite elsewhere.
	 */
	public function testWithoutAGroupManagerAGroupAssignmentRefuses(): void {
		$run = $this->runWithSlots(['n' => ['askedAt' => 'now', 'assignee' => 'behandelaars']]);

		$this->assertFalse((new FlowRunAssignee())->mayAnswer(run: $run, uid: 'carol'));
	}//end testWithoutAGroupManagerAGroupAssignmentRefuses()

	public function testARunWithNoSlotsNamesNobodyAndIsOpen(): void {
		$run = new FlowRun();

		$assignee = new FlowRunAssignee();

		$this->assertSame('', $assignee->recordedFor(run: $run));
		$this->assertTrue($assignee->mayAnswer(run: $run, uid: 'anyone'));
	}//end testARunWithNoSlotsNamesNobodyAndIsOpen()
}//end class
