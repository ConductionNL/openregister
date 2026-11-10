<?php

/**
 * The portal seam's two narrow windows into the task service, and the
 * completion fields it added: openFor authorizes and audits before any work,
 * record grows the trail without moving the task, and a completion may carry
 * the submitted answers and the stored file references.
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
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Task\TaskAct;
use OCA\OpenRegister\Service\Task\TaskAnswer;
use OCA\OpenRegister\Service\Task\TaskAnswerPayload;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskBuilder;
use OCA\OpenRegister\Service\Task\TaskCommit;
use OCA\OpenRegister\Service\Task\TaskIntake;
use OCA\OpenRegister\Service\Task\TaskLifecycleServices;
use OCA\OpenRegister\Service\Task\TaskPerformerResolver;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Task\TaskTables;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use OCA\OpenRegister\Service\Flow\TaskAuditEntry;

/**
 * Tests for {@see TaskService::openFor()}, {@see TaskService::record()} and
 * the completion's responses and evidence.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskService
 * @covers \OCA\OpenRegister\Service\Flow\FlowTaskBridge
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Db\TaskAudit
 * @uses \OCA\OpenRegister\Service\Task\TaskState
 */
class TaskServicePortalWindowsTest extends TestCase {

	/**
	 * The task table, mocked.
	 *
	 * @var TaskMapper&MockObject
	 */
	private TaskMapper&MockObject $tasks;

	/**
	 * The audit rows, mocked.
	 *
	 * @var TaskAuditMapper&MockObject
	 */
	private TaskAuditMapper&MockObject $audits;

	/**
	 * Authorization, mocked.
	 *
	 * @var TaskAuthorizationService&MockObject
	 */
	private TaskAuthorizationService&MockObject $authorization;

	/**
	 * The service under test.
	 *
	 * @var TaskService
	 */
	private TaskService $service;

	/**
	 * Happy plumbing: the row exists, updates go through, audits insert.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->audits = $this->createMock(TaskAuditMapper::class);
		$this->authorization = $this->createMock(TaskAuthorizationService::class);
		$this->tasks->method('updateIfOpen')->willReturn(true);
		$this->audits->method('insert')->willReturnArgument(0);

		$this->service = new TaskService(
			authorization: $this->authorization,
			services: new TaskLifecycleServices(
				tables: new TaskTables(
					tasks: $this->tasks,
					candidates: $this->createMock(TaskCandidateMapper::class),
					relations: $this->createMock(TaskRelationMapper::class),
					audits: $this->audits
				),
				commit: new TaskCommit(
					db: $this->createMock(IDBConnection::class),
					logger: new NullLogger()
				),
				intake: new TaskIntake(
					builder: new TaskBuilder()
				),
				resolver: $this->createMock(TaskPerformerResolver::class)
			)
		);
	}//end setUp()

	/**
	 * An open external task.
	 *
	 * @return Task The task.
	 */
	private function task(): Task {
		$task = new Task();
		$task->setId(7);
		$task->setUuid('t-1');
		$task->setState(Task::STATE_ACTIVE);
		$task->setIsTerminal(false);
		$task->setPerformerType(Task::PERFORMER_EXTERNAL);
		$task->setAssignee('party:bsn-1');

		return $task;
	}//end task()

	/**
	 * openFor returns the open, authorized task without mutating it.
	 *
	 * @return void
	 */
	public function testOpenForReturnsTheOpenAuthorizedTask(): void {
		$task = $this->task();
		$this->tasks->method('findByUuid')->willReturn($task);
		$this->authorization->expects($this->once())->method('assertMay')->with(new TaskAct(verb: 'complete', actor: 'party:bsn-1'), $task);

		$this->assertSame($task, $this->service->openFor(act: new TaskAct(verb: 'complete', actor: 'party:bsn-1'), uuid: 't-1'));
		$this->assertSame(Task::STATE_ACTIVE, $task->getState());
	}//end testOpenForReturnsTheOpenAuthorizedTask()

	/**
	 * A denial through openFor is AUDITED as unauthorized, then rethrown.
	 *
	 * @return void
	 */
	public function testOpenForAuditsADenialBeforeRethrowingIt(): void {
		$task = $this->task();
		$this->tasks->method('findByUuid')->willReturn($task);
		$this->authorization->method('assertMay')->willThrowException(
			new TaskAccessDeniedException("Verb 'complete' denied: only the matched portal subject may answer an external task.")
		);
		$audited = null;
		$this->audits->expects($this->once())->method('insert')->willReturnCallback(
			static function (TaskAudit $entry) use (&$audited): TaskAudit {
				$audited = $entry;

				return $entry;
			}
		);

		try {
			$this->service->openFor(act: new TaskAct(verb: 'complete', actor: 'party:bsn-2'), uuid: 't-1');
			$this->fail('The denial was swallowed.');
		} catch (TaskAccessDeniedException) {
			$this->addToAssertionCount(1);
		}

		$this->assertNotNull($audited);
		$this->assertFalse($audited->getAuthorized());
		$this->assertSame('complete', $audited->getAction());
		$this->assertSame('party:bsn-2', $audited->getActor());
		$this->assertSame(Task::PERFORMER_EXTERNAL, $audited->getPerformerType());
	}//end testOpenForAuditsADenialBeforeRethrowingIt()

	/**
	 * A terminal task conflicts through openFor, exactly as through a verb.
	 *
	 * @return void
	 */
	public function testOpenForConflictsOnATerminalTask(): void {
		$task = $this->task();
		$task->setState(Task::STATE_COMPLETED);
		$task->setIsTerminal(true);
		$this->tasks->method('findByUuid')->willReturn($task);

		$this->expectException(TaskConflictException::class);
		$this->service->openFor(act: new TaskAct(verb: 'complete', actor: 'party:bsn-1'), uuid: 't-1');
	}//end testOpenForConflictsOnATerminalTask()

	/**
	 * record grows the audit trail with the fact, and moves nothing.
	 *
	 * @return void
	 */
	public function testRecordAppendsTheFactWithoutMovingTheTask(): void {
		$task = $this->task();
		$this->tasks->method('findByUuid')->willReturn($task);
		$this->tasks->expects($this->never())->method('updateIfOpen');
		$audited = null;
		$this->audits->expects($this->once())->method('insert')->willReturnCallback(
			static function (TaskAudit $entry) use (&$audited): TaskAudit {
				$audited = $entry;

				return $entry;
			}
		);

		$returned = $this->service->record(uuid: 't-1', action: 'match', actor: 'caseworker', reason: "Matched role 'initiator' to 'party:bsn-1'.");
		$this->assertSame($task, $returned);
		$this->assertSame(Task::STATE_ACTIVE, $task->getState());
		$this->assertSame('match', $audited->getAction());
		$this->assertTrue($audited->getAuthorized());
		$this->assertStringContainsString('initiator', (string)$audited->getReason());
	}//end testRecordAppendsTheFactWithoutMovingTheTask()

	/**
	 * A completion may carry the answers and the stored file references, and
	 * both land on the task; without them nothing is overwritten.
	 *
	 * @return void
	 */
	public function testCompleteCarriesResponsesAndEvidence(): void {
		$task = $this->task();
		$task->setResponses(['kept' => true]);
		$this->tasks->method('findByUuid')->willReturn($task);

		$completed = $this->service->completeWithAnswer(
			uuid: 't-1',
			answer: new TaskAnswer(
				outcome: 'submitted',
				payload: new TaskAnswerPayload(responses: ['remarks' => 'here'], evidence: [['fileId' => 42, 'name' => 'payslip.pdf']])
			),
			actor: 'party:bsn-1'
		);
		$this->assertSame(['remarks' => 'here'], $completed->getResponses());
		$this->assertSame(42, $completed->getEvidence()[0]['fileId']);
		$this->assertSame(Task::STATE_COMPLETED, $completed->getState());

		// A second, fresh service: a mock's first findByUuid expectation is
		// not overridable, and the first task is terminal by now.
		$this->setUp();
		$again = $this->task();
		$again->setResponses(['kept' => true]);
		$again->setEvidence([['fileId' => 1]]);
		$this->tasks->method('findByUuid')->willReturn($again);
		$plain = $this->service->completeWithAnswer(uuid: 't-1', answer: new TaskAnswer(outcome: 'done'), actor: 'party:bsn-1');
		$this->assertSame(['kept' => true], $plain->getResponses(), 'absent responses overwrite nothing');
		$this->assertSame([['fileId' => 1]], $plain->getEvidence(), 'absent evidence overwrites nothing');
	}//end testCompleteCarriesResponsesAndEvidence()

	/**
	 * The bridge's record window delegates to the service, so the node needs
	 * no task-service dependency of its own.
	 *
	 * @return void
	 */
	public function testTheBridgeRecordWindowDelegates(): void {
		$tasks = $this->createMock(TaskService::class);
		$tasks->expects($this->once())
			->method('record')
			->with('t-1', 'match', 'caseworker', 'the reason')
			->willReturn($this->task());

		$bridge = new FlowTaskBridge(
			tasks: $tasks,
			runs: $this->createMock(\OCA\OpenRegister\Db\FlowRunMapper::class),
			container: $this->createMock(\Psr\Container\ContainerInterface::class),
			logger: new NullLogger()
		);
		$bridge->record(entry: new TaskAuditEntry(uuid: 't-1', action: 'match', actor: 'caseworker', reason: 'the reason'));
	}//end testTheBridgeRecordWindowDelegates()
}//end class
