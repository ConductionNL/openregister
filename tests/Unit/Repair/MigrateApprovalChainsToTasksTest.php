<?php

/**
 * The approval data migration over a seeded (in-memory) database: every
 * in-flight approval survives at the same ordinal, decided steps keep their
 * decider, a second run changes nothing, and a set that cannot reconcile
 * FAILS LOUDLY naming the chain, the object and the step
 * (flow-approval-consolidation tasks 6.1, 6.2, 8.2).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskCandidateMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Db\TaskSequenceMapper;
use OCA\OpenRegister\Repair\MigrateApprovalChainsToTasks;
use OCA\OpenRegister\Service\Task\TaskBuilder;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Repair\MigrateApprovalChainsToTasks
 */
class MigrateApprovalChainsToTasksTest extends TestCase {

	/**
	 * The in-memory legacy tables.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $chains = [];
	private array $steps = [];

	/**
	 * The in-memory task and sequence stores.
	 *
	 * @var array<string, Task>
	 */
	private array $tasks = [];

	/**
	 * @var array<string, TaskSequence>
	 */
	private array $sequences = [];

	/**
	 * @var array<int, TaskAudit>
	 */
	private array $audits = [];

	private int $nextTaskId = 1;

	protected function setUp(): void {
		parent::setUp();
		$this->chains = [
			[
				'id' => 10,
				'name' => 'submit-approval',
				'schema_id' => 5,
				'steps' => json_encode(
					[
						['order' => 1, 'role' => 'clerks'],
						['order' => 2, 'role' => 'managers'],
						['order' => 3, 'role' => 'directors'],
					]
				),
			],
		];
		$this->steps = [
			// Object A: in flight — 1 approved, 2 pending, 3 waiting.
			['id' => 1, 'chain_id' => 10, 'object_uuid' => 'obj-a', 'step_order' => 1, 'role' => 'clerks', 'status' => 'approved', 'decided_by' => 'alice', 'comment' => 'fine by me', 'decided_at' => '2026-08-01 09:00:00', 'created' => '2026-07-30 08:00:00', 'requester_id' => 'bob', 'migrated_task_uuid' => null],
			['id' => 2, 'chain_id' => 10, 'object_uuid' => 'obj-a', 'step_order' => 2, 'role' => 'managers', 'status' => 'pending', 'decided_by' => null, 'comment' => null, 'decided_at' => null, 'created' => '2026-07-30 08:00:00', 'requester_id' => 'bob', 'migrated_task_uuid' => null],
			['id' => 3, 'chain_id' => 10, 'object_uuid' => 'obj-a', 'step_order' => 3, 'role' => 'directors', 'status' => 'waiting', 'decided_by' => null, 'comment' => null, 'decided_at' => null, 'created' => '2026-07-30 08:00:00', 'requester_id' => 'bob', 'migrated_task_uuid' => null],
			// Object B: rejected by a decider who has since left.
			['id' => 4, 'chain_id' => 10, 'object_uuid' => 'obj-b', 'step_order' => 1, 'role' => 'clerks', 'status' => 'rejected', 'decided_by' => 'ghost-user', 'comment' => 'no', 'decided_at' => '2026-08-02 10:00:00', 'created' => '2026-08-01 08:00:00', 'requester_id' => 'carol', 'migrated_task_uuid' => null],
		];
	}//end setUp()

	/**
	 * The repair step wired onto the in-memory stores.
	 */
	private function step(): MigrateApprovalChainsToTasks {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(function (string $sql) {
			$rows = [];
			if (str_contains($sql, 'approval_chains') === true) {
				$rows = $this->chains;
			} elseif (str_contains($sql, 'approval_steps') === true && str_contains($sql, 'chain_id') === true) {
				$rows = $this->steps;
			} elseif (str_contains($sql, 'approval_steps') === true) {
				$rows = $this->steps;
			} elseif (str_contains($sql, 'task_sequences') === true) {
				$rows = $this->duplicateRunningRows();
			} elseif (str_contains($sql, 'openregister_tasks') === true) {
				$rows = [['n' => $this->enabledMigratedCount()]];
			}

			$result = $this->createMock(IResult::class);
			$result->method('fetchAll')->willReturn($rows);

			return $result;
		});
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->updateBuilder());

		$tasks = $this->createMock(TaskMapper::class);
		$tasks->method('insert')->willReturnCallback(function (Task $task): Task {
			$task->setId($this->nextTaskId++);
			$this->tasks[(string)$task->getUuid()] = $task;

			return $task;
		});
		$tasks->method('findByUuid')->willReturnCallback(function (string $uuid): Task {
			if (isset($this->tasks[$uuid]) === false) {
				throw new DoesNotExistException('no task ' . $uuid);
			}

			return $this->tasks[$uuid];
		});

		$sequences = $this->createMock(TaskSequenceMapper::class);
		$sequences->method('insert')->willReturnCallback(function (TaskSequence $sequence): TaskSequence {
			$this->sequences[(string)$sequence->getUuid()] = $sequence;

			return $sequence;
		});
		$sequences->method('findByUuid')->willReturnCallback(function (string $uuid): TaskSequence {
			if (isset($this->sequences[$uuid]) === false) {
				throw new DoesNotExistException('no sequence ' . $uuid);
			}

			return $this->sequences[$uuid];
		});

		$audits = $this->createMock(TaskAuditMapper::class);
		$audits->method('insert')->willReturnCallback(function (TaskAudit $entry): TaskAudit {
			$this->audits[] = $entry;

			return $entry;
		});

		return new MigrateApprovalChainsToTasks(
			db: $db,
			builder: new TaskBuilder(),
			tasks: $tasks,
			candidates: $this->createMock(TaskCandidateMapper::class),
			audits: $audits,
			sequences: $sequences,
			config: $this->createMock(IAppConfig::class),
			logger: new NullLogger()
		);
	}//end step()

	/**
	 * A minimal UPDATE builder: applies `SET migrated_task_uuid` (or any
	 * column) on the in-memory step row the WHERE id names.
	 */
	private function updateBuilder(): IQueryBuilder {
		$state = new \stdClass();
		$state->sets = [];
		$state->id = null;

		$builder = $this->createMock(IQueryBuilder::class);
		$builder->method('update')->willReturnSelf();
		$builder->method('createNamedParameter')->willReturnArgument(0);
		$builder->method('set')->willReturnCallback(function (string $column, $value) use ($builder, $state) {
			$state->sets[$column] = $value;

			return $builder;
		});
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(function (string $column, $value) use ($state): string {
			if ($column === 'id') {
				$state->id = (int)$value;
			}

			return $column . ' = ' . (string)$value;
		});
		$builder->method('expr')->willReturn($expr);
		$builder->method('where')->willReturnSelf();
		$builder->method('executeStatement')->willReturnCallback(function () use ($state): int {
			foreach ($this->steps as $index => $row) {
				if ((int)$row['id'] === $state->id) {
					foreach ($state->sets as $column => $value) {
						$this->steps[$index][$column] = $value;
					}
				}
			}

			return 1;
		});

		return $builder;
	}//end updateBuilder()

	/**
	 * Anchors holding more than one running sequence, grouped.
	 */
	private function duplicateRunningRows(): array {
		$byAnchor = [];
		foreach ($this->sequences as $sequence) {
			if ((string)$sequence->getStatus() !== TaskSequence::STATUS_RUNNING) {
				continue;
			}

			$key = (string)$sequence->getAnchorObjectUuid() . '|' . (string)$sequence->getTemplateId();
			$byAnchor[$key] = (($byAnchor[$key] ?? 0) + 1);
		}

		$rows = [];
		foreach ($byAnchor as $key => $count) {
			if ($count > 1) {
				[$anchor, $template] = explode('|', $key);
				$rows[] = ['anchor_object_uuid' => $anchor, 'template_id' => $template, 'n' => $count];
			}
		}

		return $rows;
	}//end duplicateRunningRows()

	private function enabledMigratedCount(): int {
		$count = 0;
		foreach ($this->tasks as $task) {
			if ($task->getLegacyStepId() !== null && (string)$task->getState() === Task::STATE_ENABLED) {
				$count++;
			}
		}

		return $count;
	}//end enabledMigratedCount()

	private function taskForStep(int $stepId): ?Task {
		foreach ($this->steps as $row) {
			if ((int)$row['id'] === $stepId) {
				return ($this->tasks[trim((string)($row['migrated_task_uuid'] ?? ''))] ?? null);
			}
		}

		return null;
	}//end taskForStep()

	public function testEveryInFlightApprovalSurvivesAtItsOrdinal(): void {
		$output = $this->createMock(IOutput::class);
		$this->step()->run($output);

		self::assertCount(4, $this->tasks, 'one task per step');
		self::assertCount(2, $this->sequences, 'one sequence per (chain, object)');

		$pending = $this->taskForStep(2);
		self::assertSame(Task::STATE_ENABLED, $pending->getState(), 'the pending step is THE enabled position');
		self::assertSame(2, $pending->getSequencePosition());
		self::assertSame(['managers'], $pending->getCandidateGroups());
		self::assertSame('bob', $pending->getRequester());
		self::assertSame('2026-07-30 08:00:00', $pending->getCreated()->format('Y-m-d H:i:s'), 'creation time preserved');

		$waiting = $this->taskForStep(3);
		self::assertSame(Task::STATE_AVAILABLE, $waiting->getState(), 'a waiting step is created and NOT enabled');

		$approved = $this->taskForStep(1);
		self::assertSame(Task::STATE_COMPLETED, $approved->getState());
		self::assertSame('approved', $approved->getOutcome());
		self::assertSame('alice', $approved->getCompletedBy(), 'the ORIGINAL decider, not the migrator');

		$sequenceA = $this->sequences[(string)$pending->getSequenceUuid()];
		self::assertSame(TaskSequence::STATUS_RUNNING, $sequenceA->getStatus());
		self::assertSame(2, $sequenceA->getPositionCursor());

		$rejected = $this->taskForStep(4);
		$sequenceB = $this->sequences[(string)$rejected->getSequenceUuid()];
		self::assertSame(TaskSequence::STATUS_REJECTED, $sequenceB->getStatus(), 'a rejected set migrates terminal, never as work');
	}//end testEveryInFlightApprovalSurvivesAtItsOrdinal()

	public function testADepartedDeciderKeepsItsRecordedIdentity(): void {
		$this->step()->run($this->createMock(IOutput::class));

		$byGhost = array_filter(
			$this->audits,
			static fn (TaskAudit $entry): bool => $entry->getActor() === 'ghost-user'
		);
		self::assertCount(1, $byGhost, 'the decision audit keeps the recorded identity string');
		$entry = array_values($byGhost)[0];
		self::assertStringContainsString('[migrated decision] no', (string)$entry->getReason());
		self::assertSame('2026-08-02 10:00:00', $entry->getCreated()->format('Y-m-d H:i:s'), 'the original decision time');
	}//end testADepartedDeciderKeepsItsRecordedIdentity()

	public function testRunningTheMigrationTwiceChangesNothing(): void {
		$this->step()->run($this->createMock(IOutput::class));
		$tasksAfterFirst = count($this->tasks);
		$sequencesAfterFirst = count($this->sequences);
		$auditsAfterFirst = count($this->audits);

		$this->step()->run($this->createMock(IOutput::class));

		self::assertSame($tasksAfterFirst, count($this->tasks));
		self::assertSame($sequencesAfterFirst, count($this->sequences));
		self::assertSame($auditsAfterFirst, count($this->audits));
	}//end testRunningTheMigrationTwiceChangesNothing()

	public function testAnUnreconcilableStepFailsLoudlyNamingIt(): void {
		// A reconciliation column pointing at a task that does not exist:
		// exactly the partial state the verification exists to refuse.
		$this->steps[1]['migrated_task_uuid'] = 'ghost-task-uuid';

		try {
			$this->step()->run($this->createMock(IOutput::class));
			self::fail('the verification must stop the migration');
		} catch (RuntimeException $failure) {
			self::assertStringContainsString('chain 10', $failure->getMessage());
			self::assertStringContainsString('obj-a', $failure->getMessage());
			self::assertStringContainsString('step 2', $failure->getMessage());
			self::assertStringContainsString('did NOT succeed', $failure->getMessage());
		}
	}//end testAnUnreconcilableStepFailsLoudlyNamingIt()

	public function testAMissingLegacyTableMeansNothingToMigrate(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willThrowException(new RuntimeException('no such table'));
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('nothing to migrate'));

		$step = new MigrateApprovalChainsToTasks(
			db: $db,
			builder: new TaskBuilder(),
			tasks: $this->createMock(TaskMapper::class),
			candidates: $this->createMock(TaskCandidateMapper::class),
			audits: $this->createMock(TaskAuditMapper::class),
			sequences: $this->createMock(TaskSequenceMapper::class),
			config: $this->createMock(IAppConfig::class),
			logger: new NullLogger()
		);
		$step->run($output);
	}//end testAMissingLegacyTableMeansNothingToMigrate()
}//end class
