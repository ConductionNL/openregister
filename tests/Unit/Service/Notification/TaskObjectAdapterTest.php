<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The adapter maps a task onto a flat payload and stops: no notification
 * logic, the task uuid as the entity uuid (per-task dedupe), and every
 * payload key a rule may address present.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-task-lifecycle-delivery-is-automatic-and-declarative
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Notification;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Notification\TaskNotificationRules;
use OCA\OpenRegister\Service\Notification\TaskObjectAdapter;
use PHPUnit\Framework\TestCase;

class TaskObjectAdapterTest extends TestCase {
	private function task(): Task {
		$task = new Task();
		$task->setUuid('00000000-0000-0000-0000-000000000002');
		$task->setTitle('Approve the permit');
		$task->setState(Task::STATE_ACTIVE);
		$task->setIsTerminal(false);
		$task->setLastAction('assign');
		$task->setPerformerType(Task::PERFORMER_USER);
		$task->setAssignee('approver');
		$task->setCandidateGroups(['permits']);
		$task->setRequester('clerk');
		$task->setPriority('high');
		$task->setDueAt(new DateTime('2026-09-10T12:00:00+00:00'));

		return $task;
	}

	public function testTheEntityUuidIsTheTaskUuidUnderTheTaskSlug(): void {
		$adapter = new TaskObjectAdapter($this->task());

		$this->assertSame('00000000-0000-0000-0000-000000000002', $adapter->getUuid());
		$this->assertSame(TaskNotificationRules::SLUG, $adapter->getSchema());
		$this->assertNull($adapter->getRegister());
		$this->assertSame('Approve the permit', $adapter->getName());
	}

	public function testThePayloadCoversEveryDeclaredProperty(): void {
		$payload = TaskObjectAdapter::payload($this->task());
		$declared = array_keys((new TaskNotificationRules())->payloadProperties());

		sort($declared);
		$keys = array_keys($payload);
		sort($keys);
		$this->assertSame($declared, $keys);
	}

	public function testDerivedFieldsComeFromTheRowNotFromTheAdapter(): void {
		$row = ['displayTitle' => 'Assign: Permit 42', 'overdue' => true, 'daysOverdue' => 3, 'subject' => ['title' => 'Permit 42']];
		$payload = (new TaskObjectAdapter($this->task(), $row))->getObject();

		$this->assertSame('Assign: Permit 42', $payload['title']);
		$this->assertTrue($payload['overdue']);
		$this->assertSame(3, $payload['daysOverdue']);
		$this->assertSame('Permit 42', $payload['subjectTitle']);
		$this->assertSame('2026-09-10T12:00:00+00:00', $payload['dueAt']);
	}

	public function testExtraOnlyFillsDeclaredKeys(): void {
		$payload = (new TaskObjectAdapter($this->task(), [], [
			'previousAssignee' => 'former',
			'writeBackActor' => 'stranger',
			'somethingElse' => 'ignored',
		]))->getObject();

		$this->assertSame('former', $payload['previousAssignee']);
		$this->assertSame('stranger', $payload['writeBackActor']);
		$this->assertArrayNotHasKey('somethingElse', $payload);
	}

	public function testAPooledTaskCarriesNoAssignee(): void {
		$task = $this->task();
		$task->setAssignee(null);
		$payload = (new TaskObjectAdapter($task))->getObject();

		$this->assertNull($payload['assignee']);
		$this->assertSame(['permits'], $payload['candidateGroups']);
	}

	public function testABareAdapterIsConstructibleForTheEntityBaseClass(): void {
		$adapter = new TaskObjectAdapter();

		$this->assertSame(TaskNotificationRules::SLUG, $adapter->getSchema());
		$this->assertNull($adapter->getUuid());
	}
}
