<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Task transitions reach the ONE dispatcher through dispatchWithSchema()
 * with the recorded action; terminal transitions and assignee changes
 * withdraw first; a dispatcher failure never propagates.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-notification-for-an-answered-task-is-withdrawn
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-delivery-failure-never-fails-the-task
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Event\FlowRunTerminalEvent;
use OCA\OpenRegister\Event\TaskTransitionedEvent;
use OCA\OpenRegister\Listener\TaskNotificationListener;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\Notification\TaskNotificationRules;
use OCA\OpenRegister\Service\Notification\TaskObjectAdapter;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TaskNotificationListenerTest extends TestCase {
	private AnnotationNotificationDispatcher&MockObject $dispatcher;

	private IManager&MockObject $notifications;

	protected function setUp(): void {
		parent::setUp();
		$this->dispatcher = $this->createMock(AnnotationNotificationDispatcher::class);
		$this->notifications = $this->createMock(IManager::class);
	}

	private function listener(): TaskNotificationListener {
		$inbox = $this->createMock(TaskInboxService::class);
		$inbox->method('enrich')->willReturnCallback(
			static fn (Task $task): array => ['displayTitle' => 'Assign: Permit 42', 'overdue' => false, 'subject' => null]
		);

		return new TaskNotificationListener($this->dispatcher, new TaskNotificationRules(), $inbox, $this->notifications, new NullLogger());
	}

	private function task(string $state, ?string $assignee, string $action): Task {
		$task = new Task();
		$task->setUuid('t-1');
		$task->setState($state);
		$task->setIsTerminal(in_array($state, Task::TERMINAL_STATES, true));
		$task->setLastAction($action);
		$task->setPerformerType(Task::PERFORMER_USER);
		$task->setAssignee($assignee);

		return $task;
	}

	private function expectWithdrawal(int $times): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->expects($this->exactly($times))->method('setObject')->with('object', 't-1')->willReturnSelf();
		$this->notifications->method('createNotification')->willReturn($notification);
		$this->notifications->expects($this->exactly($times))->method('markProcessed');
	}

	public function testAnAssignmentIsDispatchedUnderTheTaskSlugWithTheRecordedAction(): void {
		$this->expectWithdrawal(1);
		$this->dispatcher->expects($this->once())->method('dispatchWithSchema')
			->with(
				$this->callback(
					static function (TaskObjectAdapter $adapter): bool {
						$payload = $adapter->getObject();
						return $adapter->getUuid() === 't-1'
							&& $adapter->getSchema() === TaskNotificationRules::SLUG
							&& $payload['assignee'] === 'approver'
							&& $payload['previousAssignee'] === null
							&& $payload['title'] === 'Assign: Permit 42';
					}
				),
				'transition',
				$this->callback(static fn (array $ctx): bool => $ctx['action'] === 'assign' && $ctx['actor'] === 'clerk' && $ctx['to'] === 'active'),
				$this->callback(static fn ($schema): bool => $schema->getSlug() === TaskNotificationRules::SLUG)
			);

		$this->listener()->handle(new TaskTransitionedEvent($this->task(Task::STATE_ACTIVE, 'approver', 'assign'), null, 'enabled', 'clerk'));
	}

	public function testATerminalTransitionWithdrawsBeforeItDispatches(): void {
		$order = [];
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$this->notifications->method('createNotification')->willReturn($notification);
		$this->notifications->method('markProcessed')->willReturnCallback(
			static function () use (&$order): void {
				$order[] = 'withdraw';
			}
		);
		$this->dispatcher->method('dispatchWithSchema')->willReturnCallback(
			static function () use (&$order): void {
				$order[] = 'dispatch';
			}
		);

		$this->listener()->handle(new TaskTransitionedEvent($this->task(Task::STATE_TERMINATED, 'approver', 'terminate'), 'approver', 'active', null));

		$this->assertSame(['withdraw', 'dispatch'], $order);
	}

	public function testAnUnchangedAssigneeOnAnOpenTaskWithdrawsNothing(): void {
		$this->notifications->expects($this->never())->method('markProcessed');
		$this->dispatcher->expects($this->once())->method('dispatchWithSchema');

		$this->listener()->handle(new TaskTransitionedEvent($this->task(Task::STATE_ACTIVE, 'approver', 'due-soon'), 'approver', 'active', null));
	}

	public function testAClaimWithdrawsThePoolsNotificationsAndCarriesThePreviousHolder(): void {
		$this->expectWithdrawal(1);
		$this->dispatcher->expects($this->once())->method('dispatchWithSchema')
			->with(
				$this->callback(static fn (TaskObjectAdapter $adapter): bool => $adapter->getObject()['previousAssignee'] === null),
				'transition',
				$this->callback(static fn (array $ctx): bool => $ctx['action'] === 'claim'),
				$this->anything()
			);

		$this->listener()->handle(new TaskTransitionedEvent($this->task(Task::STATE_ACTIVE, 'member-two', 'claim'), null, 'enabled', 'member-two'));
	}

	public function testAReassignmentCarriesThePreviousAssigneeForTheAwayRule(): void {
		$this->expectWithdrawal(1);
		$this->dispatcher->expects($this->once())->method('dispatchWithSchema')
			->with(
				$this->callback(static fn (TaskObjectAdapter $adapter): bool => $adapter->getObject()['previousAssignee'] === 'former'),
				'transition',
				$this->callback(static fn (array $ctx): bool => $ctx['action'] === 'reassign'),
				$this->anything()
			);

		$this->listener()->handle(new TaskTransitionedEvent($this->task(Task::STATE_ACTIVE, 'next', 'reassign'), 'former', 'active', 'manager'));
	}

	public function testADispatcherFailureNeverPropagates(): void {
		$this->dispatcher->method('dispatchWithSchema')->willThrowException(new \RuntimeException('notification backend down'));

		$this->listener()->handle(new TaskTransitionedEvent($this->task(Task::STATE_ACTIVE, 'approver', 'assign'), 'approver', 'active', null));
		$this->addToAssertionCount(1);
	}

	public function testOtherEventsAreIgnored(): void {
		$this->dispatcher->expects($this->never())->method('dispatchWithSchema');

		$this->listener()->handle(new FlowRunTerminalEvent('run-1', 'failed'));
	}
}
