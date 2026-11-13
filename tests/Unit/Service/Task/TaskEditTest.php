<?php

/**
 * Editing a task's own fields: title, description, due date and priority.
 *
 * The assignee, a member of the candidate pool and an administrator may
 * edit; anyone else is refused before anything is written, and the refusal
 * leaves the task exactly as it was.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use DateTime;
use OCA\OpenRegister\Service\Task\TaskAct;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskCandidateMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskRelationMapper;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskBuilder;
use OCA\OpenRegister\Service\Task\TaskCommit;
use OCA\OpenRegister\Service\Task\TaskIntake;
use OCA\OpenRegister\Service\Task\TaskLifecycleServices;
use OCA\OpenRegister\Service\Task\TaskPerformerResolver;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Task\TaskTables;
use OCP\IDBConnection;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use OCA\OpenRegister\Kernel\Contracts\Value\TaskActorRef;

/**
 * @covers \OCA\OpenRegister\Service\Task\TaskService::edit
 * @covers \OCA\OpenRegister\Service\Task\TaskEdit
 * @covers \OCA\OpenRegister\Service\Task\TaskAuthorizationService
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Db\TaskAudit
 * @uses \OCA\OpenRegister\Service\Task\TaskPriority
 * @uses \OCA\OpenRegister\Service\Task\TaskService
 * @uses \OCA\OpenRegister\Service\Task\Authorization\FailClosedGroups
 * @uses \OCA\OpenRegister\Service\Task\Authorization\TaskVerbAttempt
 * @uses \OCA\OpenRegister\Service\Task\TaskAct
 * @uses \OCA\OpenRegister\Service\Task\TaskCommit
 * @uses \OCA\OpenRegister\Service\Task\TaskIntake
 * @uses \OCA\OpenRegister\Service\Task\TaskLifecycleServices
 * @uses \OCA\OpenRegister\Service\Task\TaskTables
 */
class TaskEditTest extends TestCase {

	private TaskMapper&MockObject $tasks;

	private TaskAuditMapper&MockObject $audits;

	private IDBConnection&MockObject $db;

	/** @var array<int, TaskAudit> */
	private array $written = [];

	protected function setUp(): void {
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->audits = $this->createMock(TaskAuditMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->written = [];
		$this->audits->method('insert')->willReturnCallback(
			function (TaskAudit $entry): TaskAudit {
				$this->written[] = $entry;

				return $entry;
			}
		);
	}//end setUp()

	/**
	 * A group backend in which greta is in `reviewers` and root is an admin.
	 *
	 * @return IGroupManager The mock.
	 */
	private function groupBackend(): IGroupManager {
		$manager = $this->createMock(IGroupManager::class);
		$manager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'root');
		$manager->method('groupExists')->willReturn(true);
		$manager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => ($uid === 'greta' && $gid === 'reviewers')
		);

		return $manager;
	}//end groupBackend()

	/**
	 * @return TaskService The service over the real authorization rules.
	 */
	private function service(): TaskService {
		return new TaskService(
			authorization: new TaskAuthorizationService(groupManager: $this->groupBackend()),
			services: new TaskLifecycleServices(
				tables: new TaskTables(
					tasks: $this->tasks,
					candidates: $this->createMock(TaskCandidateMapper::class),
					relations: $this->createMock(TaskRelationMapper::class),
					audits: $this->audits
				),
				commit: new TaskCommit(
					db: $this->db,
					logger: new NullLogger()
				),
				intake: new TaskIntake(
					builder: new TaskBuilder()
				),
				resolver: $this->createMock(TaskPerformerResolver::class)
			)
		);
	}//end service()

	/**
	 * An open task assigned to alice, requested by rita, offered to reviewers.
	 *
	 * @return Task The task.
	 */
	private function task(): Task {
		$task = new Task();
		$task->setId(7);
		$task->setUuid('t-7');
		$task->setState(Task::STATE_ACTIVE);
		$task->setIsTerminal(false);
		$task->setPerformerType(Task::PERFORMER_USER);
		$task->setAssignee('alice');
		$task->setRequester('rita');
		$task->setCandidateGroups(['reviewers']);
		$task->setTitle('Hoorzitting inplannen');
		$task->setDescription('Plan the hearing');
		$task->setPriority('normal');
		$task->setDueAt(new DateTime('2026-10-21T00:00:00+00:00'));

		return $task;
	}//end task()

	/**
	 * The assignee, a pool member and an administrator may edit.
	 *
	 * @return void
	 */
	public function testTheAssigneeAPoolMemberAndAnAdministratorMayEdit(): void {
		$service = new TaskAuthorizationService(groupManager: $this->groupBackend());
		foreach (['alice', 'greta', 'root'] as $uid) {
			$service->assertMay(act: new TaskAct(verb: 'edit', actor: $uid), task: $this->task());
		}

		$this->assertTrue(true);
	}//end testTheAssigneeAPoolMemberAndAnAdministratorMayEdit()

	/**
	 * The requester alone, a watcher and a stranger may not.
	 *
	 * @return void
	 */
	public function testTheRequesterAWatcherAndAStrangerMayNotEdit(): void {
		$service = new TaskAuthorizationService(groupManager: $this->groupBackend());
		$task = $this->task();
		$task->setWatchers(['wanda']);
		foreach (['rita', 'wanda', 'mallory'] as $uid) {
			try {
				$service->assertMay(act: new TaskAct(verb: 'edit', actor: $uid), task: $task);
				$this->fail(sprintf("'%s' was allowed to edit.", $uid));
			} catch (TaskAccessDeniedException $denied) {
				$this->assertStringContainsString('edit', $denied->getMessage());
			}
		}
	}//end testTheRequesterAWatcherAndAStrangerMayNotEdit()

	/**
	 * An external task's fields are not edited through the seam.
	 *
	 * @return void
	 */
	public function testAnExternalTaskIsNotEdited(): void {
		$service = new TaskAuthorizationService(groupManager: $this->groupBackend());
		$task = $this->task();
		$task->setPerformerType(Task::PERFORMER_EXTERNAL);

		$this->expectException(TaskAccessDeniedException::class);
		$service->assertMay(act: new TaskAct(verb: 'edit', actor: 'root'), task: $task);
	}//end testAnExternalTaskIsNotEdited()

	/**
	 * The assignee's edit is written and audited, naming the changed fields.
	 *
	 * @return void
	 */
	public function testAnEditIsWrittenAndAudited(): void {
		$task = $this->task();
		$this->tasks->method('findByUuid')->willReturn($task);
		$this->tasks->expects($this->once())->method('updateIfOpen')->willReturn(true);
		$this->db->expects($this->once())->method('commit');

		$edited = $this->service()->edit(
			ref: new TaskActorRef(uuid: 't-7', actor: 'alice'),
			changes: ['title' => 'Hoorzitting plannen', 'priority' => 'high', 'dueAt' => '2026-10-28', 'description' => null],
		);

		$this->assertSame('Hoorzitting plannen', $edited->getTitle());
		$this->assertSame('high', $edited->getPriority());
		$this->assertSame('2026-10-28', $edited->getDueAt()?->format('Y-m-d'));
		$this->assertNull($edited->getDescription());
		$this->assertCount(1, $this->written);
		$this->assertSame('edit', $this->written[0]->getAction());
		$this->assertTrue($this->written[0]->getAuthorized());
		$this->assertSame('alice', $this->written[0]->getActor());
		$this->assertStringContainsString('title', (string)$this->written[0]->getReason());
		$this->assertStringContainsString('priority', (string)$this->written[0]->getReason());
	}//end testAnEditIsWrittenAndAudited()

	/**
	 * A refused edit writes nothing to the task and leaves its fields as
	 * they were; only the denial is audited.
	 *
	 * @return void
	 */
	public function testARefusedEditLeavesTheTaskUnchanged(): void {
		$task = $this->task();
		$this->tasks->method('findByUuid')->willReturn($task);
		$this->tasks->expects($this->never())->method('updateIfOpen');
		$this->tasks->expects($this->never())->method('update');
		$this->db->expects($this->never())->method('beginTransaction');

		try {
			$this->service()->edit(ref: new TaskActorRef(uuid: 't-7', actor: 'mallory'), changes: ['title' => 'Mine now', 'priority' => 'urgent']);
			$this->fail('A stranger edited the task.');
		} catch (TaskAccessDeniedException) {
			// Expected.
		}

		$this->assertSame('Hoorzitting inplannen', $task->getTitle());
		$this->assertSame('normal', $task->getPriority());
		$this->assertCount(1, $this->written);
		$this->assertFalse($this->written[0]->getAuthorized());
	}//end testARefusedEditLeavesTheTaskUnchanged()

	/**
	 * What cannot be stored is refused before anything is written.
	 *
	 * @return void
	 */
	public function testAnInvalidEditIsRefused(): void {
		$this->tasks->method('findByUuid')->willReturn($this->task());
		$this->tasks->expects($this->never())->method('updateIfOpen');

		$invalid = [
			['priority' => 'whenever'],
			['title' => ''],
			['dueAt' => 'next week'],
			['assignee' => 'mallory'],
			[],
		];
		foreach ($invalid as $changes) {
			try {
				$this->service()->edit(ref: new TaskActorRef(uuid: 't-7', actor: 'alice'), changes: $changes);
				$this->fail('Accepted: ' . json_encode($changes));
			} catch (TaskValidationException) {
				$this->assertTrue(true);
			}
		}
	}//end testAnInvalidEditIsRefused()

	/**
	 * A due date after the expiry is refused naming both.
	 *
	 * @return void
	 */
	public function testADueDatePastTheExpiryIsRefused(): void {
		$task = $this->task();
		$task->setExpiresAt(new DateTime('2026-10-25T00:00:00+00:00'));
		$this->tasks->method('findByUuid')->willReturn($task);

		$this->expectException(TaskValidationException::class);
		$this->service()->edit(ref: new TaskActorRef(uuid: 't-7', actor: 'alice'), changes: ['dueAt' => '2026-10-30']);
	}//end testADueDatePastTheExpiryIsRefused()
}//end class
