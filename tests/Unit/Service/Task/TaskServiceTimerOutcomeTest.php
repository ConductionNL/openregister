<?php

/**
 * A business timer's four enforcing outcomes leave the task in four
 * DISTINCT observable states, through one code path that audits the timer as
 * actor; an unknown outcome is refused and an already-terminal task is left as
 * it ended.
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
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskCandidateMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskRelationMapper;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskBuilder;
use OCA\OpenRegister\Service\Task\TaskPerformerResolver;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Task\TaskService
 * @covers \OCA\OpenRegister\Db\Task
 * @covers \OCA\OpenRegister\Db\TaskAudit
 * @covers \OCA\OpenRegister\Service\Task\TaskState
 */
class TaskServiceTimerOutcomeTest extends TestCase {

	private TaskMapper&MockObject $tasks;

	private TaskAuditMapper&MockObject $audits;

	/**
	 * @var array<int, TaskAudit>
	 */
	private array $audited = [];

	protected function setUp(): void {
		parent::setUp();
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->audits = $this->createMock(TaskAuditMapper::class);
		$this->audits->method('insert')->willReturnCallback(function (TaskAudit $entry): TaskAudit {
			$this->audited[] = $entry;

			return $entry;
		});
	}//end setUp()

	private function service(): TaskService {
		return new TaskService(
			tasks: $this->tasks,
			candidates: $this->createMock(TaskCandidateMapper::class),
			relations: $this->createMock(TaskRelationMapper::class),
			audits: $this->audits,
			authorization: $this->createMock(TaskAuthorizationService::class),
			resolver: $this->createMock(TaskPerformerResolver::class),
			db: $this->createMock(IDBConnection::class),
			logger: new NullLogger(),
			builder: new TaskBuilder()
		);
	}//end service()

	private function openTask(string $uuid): Task {
		$task = new Task();
		$task->setId(7);
		$task->setUuid($uuid);
		$task->setState(Task::STATE_ACTIVE);
		$task->setIsTerminal(false);
		$task->setPerformerType(Task::PERFORMER_USER);
		$task->setAssignee('alice');

		return $task;
	}//end openTask()

	public function testTheFourOutcomesLeaveFourDistinguishableStates(): void {
		$tasks = [];
		$this->tasks->method('findByUuid')->willReturnCallback(function (string $uuid) use (&$tasks): Task {
			$tasks[$uuid] = $this->openTask($uuid);

			return $tasks[$uuid];
		});
		$this->tasks->method('updateIfOpen')->willReturn(true);
		$service = $this->service();

		$results = [];
		foreach (['skip', 'error', 'dead_letter', 'transition:approve'] as $outcome) {
			$task = $service->applyTimerOutcome(uuid: 't-' . $outcome, outcome: $outcome, source: 'flow-timer:abc', reason: 'deadline reached');
			$results[$outcome] = $task->getState() . '/' . $task->getOutcome();
			self::assertTrue($task->getIsTerminal());
		}

		self::assertSame(
			['skip' => 'completed/skipped', 'error' => 'terminated/failed', 'dead_letter' => 'disabled/dead_letter', 'transition:approve' => 'completed/approve'],
			$results
		);
		self::assertCount(4, array_unique($results), 'skip and error are different outcomes');
		self::assertSame('flow-timer:abc', $tasks['t-skip']->getCompletedBy());
		self::assertNotNull($tasks['t-skip']->getCompletedAt());
		self::assertNull($tasks['t-error']->getCompletedAt(), 'a failure is not a completion');

		self::assertSame(['skip', 'error', 'dead_letter', 'approve'], array_map(static fn (TaskAudit $a): string => (string)$a->getAction(), $this->audited));
		self::assertSame('flow-timer:abc', $this->audited[0]->getActor());
		self::assertSame('deadline reached', $this->audited[0]->getReason());
	}//end testTheFourOutcomesLeaveFourDistinguishableStates()

	public function testAnAlreadyTerminalTaskIsLeftAsItEnded(): void {
		$done = $this->openTask('t-1');
		$done->setState(Task::STATE_COMPLETED);
		$done->setIsTerminal(true);
		$done->setOutcome('approved');
		$this->tasks->method('findByUuid')->willReturn($done);
		$this->tasks->expects(self::never())->method('updateIfOpen');

		$result = $this->service()->applyTimerOutcome(uuid: 't-1', outcome: 'error', source: 'flow-timer:abc', reason: 'x');
		self::assertSame('approved', $result->getOutcome());
		self::assertSame([], $this->audited);
	}//end testAnAlreadyTerminalTaskIsLeftAsItEnded()

	public function testUnknownAndMalformedOutcomesAreRefusedBeforeAnyRead(): void {
		$this->tasks->expects(self::never())->method('findByUuid');
		$service = $this->service();
		foreach (['expire', 'transition:', 'transition:skip', 'transition:' . str_repeat('x', 33)] as $bad) {
			try {
				$service->applyTimerOutcome(uuid: 't-1', outcome: $bad, source: 's', reason: 'r');
				self::fail('accepted ' . $bad);
			} catch (TaskValidationException $refused) {
				self::assertStringContainsString("'" . $bad . "'", $refused->getMessage());
			}
		}
	}//end testUnknownAndMalformedOutcomesAreRefusedBeforeAnyRead()

	public function testALostRaceSurfacesAsAConflict(): void {
		$this->tasks->method('findByUuid')->willReturn($this->openTask('t-1'));
		$this->tasks->method('updateIfOpen')->willReturn(false);
		$this->expectException(TaskConflictException::class);
		$this->service()->applyTimerOutcome(uuid: 't-1', outcome: 'skip', source: 's', reason: 'r');
	}//end testALostRaceSurfacesAsAConflict()
}//end class
