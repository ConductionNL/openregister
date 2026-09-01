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
use OCA\OpenRegister\Service\Task\TaskBuilder;
use OCA\OpenRegister\Service\Task\TaskForm;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCA\OpenRegister\Service\Task\TaskPerformerResolver;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use UnexpectedValueException;

/**
 * Authorization ordering, concurrency, transactionality and normalisation.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskService
 * @covers \OCA\OpenRegister\Service\Task\TaskBuilder
 * @covers \OCA\OpenRegister\Service\Task\TaskState
 * @covers \OCA\OpenRegister\Service\Task\TaskPriority
 * @covers \OCA\OpenRegister\Db\Task
 * @covers \OCA\OpenRegister\Db\TaskAudit
 * @covers \OCA\OpenRegister\Db\TaskRelation
 * @covers \OCA\OpenRegister\Exception\TaskValidationException
 * @covers \OCA\OpenRegister\Exception\TaskAccessDeniedException
 * @covers \OCA\OpenRegister\Exception\TaskConflictException
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
			logger: new NullLogger(),
			builder: new TaskBuilder()
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
		$this->tasks->method('updateIfOpen')->willReturn(true);
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
		$this->tasks->expects($this->never())->method('updateIfOpen');
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
		$this->tasks->expects($this->never())->method('updateIfOpen');

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
		// A closed task is importable only on the TRUSTED path (migrations);
		// the HTTP path refuses it, see testCreateOverHttpRefusesATerminalState.
		$created = $this->service()->import(data: ['state' => 'done'], actor: 'rita');

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
		$updated = [];
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->tasks->expects($this->once())
			->method('findOpenByRunUuid')
			->with(runUuid: 'run-9')
			->willReturn([$first, $second]);
		$this->tasks->method('updateIfOpen')->willReturnCallback(
			static function (Task $task) use (&$updated): bool {
				$updated[] = $task;

				return true;
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
		$this->tasks->expects($this->never())->method('updateIfOpen');

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
	 * RED 1: OFFER ON AN ASSIGNED TASK IS REFUSED. Before this guard,
	 * `offer {"routingFallback": "mallory"}` on an active, assigned task
	 * made mallory the assignee, after which mallory's complete passed.
	 * Now the assigned task conflicts, the pool and fallback are untouched,
	 * and nothing is written.
	 *
	 * @return void
	 */
	public function testOfferIsRefusedOnAnAssignedTask(): void {
		$assigned = $this->openTask();
		$this->tasks->method('findByUuid')->willReturn($assigned);
		$this->tasks->expects($this->never())->method('updateIfOpen');
		$this->candidates->expects($this->never())->method('replaceForTask');

		try {
			$this->service()->offer(uuid: 't-7', pool: ['routingFallback' => 'mallory'], actor: 'rita');
			$this->fail('An assigned task accepted an offer.');
		} catch (TaskConflictException $conflict) {
			$this->assertStringContainsString('already assigned', $conflict->getMessage());
		}

		$this->assertSame('alice', $assigned->getAssignee());
		$this->assertNull($assigned->getRoutingFallback());
	}//end testOfferIsRefusedOnAnAssignedTask()

	/**
	 * Offer on a POOLED task still works, for the requester.
	 *
	 * @return void
	 */
	public function testOfferRoutesAPooledTask(): void {
		$pooled = $this->openTask();
		$pooled->setAssignee(null);
		$this->tasks->method('findByUuid')->willReturn($pooled);
		$this->candidates->expects($this->once())->method('replaceForTask');

		$offered = $this->service()->offer(uuid: 't-7', pool: ['candidateUsers' => ['pat', 'quinn']], actor: 'rita');

		$this->assertSame(['pat', 'quinn'], $offered->getCandidateUsers());
		$this->assertSame(Task::STATE_ENABLED, $offered->getState());
	}//end testOfferRoutesAPooledTask()

	/**
	 * AUTHORIZATION RUNS BEFORE THE TERMINALITY CHECK: a stranger probing a
	 * completed task gets the denial, never a 409 that names its state.
	 *
	 * @return void
	 */
	public function testAuthorizationRunsBeforeTheTerminalityCheck(): void {
		$done = $this->openTask();
		$done->setState(Task::STATE_COMPLETED);
		$done->setIsTerminal(true);
		$this->tasks->method('findByUuid')->willReturn($done);
		$this->authorization->method('assertMay')->willThrowException(new TaskAccessDeniedException('denied'));

		$this->expectException(TaskAccessDeniedException::class);
		$this->service()->complete(uuid: 't-7', outcome: 'approved', resultText: null, comment: null, actor: 'mallory');
	}//end testAuthorizationRunsBeforeTheTerminalityCheck()

	/**
	 * TWO COMPLETIONS RACE: the second conditional update affects no row,
	 * so it conflicts and rolls back instead of overwriting the first outcome.
	 *
	 * @return void
	 */
	public function testASecondCompletionLosesTheConditionalUpdate(): void {
		$this->tasks->method('findByUuid')->willReturn($this->openTask());
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->tasks->method('findByUuid')->willReturn($this->openTask());
		$this->tasks->method('updateIfOpen')->willReturn(false);
		$this->audits->expects($this->never())->method('insert');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(TaskConflictException::class);
		$this->service()->complete(uuid: 't-7', outcome: 'rejected', resultText: null, comment: 'no', actor: 'alice');
	}//end testASecondCompletionLosesTheConditionalUpdate()

	/**
	 * A REFUSED COMPLETION IS AUDITED, DISTINCTLY: its own action name, the
	 * task's unchanged state, authorized (the caller was allowed to try), and
	 * outside any transaction because nothing else changed.
	 *
	 * @return void
	 */
	public function testARefusedCompletionIsAuditedDistinctlyFromACompletion(): void {
		$this->audits->expects($this->once())->method('insert')->willReturnCallback(
			function (TaskAudit $entry): TaskAudit {
				$this->assertSame('complete-refused', $entry->getAction());
				$this->assertSame(Task::STATE_ACTIVE, $entry->getStateAfter());
				$this->assertTrue($entry->getAuthorized());
				$this->assertSame('alice', $entry->getActor());
				$this->assertStringContainsString('reason', (string)$entry->getReason());

				return $entry;
			}
		);
		$this->db->expects($this->never())->method('beginTransaction');
		$this->tasks->expects($this->never())->method('updateIfOpen');

		$this->service()->recordRefusedCompletion(task: $this->openTask(), reason: 'missing required input field(s): "reason"', actor: 'alice');
	}//end testARefusedCompletionIsAuditedDistinctlyFromACompletion()

	/**
	 * The seam a write-first caller uses: exists, authorized, open. A terminal
	 * task is a conflict and a denial is audited, exactly as for the verb.
	 *
	 * @return void
	 */
	public function testAuthorizedOpenTaskRefusesATerminalTaskAndAuditsADenial(): void {
		$closed = $this->openTask();
		$closed->setState(Task::STATE_COMPLETED);
		$closed->setIsTerminal(true);
		$this->tasks->method('findByUuid')->willReturn($closed);

		try {
			$this->service()->authorizedOpenTask(verb: 'complete', uuid: 't-7', actor: 'alice');
			$this->fail('Expected a conflict.');
		} catch (TaskConflictException $conflict) {
			$this->assertStringContainsString('completed', $conflict->getMessage());
		}

		$this->authorization->method('assertMay')->willThrowException(new TaskAccessDeniedException('not the assignee'));
		$this->audits->expects($this->once())->method('insert');

		$this->expectException(TaskAccessDeniedException::class);
		$this->service()->authorizedOpenTask(verb: 'complete', uuid: 't-7', actor: 'mallory');
	}//end testAuthorizedOpenTaskRefusesATerminalTaskAndAuditsADenial()

	/**
	 * A run-less task's own form declaration is refused at creation, the way a
	 * step's is at save: nothing is inserted, the reader's reason is the message.
	 *
	 * @return void
	 */
	public function testCreateRefusesARecordFormTheReaderRefuses(): void {
		$this->authorization->method('isAdministrator')->willReturn(false);
		$reader = $this->createMock(TaskFormReader::class);
		$reader->method('fromRecord')->willReturn(new TaskForm(kind: 'fields', schema: 'case', fields: [['field' => 'reasonn', 'required' => true]]));
		$reader->method('validate')->willThrowException(new UnexpectedValueException('Field "reasonn" of schema "case" cannot be asked for: the schema has no such property.'));
		$service = new TaskService(
			tasks: $this->tasks,
			candidates: $this->candidates,
			relations: $this->relations,
			audits: $this->audits,
			authorization: $this->authorization,
			resolver: $this->createMock(TaskPerformerResolver::class),
			db: $this->db,
			logger: new NullLogger(),
			builder: new TaskBuilder(),
			forms: $reader
		);
		$this->tasks->expects($this->never())->method('insert');

		$this->expectException(TaskValidationException::class);
		$this->expectExceptionMessage('reasonn');
		$service->create(data: ['title' => 'Approve', 'metadata' => ['form' => ['kind' => 'fields', 'schema' => 'case', 'fields' => 'reasonn*']]], actor: 'alice');
	}//end testCreateRefusesARecordFormTheReaderRefuses()

	/**
	 * A task without a record form is created without consulting the reader.
	 *
	 * @return void
	 */
	public function testCreateWithoutARecordFormNeverConsultsTheReader(): void {
		$this->authorization->method('isAdministrator')->willReturn(false);
		$reader = $this->createMock(TaskFormReader::class);
		$reader->expects($this->never())->method('validate');
		$service = new TaskService(
			tasks: $this->tasks,
			candidates: $this->candidates,
			relations: $this->relations,
			audits: $this->audits,
			authorization: $this->authorization,
			resolver: $this->createMock(TaskPerformerResolver::class),
			db: $this->db,
			logger: new NullLogger(),
			builder: new TaskBuilder(),
			forms: $reader
		);

		$created = $service->create(data: ['title' => 'Approve'], actor: 'alice');

		$this->assertSame('Approve', $created->getTitle());
	}//end testCreateWithoutARecordFormNeverConsultsTheReader()

	/**
	 * OVER HTTP, THE REQUESTER IS THE ACTOR: an ordinary caller cannot write
	 * somebody else's name into the seat that owns cancel and reassign.
	 *
	 * @return void
	 */
	public function testCreateOverHttpPinsTheRequesterToTheActor(): void {
		$this->authorization->method('isAdministrator')->willReturn(false);

		$created = $this->service()->create(data: ['requester' => 'director'], actor: 'mallory');

		$this->assertSame('mallory', $created->getRequester());
	}//end testCreateOverHttpPinsTheRequesterToTheActor()

	/**
	 * OVER HTTP, A TASK CANNOT BE BORN CLOSED: 'approved' maps to completed
	 * with nobody having completed it, so it is refused for non-admins.
	 *
	 * @return void
	 */
	public function testCreateOverHttpRefusesATerminalState(): void {
		$this->authorization->method('isAdministrator')->willReturn(false);
		$this->tasks->expects($this->never())->method('insert');

		$this->expectException(TaskValidationException::class);
		$this->expectExceptionMessage("'approved'");
		$this->service()->create(data: ['state' => 'approved'], actor: 'mallory');
	}//end testCreateOverHttpRefusesATerminalState()

	/**
	 * An administrator keeps the full create surface over HTTP.
	 *
	 * @return void
	 */
	public function testAnAdministratorMayNameARequesterOnCreate(): void {
		$this->authorization->method('isAdministrator')->willReturn(true);

		$created = $this->service()->create(data: ['requester' => 'director'], actor: 'root');

		$this->assertSame('director', $created->getRequester());
	}//end testAnAdministratorMayNameARequesterOnCreate()

	/**
	 * A delegation needs a delegate, not only a mandate.
	 *
	 * @return void
	 */
	public function testDelegateRefusesAnEmptyDelegate(): void {
		$this->tasks->method('findByUuid')->willReturn($this->openTask());
		$this->db->expects($this->never())->method('beginTransaction');

		$this->expectException(TaskValidationException::class);
		$this->expectExceptionMessage('delegate');
		$this->service()->delegate(uuid: 't-7', delegate: '  ', mandate: 'Volmacht', actor: 'alice');
	}//end testDelegateRefusesAnEmptyDelegate()

	/**
	 * A re-delegation keeps naming the ORIGINAL performer.
	 *
	 * @return void
	 */
	public function testReDelegationKeepsTheOriginalOnBehalfOf(): void {
		$task = $this->openTask();
		$this->tasks->method('findByUuid')->willReturn($task);

		$this->service()->delegate(uuid: 't-7', delegate: 'dora', mandate: 'Volmacht 1', actor: 'alice');
		$this->service()->delegate(uuid: 't-7', delegate: 'ed', mandate: 'Volmacht 2', actor: 'dora');

		$this->assertSame('ed', $task->getAssignee());
		$this->assertSame('alice', $task->getOnBehalfOf());
	}//end testReDelegationKeepsTheOriginalOnBehalfOf()

	/**
	 * PROPAGATION CONTINUES PAST A FAILING TASK: one broken row does not
	 * orphan the rest of the run's tasks in their assignees' inboxes.
	 *
	 * @return void
	 */
	public function testTerminateForRunContinuesPastAFailingTask(): void {
		$first = $this->openTask(runUuid: 'run-9');
		$second = $this->openTask(runUuid: 'run-9');
		$second->setId(8);
		$second->setUuid('t-8');
		$third = $this->openTask(runUuid: 'run-9');
		$third->setId(9);
		$third->setUuid('t-9');

		$this->tasks = $this->createMock(TaskMapper::class);
		$this->tasks->method('findOpenByRunUuid')->willReturn([$first, $second, $third]);
		$this->tasks->method('updateIfOpen')->willReturnCallback(
			static function (Task $task): bool {
				if ($task->getUuid() === 't-8') {
					throw new RuntimeException('row locked');
				}

				return true;
			}
		);
		$this->db->expects($this->exactly(3))->method('beginTransaction');
		$this->db->expects($this->exactly(2))->method('commit');
		$this->db->expects($this->once())->method('rollBack');

		$this->assertSame(2, $this->service()->terminateForRun(runUuid: 'run-9', runStatus: 'stopped'));
		$this->assertSame(Task::STATE_TERMINATED, $third->getState());
	}//end testTerminateForRunContinuesPastAFailingTask()

	/**
	 * unclaim returns the task to its pool and clears delegation state.
	 *
	 * @return void
	 */
	public function testUnclaimReturnsTheTaskToItsPool(): void {
		$task = $this->openTask();
		$task->setOnBehalfOf('someone');
		$this->tasks->method('findByUuid')->willReturn($task);
		$this->audits->expects($this->once())->method('insert');

		$pooled = $this->service()->unclaim(uuid: 't-7', actor: 'alice');

		$this->assertNull($pooled->getAssignee());
		$this->assertNull($pooled->getOnBehalfOf());
		$this->assertSame(Task::STATE_ENABLED, $pooled->getState());
		$this->assertSame('unclaim', $pooled->getLastAction());
	}//end testUnclaimReturnsTheTaskToItsPool()

	/**
	 * assign and reassign set the holder, activate, and refuse an empty one.
	 *
	 * @return void
	 */
	public function testAssignAndReassignSetTheHolder(): void {
		$task = $this->openTask();
		$task->setAssignee(null);
		$task->setState(Task::STATE_ENABLED);
		$this->tasks->method('findByUuid')->willReturn($task);

		$assigned = $this->service()->assign(uuid: 't-7', assignee: 'bob', actor: 'rita');
		$this->assertSame('bob', $assigned->getAssignee());
		$this->assertSame(Task::STATE_ACTIVE, $assigned->getState());

		$reassigned = $this->service()->reassign(uuid: 't-7', assignee: 'carol', actor: 'rita');
		$this->assertSame('carol', $reassigned->getAssignee());
		$this->assertSame('reassign', $reassigned->getLastAction());

		$this->expectException(TaskValidationException::class);
		$this->service()->assign(uuid: 't-7', assignee: ' ', actor: 'rita');
	}//end testAssignAndReassignSetTheHolder()

	/**
	 * cancel terminates with outcome cancelled and records the reason.
	 *
	 * @return void
	 */
	public function testCancelTerminatesWithAReason(): void {
		$task = $this->openTask();
		$this->tasks->method('findByUuid')->willReturn($task);
		$reason = null;
		$this->audits->method('insert')->willReturnCallback(
			static function (TaskAudit $entry) use (&$reason): TaskAudit {
				$reason = $entry->getReason();

				return $entry;
			}
		);

		$cancelled = $this->service()->cancel(uuid: 't-7', reason: 'Aanvraag ingetrokken', actor: 'rita');

		$this->assertSame(Task::STATE_TERMINATED, $cancelled->getState());
		$this->assertTrue($cancelled->getIsTerminal());
		$this->assertSame('cancelled', $cancelled->getOutcome());
		$this->assertSame('Aanvraag ingetrokken', $reason);
	}//end testCancelTerminatesWithAReason()

	/**
	 * resolve completes with the resolved outcome.
	 *
	 * @return void
	 */
	public function testResolveCompletesWithTheResolvedOutcome(): void {
		$this->tasks->method('findByUuid')->willReturn($this->openTask());

		$resolved = $this->service()->resolve(uuid: 't-7', resultText: 'Klaar', comment: null, actor: 'alice');

		$this->assertSame(Task::STATE_COMPLETED, $resolved->getState());
		$this->assertSame('resolved', $resolved->getOutcome());
		$this->assertSame('Klaar', $resolved->getResultText());
		$this->assertSame('alice', $resolved->getCompletedBy());
	}//end testResolveCompletesWithTheResolvedOutcome()

	/**
	 * terminateAsMoot terminates an open task with the source as actor and
	 * leaves an already-terminal one exactly as it ended.
	 *
	 * @return void
	 */
	public function testTerminateAsMootIsIdempotent(): void {
		$open = $this->openTask();
		$this->tasks->method('findByUuid')->willReturn($open);
		$actor = null;
		$this->audits->method('insert')->willReturnCallback(
			static function (TaskAudit $entry) use (&$actor): TaskAudit {
				$actor = $entry->getActor();

				return $entry;
			}
		);

		$terminated = $this->service()->terminateAsMoot(uuid: 't-7', reason: 'Branch closed', source: 'flow-node:gateway');
		$this->assertSame(Task::STATE_TERMINATED, $terminated->getState());
		$this->assertSame('flow-node:gateway', $actor);

		// Second observation: already terminal, nothing written.
		$this->db->expects($this->never())->method('beginTransaction');
		$again = $this->service()->terminateAsMoot(uuid: 't-7', reason: 'Branch closed', source: 'flow-node:gateway');
		$this->assertSame(Task::STATE_TERMINATED, $again->getState());
	}//end testTerminateAsMootIsIdempotent()

	/**
	 * get and auditTrail read through the mappers.
	 *
	 * @return void
	 */
	public function testGetAndAuditTrailRead(): void {
		$this->tasks->method('findByUuid')->willReturn($this->openTask());
		$this->audits->expects($this->once())->method('findForTask')->with(taskId: 7)->willReturn([new TaskAudit()]);

		$this->assertSame('t-7', $this->service()->get(uuid: 't-7')->getUuid());
		$this->assertCount(1, $this->service()->auditTrail(uuid: 't-7'));
	}//end testGetAndAuditTrailRead()

	/**
	 * Relations named at creation are inserted as typed rows; one without a
	 * role or object is refused.
	 *
	 * @return void
	 */
	public function testRelationsAtCreationAreInsertedAndValidated(): void {
		$inserted = [];
		$this->relations->method('insert')->willReturnCallback(
			static function ($row) use (&$inserted) {
				$inserted[] = $row;

				return $row;
			}
		);

		$this->service()->import(
			data: [
				'relations' => [
					['role' => 'case', 'objectUuid' => 'obj-1', 'registerId' => 2, 'schemaId' => 3],
					['role' => 'evidence', 'objectUuid' => 'obj-2'],
					'not-an-array',
				],
			],
			actor: 'rita'
		);
		$this->assertCount(2, $inserted);
		$this->assertSame('case', $inserted[0]->getRole());
		$this->assertSame(2, $inserted[0]->getRegisterId());

		$this->expectException(TaskValidationException::class);
		$this->service()->import(data: ['relations' => [['role' => '', 'objectUuid' => 'obj-1']]], actor: 'rita');
	}//end testRelationsAtCreationAreInsertedAndValidated()

	/**
	 * Intake refusals from the builder, each naming what was wrong: an
	 * unparsable date, a malformed checklist item, an unknown performer type.
	 *
	 * @return void
	 */
	public function testIntakeRefusalsAreNamed(): void {
		$service = $this->service();
		$cases = [
			[['dueAt' => 'not a date'], 'dueAt'],
			[['checklist' => [['label' => 'no id']]], 'id and a label'],
			[['checklist' => 'yes'], 'string containing JSON'],
			[['checklist' => 42], 'typed array'],
			[['performerType' => 'robot'], "'robot'"],
			[['priority' => 'normaal'], "'normaal'"],
		];
		foreach ($cases as [$data, $named]) {
			try {
				$service->import(data: $data, actor: 'rita');
				$this->fail('Accepted: ' . json_encode($data));
			} catch (TaskValidationException $refused) {
				$this->assertStringContainsString($named, $refused->getMessage());
			}
		}
	}//end testIntakeRefusalsAreNamed()

	/**
	 * Creation carries every stored-but-uninterpreted column through
	 * unchanged (the round-trip the design demands for the timer columns),
	 * accepts a DateTime as well as an ISO string, and stamps the creator.
	 *
	 * @return void
	 */
	public function testCreationRoundTripsTheStoredButUninterpretedColumns(): void {
		$start = new \DateTime('2026-09-02T09:00:00+00:00');
		$created = $this->service()->import(
			data: [
				'uuid' => 'fixed-uuid',
				'key' => 'EXT-1',
				'title' => 'T',
				'description' => 'D',
				'metadata' => ['x' => 1],
				'runUuid' => 'run-1',
				'nodeId' => 'node-1',
				'definitionVersion' => 4,
				'appId' => 'dossiq',
				'workflowStepId' => 'step-1',
				'organisation' => 'org-1',
				'startAt' => $start,
				'suspendedUntil' => '2026-09-03T09:00:00+00:00',
				'slaValue' => 5,
				'slaUnit' => 'days',
				'compliancePeriodDays' => 30,
				'recurrence' => 'FREQ=WEEKLY',
				'watchers' => ['w1'],
				'parentTaskId' => 1,
				'epicTaskId' => 2,
				'percentComplete' => 10,
				'responses' => [['a' => 1]],
				'evidence' => [['file' => 9]],
				'outcome' => 'custom',
				'state' => 'in-progress',
			],
			actor: 'rita'
		);

		$this->assertSame('fixed-uuid', $created->getUuid());
		$this->assertSame('EXT-1', $created->getTaskKey());
		$this->assertSame(4, $created->getDefinitionVersion());
		$this->assertSame($start, $created->getStartAt());
		$this->assertSame('2026-09-03T09:00:00+00:00', $created->getSuspendedUntil()?->format('c'));
		$this->assertSame(5, $created->getSlaValue());
		$this->assertSame('days', $created->getSlaUnit());
		$this->assertSame(30, $created->getCompliancePeriodDays());
		$this->assertSame('FREQ=WEEKLY', $created->getRecurrence());
		$this->assertSame(['w1'], $created->getWatchers());
		$this->assertSame(2, $created->getEpicTaskId());
		$this->assertSame(10, $created->getPercentComplete());
		// An explicit outcome wins over the mapping's; the state still maps.
		$this->assertSame('custom', $created->getOutcome());
		$this->assertSame(Task::STATE_ACTIVE, $created->getState());
		$this->assertSame('rita', $created->getCreatedBy());
		$this->assertSame('create', $created->getLastAction());
	}//end testCreationRoundTripsTheStoredButUninterpretedColumns()

	/**
	 * A checklist item that does not exist is refused by id.
	 *
	 * @return void
	 */
	public function testAMissingChecklistItemIsRefusedById(): void {
		$task = $this->openTask();
		$task->setChecklist([['id' => 'c1', 'label' => 'Een', 'description' => null, 'checked' => false]]);
		$this->tasks->method('findByUuid')->willReturn($task);

		$this->expectException(TaskValidationException::class);
		$this->expectExceptionMessage("'c9'");
		$this->service()->checkChecklistItem(uuid: 't-7', itemId: 'c9', checked: true, actor: 'alice');
	}//end testAMissingChecklistItemIsRefusedById()

	/**
	 * A denial on a task with no id (creation) is rethrown without an audit
	 * row, and a failing denial-audit write does not change the denial.
	 *
	 * @return void
	 */
	public function testDenialAuditFailuresNeverChangeTheDenial(): void {
		$this->authorization->method('assertMay')->willThrowException(new TaskAccessDeniedException('no identity'));
		$this->audits->expects($this->never())->method('insert');
		try {
			$this->service()->import(data: [], actor: null);
			$this->fail('Created without an identity.');
		} catch (TaskAccessDeniedException) {
			// Expected: nothing to audit against yet.
		}

		$this->setUp();
		$this->tasks->method('findByUuid')->willReturn($this->openTask());
		$this->authorization->method('assertMay')->willThrowException(new TaskAccessDeniedException('denied'));
		$this->audits->method('insert')->willThrowException(new RuntimeException('audit down'));

		$this->expectException(TaskAccessDeniedException::class);
		$this->service()->cancel(uuid: 't-7', reason: null, actor: 'mallory');
	}//end testDenialAuditFailuresNeverChangeTheDenial()

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
