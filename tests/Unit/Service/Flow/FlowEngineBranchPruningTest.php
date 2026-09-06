<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowStepDispatcher;
use OCA\OpenRegister\Service\Flow\FlowTaskMootness;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;

/**
 * Holds the marking for one walk.
 */
class PruningSubject {
	public array $marking = [];
}//end class

/**
 * Records which steps ran.
 */
class PruningDispatcher implements FlowStepDispatcher {
	public array $ran = [];

	public function dispatch(array $step, array $items, array $context): array {
		$this->ran[] = (string)($step['id'] ?? '');

		return $items;
	}//end dispatch()
}//end class

/**
 * The branch-pruning report: a routing decision that clears a place tells the
 * mootness collaborator which places, and which transition decided. The
 * single-stream walk and the stream walk's firing route through the same
 * wrapper, so one hook covers both.
 *
 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-a-task-whose-run-or-branch-has-died-is-terminated-not-orphaned
 */
class FlowEngineBranchPruningTest extends TestCase {

	private function linearFlow(): array {
		return [
			'id' => 'linear',
			'nodes' => [
				['id' => 'a', 'type' => 'openregister.set-fields'],
				['id' => 'b', 'type' => 'openregister.set-fields'],
			],
			'edges' => [['id' => 'ab', 'from' => 'a', 'to' => 'b']],
		];
	}//end linearFlow()

	private function switchFlow(): array {
		return [
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
	}//end switchFlow()

	private function walk(FlowEngine $engine, PruningDispatcher $dispatcher, array $context, array $flow): array {
		return $engine->run(
			flow: $flow,
			store: new MethodMarkingStore(false, 'marking'),
			subject: new PruningSubject(),
			dispatcher: $dispatcher,
			context: $context,
			items: [FlowItems::item(json: ['n' => 42])]
		);
	}//end walk()

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

		$state = new FlowResumeState();
		$state->forNode(nodeId: 'lo')->set(key: 'taskUuid', value: 't-lo');

		$dispatcher = new PruningDispatcher();
		$this->walk($engine, $dispatcher, [FlowResumeState::CONTEXT_KEY => $state], $this->switchFlow());

		$this->assertSame(['s', 'hi'], $dispatcher->ran);
	}//end testPrunedExitsAreReportedToTheMootnessCollaborator()

	/**
	 * A linear flow prunes nothing, so nothing is reported.
	 */
	public function testALinearFlowReportsNoPruning(): void {
		$mootness = $this->createMock(FlowTaskMootness::class);
		$mootness->expects($this->never())->method('placesPruned');

		$engine = new FlowEngine(new FlowDefinitionBuilder(), new NullLogger(), null, null, null, null, $mootness);

		$this->walk($engine, new PruningDispatcher(), [], $this->linearFlow());
	}//end testALinearFlowReportsNoPruning()
}//end class
