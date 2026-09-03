<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowStop;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskNode;
use OCA\OpenRegister\Service\Task\TaskForm;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/**
 * The user-task node: one task per node per run, resume on terminality,
 * outcome onto every item, rejection as a branch.
 *
 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md
 */
class UserTaskNodeTest extends TestCase {

	private FlowTaskBridge&MockObject $bridge;

	/**
	 * The timer service the node arms through.
	 *
	 * @var FlowTimerService&MockObject
	 */
	private $timers;

	private UserTaskNode $node;

	protected function setUp(): void {
		$this->bridge = $this->createMock(FlowTaskBridge::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				if ($parameters === []) {
					return $text;
				}

				return vsprintf($text, $parameters);
			}
		);

		// A reader that accepts every declaration: the form contract has its
		// own suite, this one is about the node's lifecycle.
		$forms = $this->createMock(TaskFormReader::class);
		$forms->method('fromConfig')->willReturn(new TaskForm(kind: null));

		$this->timers = $this->createMock(FlowTimerService::class);

		$this->node = new UserTaskNode(
			$this->bridge,
			$l10n,
			$this->createMock(IURLGenerator::class),
			$forms,
			$this->timers
		);
	}//end setUp()

	/**
	 * A minimal valid configuration.
	 *
	 * @param array<string, mixed> $overrides Keys to add or replace.
	 *
	 * @return array<string, mixed> The config.
	 */
	private function config(array $overrides = []): array {
		return array_merge(['title' => 'Approve {{ name }}', 'assignee' => 'alice'], $overrides);
	}//end config()

	/**
	 * A run context with this node scoped to its own slot.
	 *
	 * @param FlowResumeState $state The run's resume state.
	 * @param string $nodeId The node being dispatched.
	 * @param array<string, mixed> $extra Extra context keys.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function context(FlowResumeState $state, string $nodeId = 'ask', array $extra = []): array {
		return array_merge(
			[
				FlowResumeState::CONTEXT_KEY => $state,
				FlowNodeResumeState::CONTEXT_KEY => $state->forNode(nodeId: $nodeId),
				FlowRunContext::CONTEXT_RUN => 'run-1',
				'runUuid' => 'run-1',
				'runAs' => 'owner',
			],
			$extra
		);
	}//end context()

	private function items(): array {
		return [FlowItems::item(json: ['name' => 'Case 7', '@self' => ['uuid' => 'obj-7', 'register' => 3, 'schema' => 9]])];
	}//end items()

	private function task(string $state, string $uuid = 't-1'): Task {
		$task = new Task();
		$task->setUuid($uuid);
		$task->setState($state);
		$task->setIsTerminal(in_array($state, Task::TERMINAL_STATES, true));
		$task->setPerformerType(Task::PERFORMER_USER);
		$task->setAssignee('alice');
		$task->setRunUuid('run-1');
		$task->setNodeId('ask');

		return $task;
	}//end task()

	// ---- Creation and suspension ------------------------------------------

	/**
	 * 🔴 A NODE THAT DECLARES AN SLA ARMS A TIMER FOR ITS TASK.
	 *
	 * Nothing in the engine did this before: `FlowTimerService::arm()` had no
	 * production caller at all, so a node could describe an SLA and no clock
	 * ever started. The whole business-timer capability was built, tested and
	 * unreachable.
	 *
	 * The binding is what matters. `FlowTimerSubjectTerminalListener` cancels
	 * on `(subjectType: 'task', subjectUuid)`, so arming on anything else would
	 * leave a timer nothing ever cancels — an orphan the spec calls a defect
	 * rather than a tolerable condition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function testADeclaredSlaArmsATimerBoundToTheTask(): void {
		$state = new FlowResumeState();
		$this->bridge->method('createTask')->willReturn($this->task(state: Task::STATE_ACTIVE));

		$armed = null;
		$this->timers->expects($this->once())
			->method('arm')
			->willReturnCallback(
				function (array $config, ?string $actor = null, $now = null) use (&$armed) {
					$armed = $config;

					return $this->createMock(FlowTimer::class);
				}
			);

		try {
			$this->node->execute(
				$this->items(),
				$this->config(['sla' => ['value' => 5, 'unit' => 'businessDays'], 'ladder' => 'awb-standard']),
				$this->context($state)
			);
		} catch (FlowSuspension $suspension) {
			// Suspension is the normal outcome; the arming is the subject here.
		}

		$this->assertIsArray($armed, 'a declared SLA must arm a timer');
		$this->assertSame('task', $armed['subjectType']);
		$this->assertSame('t-1', $armed['subjectUuid'], 'the timer must bind to the task the listener cancels');
		$this->assertSame(['value' => 5, 'unit' => 'businessDays'], $armed['sla']);
		$this->assertSame('awb-standard', $armed['ladder'], 'the escalation ladder must reach the timer');
		$this->assertSame('run-1', $armed['runUuid'], 'the run is recorded as provenance');
		$this->assertSame('ask', $armed['nodeId']);
	}//end testADeclaredSlaArmsATimerBoundToTheTask()


	/**
	 * A node may carry a deadline without carrying a title.
	 *
	 * `title` is optional on a user task, and an empty one must reach the timer
	 * as null rather than as an empty string: the timer renders it in the
	 * escalation notice, where '' produces a blank subject line while null lets
	 * the timer fall back to describing its subject.
	 *
	 * This case is also the only one that executes the `$title = null` branch.
	 * Every other test in this class builds its config through the helper,
	 * which always supplies a title, so without this test that branch is dead
	 * on the coverage report and nothing would notice if it stopped working.
	 *
	 * @return void
	 */
	public function testATitlelessNodeArmsItsTimerWithNoTitle(): void {
		$state = new FlowResumeState();
		$this->bridge->method('createTask')->willReturn($this->task(state: Task::STATE_ACTIVE));

		$armed = null;
		$this->timers->expects($this->once())
			->method('arm')
			->willReturnCallback(
				function (array $config, ?string $actor = null, $now = null) use (&$armed) {
					$armed = $config;

					return $this->createMock(FlowTimer::class);
				}
			);

		try {
			$this->node->execute(
				$this->items(),
				// Whitespace, not a missing key: a title of spaces is still no
				// title, and trim() is what makes the two the same.
				['title' => '   ', 'assignee' => 'alice', 'sla' => ['value' => 2, 'unit' => 'businessDays']],
				$this->context($state)
			);
		} catch (FlowSuspension $suspension) {
			// Suspension is the normal outcome; the arming is the subject here.
		}

		$this->assertIsArray($armed, 'a declared SLA must arm a timer even with no title');
		$this->assertNull($armed['title'], 'an empty title must reach the timer as null, not as an empty string');
		$this->assertSame('task', $armed['subjectType']);
		$this->assertSame(['value' => 2, 'unit' => 'businessDays'], $armed['sla']);
	}//end testATitlelessNodeArmsItsTimerWithNoTitle()

	/**
	 * A node WITHOUT an SLA arms nothing.
	 *
	 * This is what makes the change safe to add to an engine every app shares:
	 * a flow that never mentioned a deadline behaves exactly as it did.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md
	 */
	public function testANodeWithoutAnSlaArmsNothing(): void {
		$state = new FlowResumeState();
		$this->bridge->method('createTask')->willReturn($this->task(state: Task::STATE_ACTIVE));

		$this->timers->expects($this->never())->method('arm');

		try {
			$this->node->execute($this->items(), $this->config(), $this->context($state));
		} catch (FlowSuspension $suspension) {
			// Expected.
		}
	}//end testANodeWithoutAnSlaArmsNothing()

	/**
	 * An SLA that cannot be armed FAILS the node rather than losing the clock.
	 *
	 * A task with a declared deadline and no timer is a task whose term nobody
	 * is measuring. For a `wettelijk` term that is a legal defect, not
	 * something to log and carry on from — and it cannot regress an existing
	 * flow, because a flow with no SLA never reaches this branch.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md
	 */
	public function testAnUnarmableSlaFailsRatherThanSilentlyLosingTheDeadline(): void {
		$this->bridge->method('createTask')->willReturn($this->task(state: Task::STATE_ACTIVE));
		$this->timers->method('arm')->willThrowException(new RuntimeException('calendar unresolvable'));

		$this->expectException(RuntimeException::class);

		$this->node->execute(
			$this->items(),
			$this->config(['sla' => ['value' => 5, 'unit' => 'businessDays']]),
			$this->context(new FlowResumeState())
		);
	}//end testAnUnarmableSlaFailsRatherThanSilentlyLosingTheDeadline()

	/**
	 * The first firing creates exactly one task, stamped with run and node,
	 * and suspends with a heartbeat that is NOT null.
	 */
	public function testTheFirstFiringCreatesOneTaskAndSuspendsWithAHeartbeat(): void {
		$state = new FlowResumeState();
		$created = $this->task(state: Task::STATE_ACTIVE);

		$this->bridge->expects($this->once())
			->method('createTask')
			->with(
				$this->callback(function (array $data): bool {
					$this->assertSame('Approve Case 7', $data['title'], 'the title is templated against the item');
					$this->assertSame('alice', $data['assignee']);
					$this->assertSame(Task::STATE_ACTIVE, $data['state'], 'a directly assigned task is created active');
					$this->assertSame('obj-7', $data['objectUuid']);
					$this->assertSame(3, $data['registerId']);
					$this->assertSame('task', $data['metadata']['outcomeKey']);

					return true;
				}),
				'run-1',
				'ask',
				'owner'
			)
			->willReturn($created);

		try {
			$this->node->execute($this->items(), $this->config(), $this->context($state));
			$this->fail('Expected the node to suspend.');
		} catch (FlowSuspension $suspension) {
			$this->assertNotNull($suspension->getResumeAt(), 'a null resumeAt is the one shape the 14-day reaper fails');
			$this->assertGreaterThan(new DateTime(), $suspension->getResumeAt());
			$this->assertStringContainsString('Approve Case 7', $suspension->getMessage());
		}

		$slot = $state->read(nodeId: 'ask');
		$this->assertSame('t-1', $slot[FlowTaskBridge::SLOT_TASK_UUID]);
		$this->assertArrayHasKey(FlowTaskBridge::SLOT_ASKED_AT, $slot);
		$this->assertSame(0, $slot[FlowTaskBridge::SLOT_ADVANCE], 'the default budget is stored as 0');
		$this->assertSame('alice', $slot['assignee']);
	}//end testTheFirstFiringCreatesOneTaskAndSuspendsWithAHeartbeat()

	/**
	 * A firing with no items creates nothing and does not suspend.
	 */
	public function testAnEmptyBranchCreatesNothingAndDoesNotSuspend(): void {
		$this->bridge->expects($this->never())->method('createTask');

		$out = $this->node->execute([], $this->config(), $this->context(new FlowResumeState()));

		$this->assertSame([], $out);
	}//end testAnEmptyBranchCreatesNothingAndDoesNotSuspend()

	/**
	 * The heartbeat re-enters the node with the task still open. It must NOT
	 * create a second task, and it must NOT restamp askedAt.
	 */
	public function testAHeartbeatWakeCreatesNoSecondTaskAndKeepsAskedAt(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'ask')->merge(
			values: [
				FlowTaskBridge::SLOT_TASK_UUID => 't-1',
				FlowTaskBridge::SLOT_ASKED_AT => '2026-01-01T00:00:00+00:00',
			]
		);

		$this->bridge->expects($this->never())->method('createTask');
		$this->bridge->method('taskOrNull')->with('t-1')->willReturn($this->task(state: Task::STATE_ENABLED));

		try {
			$this->node->execute($this->items(), $this->config(), $this->context($state));
			$this->fail('Expected the node to suspend again.');
		} catch (FlowSuspension $suspension) {
			$this->assertNotNull($suspension->getResumeAt());
		}

		$this->assertSame(
			'2026-01-01T00:00:00+00:00',
			$state->read(nodeId: 'ask')[FlowTaskBridge::SLOT_ASKED_AT],
			'a heartbeat that restamps askedAt makes every long wait read as minutes old'
		);
	}//end testAHeartbeatWakeCreatesNoSecondTaskAndKeepsAskedAt()

	/**
	 * A claim moves the task to active with an assignee. It is not an answer.
	 */
	public function testAClaimIsNotACompletion(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'ask')->set(key: FlowTaskBridge::SLOT_TASK_UUID, value: 't-1');
		$claimed = $this->task(state: Task::STATE_ACTIVE);
		$claimed->setAssignee('bob');
		$claimed->setLastAction('claim');
		$this->bridge->method('taskOrNull')->willReturn($claimed);

		$this->expectException(FlowSuspension::class);

		$this->node->execute($this->items(), $this->config(), $this->context($state));
	}//end testAClaimIsNotACompletion()

	/**
	 * The resume endpoint writes a decision into context.signal. This node
	 * MUST NOT read it: the task is the only source of an answer.
	 */
	public function testASignalWithADecisionDoesNotAnswerForThePerformer(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'ask')->set(key: FlowTaskBridge::SLOT_TASK_UUID, value: 't-1');
		$this->bridge->method('taskOrNull')->willReturn($this->task(state: Task::STATE_ACTIVE));

		$this->expectException(FlowSuspension::class);

		$this->node->execute(
			$this->items(),
			$this->config(),
			$this->context($state, extra: [FlowRunService::SIGNAL_CONTEXT_KEY => ['decision' => 'approve', 'by' => 'stranger']])
		);
	}//end testASignalWithADecisionDoesNotAnswerForThePerformer()

	/**
	 * Without a resume slot there is nowhere to record the task, so every
	 * heartbeat would create one. Refusing is the safe direction.
	 */
	public function testANodeWithoutAResumeSlotRefusesToRun(): void {
		$this->bridge->expects($this->never())->method('createTask');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/resume slot/');

		$this->node->execute($this->items(), $this->config(), ['runUuid' => 'run-1']);
	}//end testANodeWithoutAResumeSlotRefusesToRun()

	/**
	 * A task row that vanished is neither an answer nor something to wait on.
	 */
	public function testAVanishedTaskFailsTheStepRatherThanInventingAnAnswer(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'ask')->set(key: FlowTaskBridge::SLOT_TASK_UUID, value: 't-gone');
		$this->bridge->method('taskOrNull')->willReturn(null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/t-gone/');

		$this->node->execute($this->items(), $this->config(), $this->context($state));
	}//end testAVanishedTaskFailsTheStepRatherThanInventingAnAnswer()

	// ---- Continuation and outcome placement --------------------------------

	/**
	 * A completed task lets the items through, each carrying the outcome bag
	 * under json.task so a Switch can branch on json.task.outcome.
	 */
	public function testACompletedTaskContinuesWithTheOutcomeOnEveryItem(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'ask')->set(key: FlowTaskBridge::SLOT_TASK_UUID, value: 't-1');
		$done = $this->task(state: Task::STATE_COMPLETED);
		$done->setOutcome('approved');
		$done->setComment('Looks fine');
		$done->setCompletedBy('alice');
		$this->bridge->method('taskOrNull')->willReturn($done);

		$out = $this->node->execute(
			[FlowItems::item(json: ['id' => 1]), FlowItems::item(json: ['id' => 2])],
			$this->config(),
			$this->context($state)
		);

		$this->assertCount(2, $out);
		foreach ($out as $item) {
			$bag = $item[FlowItems::JSON]['task'];
			$this->assertSame('approved', $bag['outcome']);
			$this->assertTrue($bag['decided']);
			$this->assertFalse($bag['rejected']);
			$this->assertSame('Looks fine', $bag['comment']);
			$this->assertSame('alice', $bag['completedBy']);
			$this->assertSame(Task::PERFORMER_USER, $bag['performerType']);
			$this->assertNull($bag['onBehalfOf']);
		}
	}//end testACompletedTaskContinuesWithTheOutcomeOnEveryItem()

	/**
	 * A terminal read with no signal in hand is the heartbeat recovering a
	 * missed wake — the completion's signal was refused or lost — and that
	 * recovery is recorded on the task's audit, attributed to its completer.
	 *
	 * @spec openspec/changes/flow-heartbeat-recovery/specs/flow-heartbeat-recovery/spec.md#requirement-a-heartbeat-recovered-delivery-is-recorded-on-the-tasks-audit
	 */
	public function testAHeartbeatRecoveredCompletionIsAuditedOnTheTask(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'ask')->set(key: FlowTaskBridge::SLOT_TASK_UUID, value: 't-1');
		$done = $this->task(state: Task::STATE_COMPLETED);
		$done->setOutcome('approved');
		$done->setCompletedBy('bob');
		$this->bridge->method('taskOrNull')->willReturn($done);
		$this->bridge->expects($this->once())->method('recordHeartbeatRecovery')->with($done);

		$out = $this->node->execute($this->items(), $this->config(), $this->context($state));

		$this->assertSame('approved', $out[0][FlowItems::JSON]['task']['outcome'], 'the recovery applies the outcome exactly as the signal path would');
	}//end testAHeartbeatRecoveredCompletionIsAuditedOnTheTask()

	/**
	 * A completion whose signal DID arrive is the ordinary path, not a
	 * recovery: nothing extra lands on the task's audit.
	 *
	 * @spec openspec/changes/flow-heartbeat-recovery/specs/flow-heartbeat-recovery/spec.md#requirement-a-heartbeat-recovered-delivery-is-recorded-on-the-tasks-audit
	 */
	public function testASignalDeliveredCompletionRecordsNoHeartbeatRecovery(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'ask')->set(key: FlowTaskBridge::SLOT_TASK_UUID, value: 't-1');
		$done = $this->task(state: Task::STATE_COMPLETED);
		$done->setOutcome('approved');
		$this->bridge->method('taskOrNull')->willReturn($done);
		$this->bridge->expects($this->never())->method('recordHeartbeatRecovery');

		$out = $this->node->execute(
			$this->items(),
			$this->config(),
			$this->context($state, extra: [FlowRunService::SIGNAL_CONTEXT_KEY => []])
		);

		$this->assertSame('approved', $out[0][FlowItems::JSON]['task']['outcome']);
	}//end testASignalDeliveredCompletionRecordsNoHeartbeatRecovery()

	/**
	 * A delegated completion names both identities: the deputy who acted and
	 * the person they acted for. A four-eyes rule cannot be enforced without
	 * the difference.
	 */
	public function testADelegatedCompletionNamesBothIdentities(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'ask')->set(key: FlowTaskBridge::SLOT_TASK_UUID, value: 't-1');
		$done = $this->task(state: Task::STATE_COMPLETED);
		$done->setOutcome('approved');
		$done->setAssignee('deputy');
		$done->setOnBehalfOf('manager');
		$done->setMandate('holiday cover');
		$done->setCompletedBy('deputy');
		$this->bridge->method('taskOrNull')->willReturn($done);

		$out = $this->node->execute($this->items(), $this->config(), $this->context($state));

		$bag = $out[0][FlowItems::JSON]['task'];
		$this->assertSame('deputy', $bag['completedBy']);
		$this->assertSame('manager', $bag['onBehalfOf']);
		$this->assertSame('holiday cover', $bag['mandate']);
	}//end testADelegatedCompletionNamesBothIdentities()

	/**
	 * Where the outcome lands is configurable, and a non-array item is left
	 * alone rather than failing the run.
	 */
	public function testTheOutcomeKeyIsConfigurableAndNonArrayItemsAreSkipped(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'ask')->set(key: FlowTaskBridge::SLOT_TASK_UUID, value: 't-1');
		$done = $this->task(state: Task::STATE_COMPLETED);
		$done->setOutcome('done');
		$this->bridge->method('taskOrNull')->willReturn($done);

		$out = $this->node->execute(
			[FlowItems::item(json: ['id' => 1]), 'not an item'],
			$this->config(['outcomeKey' => 'legalReview']),
			$this->context($state)
		);

		$this->assertSame('done', $out[0][FlowItems::JSON]['legalReview']['outcome']);
		$this->assertArrayNotHasKey('task', $out[0][FlowItems::JSON]);
		$this->assertSame('not an item', $out[1]);
	}//end testTheOutcomeKeyIsConfigurableAndNonArrayItemsAreSkipped()

	// ---- Rejection is a branch ----------------------------------------------

	/**
	 * By default a rejection continues, marked as rejected, so the author
	 * routes on it. The run is not failed.
	 */
	public function testARejectionCarriesOnByDefault(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'ask')->set(key: FlowTaskBridge::SLOT_TASK_UUID, value: 't-1');
		$done = $this->task(state: Task::STATE_COMPLETED);
		$done->setOutcome('rejected');
		$done->setComment('Missing signature');
		$this->bridge->method('taskOrNull')->willReturn($done);

		$out = $this->node->execute($this->items(), $this->config(), $this->context($state));

		$this->assertTrue($out[0][FlowItems::JSON]['task']['rejected']);
		$this->assertTrue($out[0][FlowItems::JSON]['task']['decided']);
	}//end testARejectionCarriesOnByDefault()

	/**
	 * Opting in turns a rejection into a deliberate stop naming the reason.
	 */
	public function testAFlowThatOptsInStopsOnARejection(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'ask')->set(key: FlowTaskBridge::SLOT_TASK_UUID, value: 't-1');
		$done = $this->task(state: Task::STATE_COMPLETED);
		$done->setOutcome('rejected');
		$done->setComment('Missing signature');
		$this->bridge->method('taskOrNull')->willReturn($done);

		try {
			$this->node->execute($this->items(), $this->config(['failOnReject' => true]), $this->context($state));
			$this->fail('Expected a FlowStop.');
		} catch (FlowStop $stop) {
			$this->assertTrue($stop->isError());
			$this->assertStringContainsString('Missing signature', $stop->getMessage());
		}
	}//end testAFlowThatOptsInStopsOnARejection()

	/**
	 * A task that was terminated (expiry, cancellation) is terminal but is not
	 * a decision. The bag says so, and it never counts as a rejection, so
	 * failOnReject does not fire on it.
	 */
	public function testATerminatedTaskIsDistinguishableFromARejection(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'ask')->set(key: FlowTaskBridge::SLOT_TASK_UUID, value: 't-1');
		$ended = $this->task(state: Task::STATE_TERMINATED);
		$ended->setOutcome('expired');
		$this->bridge->method('taskOrNull')->willReturn($ended);

		$out = $this->node->execute($this->items(), $this->config(['failOnReject' => true]), $this->context($state));

		$bag = $out[0][FlowItems::JSON]['task'];
		$this->assertFalse($bag['decided']);
		$this->assertFalse($bag['rejected']);
		$this->assertSame('expired', $bag['outcome']);
		$this->assertSame(Task::STATE_TERMINATED, $bag['state']);
	}//end testATerminatedTaskIsDistinguishableFromARejection()

	// ---- Independence of several nodes -------------------------------------

	/**
	 * Two sequential approvals need two answers: completing the first node's
	 * task does not answer the second, which creates a task of its own.
	 */
	public function testTwoNodesInOneFlowKeepIndependentState(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'first')->set(key: FlowTaskBridge::SLOT_TASK_UUID, value: 't-first');
		$firstDone = $this->task(state: Task::STATE_COMPLETED, uuid: 't-first');
		$firstDone->setOutcome('approved');

		$this->bridge->method('taskOrNull')->with('t-first')->willReturn($firstDone);
		$this->bridge->expects($this->once())
			->method('createTask')
			->with($this->anything(), 'run-1', 'second', 'owner')
			->willReturn($this->task(state: Task::STATE_ACTIVE, uuid: 't-second'));

		$afterFirst = $this->node->execute($this->items(), $this->config(), $this->context($state, nodeId: 'first'));
		$this->assertSame('approved', $afterFirst[0][FlowItems::JSON]['task']['outcome']);

		try {
			$this->node->execute($afterFirst, $this->config(), $this->context($state, nodeId: 'second'));
			$this->fail('The second node must ask its own question.');
		} catch (FlowSuspension) {
			// Expected.
		}

		$this->assertSame('t-second', $state->read(nodeId: 'second')[FlowTaskBridge::SLOT_TASK_UUID]);
		$this->assertSame('t-first', $state->read(nodeId: 'first')[FlowTaskBridge::SLOT_TASK_UUID], 'one node cannot overwrite another');
	}//end testTwoNodesInOneFlowKeepIndependentState()

	// ---- Heartbeat ------------------------------------------------------------

	public function testAHeartbeatBelowTheCronPeriodIsClamped(): void {
		$this->bridge->method('createTask')->willReturn($this->task(state: Task::STATE_ACTIVE));

		try {
			$this->node->execute($this->items(), $this->config(['heartbeatMinutes' => 1]), $this->context(new FlowResumeState()));
			$this->fail('Expected the node to suspend.');
		} catch (FlowSuspension $suspension) {
			$this->assertGreaterThan((new DateTime())->modify('+4 minutes'), $suspension->getResumeAt());
		}
	}//end testAHeartbeatBelowTheCronPeriodIsClamped()

	/**
	 * The budget the node was saved with is what the completion listener will
	 * read, so it is stored in the slot at creation, normalised.
	 */
	public function testTheAdvanceBudgetIsStoredInTheSlotAtCreation(): void {
		$state = new FlowResumeState();
		$this->bridge->method('createTask')->willReturn($this->task(state: Task::STATE_ACTIVE));

		try {
			$this->node->execute($this->items(), $this->config(['advance' => 'all']), $this->context($state));
		} catch (FlowSuspension) {
			// Expected.
		}

		$this->assertSame('all', $state->read(nodeId: 'ask')[FlowTaskBridge::SLOT_ADVANCE]);
	}//end testTheAdvanceBudgetIsStoredInTheSlotAtCreation()

	// ---- Config validation ----------------------------------------------------

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function acceptedBudgets(): array {
		return ['zero' => [0], 'three' => [3], 'all' => ['all']];
	}//end acceptedBudgets()

	#[DataProvider('acceptedBudgets')]
	public function testAcceptedBudgetsValidate(mixed $advance): void {
		$this->node->validateConfig($this->config(['advance' => $advance]));
		$this->addToAssertionCount(1);
	}//end testAcceptedBudgetsValidate()

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function refusedBudgets(): array {
		return [
			'null' => [null, 'null'],
			'empty string' => ['', "''"],
			'minus one' => [-1, '-1'],
			'unlimited' => ['unlimited', 'unlimited'],
		];
	}//end refusedBudgets()

	#[DataProvider('refusedBudgets')]
	public function testRefusedBudgetsNameTheValue(mixed $advance, string $named): void {
		try {
			$this->node->validateConfig($this->config(['advance' => $advance]));
			$this->fail('Expected the budget to be refused.');
		} catch (UnexpectedValueException $refusal) {
			$this->assertStringContainsString($named, $refusal->getMessage());
		}
	}//end testRefusedBudgetsNameTheValue()

	public function testAConfigWithNoPossiblePerformerIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/No performer can be resolved/');

		$this->node->validateConfig(['title' => 'Approve', 'candidateUsers' => '']);
	}//end testAConfigWithNoPossiblePerformerIsRefused()

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function performerSources(): array {
		return [
			'assignee' => [['assignee' => 'alice']],
			'candidate users as a string' => [['candidateUsers' => 'alice, bob']],
			'candidate groups as a list' => [['candidateGroups' => ['finance']]],
			'a role' => [['candidateRole' => 'reviewer']],
			'a routing fallback' => [['routingFallback' => 'carol']],
		];
	}//end performerSources()

	#[DataProvider('performerSources')]
	public function testAnyPerformerSourceSatisfiesTheCheck(array $source): void {
		$this->node->validateConfig(array_merge(['title' => 'Approve'], $source));
		$this->addToAssertionCount(1);
	}//end testAnyPerformerSourceSatisfiesTheCheck()

	public function testATitlelessTaskIsRefused(): void {
		$this->expectException(UnexpectedValueException::class);

		$this->node->validateConfig(['assignee' => 'alice']);
	}//end testATitlelessTaskIsRefused()

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function outsideVocabulary(): array {
		return [
			'performer type' => ['performerType', 'robot'],
			'priority' => ['priority', 'asap'],
			'routing strategy' => ['routingStrategy', 'random'],
		];
	}//end outsideVocabulary()

	#[DataProvider('outsideVocabulary')]
	public function testAValueOutsideAPublishedVocabularyIsRefusedNamingIt(string $key, string $value): void {
		try {
			$this->node->validateConfig($this->config([$key => $value]));
			$this->fail('Expected the value to be refused.');
		} catch (UnexpectedValueException $refusal) {
			$this->assertStringContainsString($value, $refusal->getMessage());
			$this->assertStringContainsString($key, $refusal->getMessage());
		}
	}//end testAValueOutsideAPublishedVocabularyIsRefusedNamingIt()

	// ---- Palette and form -----------------------------------------------------

	public function testTheNodeIsOfferedInBothScopes(): void {
		$this->assertSame('openregister.user-task', $this->node->getId());
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_ADMIN));
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_USER));
	}//end testTheNodeIsOfferedInBothScopes()

	/**
	 * Every form field writes a key the node reads, and the form covers the
	 * fields the spec names.
	 */
	public function testTheFormCoversTheSpecifiedFieldsAndOnlyDeclaredKeys(): void {
		$keys = $this->node->configKeys();
		$formKeys = array_map(static fn (array $field): string => (string)$field['key'], $this->node->configForm());

		foreach ($formKeys as $key) {
			$this->assertContains($key, $keys, 'a form field over a key the node ignores looks like it works and changes nothing');
		}

		foreach (['title', 'description', 'candidateUsers', 'candidateGroups', 'candidateRole', 'routingStrategy', 'routingFallback', 'priority', 'dueAt', 'expiresAt', 'outcomes', 'outcomeKey', 'failOnReject', 'heartbeatMinutes', 'advance'] as $required) {
			$this->assertContains($required, $formKeys);
		}

		// The form block: kind, subject schema, action or inline list, the
		// external form reference, and the checklist rule (flow-task-forms 2.1).
		foreach (['formKind', 'formSchema', 'formAction', 'formFields', 'formId', 'formRequireChecklist'] as $formKey) {
			$this->assertContains($formKey, $formKeys);
			$this->assertContains($formKey, $keys);
		}

		$this->assertNotEmpty($this->node->configForm());
	}//end testTheFormCoversTheSpecifiedFieldsAndOnlyDeclaredKeys()

	/**
	 * The palette description states the division of labour with the signal
	 * node, so an author picks correctly without reading the source.
	 */
	public function testTheDescriptionNamesTheOtherHalfOfThePair(): void {
		$this->assertStringContainsString('Wait for an answer', $this->node->getDescription());
		$this->assertStringNotContainsString("\u{2014}", $this->node->getDescription(), 'no em-dashes in user-facing copy');
	}//end testTheDescriptionNamesTheOtherHalfOfThePair()
}//end class
