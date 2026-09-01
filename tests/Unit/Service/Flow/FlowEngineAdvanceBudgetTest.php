<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use DateTime;
use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowOversightRegistry;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowStepDispatcher;
use OCA\OpenRegister\Service\Flow\FlowTaskMootness;
use OCA\OpenRegister\Service\Flow\IFlowOversightCheck;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;

/**
 * Holds the marking between two walks, like a persisted run does.
 */
class BudgetSubject {
	public array $marking = [];
}//end class

/**
 * Records which steps ran.
 */
class BudgetDispatcher implements FlowStepDispatcher {
	public array $ran = [];

	public function dispatch(array $step, array $items, array $context): array {
		$this->ran[] = (string)($step['id'] ?? '');

		return $items;
	}//end dispatch()
}//end class

/**
 * The per-walk ceiling: read by the engine's own loop, consumed by the walk,
 * bounded by oversight like every other hop; and the branch-pruning report.
 *
 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-advance-budget-says-how-far-a-completion-may-push-the-run
 */
class FlowEngineAdvanceBudgetTest extends TestCase {

	private function linearFlow(): array {
		return [
			'id' => 'linear',
			'nodes' => [
				['id' => 'a', 'type' => 'openregister.set-fields'],
				['id' => 'b', 'type' => 'openregister.set-fields'],
				['id' => 'c', 'type' => 'openregister.set-fields'],
			],
			'edges' => [
				['id' => 'ab', 'from' => 'a', 'to' => 'b'],
				['id' => 'bc', 'from' => 'b', 'to' => 'c'],
			],
		];
	}//end linearFlow()

	private function walk(FlowEngine $engine, BudgetSubject $subject, BudgetDispatcher $dispatcher, array $context, array $flow): array {
		return $engine->run(
			flow: $flow,
			store: new MethodMarkingStore(false, 'marking'),
			subject: $subject,
			dispatcher: $dispatcher,
			context: $context,
			items: [FlowItems::item(json: ['n' => 42])]
		);
	}//end walk()

	/**
	 * A ceiling of 2 fires two transitions and PARKS: suspended, due now, with
	 * a log entry saying why. Then a second walk with no ceiling, over the
	 * same marking, runs exactly the remainder. That second walk is what the
	 * worker does.
	 */
	public function testASpentBudgetParksTheRunAndTheWorkerRunsTheRemainder(): void {
		$engine = new FlowEngine(new FlowDefinitionBuilder(), new NullLogger());
		$subject = new BudgetSubject();

		$first = new BudgetDispatcher();
		$result = $this->walk($engine, $subject, $first, [FlowEngine::CONTEXT_ADVANCE_BUDGET => 2], $this->linearFlow());

		$this->assertSame(['a', 'b'], $first->ran);
		$this->assertSame(FlowEngine::STATUS_SUSPENDED, $result['status']);
		$this->assertInstanceOf(DateTime::class, $result['resumeAt'], 'parked as DUE, so findDue() picks it up');
		$this->assertSame('parked', $result['log'][2]['status']);
		$this->assertStringContainsString('budget', (string)$result['log'][2]['reason']);
		$this->assertArrayNotHasKey(
			FlowEngine::CONTEXT_ADVANCE_BUDGET,
			$result['context'],
			'the ceiling is consumed by the walk that read it; the worker must not inherit it'
		);

		$second = new BudgetDispatcher();
		$resumed = $this->walk($engine, $subject, $second, $result['context'], $this->linearFlow());

		$this->assertSame(['c'], $second->ran);
		$this->assertSame(FlowEngine::STATUS_COMPLETED, $resumed['status']);
	}//end testASpentBudgetParksTheRunAndTheWorkerRunsTheRemainder()

	/**
	 * Positive control: no ceiling, the same flow completes in one walk.
	 */
	public function testWithoutABudgetTheWalkRunsToTheEnd(): void {
		$engine = new FlowEngine(new FlowDefinitionBuilder(), new NullLogger());
		$dispatcher = new BudgetDispatcher();

		$result = $this->walk($engine, new BudgetSubject(), $dispatcher, [], $this->linearFlow());

		$this->assertSame(['a', 'b', 'c'], $dispatcher->ran);
		$this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
	}//end testWithoutABudgetTheWalkRunsToTheEnd()

	/**
	 * A ceiling larger than the flow changes nothing.
	 */
	public function testABudgetLargerThanTheFlowCompletesNormally(): void {
		$engine = new FlowEngine(new FlowDefinitionBuilder(), new NullLogger());
		$dispatcher = new BudgetDispatcher();

		$result = $this->walk($engine, new BudgetSubject(), $dispatcher, [FlowEngine::CONTEXT_ADVANCE_BUDGET => 10], $this->linearFlow());

		$this->assertSame(['a', 'b', 'c'], $dispatcher->ran);
		$this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
	}//end testABudgetLargerThanTheFlowCompletesNormally()

	/**
	 * Junk is not a ceiling. Zero, negatives and strings read as "no budget",
	 * never as "stop before the first hop".
	 */
	public function testJunkIsNotACeiling(): void {
		$engine = new FlowEngine(new FlowDefinitionBuilder(), new NullLogger());

		foreach ([0, -3, '2', null] as $junk) {
			$dispatcher = new BudgetDispatcher();
			$result = $this->walk($engine, new BudgetSubject(), $dispatcher, [FlowEngine::CONTEXT_ADVANCE_BUDGET => $junk], $this->linearFlow());
			$this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status'], var_export($junk, true));
		}
	}//end testJunkIsNotACeiling()

	/**
	 * An in-request continuation passes the SAME oversight gate as any other
	 * walk: a veto stops the run with the check's id, and the hop is not taken.
	 */
	public function testAnOversightVetoStillAppliesUnderABudget(): void {
		$check = $this->createMock(IFlowOversightCheck::class);
		$check->method('getId')->willReturn('test.kill');
		$check->method('veto')->willReturn('The kill switch is set.');
		$registry = new FlowOversightRegistry(new NullLogger());
		$registry->register($check);

		$engine = new FlowEngine(new FlowDefinitionBuilder(), new NullLogger(), $registry);
		$dispatcher = new BudgetDispatcher();

		$result = $this->walk($engine, new BudgetSubject(), $dispatcher, [FlowEngine::CONTEXT_ADVANCE_BUDGET => 5], $this->linearFlow());

		$this->assertSame([], $dispatcher->ran, 'the vetoed hop must not be taken');
		$this->assertSame(FlowEngine::STATUS_STOPPED, $result['status']);
		$this->assertSame('test.kill', $result['log'][0]['checkId']);
		$this->assertStringContainsString('kill switch', (string)$result['log'][0]['reason']);
	}//end testAnOversightVetoStillAppliesUnderABudget()

	/**
	 * A routing decision that clears a place is REPORTED, with the cleared
	 * places and the transition that decided, so a user task waiting on one
	 * of them can be terminated.
	 */
	public function testPrunedExitsAreReportedToTheMootnessCollaborator(): void {
		$mootness = $this->createMock(FlowTaskMootness::class);
		$mootness->expects($this->once())
			->method('placesPruned')
			->with($this->anything(), ['lo'], 's');

		$engine = new FlowEngine(new FlowDefinitionBuilder(), new NullLogger(), null, null, null, null, $mootness);

		$flow = [
			'id' => 'switch',
			'nodes' => [
				[
					'id' => 's',
					'type' => 'switch',
					'exits' => [
						['id' => 'high', 'condition' => ['>' => [['var' => 'json.n'], 10]]],
						['id' => 'low'],
					],
				],
				['id' => 'hi', 'type' => 'high'],
				['id' => 'lo', 'type' => 'openregister.user-task'],
			],
			'edges' => [
				['id' => 'toHigh', 'from' => 's', 'fromExit' => 'high', 'to' => 'hi'],
				['id' => 'toLow', 'from' => 's', 'fromExit' => 'low', 'to' => 'lo'],
			],
		];

		$state = new FlowResumeState();
		$state->forNode(nodeId: 'lo')->set(key: 'taskUuid', value: 't-lo');

		$dispatcher = new BudgetDispatcher();
		$this->walk($engine, new BudgetSubject(), $dispatcher, [FlowResumeState::CONTEXT_KEY => $state], $flow);

		$this->assertSame(['s', 'hi'], $dispatcher->ran);
	}//end testPrunedExitsAreReportedToTheMootnessCollaborator()

	/**
	 * A linear flow prunes nothing, so nothing is reported.
	 */
	public function testALinearFlowReportsNoPruning(): void {
		$mootness = $this->createMock(FlowTaskMootness::class);
		$mootness->expects($this->never())->method('placesPruned');

		$engine = new FlowEngine(new FlowDefinitionBuilder(), new NullLogger(), null, null, null, null, $mootness);

		$this->walk($engine, new BudgetSubject(), new BudgetDispatcher(), [], $this->linearFlow());
	}//end testALinearFlowReportsNoPruning()
}//end class
