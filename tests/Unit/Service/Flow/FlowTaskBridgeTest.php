<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowStream;
use OCA\OpenRegister\Db\FlowStreamMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunAdvancer;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The bridge: provenance on creation, and the advance budget on completion.
 *
 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-the-advance-budget-says-how-far-a-completion-may-push-the-run
 */
class FlowTaskBridgeTest extends TestCase {

	private TaskService&MockObject $tasks;

	private FlowRunMapper&MockObject $runs;

	private FlowRunService&MockObject $runService;

	private FlowRunAdvancer&MockObject $advancer;

	private FlowStreamMapper&MockObject $streams;

	private FlowTaskBridge $bridge;

	protected function setUp(): void {
		$this->tasks = $this->createMock(TaskService::class);
		$this->runs = $this->createMock(FlowRunMapper::class);
		$this->runService = $this->createMock(FlowRunService::class);
		$this->advancer = $this->createMock(FlowRunAdvancer::class);
		$this->streams = $this->createMock(FlowStreamMapper::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): object {
				return match ($id) {
					FlowRunService::class => $this->runService,
					FlowRunAdvancer::class => $this->advancer,
					FlowStreamMapper::class => $this->streams,
					default => throw new RuntimeException('unexpected service ' . $id),
				};
			}
		);

		$this->bridge = new FlowTaskBridge($this->tasks, $this->runs, $container, new NullLogger());
	}//end setUp()

	/**
	 * A suspended run whose node slot holds the given budget.
	 *
	 * @param mixed $advance The stored budget, or null for a slot without one.
	 *
	 * @return FlowRun The run.
	 */
	private function suspendedRun(mixed $advance): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setStatus(FlowRun::STATUS_SUSPENDED);
		$run->setFlowVersion(3);
		$slot = ['taskUuid' => 't-1'];
		if ($advance !== null) {
			$slot[FlowTaskBridge::SLOT_ADVANCE] = $advance;
		}

		$run->setContext([FlowResumeState::CONTEXT_KEY => ['ask' => $slot]]);

		return $run;
	}//end suspendedRun()

	/**
	 * A live stream row standing on the given place.
	 */
	private function stream(string $id, string $place, string $status = FlowRun::STATUS_SUSPENDED): FlowStream {
		$stream = new FlowStream();
		$stream->setRunUuid('run-1');
		$stream->setStreamId($id);
		$stream->setPlace($place);
		$stream->setStatus($status);

		return $stream;
	}//end stream()

	private function terminalTask(?string $runUuid = 'run-1'): Task {
		$task = new Task();
		$task->setUuid('t-1');
		$task->setRunUuid($runUuid);
		$task->setNodeId('ask');
		$task->setState(Task::STATE_COMPLETED);
		$task->setIsTerminal(true);
		$task->setOutcome('approved');

		return $task;
	}//end terminalTask()

	/**
	 * Make signal() behave: park the run as due and hand it back.
	 */
	private function signalParks(): void {
		$this->runService->method('signal')->willReturnCallback(
			static function (FlowRun $run): FlowRun {
				$run->setResumeAt(new DateTime());

				return $run;
			}
		);
	}//end signalParks()

	// ---- Creation ---------------------------------------------------------------

	/**
	 * The task carries the run, the node, the requester and the run's version
	 * pin. That provenance is what propagation and the completion find it by.
	 */
	public function testCreationStampsProvenanceAndTheVersionPin(): void {
		$this->runs->method('findByUuid')->with('run-1')->willReturn($this->suspendedRun(advance: null));

		$this->tasks->expects($this->once())
			->method('import')
			->with(
				$this->callback(function (array $data): bool {
					$this->assertSame('run-1', $data['runUuid']);
					$this->assertSame('ask', $data['nodeId']);
					$this->assertSame('owner', $data['requester']);
					$this->assertSame(3, $data['definitionVersion']);

					return true;
				}),
				'owner'
			)
			->willReturn($this->terminalTask());
		$this->tasks->expects($this->never())->method('offer');

		$this->bridge->createTask(data: ['title' => 'x', 'assignee' => 'alice'], runUuid: 'run-1', nodeId: 'ask', actor: 'owner');
	}//end testCreationStampsProvenanceAndTheVersionPin()

	/**
	 * A routing strategy with no direct assignee is resolved by OFFERING the
	 * task after creation, so the strategy runs now rather than at first claim.
	 */
	public function testARoutingStrategyWithoutAnAssigneeOffersTheTask(): void {
		$this->runs->method('findByUuid')->willReturn($this->suspendedRun(advance: null));
		$this->tasks->method('import')->willReturn($this->terminalTask());
		$this->tasks->expects($this->once())
			->method('offer')
			->with('t-1', ['routingStrategy' => 'or-set', 'routingFallback' => 'carol'], 'owner')
			->willReturn($this->terminalTask());

		$this->bridge->createTask(
			data: ['title' => 'x', 'candidateGroups' => ['finance'], 'routingStrategy' => 'or-set', 'routingFallback' => 'carol'],
			runUuid: 'run-1',
			nodeId: 'ask',
			actor: 'owner'
		);
	}//end testARoutingStrategyWithoutAnAssigneeOffersTheTask()

	public function testAVanishedTaskReadsAsNull(): void {
		$this->tasks->method('get')->willThrowException(new DoesNotExistException('gone'));

		$this->assertNull($this->bridge->taskOrNull(uuid: 't-gone'));
	}//end testAVanishedTaskReadsAsNull()

	// ---- Continuation and the budget ------------------------------------------

	/**
	 * The default budget: the completion parks the run as due and returns.
	 * The advancer is never even resolved.
	 */
	public function testTheDefaultBudgetParksForTheWorker(): void {
		$run = $this->suspendedRun(advance: null);
		$this->runs->method('findByUuid')->willReturn($run);
		$this->signalParks();
		$this->advancer->expects($this->never())->method('advanceStream');

		$after = $this->bridge->continueRun(task: $this->terminalTask());

		$this->assertSame($run, $after);
		$this->assertSame(FlowRun::STATUS_SUSPENDED, $after->getStatus());
		$this->assertNotNull($after->getResumeAt(), 'the run must be due for the worker');
	}//end testTheDefaultBudgetParksForTheWorker()

	/**
	 * A budget of N continues THE STREAM parked on the node, for N + 1
	 * firings (the node's own re-entry is the completion landing), through
	 * the one stream-scoped advance path.
	 */
	public function testABudgetOfNAdvancesTheParkedStreamInRequest(): void {
		$run = $this->suspendedRun(advance: 3);
		$this->runs->method('findByUuid')->willReturn($run);
		$this->signalParks();
		$this->streams->method('findByRun')->with('run-1')->willReturn([
			$this->stream('s-root', 'elsewhere'),
			$this->stream('s-ask', 'ask'),
		]);
		$this->advancer->expects($this->once())
			->method('advanceStream')
			->with($run, 's-ask', 4)
			->willReturn($run);

		$this->bridge->continueRun(task: $this->terminalTask());
	}//end testABudgetOfNAdvancesTheParkedStreamInRequest()

	/**
	 * "all" is passed through as "all": the stream walk's natural stopping
	 * points (a suspension, another user task, an end) bound it.
	 */
	public function testABudgetOfAllIsPassedThroughToTheStream(): void {
		$run = $this->suspendedRun(advance: 'all');
		$this->runs->method('findByUuid')->willReturn($run);
		$this->signalParks();
		$this->streams->method('findByRun')->willReturn([$this->stream('s-ask', 'ask')]);
		$this->advancer->expects($this->once())
			->method('advanceStream')
			->with($run, 's-ask', 'all')
			->willReturn($run);

		$this->bridge->continueRun(task: $this->terminalTask());
	}//end testABudgetOfAllIsPassedThroughToTheStream()

	/**
	 * A terminal stream is not a branch to continue, and a run whose streams
	 * predate the node has nothing standing on it: both leave the run due
	 * for the worker rather than guessing a stream.
	 */
	public function testWithoutALiveStreamOnTheNodeTheRunIsLeftDueForTheWorker(): void {
		$run = $this->suspendedRun(advance: 'all');
		$this->runs->method('findByUuid')->willReturn($run);
		$this->signalParks();
		$this->streams->method('findByRun')->willReturn([$this->stream('s-ask', 'ask', FlowRun::STATUS_COMPLETED)]);
		$this->advancer->expects($this->never())->method('advanceStream');

		$after = $this->bridge->continueRun(task: $this->terminalTask());

		$this->assertNotNull($after);
		$this->assertNotNull($after->getResumeAt());
	}//end testWithoutALiveStreamOnTheNodeTheRunIsLeftDueForTheWorker()

	/**
	 * Design D-5: the budget is an optimisation, so its failure mode is the
	 * unoptimised behaviour. The task is committed already; the run stays due.
	 */
	public function testAFailedContinuationLeavesTheRunDueAndDoesNotThrow(): void {
		$run = $this->suspendedRun(advance: 'all');
		$this->runs->method('findByUuid')->willReturn($run);
		$this->signalParks();
		$this->streams->method('findByRun')->willReturn([$this->stream('s-ask', 'ask')]);
		$this->advancer->method('advanceStream')->willThrowException(new RuntimeException('downstream step blew up'));

		$after = $this->bridge->continueRun(task: $this->terminalTask());

		$this->assertNotNull($after);
		$this->assertSame(FlowRun::STATUS_SUSPENDED, $after->getStatus());
		$this->assertNotNull($after->getResumeAt(), 'still due: the worker takes over');
	}//end testAFailedContinuationLeavesTheRunDueAndDoesNotThrow()

	/**
	 * A stored budget that does not read is the default, never "unlimited":
	 * parking is the safe direction.
	 */
	public function testAnUnreadableStoredBudgetParksForTheWorker(): void {
		$run = $this->suspendedRun(advance: 'whenever');
		$this->runs->method('findByUuid')->willReturn($run);
		$this->signalParks();
		$this->advancer->expects($this->never())->method('advanceStream');

		$this->bridge->continueRun(task: $this->terminalTask());
	}//end testAnUnreadableStoredBudgetParksForTheWorker()

	/**
	 * A run that is not suspended (mid-walk, or terminal) cannot be woken; the
	 * walk itself reads the task, or nothing is owed.
	 */
	public function testARunThatIsNotSuspendedIsLeftAlone(): void {
		$run = $this->suspendedRun(advance: 'all');
		$run->setStatus(FlowRun::STATUS_RUNNING);
		$this->runs->method('findByUuid')->willReturn($run);
		$this->runService->method('signal')->willReturn(null);
		$this->advancer->expects($this->never())->method('advanceStream');

		$this->assertNull($this->bridge->continueRun(task: $this->terminalTask()));
	}//end testARunThatIsNotSuspendedIsLeftAlone()

	public function testATaskWithoutARunIsNobodysBusinessHere(): void {
		$this->runs->expects($this->never())->method('findByUuid');

		$this->assertNull($this->bridge->continueRun(task: $this->terminalTask(runUuid: null)));
	}//end testATaskWithoutARunIsNobodysBusinessHere()

	public function testAMissingRunIsLoggedNotThrown(): void {
		$this->runs->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));

		$this->assertNull($this->bridge->continueRun(task: $this->terminalTask()));
	}//end testAMissingRunIsLoggedNotThrown()

	// ---- The outcome bag ----------------------------------------------------------

	public function testTheBagSeparatesADecisionFromAnEnding(): void {
		$decided = $this->terminalTask();
		$decided->setOutcome('rejected');
		$decided->setComment('no');
		$bag = FlowTaskBridge::outcomeBagFor(task: $decided);
		$this->assertTrue($bag['decided']);
		$this->assertTrue($bag['rejected']);

		$ended = $this->terminalTask();
		$ended->setState(Task::STATE_TERMINATED);
		$ended->setOutcome(null);
		$bag = FlowTaskBridge::outcomeBagFor(task: $ended);
		$this->assertFalse($bag['decided']);
		$this->assertFalse($bag['rejected']);
		$this->assertSame(Task::STATE_TERMINATED, $bag['outcome'], 'an ending with no outcome reports its state');
	}//end testTheBagSeparatesADecisionFromAnEnding()

	// ---- Heartbeat recovery -------------------------------------------------------

	/**
	 * 🔴 THE OTHER HALF OF THE REFUSAL TRAIL. The guarded signal seam records
	 * that a completion was refused; without this entry the trail ends there
	 * and a recovered answer reads as one that vanished. Attributed to the
	 * task's COMPLETER, because the fact being recorded is that person's
	 * answer arriving late by poll — not the cron job acting.
	 *
	 * Driven through the REAL bridge. Every other test of this behaviour mocks
	 * FlowTaskBridge (the nodes are the unit there), so this method's body had
	 * no execution coverage at all until this test.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-heartbeat-recovery/specs/flow-heartbeat-recovery/spec.md#requirement-a-heartbeat-recovered-delivery-is-recorded-on-the-tasks-audit
	 */
	public function testAHeartbeatRecoveryIsAuditedToTheTasksCompleter(): void {
		$task = $this->terminalTask();
		$task->setCompletedBy('bob');

		$seen = [];
		$this->tasks->expects($this->once())
			->method('record')
			->willReturnCallback(
				function (string $uuid, string $action, ?string $actor, string $reason) use (&$seen, $task): Task {
					$seen = ['uuid' => $uuid, 'action' => $action, 'actor' => $actor, 'reason' => $reason];

					return $task;
				}
			);

		$this->bridge->recordHeartbeatRecovery(task: $task);

		$this->assertSame('t-1', $seen['uuid']);
		$this->assertSame('heartbeat-recovered', $seen['action']);
		$this->assertSame('bob', $seen['actor'], 'the recovery is the completer\'s answer arriving, not the worker\'s');
		$this->assertStringContainsString('run-1', $seen['reason'], 'the reason names the run whose signal never arrived');
	}//end testAHeartbeatRecoveryIsAuditedToTheTasksCompleter()

	/**
	 * 🔴 BEST-EFFORT, AND THAT IS THE POINT. The recovery itself is the node
	 * applying the outcome; this entry only describes it. An audit write that
	 * fails must therefore NOT propagate — letting it out would abort the walk
	 * that was recovering the run and put the run straight back into the wedge
	 * this whole change exists to remove.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-heartbeat-recovery/specs/flow-heartbeat-recovery/spec.md#requirement-a-heartbeat-recovered-delivery-is-recorded-on-the-tasks-audit
	 */
	public function testAFailedRecoveryAuditIsSwallowedSoTheRecoveredRunStands(): void {
		$task = $this->terminalTask();
		$task->setCompletedBy('bob');

		$this->tasks->expects($this->once())
			->method('record')
			->willThrowException(new RuntimeException('the audit table is unavailable'));

		$this->bridge->recordHeartbeatRecovery(task: $task);

		// Reached only because nothing propagated: the recovery outlives its
		// own audit failure.
		$this->addToAssertionCount(1);
	}//end testAFailedRecoveryAuditIsSwallowedSoTheRecoveredRunStands()

	/**
	 * A task that ended WITHOUT a completer — terminated or expired rather than
	 * answered — still records its recovery, with no actor rather than an
	 * invented one. `completedBy` is null on exactly those endings, and an
	 * audit that guessed a name there would be worse than one that admits it
	 * has none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-heartbeat-recovery/specs/flow-heartbeat-recovery/spec.md#requirement-a-heartbeat-recovered-delivery-is-recorded-on-the-tasks-audit
	 */
	public function testARecoveredEndingWithNoCompleterRecordsNoActor(): void {
		$task = $this->terminalTask();
		$task->setState(Task::STATE_TERMINATED);
		$task->setCompletedBy(null);

		$actor = 'unset';
		$this->tasks->expects($this->once())
			->method('record')
			->willReturnCallback(
				function (string $uuid, string $action, ?string $seenActor, string $reason) use (&$actor, $task): Task {
					$actor = $seenActor;

					return $task;
				}
			);

		$this->bridge->recordHeartbeatRecovery(task: $task);

		$this->assertNull($actor, 'an ending nobody answered is recorded with no actor, never a guessed one');
	}//end testARecoveredEndingWithNoCompleterRecordsNoActor()

}//end class
