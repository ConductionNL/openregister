<?php

/**
 * The sequence's lifecycle verbs on TaskService: enable, consume, and the
 * separation-of-duties guard running BEFORE the performer check
 * (flow-approval-consolidation tasks 2.1, 2.2 and the HITL consumedAt home).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskCandidateMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskRelationMapper;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Db\TaskSequenceMapper;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Exception\TaskSeparationOfDutiesException;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskBuilder;
use OCA\OpenRegister\Service\Task\TaskPerformerResolver;
use OCA\OpenRegister\Service\Task\TaskSequenceDecisionGuard;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Task\TaskService
 * @covers \OCA\OpenRegister\Service\Task\TaskSequenceDecisionGuard
 */
class TaskSequenceLifecycleVerbsTest extends TestCase {

	private TaskMapper&MockObject $tasks;
	private TaskAuthorizationService&MockObject $authorization;
	private TaskSequenceMapper&MockObject $sequences;

	protected function setUp(): void {
		parent::setUp();
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->tasks->method('update')->willReturnArgument(0);
		$this->tasks->method('updateIfOpen')->willReturn(true);
		$this->authorization = $this->createMock(TaskAuthorizationService::class);
		$this->sequences = $this->createMock(TaskSequenceMapper::class);
	}//end setUp()

	private function service(): TaskService {
		$audits = $this->createMock(TaskAuditMapper::class);
		$audits->method('insert')->willReturnArgument(0);

		return new TaskService(
			tasks: $this->tasks,
			candidates: $this->createMock(TaskCandidateMapper::class),
			relations: $this->createMock(TaskRelationMapper::class),
			audits: $audits,
			authorization: $this->authorization,
			resolver: $this->createMock(TaskPerformerResolver::class),
			db: $this->createMock(IDBConnection::class),
			logger: new NullLogger(),
			builder: new TaskBuilder(),
			dispatcher: null,
			sequenceGuard: new TaskSequenceDecisionGuard(sequences: $this->sequences)
		);
	}//end service()

	private function task(string $state, ?string $outcome = null, ?array $metadata = null): Task {
		$task = new Task();
		$task->setId(7);
		$task->setUuid('t-7');
		$task->setState($state);
		$task->setIsTerminal(in_array($state, Task::TERMINAL_STATES, true));
		$task->setOutcome($outcome);
		$task->setMetadata($metadata);
		$task->setPerformerType(Task::PERFORMER_GROUP);
		$task->setAssignee('alice');
		$task->setSequenceUuid('seq-1');

		return $task;
	}//end task()

	private function sequenceRequestedBy(?string $requester): void {
		$sequence = new TaskSequence();
		$sequence->setUuid('seq-1');
		$sequence->setRequesterId($requester);
		$sequence->setTemplateSnapshot(['name' => 'submit-approval']);
		$this->sequences->method('findByUuid')->willReturn($sequence);
	}//end sequenceRequestedBy()

	public function testEnableMovesAnAvailablePositionToEnabled(): void {
		$this->tasks->method('findByUuid')->willReturn($this->task(Task::STATE_AVAILABLE));

		$enabled = $this->service()->enable(uuid: 't-7', source: 'task-sequence:seq-1', reason: 'position 1 approved');

		self::assertSame(Task::STATE_ENABLED, $enabled->getState());
		self::assertSame('enable', $enabled->getLastAction());
	}//end testEnableMovesAnAvailablePositionToEnabled()

	public function testEnableIsIdempotentOnAnEnabledTask(): void {
		$this->tasks->method('findByUuid')->willReturn($this->task(Task::STATE_ENABLED));
		$this->tasks->expects(self::never())->method('updateIfOpen');

		$enabled = $this->service()->enable(uuid: 't-7', source: 's', reason: 'again');

		self::assertSame(Task::STATE_ENABLED, $enabled->getState());
	}//end testEnableIsIdempotentOnAnEnabledTask()

	public function testEnableRefusesATerminalTaskNamingItsState(): void {
		$this->tasks->method('findByUuid')->willReturn($this->task(Task::STATE_TERMINATED, 'cancelled'));

		$this->expectException(TaskConflictException::class);
		$this->expectExceptionMessage("terminal state 'terminated'");
		$this->service()->enable(uuid: 't-7', source: 's', reason: 'r');
	}//end testEnableRefusesATerminalTaskNamingItsState()

	public function testDecidingAnAlreadyTerminalTaskIsRefusedNamingTheState(): void {
		$this->sequenceRequestedBy(requester: null);
		$this->tasks->method('findByUuid')->willReturn($this->task(Task::STATE_COMPLETED, 'approved'));

		$this->expectException(TaskConflictException::class);
		$this->expectExceptionMessage("terminal state 'completed'");
		$this->service()->complete(uuid: 't-7', outcome: 'approved', resultText: null, comment: null, actor: 'bob');
	}//end testDecidingAnAlreadyTerminalTaskIsRefusedNamingTheState()

	public function testTheRequesterIsRefusedBeforeThePerformerCheck(): void {
		$this->sequenceRequestedBy(requester: 'alice');
		$this->tasks->method('findByUuid')->willReturn($this->task(Task::STATE_ENABLED));

		// The performer check must never run: the SoD refusal comes first so
		// the reason is honest even for a fully authorized requester.
		$this->authorization->expects(self::never())->method('assertMay');

		$this->expectException(TaskSeparationOfDutiesException::class);
		$this->service()->complete(uuid: 't-7', outcome: 'approved', resultText: null, comment: null, actor: 'alice');
	}//end testTheRequesterIsRefusedBeforeThePerformerCheck()

	public function testANonRequesterPassesTheGuardAndCompletes(): void {
		$this->sequenceRequestedBy(requester: 'alice');
		$this->tasks->method('findByUuid')->willReturn($this->task(Task::STATE_ENABLED));

		$completed = $this->service()->complete(uuid: 't-7', outcome: 'approved', resultText: null, comment: null, actor: 'bob');

		self::assertSame(Task::STATE_COMPLETED, $completed->getState());
		self::assertSame('approved', $completed->getOutcome());
	}//end testANonRequesterPassesTheGuardAndCompletes()

	public function testARejectionWithoutACommentIsRefused(): void {
		$this->sequenceRequestedBy(requester: 'alice');
		$this->tasks->method('findByUuid')->willReturn($this->task(Task::STATE_ENABLED));

		try {
			$this->service()->complete(uuid: 't-7', outcome: 'rejected', resultText: null, comment: '  ', actor: 'bob');
			self::fail('a comment-less rejection must be refused');
		} catch (\OCA\OpenRegister\Exception\TaskValidationException $refusal) {
			self::assertStringContainsString('comment is mandatory', $refusal->getMessage());
		}
	}//end testARejectionWithoutACommentIsRefused()

	public function testConsumeRecordsTheConsumptionOnce(): void {
		$this->tasks->method('findByUuid')->willReturn($this->task(Task::STATE_COMPLETED, 'approved'));

		$consumed = $this->service()->consume(uuid: 't-7', source: 'sync-run:s-1', reason: 'batch 42 relied on it');

		self::assertSame('sync-run:s-1', $consumed->getMetadata()['consumedBy']);
		self::assertNotSame('', (string)$consumed->getMetadata()['consumedAt']);
	}//end testConsumeRecordsTheConsumptionOnce()

	public function testAConsumedApprovalCannotAuthorizeTwice(): void {
		$this->tasks->method('findByUuid')->willReturn(
			$this->task(Task::STATE_COMPLETED, 'approved', metadata: ['consumedAt' => '2026-09-01T10:00:00+02:00', 'consumedBy' => 'sync-run:s-1'])
		);

		$this->expectException(TaskConflictException::class);
		$this->expectExceptionMessage('authorizes exactly once');
		$this->service()->consume(uuid: 't-7', source: 'sync-run:s-2', reason: 'a later run');
	}//end testAConsumedApprovalCannotAuthorizeTwice()

	public function testConsumeRefusesARejectedDecision(): void {
		$this->tasks->method('findByUuid')->willReturn($this->task(Task::STATE_COMPLETED, 'rejected'));

		$this->expectException(TaskConflictException::class);
		$this->expectExceptionMessage('not an approving decision');
		$this->service()->consume(uuid: 't-7', source: 'sync-run:s-1', reason: 'r');
	}//end testConsumeRefusesARejectedDecision()

	public function testConsumeRefusesAnOpenTask(): void {
		$this->tasks->method('findByUuid')->willReturn($this->task(Task::STATE_ENABLED));

		$this->expectException(TaskConflictException::class);
		$this->service()->consume(uuid: 't-7', source: 'sync-run:s-1', reason: 'r');
	}//end testConsumeRefusesAnOpenTask()
}//end class
