<?php

/**
 * Behaviour tests for SeedTaskFixtures: candidate-index rows, audit mapping and failure reporting.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAudit;
use OCA\OpenRegister\Db\TaskAuditMapperInterface;
use OCA\OpenRegister\Db\TaskCandidateMapperInterface;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Repair\SeedTaskFixtures;
use OCA\OpenRegister\Db\TaskSequenceMapperInterface;
use OCA\OpenRegister\Repair\Support\ApprovalTaskStores;
use OCA\OpenRegister\Repair\Support\RepairPlatform;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Repair\SeedTaskFixtures
 * @uses   \OCA\OpenRegister\Repair\Support\ApprovalTaskStores
 * @uses   \OCA\OpenRegister\Repair\Support\RepairPlatform
 * @uses   \OCA\OpenRegister\Db\Task
 * @uses   \OCA\OpenRegister\Db\TaskAudit
 */
final class SeedTaskFixturesBehaviourTest extends TestCase {

	/** @var TaskMapper&MockObject */
	private TaskMapper $tasks;

	/** @var TaskCandidateMapperInterface&MockObject */
	private TaskCandidateMapperInterface $candidates;

	/** @var TaskAuditMapperInterface&MockObject */
	private TaskAuditMapperInterface $audits;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->candidates = $this->createMock(TaskCandidateMapperInterface::class);
		$this->audits = $this->createMock(TaskAuditMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function step(bool $enabled = true): SeedTaskFixtures {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->with('openregister', SeedTaskFixtures::FLAG, false)->willReturn($enabled);
		return new SeedTaskFixtures(
			new RepairPlatform($this->createMock(IDBConnection::class), $config, $this->logger),
			new ApprovalTaskStores($this->tasks, $this->candidates, $this->audits, $this->createMock(TaskSequenceMapperInterface::class))
		);
	}

	public function testDisabledFlagSkipsWithoutReadingTheTable(): void {
		$this->tasks->expects($this->never())->method('findByUuid');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with('Task fixtures: skipped (openregister/seed_demo_tasks is not enabled).');

		$this->step(false)->run($output);
	}

	public function testCandidateIndexHoldsUserGroupAndRoleRowsOfThePersistedTask(): void {
		$this->tasks->method('findByUuid')->willThrowException(new DoesNotExistException('absent'));
		$this->tasks->method('insert')->willReturnCallback(
			static function (Task $task): Task {
				$persisted = new Task();
				$persisted->setId(70);
				$persisted->setCandidateUsers(['alice', 7]);
				$persisted->setCandidateGroups(['staff']);
				$persisted->setCandidateRole('reviewer');
				return $persisted;
			}
		);
		$rows = [];
		$this->candidates->method('replaceForTask')->willReturnCallback(
			function (int $taskId, array $candidates) use (&$rows): void {
				$rows[] = [$taskId, $candidates];
			}
		);
		$this->audits->method('insert')->willReturnArgument(0);

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertCount(5, $rows);
		$this->assertSame(
			[70, [['kind' => 'user', 'ref' => 'alice'], ['kind' => 'user', 'ref' => '7'], ['kind' => 'group', 'ref' => 'staff'], ['kind' => 'role', 'ref' => 'reviewer']]],
			$rows[0]
		);
	}

	public function testABlankRoleAndNoCandidatesYieldAnEmptyIndex(): void {
		$this->tasks->method('findByUuid')->willThrowException(new DoesNotExistException('absent'));
		$this->tasks->method('insert')->willReturnCallback(
			static function (Task $task): Task {
				$persisted = new Task();
				$persisted->setId(1);
				$persisted->setCandidateRole('   ');
				return $persisted;
			}
		);
		$rows = [];
		$this->candidates->method('replaceForTask')->willReturnCallback(
			function (int $taskId, array $candidates) use (&$rows): void {
				$rows[] = $candidates;
			}
		);
		$this->audits->method('insert')->willReturnArgument(0);

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame([[], [], [], [], []], $rows);
	}

	public function testInsertedTaskCarriesTheFixtureUuidAndAuditRowsMapEveryField(): void {
		$this->tasks->method('findByUuid')->willThrowException(new DoesNotExistException('absent'));
		$uuids = [];
		$this->tasks->method('insert')->willReturnCallback(
			static function (Task $task) use (&$uuids): Task {
				$uuids[] = $task->getUuid();
				$task->setId(count($uuids));
				return $task;
			}
		);
		$audits = [];
		$this->audits->method('insert')->willReturnCallback(
			static function (TaskAudit $a) use (&$audits): TaskAudit {
				$audits[] = $a;
				return $a;
			}
		);

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame(
			[
				'00000000-0000-0000-0000-000000000001',
				'00000000-0000-0000-0000-000000000002',
				'00000000-0000-0000-0000-000000000003',
				'00000000-0000-0000-0000-000000000004',
				'00000000-0000-0000-0000-000000000005',
			],
			$uuids
		);
		$delegate = $audits[1];
		$this->assertSame(2, $delegate->getTaskId());
		$this->assertSame('delegate', $delegate->getAction());
		$this->assertSame(Task::STATE_ACTIVE, $delegate->getStateAfter());
		$this->assertSame('EXAMPLE_DIRECTOR_USER', $delegate->getActor());
		$this->assertSame(Task::PERFORMER_USER, $delegate->getPerformerType());
		$this->assertSame('EXAMPLE_DIRECTOR_USER', $delegate->getOnBehalfOf());
		$this->assertSame('Volmacht inkoop 2026, artikel 4 lid 2', $delegate->getMandate());
		$this->assertNull($delegate->getReason());
		$this->assertTrue($delegate->getAuthorized());
		$denied = array_values(array_filter($audits, static fn (TaskAudit $a): bool => $a->getAuthorized() === false));
		$this->assertSame("Verb 'complete' denied: only the current assignee may perform it.", $denied[0]->getReason());
		$this->assertSame(4, $denied[0]->getTaskId());
	}

	public function testAFailingFixtureIsLoggedWithItsUuidAndCountedAsPresent(): void {
		$this->tasks->method('findByUuid')->willReturnCallback(
			static function (string $uuid): Task {
				if ($uuid === '00000000-0000-0000-0000-000000000003') {
					throw new \RuntimeException('table locked');
				}
				return new Task();
			}
		);
		$this->tasks->expects($this->never())->method('insert');
		$this->logger->expects($this->once())->method('warning')
			->with('[SeedTaskFixtures] Fixture failed: table locked', ['uuid' => '00000000-0000-0000-0000-000000000003']);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')
			->with('Task fixture 00000000-0000-0000-0000-000000000003 failed: table locked');
		$output->expects($this->once())->method('info')->with('Task fixtures: 0 seeded, 5 already present.');

		$this->step()->run($output);
	}
}
