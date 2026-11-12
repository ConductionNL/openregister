<?php

/**
 * The reverse repair writes decisions back for what the legacy schema can
 * hold and REPORTS what it cannot carry (flow-approval-consolidation task
 * 6.3, design D-6).
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

use DateTime;
use OCA\OpenRegister\Command\RollbackApprovalMigrationCommand;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\OpenRegister\Command\RollbackApprovalMigrationCommand
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Service\Task\TaskState
 */
class RollbackApprovalMigrationCommandTest extends TestCase {

	/**
	 * The columns each executeStatement() applied, by step id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * Wire the command onto one migrated step row and one task.
	 *
	 * @param Task $task The migrated task the step points at.
	 * @param array<string, mixed> $stepOverrides Legacy row overrides.
	 */
	private function tester(Task $task, array $stepOverrides = []): CommandTester {
		$step = array_merge(
			[
				'id' => 2,
				'status' => 'pending',
				'decided_by' => null,
				'migrated_task_uuid' => 'task-2',
			],
			$stepOverrides
		);

		$db = $this->createMock(IDBConnection::class);
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([$step]);
		$db->method('executeQuery')->willReturn($result);
		$db->method('getQueryBuilder')->willReturnCallback(function () use ($step): IQueryBuilder {
			$builder = $this->createMock(IQueryBuilder::class);
			$builder->method('update')->willReturnSelf();
			$builder->method('createNamedParameter')->willReturnArgument(0);
			$sets = [];
			$builder->method('set')->willReturnCallback(function (string $column, $value) use ($builder, &$sets) {
				$sets[$column] = $value;

				return $builder;
			});
			$expr = $this->createMock(IExpressionBuilder::class);
			$expr->method('eq')->willReturn('id = 2');
			$builder->method('expr')->willReturn($expr);
			$builder->method('where')->willReturnSelf();
			$builder->method('executeStatement')->willReturnCallback(function () use (&$sets, $step): int {
				$this->written[(int)$step['id']] = $sets;

				return 1;
			});

			return $builder;
		});

		$tasks = $this->createMock(TaskMapper::class);
		$tasks->method('findByUuid')->willReturn($task);

		return new CommandTester(new RollbackApprovalMigrationCommand(db: $db, tasks: $tasks));
	}//end tester()

	private function decidedTask(): Task {
		$task = new Task();
		$task->setUuid('task-2');
		$task->setState(Task::STATE_COMPLETED);
		$task->setIsTerminal(true);
		$task->setOutcome('approved');
		$task->setCompletedBy('deputy');
		$task->setCompletedAt(new DateTime('2026-09-01 12:00:00'));
		$task->setComment('signed off');
		$task->setPerformerType(Task::PERFORMER_AGENT);
		$task->setOnBehalfOf('manager1');
		$task->setMandate('mandate-7');

		return $task;
	}//end decidedTask()

	public function testADecisionIsWrittenBackAndTheLostFactsAreReported(): void {
		$tester = $this->tester(task: $this->decidedTask());
		$tester->execute([]);

		self::assertSame('approved', $this->written[2]['status']);
		self::assertSame('deputy', $this->written[2]['decided_by']);
		self::assertSame('signed off', $this->written[2]['comment']);

		$display = $tester->getDisplay();
		self::assertStringContainsString('1 decision(s) written back', $display);
		self::assertStringContainsString('cannot hold the following facts', $display);
		self::assertStringContainsString("performer type 'agent'", $display);
		self::assertStringContainsString("on-behalf-of identity 'manager1'", $display);
		self::assertStringContainsString("mandate 'mandate-7'", $display);
		self::assertStringContainsString('per-entry audit trail', $display);
		self::assertStringContainsString('not dropped', $display);
	}//end testADecisionIsWrittenBackAndTheLostFactsAreReported()

	public function testDryRunReportsWithoutWriting(): void {
		$tester = $this->tester(task: $this->decidedTask());
		$tester->execute(['--dry-run' => true]);

		self::assertSame([], $this->written, 'no row was touched');
		self::assertStringContainsString('would be written back', $tester->getDisplay());
	}//end testDryRunReportsWithoutWriting()

	public function testAnUndecidedTaskLeavesTheStepAlone(): void {
		$open = new Task();
		$open->setUuid('task-2');
		$open->setState(Task::STATE_ENABLED);
		$open->setIsTerminal(false);

		$tester = $this->tester(task: $open);
		$tester->execute([]);

		self::assertSame([], $this->written);
		self::assertStringContainsString('0 decision(s) written back', $tester->getDisplay());
	}//end testAnUndecidedTaskLeavesTheStepAlone()
	/**
	 * Wire the command onto a database whose legacy query, or task lookup, throws.
	 *
	 * @param boolean $tablesGone Whether the legacy step query throws.
	 *
	 * @return CommandTester
	 */
	private function failingTester(bool $tablesGone): CommandTester {
		$db = $this->createMock(IDBConnection::class);
		if ($tablesGone === true) {
			$db->method('executeQuery')->willThrowException(new RuntimeException('no such table'));
		} else {
			$result = $this->createMock(IResult::class);
			$result->method('fetchAll')->willReturn([['id' => 5, 'status' => 'pending', 'decided_by' => null, 'migrated_task_uuid' => ' task-5 ']]);
			$db->method('executeQuery')->willReturn($result);
		}

		$db->expects(self::never())->method('getQueryBuilder');

		$tasks = $this->createMock(TaskMapper::class);
		$tasks->method('findByUuid')->willThrowException(new RuntimeException('gone'));

		return new CommandTester(new RollbackApprovalMigrationCommand(db: $db, tasks: $tasks));
	}//end failingTester()

	public function testTheFullReportOfADecisionWrittenBackIsPinned(): void {
		$tester = $this->tester(task: $this->decidedTask());
		$exit = $tester->execute([]);

		self::assertSame(0, $exit);
		self::assertSame('2026-09-01 12:00:00', $this->written[2]['decided_at']);
		self::assertSame(
			"1 decision(s) written back onto legacy step rows.\n"
			."The legacy schema cannot hold the following facts; they stay ONLY on the task side:\n"
			."  - step 2 (task task-2): the per-entry audit trail\n"
			."  - step 2 (task task-2): performer type 'agent'\n"
			."  - step 2 (task task-2): on-behalf-of identity 'manager1'\n"
			."  - step 2 (task task-2): mandate 'mandate-7'\n"
			."The legacy tables were not dropped, and dropping them is not part of any rollback path.\n",
			$tester->getDisplay()
		);
	}//end testTheFullReportOfADecisionWrittenBackIsPinned()

	public function testDryRunStillReportsTheLostFacts(): void {
		$tester = $this->tester(task: $this->decidedTask());
		$exit = $tester->execute(['--dry-run' => true]);

		self::assertSame(0, $exit);
		self::assertSame([], $this->written);
		self::assertStringContainsString("1 decision(s) would be written back onto legacy step rows.\n", $tester->getDisplay());
		self::assertStringContainsString("  - step 2 (task task-2): mandate 'mandate-7'\n", $tester->getDisplay());
	}//end testDryRunStillReportsTheLostFacts()

	public function testARejectingOutcomeIsWrittenBackAsRejected(): void {
		$task = $this->decidedTask();
		$task->setOutcome(' Declined ');
		$tester = $this->tester(task: $task);
		$tester->execute([]);

		self::assertSame('rejected', $this->written[2]['status']);
	}//end testARejectingOutcomeIsWrittenBackAsRejected()

	public function testAGroupDecisionWithoutDeputyOnlyLosesTheAuditTrail(): void {
		$task = $this->decidedTask();
		$task->setPerformerType(Task::PERFORMER_GROUP);
		$task->setOnBehalfOf(' ');
		$task->setMandate(null);
		$task->setCompletedBy(null);
		$task->setComment(null);
		$task->setCompletedAt(null);
		$tester = $this->tester(task: $task);
		$tester->execute([]);

		self::assertSame('', $this->written[2]['decided_by']);
		self::assertSame('', $this->written[2]['comment']);
		self::assertNull($this->written[2]['decided_at']);
		self::assertSame(
			"1 decision(s) written back onto legacy step rows.\n"
			."The legacy schema cannot hold the following facts; they stay ONLY on the task side:\n"
			."  - step 2 (task task-2): the per-entry audit trail\n"
			."The legacy tables were not dropped, and dropping them is not part of any rollback path.\n",
			$tester->getDisplay()
		);
	}//end testAGroupDecisionWithoutDeputyOnlyLosesTheAuditTrail()

	public function testAStepAlreadyDecidedIdenticallyIsLeftAlone(): void {
		$tester = $this->tester($this->decidedTask(), ['status' => 'approved', 'decided_by' => 'someone']);
		$tester->execute([]);

		self::assertSame([], $this->written);
		self::assertSame(
			"0 decision(s) written back onto legacy step rows.\n"
			."The legacy tables were not dropped, and dropping them is not part of any rollback path.\n",
			$tester->getDisplay()
		);
	}//end testAStepAlreadyDecidedIdenticallyIsLeftAlone()

	public function testAStepWithTheSameStatusButNoDeciderIsWrittenBack(): void {
		$tester = $this->tester($this->decidedTask(), ['status' => 'approved', 'decided_by' => '  ']);
		$tester->execute([]);

		self::assertSame('approved', $this->written[2]['status']);
	}//end testAStepWithTheSameStatusButNoDeciderIsWrittenBack()

	public function testAStepWithADifferentStatusIsOverwritten(): void {
		$tester = $this->tester($this->decidedTask(), ['status' => 'rejected', 'decided_by' => 'someone']);
		$tester->execute([]);

		self::assertSame('approved', $this->written[2]['status']);
	}//end testAStepWithADifferentStatusIsOverwritten()

	public function testATerminatedTaskLeavesTheStepAlone(): void {
		$task = $this->decidedTask();
		$task->setState(Task::STATE_TERMINATED);
		$tester = $this->tester(task: $task);
		$tester->execute([]);

		self::assertSame([], $this->written);
		self::assertStringContainsString('0 decision(s) written back', $tester->getDisplay());
	}//end testATerminatedTaskLeavesTheStepAlone()

	public function testAMissingTaskIsReportedAndTheRowLeftAlone(): void {
		$tester = $this->failingTester(false);
		$exit = $tester->execute([]);

		self::assertSame(0, $exit);
		self::assertSame(
			"Step 5: migrated task task-5 no longer exists; row left as it is.\n"
			."0 decision(s) written back onto legacy step rows.\n"
			."The legacy tables were not dropped, and dropping them is not part of any rollback path.\n",
			$tester->getDisplay()
		);
	}//end testAMissingTaskIsReportedAndTheRowLeftAlone()

	public function testGoneLegacyTablesFailTheCommand(): void {
		$tester = $this->failingTester(true);
		$exit = $tester->execute([]);

		self::assertSame(1, $exit);
		self::assertSame("The legacy approval tables are gone; there is nothing to roll back onto.\n", $tester->getDisplay());
	}//end testGoneLegacyTablesFailTheCommand()
}//end class
