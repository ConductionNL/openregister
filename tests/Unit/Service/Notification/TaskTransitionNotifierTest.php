<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The notifier builds the task's adapter from the row the task row port gives
 * and dispatches the `transition` trigger with the task rule schema. It needs
 * only the Kernel task contract and the Notifications-owned port.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Kernel\Contracts\Notifications\TaskRowSourceInterface;
use OCA\OpenRegister\Kernel\Contracts\TaskInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRuleDispatcherInterface;
use OCA\OpenRegister\Notifications\Service\Notification\TaskNotificationRulesInterface;
use OCA\OpenRegister\Notifications\Service\Notification\TaskTransitionNotifier;
use OCA\OpenRegister\Notifications\Service\Notification\TransitionNotice;
use OCA\OpenRegister\Service\Task\TaskInboxServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\TaskTransitionNotifier
 */
final class TaskTransitionNotifierTest extends TestCase {
	private function task(): Task {
		$task = new Task();
		$task->setUuid('00000000-0000-0000-0000-000000000007');
		$task->setTitle('Approve the permit');
		$task->setState(Task::STATE_ACTIVE);

		return $task;
	}

	public function testTheAdapterIsBuiltFromThePortRowAndDispatchedAsATransition(): void {
		$task = $this->task();
		$schema = new Schema();

		$rows = $this->createMock(TaskRowSourceInterface::class);
		$rows->expects($this->once())->method('enrich')->with($task)->willReturn(['displayTitle' => 'Assign: Permit 42']);

		$rules = $this->createMock(TaskNotificationRulesInterface::class);
		$rules->method('buildSchema')->willReturn($schema);

		$seen = [];
		$dispatcher = $this->createMock(NotificationRuleDispatcherInterface::class);
		$dispatcher->expects($this->once())->method('dispatchWithSchema')->willReturnCallback(
			static function (ObjectEntity $object, string $trigger, array $context, Schema $given) use (&$seen): void {
				$seen = ['object' => $object, 'trigger' => $trigger, 'context' => $context, 'schema' => $given];
			}
		);

		$notifier = new TaskTransitionNotifier(dispatcher: $dispatcher, rules: $rules, inbox: $rows);
		$notifier->notifyTransition($task, new TransitionNotice(extra: ['previousAssignee' => 'former'], context: ['action' => 'assign']));

		$this->assertSame('transition', $seen['trigger']);
		$this->assertSame(['action' => 'assign'], $seen['context']);
		$this->assertSame($schema, $seen['schema']);
		$this->assertSame('00000000-0000-0000-0000-000000000007', $seen['object']->getUuid());
		$this->assertSame('Assign: Permit 42', $seen['object']->getObject()['title']);
		$this->assertSame('former', $seen['object']->getObject()['previousAssignee']);
	}

	public function testTheTaskInboxAnswersTheTaskRowPort(): void {
		$this->assertTrue(is_a(TaskInboxServiceInterface::class, TaskRowSourceInterface::class, true));
		$this->assertTrue(is_a(Task::class, TaskInterface::class, true));
	}
}
