<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Flow\FlowGraph;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowTaskMootness;
use OCA\OpenRegister\Service\Task\TaskService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * A losing branch takes its task with it; a run-less task is never touched.
 *
 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-a-task-whose-run-or-branch-has-died-is-terminated-not-orphaned
 */
class FlowTaskMootnessTest extends TestCase {

	private TaskService&MockObject $tasks;

	private FlowTaskMootness $mootness;

	protected function setUp(): void {
		$this->tasks = $this->createMock(TaskService::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(TaskService::class)->willReturn($this->tasks);

		$this->mootness = new FlowTaskMootness($container, new NullLogger());
	}//end setUp()

	private function task(?string $runUuid): Task {
		$task = new Task();
		$task->setUuid('t-lo');
		$task->setRunUuid($runUuid);
		$task->setNodeId('lo');
		$task->setState(Task::STATE_ACTIVE);
		$task->setIsTerminal(false);

		return $task;
	}//end task()

	private function context(FlowResumeState $state): array {
		return [
			FlowResumeState::CONTEXT_KEY => $state,
			FlowRunContext::CONTEXT_RUN => 'run-1',
		];
	}//end context()

	/**
	 * The node on the cleared place had asked somebody: its task is
	 * terminated with a reason naming the branch, and its slot is cleared so a
	 * later re-entry asks afresh.
	 */
	public function testALosingBranchTakesItsTaskWithIt(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'lo')->set(key: 'taskUuid', value: 't-lo');
		$state->forNode(nodeId: 'elsewhere')->set(key: 'taskUuid', value: 't-else');

		$this->tasks->method('get')->with('t-lo')->willReturn($this->task(runUuid: 'run-1'));
		$this->tasks->expects($this->once())
			->method('terminateAsMoot')
			->with(
				't-lo',
				$this->callback(function (string $reason): bool {
					$this->assertStringContainsString("'s'", $reason, 'names the deciding transition');
					$this->assertStringContainsString("'lo'", $reason, 'names the node');
					$this->assertStringContainsString('run-1', $reason);

					return true;
				}),
				'flow-run:run-1'
			)
			->willReturn($this->task(runUuid: 'run-1'));

		$count = $this->mootness->placesPruned(context: $this->context($state), places: ['lo'], byTransition: 's');

		$this->assertSame(1, $count);
		$this->assertSame([], $state->read(nodeId: 'lo'), 'the slot is cleared');
		$this->assertSame('t-else', $state->read(nodeId: 'elsewhere')['taskUuid'], 'other nodes are untouched');
	}//end testALosingBranchTakesItsTaskWithIt()

	/**
	 * A join's per-edge place is `node#edge`; pruning it is pruning the node.
	 */
	public function testAJoinPlaceIsRecognisedAsTheNodes(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'lo')->set(key: 'taskUuid', value: 't-lo');
		$this->tasks->method('get')->willReturn($this->task(runUuid: 'run-1'));
		$this->tasks->expects($this->once())->method('terminateAsMoot')->willReturn($this->task(runUuid: 'run-1'));

		$this->mootness->placesPruned(context: $this->context($state), places: ['lo' . FlowGraph::PLACE_JOIN . 'e1'], byTransition: 's');
	}//end testAJoinPlaceIsRecognisedAsTheNodes()

	/**
	 * Propagation SHALL NEVER reach a task that carries no run uuid.
	 */
	public function testARunlessTaskIsNeverTouched(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'lo')->set(key: 'taskUuid', value: 't-lo');
		$this->tasks->method('get')->willReturn($this->task(runUuid: null));
		$this->tasks->expects($this->never())->method('terminateAsMoot');

		$count = $this->mootness->placesPruned(context: $this->context($state), places: ['lo'], byTransition: 's');

		$this->assertSame(0, $count);
		$this->assertSame('t-lo', $state->read(nodeId: 'lo')['taskUuid'], 'the slot is kept when nothing was terminated');
	}//end testARunlessTaskIsNeverTouched()

	/**
	 * A task belonging to a different run than the one walking is stale
	 * evidence, not ours to terminate.
	 */
	public function testAnotherRunsTaskIsNeverTouched(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'lo')->set(key: 'taskUuid', value: 't-lo');
		$this->tasks->method('get')->willReturn($this->task(runUuid: 'run-other'));
		$this->tasks->expects($this->never())->method('terminateAsMoot');

		$this->mootness->placesPruned(context: $this->context($state), places: ['lo'], byTransition: 's');
	}//end testAnotherRunsTaskIsNeverTouched()

	/**
	 * A node whose place was NOT cleared keeps its task.
	 */
	public function testAPlaceThatWasNotClearedIsLeftAlone(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'lo')->set(key: 'taskUuid', value: 't-lo');
		$this->tasks->expects($this->never())->method('terminateAsMoot');

		$count = $this->mootness->placesPruned(context: $this->context($state), places: ['hi'], byTransition: 's');

		$this->assertSame(0, $count);
	}//end testAPlaceThatWasNotClearedIsLeftAlone()

	/**
	 * Task bookkeeping must never fail a hop: a throwing service is logged
	 * and the walk carries on.
	 */
	public function testATaskLayerFailureNeverFailsTheHop(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'lo')->set(key: 'taskUuid', value: 't-lo');
		$this->tasks->method('get')->willThrowException(new RuntimeException('database away'));

		$count = $this->mootness->placesPruned(context: $this->context($state), places: ['lo'], byTransition: 's');

		$this->assertSame(0, $count);
	}//end testATaskLayerFailureNeverFailsTheHop()

	public function testWithoutAResumeStateNothingHappens(): void {
		$this->tasks->expects($this->never())->method('get');

		$this->assertSame(0, $this->mootness->placesPruned(context: [], places: ['lo'], byTransition: 's'));
	}//end testWithoutAResumeStateNothingHappens()
}//end class
