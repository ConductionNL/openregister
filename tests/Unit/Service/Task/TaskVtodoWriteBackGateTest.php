<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The one path back: a stranger's completion refused and audited; an
 * illegal transition from a terminal task refused; a VTODO with no task
 * identity untouched; a SUMMARY edit never reaching the engine; an echo
 * ignored; a refusal always reverting AND notifying the actor.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-ticking-off-the-vtodo-completes-the-engine-task-through-authorization
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-notification-for-an-answered-task-is-withdrawn
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-the-projection-carries-a-real-assignee-not-prose
 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-task-status-mapping
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\Notification\TaskNotificationRules;
use OCA\OpenRegister\Service\Task\TaskCalendarProjector;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskProjectionService;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGate;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TaskVtodoWriteBackGateTest extends TestCase {
	private const UUID = TaskCalendarProjectorTest::PROJECTED_UUID;

	private TaskService&MockObject $tasks;

	private TaskMapper&MockObject $mapper;

	private TaskAuditMapper&MockObject $audits;

	private TaskCalendarProjector&MockObject $projector;

	private TaskProjectionService&MockObject $projections;

	private AnnotationNotificationDispatcher&MockObject $dispatcher;

	protected function setUp(): void {
		parent::setUp();
		$this->tasks = $this->createMock(TaskService::class);
		$this->mapper = $this->createMock(TaskMapper::class);
		$this->audits = $this->createMock(TaskAuditMapper::class);
		$this->projector = $this->createMock(TaskCalendarProjector::class);
		$this->projections = $this->createMock(TaskProjectionService::class);
		$this->dispatcher = $this->createMock(AnnotationNotificationDispatcher::class);
		$this->projector->method('render')->willReturnCallback(
			static fn (Task $task): string => 'RENDERED:' . $task->getState()
		);
	}

	private function gate(): TaskVtodoWriteBackGate {
		$inbox = $this->createMock(TaskInboxService::class);
		$inbox->method('enrich')->willReturn(['displayTitle' => 'Approve the permit']);

		return new TaskVtodoWriteBackGate(
			$this->tasks,
			$this->mapper,
			$this->audits,
			$this->projector,
			$this->projections,
			$inbox,
			$this->dispatcher,
			new TaskNotificationRules(),
			new NullLogger()
		);
	}

	private function vtodo(string $status, string $summary = 'Approve the permit', string $uuid = self::UUID): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:{$uuid}\r\nSUMMARY:{$summary}\r\nSTATUS:{$status}\r\n"
			. "X-OPENREGISTER-TASK:{$uuid}\r\nX-OPENREGISTER-TASK-ASSIGNEE:EXAMPLE_APPROVER_USER\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
	}

	private function taskExists(Task $task): void {
		$this->mapper->method('findByUuid')->with(self::UUID)->willReturn($task);
	}

	/**
	 * Every refusal is VISIBLE: the projection is re-rendered and the actor
	 * is told, through the refusal rule, naming the reason.
	 */
	private function expectRevertAndNotice(string $actor, string $reasonFragment): void {
		$this->projections->expects($this->once())->method('reconcileTask');
		$this->dispatcher->expects($this->once())->method('dispatchWithSchema')
			->with(
				$this->callback(
					static function ($adapter) use ($actor, $reasonFragment): bool {
						$payload = $adapter->getObject();
						return $payload['writeBackActor'] === $actor
							&& str_contains((string)$payload['writeBackReason'], $reasonFragment);
					}
				),
				'transition',
				$this->callback(static fn (array $ctx): bool => $ctx['action'] === TaskNotificationRules::ACTION_WRITE_BACK_REFUSED),
				$this->anything()
			);
	}

	public function testAHandMadeCalendarTaskIsNotTouched(): void {
		$standalone = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:x\r\nSUMMARY:Buy milk\r\nSTATUS:COMPLETED\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
		$this->mapper->expects($this->never())->method('findByUuid');
		$this->tasks->expects($this->never())->method('complete');

		$this->assertNull($this->gate()->handleWrite($standalone, 'anyone'));
		$this->assertFalse($this->gate()->isProjected($standalone));
	}

	public function testCompletingInTheTasksAppRequestsTheCompleteVerbWithTheActor(): void {
		$task = TaskCalendarProjectorTest::assignedTask();
		$this->taskExists($task);
		$this->projector->method('isEcho')->willReturn(false);
		$completed = TaskCalendarProjectorTest::assignedTask();
		$completed->setState(Task::STATE_COMPLETED);
		$this->tasks->expects($this->once())->method('complete')
			->with(self::UUID, 'done', null, null, 'EXAMPLE_APPROVER_USER')
			->willReturn($completed);
		$this->tasks->expects($this->never())->method('cancel');
		$this->projections->expects($this->never())->method('reconcileTask');

		$stored = $this->gate()->handleWrite($this->vtodo('COMPLETED'), 'EXAMPLE_APPROVER_USER');

		// What the calendar holds afterwards is the engine's rendering.
		$this->assertSame('RENDERED:completed', $stored);
	}

	public function testAStrangersTickIsRefusedRevertedAndTheStrangerTold(): void {
		$this->taskExists(TaskCalendarProjectorTest::assignedTask());
		$this->projector->method('isEcho')->willReturn(false);
		// The lifecycle denies (and audits) exactly as it would for an API call.
		$this->tasks->method('complete')
			->willThrowException(new TaskAccessDeniedException("Verb 'complete' denied: 'stranger' is not the assignee."));
		// The lifecycle already recorded the denial; the gate must not double it.
		$this->audits->expects($this->never())->method('insert');
		$this->expectRevertAndNotice('stranger', 'not the assignee');

		try {
			$this->gate()->handleWrite($this->vtodo('COMPLETED'), 'stranger');
			$this->fail('expected the denial to surface');
		} catch (TaskAccessDeniedException $denied) {
			$this->assertStringContainsString('stranger', $denied->getMessage());
		}
	}

	public function testAnIllegalTransitionFromATerminalTaskIsRefusedAndAudited(): void {
		$task = TaskCalendarProjectorTest::assignedTask();
		$task->setState(Task::STATE_COMPLETED);
		$task->setIsTerminal(true);
		$this->taskExists($task);
		$this->projector->method('isEcho')->willReturn(false);
		$this->tasks->expects($this->never())->method('complete');
		$this->tasks->expects($this->never())->method('cancel');
		$this->audits->expects($this->once())->method('insert')
			->with(
				$this->callback(
					static fn (TaskAudit $entry): bool => $entry->getAuthorized() === false
						&& $entry->getActor() === 'EXAMPLE_APPROVER_USER'
						&& $entry->getAction() === 'status'
						&& str_contains((string)$entry->getReason(), 'terminal state')
				)
			);
		$this->expectRevertAndNotice('EXAMPLE_APPROVER_USER', 'terminal state');

		$this->expectException(TaskConflictException::class);
		$this->gate()->handleWrite($this->vtodo('NEEDS-ACTION'), 'EXAMPLE_APPROVER_USER');
	}

	public function testALostRaceIsAConflictThatRevertsNotAFailure(): void {
		$this->taskExists(TaskCalendarProjectorTest::assignedTask());
		$this->projector->method('isEcho')->willReturn(false);
		$this->tasks->method('complete')
			->willThrowException(new TaskConflictException("Verb 'complete' lost a race: task is no longer open."));
		$this->audits->expects($this->once())->method('insert');
		$this->expectRevertAndNotice('EXAMPLE_APPROVER_USER', 'lost a race');

		$this->expectException(TaskConflictException::class);
		$this->gate()->handleWrite($this->vtodo('COMPLETED'), 'EXAMPLE_APPROVER_USER');
	}

	public function testASummaryEditNeverReachesTheEngineAndIsRestored(): void {
		$this->taskExists(TaskCalendarProjectorTest::assignedTask());
		$this->projector->method('isEcho')->willReturn(false);
		$this->tasks->expects($this->never())->method('complete');
		$this->tasks->expects($this->never())->method('cancel');
		$this->audits->expects($this->never())->method('insert');
		$this->dispatcher->expects($this->never())->method('dispatchWithSchema');

		$stored = $this->gate()->handleWrite($this->vtodo('IN-PROCESS', 'Assigned to: somebody else'), 'EXAMPLE_APPROVER_USER');

		$this->assertSame('RENDERED:active', $stored, 'the next render restores the projected values');
	}

	public function testTheProjectorsOwnEchoIsIgnored(): void {
		$this->projector->method('isEcho')->with(self::UUID, $this->anything())->willReturn(true);
		$this->mapper->expects($this->never())->method('findByUuid');
		$this->tasks->expects($this->never())->method('complete');

		$this->assertNull($this->gate()->handleWrite($this->vtodo('COMPLETED'), 'EXAMPLE_APPROVER_USER'));
	}

	public function testAnUnknownTaskIdentityIsRefusedNotInvented(): void {
		$this->projector->method('isEcho')->willReturn(false);
		$this->mapper->method('findByUuid')->willThrowException(new DoesNotExistException('no such task'));
		$this->tasks->expects($this->never())->method('complete');

		$this->expectException(DoesNotExistException::class);
		$this->gate()->handleWrite($this->vtodo('COMPLETED'), 'EXAMPLE_APPROVER_USER');
	}

	public function testOnlyCompletionAndCancellationCanBeRequested(): void {
		$this->taskExists(TaskCalendarProjectorTest::assignedTask());
		$this->tasks->expects($this->never())->method('assign');
		$this->audits->expects($this->once())->method('insert');
		$this->expectRevertAndNotice('EXAMPLE_APPROVER_USER', 'cannot be requested from a calendar');

		$this->expectException(TaskAccessDeniedException::class);
		$this->gate()->request(self::UUID, 'assign', 'EXAMPLE_APPROVER_USER');
	}

	public function testANullActorIsPassedToTheLifecycleWhichDeniesIt(): void {
		$this->taskExists(TaskCalendarProjectorTest::assignedTask());
		$this->projector->method('isEcho')->willReturn(false);
		$this->tasks->method('complete')->with(self::UUID, 'done', null, null, null)
			->willThrowException(new TaskAccessDeniedException("Verb 'complete' denied: no acting identity."));
		// Nobody to notify: the refusal is logged, never widened to anyone else.
		$this->dispatcher->expects($this->never())->method('dispatchWithSchema');
		$this->projections->expects($this->once())->method('reconcileTask');

		$this->expectException(TaskAccessDeniedException::class);
		$this->gate()->handleWrite($this->vtodo('COMPLETED'), null);
	}

	public function testCancelledRequestsTheCancelVerb(): void {
		$this->taskExists(TaskCalendarProjectorTest::assignedTask());
		$this->projector->method('isEcho')->willReturn(false);
		$cancelled = TaskCalendarProjectorTest::assignedTask();
		$cancelled->setState(Task::STATE_TERMINATED);
		$this->tasks->expects($this->once())->method('cancel')
			->with(self::UUID, $this->anything(), 'EXAMPLE_APPROVER_USER')
			->willReturn($cancelled);

		$this->assertSame('RENDERED:terminated', $this->gate()->handleWrite($this->vtodo('CANCELLED'), 'EXAMPLE_APPROVER_USER'));
	}
}
