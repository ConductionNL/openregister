<?php

/**
 * TaskInboxService: a row whose user assignee is away says so, and until when.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-task-goes-to-the-stand-in-of-an-absent-assignee
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use DateTimeImmutable;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\InboxListing;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskSubstitution;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Design D-6: the absent flag on the inbox row.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskInboxService
 * @uses \OCA\OpenRegister\Service\Task\TaskTemporalProjection
 * @uses \OCA\OpenRegister\Service\Task\InboxListing
 * @uses \OCA\OpenRegister\Db\Task
 */
class TaskInboxAbsenceTest extends TestCase {

	/**
	 * A user task assigned to someone.
	 *
	 * @param string      $assignee The assignee.
	 * @param string|null $type     The performer type.
	 *
	 * @return Task The task.
	 */
	private function task(string $assignee, ?string $type = Task::PERFORMER_USER): Task {
		$task = new Task();
		$task->setUuid('t-' . $assignee);
		$task->setTitle('Decide');
		$task->setPerformerType($type);
		$task->setAssignee($assignee);

		return $task;
	}//end task()

	/**
	 * The listing the rows are built for.
	 *
	 * @return InboxListing The listing.
	 */
	private function listing(): InboxListing {
		return new InboxListing(subjects: [], now: new DateTimeImmutable('2026-10-07T09:00:00Z'), openBlockers: []);
	}//end listing()

	/**
	 * Anna is away until 2026-10-16; Bram is in. Each person is asked once.
	 *
	 * @return void
	 */
	public function testARowSaysWhenItsAssigneeIsAway(): void {
		$substitution = $this->createMock(TaskSubstitution::class);
		$substitution->expects($this->exactly(2))->method('absenceOf')->willReturnCallback(
			static fn (string $uid): ?array => ($uid === 'anna')
				? ['id' => '42', 'start' => '2026-10-05', 'end' => '2026-10-16', 'replacement' => 'chris']
				: null
		);
		$inbox = new TaskInboxService(tasks: $this->createMock(TaskMapper::class), logger: new NullLogger(), substitution: $substitution);

		$anna = $inbox->row(task: $this->task(assignee: 'anna'), listing: $this->listing());
		$again = $inbox->row(task: $this->task(assignee: 'anna'), listing: $this->listing());
		$bram = $inbox->row(task: $this->task(assignee: 'bram'), listing: $this->listing());

		$this->assertTrue($anna['assigneeAbsent']);
		$this->assertSame('2026-10-16', $anna['absentUntil']);
		$this->assertSame('2026-10-16', $again['absentUntil']);
		$this->assertFalse($bram['assigneeAbsent']);
		$this->assertNull($bram['absentUntil']);
	}//end testARowSaysWhenItsAssigneeIsAway()

	/**
	 * A group task is never absent; without the service the fields are absent.
	 *
	 * @return void
	 */
	public function testGroupTasksAndNoServiceCarryNoAbsence(): void {
		$substitution = $this->createMock(TaskSubstitution::class);
		$substitution->expects($this->never())->method('absenceOf');
		$inbox = new TaskInboxService(tasks: $this->createMock(TaskMapper::class), logger: new NullLogger(), substitution: $substitution);

		$group = $inbox->row(task: $this->task(assignee: 'reviewers', type: 'group'), listing: $this->listing());
		$this->assertFalse($group['assigneeAbsent']);

		$plain = (new TaskInboxService(tasks: $this->createMock(TaskMapper::class), logger: new NullLogger()))
			->row(task: $this->task(assignee: 'anna'), listing: $this->listing());
		$this->assertArrayNotHasKey('assigneeAbsent', $plain);
	}//end testGroupTasksAndNoServiceCarryNoAbsence()
}//end class
