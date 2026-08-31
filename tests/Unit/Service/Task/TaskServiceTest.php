<?php

/**
 * The lifecycle service's structural guarantees.
 *
 * These tests pin the properties that make the entity trustworthy rather
 * than merely present: the claim race has exactly one winner and a loud
 * loser; a rejection without a comment moves nothing; an audit-write
 * failure UNWINDS the completion (the "completed task without its audit
 * entry" state is unreachable); the candidate JSON and the candidate index
 * are written from one path and agree; legacy statuses map with their
 * distinctions preserved; and a task with run_uuid null passes the same
 * verbs identically to one with a run.
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

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskCandidateMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskRelationMapper;
use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskPerformerResolver;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Authorization ordering, concurrency, transactionality and normalisation.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskService
 */
class TaskServiceTest extends TestCase {

	/**
	 * The task table, mocked.
	 *
	 * @var TaskMapper&MockObject
	 */
	private TaskMapper&MockObject $tasks;

	/**
	 * The candidate index, mocked.
	 *
	 * @var TaskCandidateMapper&MockObject
	 */
	private TaskCandidateMapper&MockObject $candidates;

	/**
	 * The relations, mocked.
	 *
	 * @var TaskRelationMapper&MockObject
	 */
	private TaskRelationMapper&MockObject $relations;

	/**
	 * The audit, mocked.
	 *
	 * @var TaskAuditMapper&MockObject
	 */
	private TaskAuditMapper&MockObject $audits;

	/**
	 * The authorization, mocked (its own suite tests the real rules).
	 *
	 * @var TaskAuthorizationService&MockObject
	 */
	private TaskAuthorizationService&MockObject $authorization;

	/**
	 * The connection, mocked for transaction assertions.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * Build a service over the mocks.
	 *
	 * @return TaskService The service.
	 */
	private function service(): TaskService {
		return new TaskService(
			tasks: $this->tasks,
			candidates: $this->candidates,
			relations: $this->relations,
			audits: $this->audits,
			authorization: $this->authorization,
			resolver: $this->createMock(TaskPerformerResolver::class),
			db: $this->db,
			logger: new NullLogger()
		);
	}//end service()

	/**
	 * Fresh mocks per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->candidates = $this->createMock(TaskCandidateMapper::class);
		$this->relations = $this->createMock(TaskRelationMapper::class);
		$this->audits = $this->createMock(TaskAuditMapper::class);
		$this->authorization = $this->createMock(TaskAuthorizationService::class);
		$this->db = $this->createMock(IDBConnection::class);

		// Default happy plumbing: insert/update hand the entity back with an
		// id, and authorization passes unless a test says otherwise.
		$this->tasks->method('insert')->willReturnCallback(
			static function (Task $task): Task {
				if ($task->getId() === null) {
					$task->setId(41);
				}

				return $task;
			}
		);
		$this->tasks->method('update')->willReturnArgument(0);
		$this->audits->method('insert')->willReturnArgument(0);
	}//end setUp()

	/**
	 * An open, assigned task as the mapper would return it.
	 *
	 * @param string|null $runUuid Optional run provenance.
	 *
	 * @return Task The task.
	 */
	private function openTask(?string $runUuid = null): Task {
		$task = new Task();
		$task->setId(7);
		$task->setUuid('t-7');
		$task->setState(Task::STATE_ACTIVE);
		$task->setIsTerminal(false);
		$task->setPerformerType(Task::PERFORMER_USER);
		$task->setAssignee('alice');
		$task->setRequester('rita');
		$task->setRunUuid($runUuid);

		return $task;
	}//end openTask()

	/**
	 * TWO CLAIMS RACE, ONE LOSES: the losing conditional update yields a
	 * conflict and a rollback — never a silent overwrite via update().
	 *
	 * @return void
	 */
	public function testTheClaimRaceLoserGetsAConflictNotAnOverwrite(): void {
		$pooled = $this->openTask();
		$pooled->setAssignee(null);
		$this->tasks->method('findByUuid')->willReturn($pooled);
		$this->tasks->expects($this->once())->method('claim')->willReturn(false);
		$this->tasks->expects($this->never())->method('update');
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(TaskConflictException::class);
		$this->service()->claim(uuid: 't-7', actor: 'bob');
	}//end testTheClaimRaceLoserGetsAConflictNotAnOverwrite()

	/**
	 * The winner's claim commits with its audit entry.
	 *
	 * @return void
	 */
	public function testTheClaimWinnerCommitsWithAnAuditEntry(): void {
		$pooled = $this->openTask();
		$pooled->setAssignee(null);
		$this->tasks->method('findByUuid')->willReturn($pooled);
		$this->tasks->expects($this->once())->method('claim')->with(taskId: 7, uid: 'bob')->willReturn(true);
		$this->audits->expects($this->once())->method('insert');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$this->service()->claim(uuid: 't-7', actor: 'bob');
	}//end testTheClaimWinnerCommitsWithAnAuditEntry()

	/**
	 * A REJECTING OUTCOME WITHOUT A COMMENT IS REFUSED — before any
	 * transaction opens, so the task provably keeps its pre-call state.
	 *
	 * @return void
	 */
	public function testARejectionWithoutACommentMovesNothing(): void {
		$this->tasks->method('findByUuid')->willReturn($this->openTask());
		$this->db->expects($this->never())->method('beginTransaction');
		$this->tasks->expects($this->never())->method('update');

		$this->expectException(TaskValidationException::class);
		$this->expectExceptionMessage("'rejected'");
		$this->service()->complete(uuid: 't-7', outcome: 'rejected', resultText: null, comment: '', actor: 'alice');
	}//end testARejectionWithoutACommentMovesNothing()

	/**
	 * AN INJECTED AUDIT-WRITE FAILURE UNWINDS THE COMPLETION: rollback runs,
	 * commit never does, and the failure propagates. A completed task
	 * without its audit entry is not a reachable state.
	 *
	 * @return void
	 */
	public function testAnAuditFailureLeavesTheTaskNotCompleted(): void {
		$this->tasks->method('findByUuid')->willReturn($this->openTask());
		$this->audits->method('insert')->willThrowException(new RuntimeException('audit store down'));
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(RuntimeException::class);
		$this->service()->complete(uuid: 't-7', outcome: 'approved', resultText: null, comment: null, actor: 'alice');
	}//end testAnAuditFailureLeavesTheTaskNotCompleted()

	/**
	 * expires_at before due_at is refused naming BOTH values.
	 *
	 * @return void
	 */
	public function testExpiryBeforeDueIsRefusedNamingBothValues(): void {
		try {
			$this->service()->create(
				data: [
					'dueAt' => '2026-09-10T12:00:00+00:00',
					'expiresAt' => '2026-09-01T12:00:00+00:00',
				],
				actor: 'rita'
			);
			$this->fail('An expiry before the due date was accepted.');
		} catch (TaskValidationException $refused) {
			$this->assertStringContainsString('2026-09-01', $refused->getMessage());
			$this->assertStringContainsString('2026-09-10', $refused->getMessage());
		}
	}//end testExpiryBeforeDueIsRefusedNamingBothValues()

	/**
	 * A legacy status maps on create: state, materialised terminality and
	 * the preserved outcome land together.
	 *
	 * @return void
	 */
	public function testCreateMapsALegacyStatusAndPreservesTheOutcome(): void {
		$created = $this->service()->create(data: ['state' => 'done'], actor: 'rita');

		$this->assertSame(Task::STATE_COMPLETED, $created->getState());
		$this->assertTrue($created->getIsTerminal());
		$this->assertSame('done', $created->getOutcome());
	}//end testCreateMapsALegacyStatusAndPreservesTheOutcome()

	/**
	 * A checklist arriving as a STRING is refused — the procest shape.
	 *
	 * @return void
	 */
	public function testAStringChecklistIsRefused(): void {
		$this->expectException(TaskValidationException::class);
		$this->expectExceptionMessage('string containing JSON');
		$this->service()->create(data: ['checklist' => '[{"id":"c1"}]'], actor: 'rita');
	}//end testAStringChecklistIsRefused()

	/**
	 * ONE WRITE PATH, TWO REPRESENTATIONS: the candidate index rows written
	 * in the create transaction agree exactly with the candidate JSON.
	 *
	 * @return void
	 */
	public function testCandidateJsonAndCandidateIndexAgree(): void {
		$captured = null;
		$this->candidates->expects($this->once())->method('replaceForTask')->willReturnCallback(
			static function (int $taskId, array $candidates) use (&$captured): void {
				$captured = $candidates;
			}
		);

		$created = $this->service()->create(
			data: [
				'candidateUsers' => ['ursula'],
				'candidateGroups' => ['reviewers', 'controllers'],
				'candidateRole' => 'fiatteur',
			],
			actor: 'rita'
		);

		$expected = [
			['kind' => 'user', 'ref' => 'ursula'],
			['kind' => 'group', 'ref' => 'reviewers'],
			['kind' => 'group', 'ref' => 'controllers'],
			['kind' => 'role', 'ref' => 'fiatteur'],
		];
		$this->assertSame($expected, $captured);
		$this->assertSame(['ursula'], $created->getCandidateUsers());
		$this->assertSame(['reviewers', 'controllers'], $created->getCandidateGroups());
	}//end testCandidateJsonAndCandidateIndexAgree()

	/**
	 * KILLING A RUN EMPTIES ITS INBOXES: every open task of the run is
	 * terminated with a reason naming the run and its status, audited with
	 * the propagation source as actor — and the read is BY RUN UUID, so a
	 * task with run_uuid null is structurally unreachable.
	 *
	 * @return void
	 */
	public function testTerminateForRunTerminatesOpenTasksWithTheReason(): void {
		$first = $this->openTask(runUuid: 'run-9');
		$second = $this->openTask(runUuid: 'run-9');
		$second->setId(8);
		$second->setUuid('t-8');
		$this->tasks->expects($this->once())
			->method('findOpenByRunUuid')
			->with(runUuid: 'run-9')
			->willReturn([$first, $second]);

		$updated = [];
		$this->tasks->method('update')->willReturnCallback(
			static function (Task $task) use (&$updated): Task {
				$updated[] = $task;

				return $task;
			}
		);
		$auditEntries = [];
		$this->audits->method('insert')->willReturnCallback(
			static function (TaskAudit $entry) use (&$auditEntries): TaskAudit {
				$auditEntries[] = $entry;

				return $entry;
			}
		);

		$count = $this->service()->terminateForRun(runUuid: 'run-9', runStatus: 'stopped');

		$this->assertSame(2, $count);
		$this->assertCount(2, $updated);
		foreach ($updated as $task) {
			$this->assertSame(Task::STATE_TERMINATED, $task->getState());
			$this->assertTrue($task->getIsTerminal());
		}

		$this->assertCount(2, $auditEntries);
		foreach ($auditEntries as $entry) {
			$this->assertSame('flow-run:run-9', $entry->getActor());
			$this->assertStringContainsString('run-9', (string)$entry->getReason());
			$this->assertStringContainsString('stopped', (string)$entry->getReason());
		}
	}//end testTerminateForRunTerminatesOpenTasksWithTheReason()

	/**
	 * Propagation with no run uuid is a no-op that touches nothing.
	 *
	 * @return void
	 */
	public function testTerminateForRunWithNoUuidIsANoOp(): void {
		$this->tasks->expects($this->never())->method('findOpenByRunUuid');
		$this->assertSame(0, $this->service()->terminateForRun(runUuid: '  ', runStatus: 'stopped'));
	}//end testTerminateForRunWithNoUuidIsANoOp()

	/**
	 * A verb against a terminal task conflicts NAMING the current state.
	 *
	 * @return void
	 */
	public function testAVerbOnATerminalTaskConflictsNamingTheState(): void {
		$done = $this->openTask();
		$done->setState(Task::STATE_COMPLETED);
		$done->setIsTerminal(true);
		$this->tasks->method('findByUuid')->willReturn($done);

		try {
			$this->service()->claim(uuid: 't-7', actor: 'bob');
			$this->fail('A terminal task accepted a verb.');
		} catch (TaskConflictException $conflict) {
			$this->assertStringContainsString(Task::STATE_COMPLETED, $conflict->getMessage());
		}
	}//end testAVerbOnATerminalTaskConflictsNamingTheState()

	/**
	 * A DENIED VERB IS AUDITED: the denial appends an authorized=false entry
	 * and the denial still propagates.
	 *
	 * @return void
	 */
	public function testADenialIsAuditedAndStillDenies(): void {
		$this->tasks->method('findByUuid')->willReturn($this->openTask());
		$this->authorization->method('assertMay')->willThrowException(
			new TaskAccessDeniedException("Verb 'complete' denied: only the current assignee may perform it.")
		);

		$denialEntry = null;
		$this->audits->expects($this->once())->method('insert')->willReturnCallback(
			static function (TaskAudit $entry) use (&$denialEntry): TaskAudit {
				$denialEntry = $entry;

				return $entry;
			}
		);
		$this->tasks->expects($this->never())->method('update');

		try {
			$this->service()->complete(uuid: 't-7', outcome: 'approved', resultText: null, comment: null, actor: 'mallory');
			$this->fail('A denied verb went through.');
		} catch (TaskAccessDeniedException) {
			// Expected.
		}

		$this->assertNotNull($denialEntry);
		$this->assertFalse($denialEntry->getAuthorized());
		$this->assertSame('mallory', $denialEntry->getActor());
	}//end testADenialIsAuditedAndStillDenies()

	/**
	 * A TASK WITH NO RUN BEHAVES IDENTICALLY TO ONE WITH A RUN: same verbs,
	 * same resulting states, same audit actions — no code path treats "no
	 * run" as degraded.
	 *
	 * @return void
	 */
	public function testARunlessTaskCompletesIdenticallyToARunfulOne(): void {
		$results = [];
		foreach ([null, 'run-42'] as $runUuid) {
			$this->setUp();
			$task = $this->openTask(runUuid: $runUuid);
			$this->tasks->method('findByUuid')->willReturn($task);
			$actions = [];
			$this->audits->method('insert')->willReturnCallback(
				static function (TaskAudit $entry) use (&$actions): TaskAudit {
					$actions[] = $entry->getAction();

					return $entry;
				}
			);

			$completed = $this->service()->complete(uuid: 't-7', outcome: 'approved', resultText: 'ok', comment: null, actor: 'alice');
			$results[] = [
				'state' => $completed->getState(),
				'terminal' => $completed->getIsTerminal(),
				'outcome' => $completed->getOutcome(),
				'actions' => $actions,
			];
		}

		$this->assertSame($results[0], $results[1]);
		$this->assertSame(Task::STATE_COMPLETED, $results[0]['state']);
	}//end testARunlessTaskCompletesIdenticallyToARunfulOne()

	/**
	 * A delegated completion names both identities on the audit entry.
	 *
	 * @return void
	 */
	public function testADelegatedCompletionNamesBothIdentities(): void {
		$task = $this->openTask();
		$this->tasks->method('findByUuid')->willReturn($task);

		$entries = [];
		$this->audits->method('insert')->willReturnCallback(
			static function (TaskAudit $entry) use (&$entries): TaskAudit {
				$entries[] = $entry;

				return $entry;
			}
		);

		$this->service()->delegate(uuid: 't-7', delegate: 'dora', mandate: 'Volmacht 2026', actor: 'alice');
		$this->service()->complete(uuid: 't-7', outcome: 'approved', resultText: null, comment: null, actor: 'dora');

		$completion = end($entries);
		$this->assertSame('dora', $completion->getActor());
		$this->assertSame('alice', $completion->getOnBehalfOf());
		$this->assertSame('Volmacht 2026', $completion->getMandate());
	}//end testADelegatedCompletionNamesBothIdentities()

	/**
	 * A checklist item is addressable by id: one item flips, the others
	 * stand, and the change is audited.
	 *
	 * @return void
	 */
	public function testAChecklistItemIsAddressableById(): void {
		$task = $this->openTask();
		$task->setChecklist(
			[
				['id' => 'c1', 'label' => 'Eerste', 'description' => null, 'checked' => false],
				['id' => 'c2', 'label' => 'Tweede', 'description' => null, 'checked' => false],
			]
		);
		$this->tasks->method('findByUuid')->willReturn($task);
		$this->audits->expects($this->once())->method('insert');

		$updated = $this->service()->checkChecklistItem(uuid: 't-7', itemId: 'c2', checked: true, actor: 'alice');

		$checklist = $updated->getChecklist();
		$this->assertFalse($checklist[0]['checked']);
		$this->assertTrue($checklist[1]['checked']);
	}//end testAChecklistItemIsAddressableById()

	/**
	 * The template freeze: id, version and snapshot land at creation.
	 *
	 * @return void
	 */
	public function testTheTemplateIsFrozenAtCreation(): void {
		$created = $this->service()->create(
			data: [
				'templateId' => 'tpl-1',
				'templateVersion' => 3,
				'templateSnapshot' => ['checklist' => [['id' => 'c1', 'label' => 'Vast']]],
			],
			actor: 'rita'
		);

		$this->assertSame('tpl-1', $created->getTemplateId());
		$this->assertSame(3, $created->getTemplateVersion());
		$this->assertSame(['checklist' => [['id' => 'c1', 'label' => 'Vast']]], $created->getTemplateSnapshot());
	}//end testTheTemplateIsFrozenAtCreation()
}//end class
