<?php

/**
 * TaskInboxServiceCanTest — every inbox row read for someone carries `can`:
 * the lifecycle verbs that reader may run on the task now (record-tasks-tab
 * design D-4), decided by the real TaskAuthorizationService and narrowed by
 * the task's state.
 *
 * The expected lists follow the rule table in TaskAuthorizationService::RULES:
 * `claim` is the pool's, `unclaim`/`delegate`/`resolve`/`complete` the
 * assignee's, `assign`/`reassign`/`offer`/`cancel` the requester's, and an
 * administrator passes every rule. On top of that `claim` and `offer` need a
 * task nobody holds, `unclaim` and `delegate` one somebody holds, and a
 * terminal task lists nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/record-tasks-tab/specs/flow-tasks/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use DateTime;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskInboxAudience;
use OCA\OpenRegister\Db\TaskInboxCriteria;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Kernel\Contracts\TaskInterface;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCA\OpenRegister\Service\Task\TaskVerbList;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Task\TaskVerbList
 * @covers \OCA\OpenRegister\Service\Task\TaskInboxService
 * @uses   \OCA\OpenRegister\Service\Task\TaskAuthorizationService
 * @uses   \OCA\OpenRegister\Service\Task\Authorization\FailClosedGroups
 * @uses   \OCA\OpenRegister\Service\Task\TaskAct
 * @uses   \OCA\OpenRegister\Service\Task\TaskVerbAttempt
 * @uses   \OCA\OpenRegister\Service\Task\InboxListing
 * @uses   \OCA\OpenRegister\Service\Task\TaskInboxPage
 * @uses   \OCA\OpenRegister\Service\Task\TaskTemporalProjection
 * @uses   \OCA\OpenRegister\Db\Task
 * @uses   \OCA\OpenRegister\Db\TaskInboxCriteria
 * @uses   \OCA\OpenRegister\Db\TaskInboxAudience
 * @uses   \OCA\OpenRegister\Exception\TaskAccessDeniedException
 */
final class TaskInboxServiceCanTest extends TestCase {

	private TaskMapper&MockObject $tasks;

	private TaskVerbList $verbs;

	protected function setUp(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'root');
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $gid): bool => ($uid === 'bob' && $gid === 'vergunningen')
		);

		$this->tasks = $this->createMock(TaskMapper::class);
		$this->verbs = new TaskVerbList(authorization: new TaskAuthorizationService(groupManager: $groups));
	}

	/**
	 * A user task in the vergunningen pool, requested by rob.
	 *
	 * @param string|null $assignee Who holds it, null for nobody.
	 * @param string      $state    Its lifecycle state.
	 *
	 * @return Task
	 */
	private static function task(?string $assignee, string $state = Task::STATE_ACTIVE): Task {
		$task = new Task();
		$task->setId(1);
		$task->setUuid('t-1');
		$task->setTitle('Toets de aanvraag');
		$task->setPerformerType(TaskInterface::PERFORMER_USER);
		$task->setRequester('rob');
		$task->setAssignee($assignee);
		$task->setCandidateGroups(['vergunningen']);
		$task->setState($state);
		$task->setCreated(new DateTime('2026-11-01T09:00:00+00:00'));

		return $task;
	}

	/**
	 * The `can` of one task read through the inbox for a reader.
	 *
	 * @param Task   $task   The task.
	 * @param string $reader The reader's uid.
	 *
	 * @return mixed The row's `can`.
	 */
	private function canThroughTheInbox(Task $task, string $reader): mixed {
		$this->tasks->method('findInbox')->willReturn([$task]);
		$this->tasks->method('countInbox')->willReturn(1);
		$inbox = new TaskInboxService(tasks: $this->tasks, logger: new NullLogger(), verbs: $this->verbs);

		$page = $inbox->inbox(criteria: new TaskInboxCriteria(audience: new TaskInboxAudience(uid: $reader)));

		return $page['results'][0]['can'] ?? 'absent';
	}

	public function testTheAssigneeMayWorkTheTaskButNotRouteIt(): void {
		$this->assertSame(['unclaim', 'delegate', 'resolve', 'complete'], $this->canThroughTheInbox(self::task(assignee: 'ann'), 'ann'));
	}

	public function testTheRequesterMayRouteAndCancelButNotWorkIt(): void {
		$this->assertSame(['assign', 'reassign', 'cancel'], $this->canThroughTheInbox(self::task(assignee: 'ann'), 'rob'));
	}

	/**
	 * A pool member may claim a task nobody holds, and nothing else.
	 *
	 * @return void
	 */
	public function testAPoolMemberMayClaimAnOpenTask(): void {
		$this->assertSame(['claim'], $this->verbs->for(self::task(assignee: null, state: Task::STATE_AVAILABLE), 'bob'));
		$this->assertSame([], $this->verbs->for(self::task(assignee: 'ann'), 'bob'), 'a held task cannot be claimed');
	}

	public function testTheRequesterMayOfferOnlyATaskNobodyHolds(): void {
		$this->assertSame(['assign', 'reassign', 'offer', 'cancel'], $this->verbs->for(self::task(assignee: null, state: Task::STATE_AVAILABLE), 'rob'));
	}

	/**
	 * An administrator passes every rule; the state still narrows the list.
	 *
	 * @return void
	 */
	public function testAnAdministratorMayDoEverythingTheStateAllows(): void {
		$this->assertSame(
			['unclaim', 'assign', 'reassign', 'delegate', 'resolve', 'complete', 'cancel'],
			$this->verbs->for(self::task(assignee: 'ann'), 'root')
		);
		$this->assertSame(
			['claim', 'assign', 'reassign', 'offer', 'resolve', 'complete', 'cancel'],
			$this->verbs->for(self::task(assignee: null, state: Task::STATE_AVAILABLE), 'root')
		);
	}

	public function testAStrangerMayDoNothing(): void {
		$this->assertSame([], $this->canThroughTheInbox(self::task(assignee: 'ann'), 'eve'));
	}

	/**
	 * A finished task lists nothing, even for an administrator.
	 *
	 * @return void
	 */
	public function testATerminalTaskListsNothing(): void {
		foreach (Task::TERMINAL_STATES as $state) {
			$this->assertSame([], $this->verbs->for(self::task(assignee: 'ann', state: $state), 'root'), $state);
		}
	}

	public function testWithoutAReaderThereIsNoList(): void {
		$this->assertSame([], $this->verbs->for(self::task(assignee: 'ann'), null));
		$this->assertSame([], $this->verbs->for(self::task(assignee: 'ann'), ' '));
	}

	/**
	 * A row read for nobody (a notification, a job) carries no `can` key; a
	 * row read for someone always does, even without a verb list wired.
	 *
	 * @return void
	 */
	public function testOnlyARowReadForSomeoneCarriesCan(): void {
		$withVerbs = new TaskInboxService(tasks: $this->tasks, logger: new NullLogger(), verbs: $this->verbs);
		$this->assertArrayNotHasKey('can', $withVerbs->enrich(task: self::task(assignee: 'ann')));
		$this->assertSame(['unclaim', 'delegate', 'resolve', 'complete'], $withVerbs->enrich(task: self::task(assignee: 'ann'), caller: 'ann')['can']);

		$withoutVerbs = new TaskInboxService(tasks: $this->tasks, logger: new NullLogger());
		$this->assertSame([], $withoutVerbs->enrich(task: self::task(assignee: 'ann'), caller: 'ann')['can']);
	}
}
