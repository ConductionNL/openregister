<?php

/**
 * Realisation: a human item becomes a task through the trusted import path
 * carrying the anchor, candidates and deadlines; a flow-bound stage queues a
 * run through the one funnel; a milestone realises nothing; terminal
 * outcomes map; and the only write back is a termination.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use DateTime;
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Case\CaseRealisationService;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Coverage of CaseRealisationService.
 *
 * @covers \OCA\OpenRegister\Service\Case\CaseRealisationService
 */
class CaseRealisationServiceTest extends TestCase {

	/**
	 * Task lifecycle.
	 *
	 * @var TaskService&MockObject
	 */
	private TaskService&MockObject $tasks;

	/**
	 * Task rows.
	 *
	 * @var TaskMapper&MockObject
	 */
	private TaskMapper&MockObject $taskRows;

	/**
	 * Runs.
	 *
	 * @var FlowRunService&MockObject
	 */
	private FlowRunService&MockObject $runs;

	/**
	 * Run rows.
	 *
	 * @var FlowRunMapper&MockObject
	 */
	private FlowRunMapper&MockObject $runRows;

	/**
	 * Fresh mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tasks = $this->createMock(TaskService::class);
		$this->taskRows = $this->createMock(TaskMapper::class);
		$this->runs = $this->createMock(FlowRunService::class);
		$this->runRows = $this->createMock(FlowRunMapper::class);
	}//end setUp()

	/**
	 * The service.
	 *
	 * @return CaseRealisationService The service.
	 */
	private function service(): CaseRealisationService {
		return new CaseRealisationService(tasks: $this->tasks, taskRows: $this->taskRows, runs: $this->runs, runRows: $this->runRows, logger: new NullLogger());
	}//end service()

	/**
	 * A human item becomes a pooled task via import(), with the anchor and the carried terms.
	 *
	 * @return void
	 */
	public function testAHumanItemBecomesATaskThroughTheTrustedPath(): void {
		$item = CaseFixtures::row(id: 1, key: 'check', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE);
		$item->setName('Controleer');
		$item->setCandidateGroups(['behandelaars']);
		$item->setDueAt(new DateTime('2026-09-04T17:00:00+02:00'));
		$item->setDoorlooptijd('P8W');
		$item->setCreatedBy('requester-1');

		$created = new Task();
		$created->setUuid('task-9');
		$this->tasks->expects($this->once())->method('import')->with(
			$this->callback(
				function (array $data): bool {
					$this->assertSame('Controleer', $data['title']);
					$this->assertSame(Task::STATE_ENABLED, $data['state'], 'Pooled: enabled.');
					$this->assertSame(Task::PERFORMER_GROUP, $data['performerType']);
					$this->assertSame(['behandelaars'], $data['candidateGroups']);
					$this->assertArrayNotHasKey('candidateUsers', $data);
					$this->assertSame(CaseFixtures::OBJECT, $data['objectUuid']);
					$this->assertSame(1, $data['registerId']);
					$this->assertSame('requester-1', $data['requester']);
					$this->assertSame('2026-09-04T17:00:00+02:00', $data['dueAt']);
					$this->assertArrayNotHasKey('expiresAt', $data);
					$this->assertSame('P8W', $data['metadata']['doorlooptijd']);
					$this->assertSame('item-1', $data['metadata']['caseItem']);

					return true;
				}
			),
			'alice'
		)->willReturn($created);

		$this->service()->realise(item: $item, actor: 'alice');
		$this->assertSame(CaseItem::REALISATION_TASK, $item->getRealisationKind());
		$this->assertSame('task-9', $item->getRealisationUuid());

		// Without candidates: available, performer user, requester = actor.
		$bare = CaseFixtures::row(id: 2, key: 'x', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_AVAILABLE);
		$bare->setCandidateUsers(['u1']);
		$bare->setCandidateRole('r');
		$bare->setExpiresAt(new DateTime('2026-10-01T00:00:00+00:00'));
		$data = $this->service()->taskDataFor(item: $bare, actor: 'case-plan');
		$this->assertSame(Task::STATE_ENABLED, $data['state']);
		$this->assertSame(Task::PERFORMER_USER, $data['performerType']);
		$this->assertSame('case-plan', $data['requester']);
		$this->assertSame('r', $data['candidateRole']);
		$this->assertSame(['u1'], $data['candidateUsers']);
		$this->assertArrayHasKey('expiresAt', $data);
		$bare->setCandidateUsers(null);
		$bare->setCandidateRole(null);
		$this->assertSame(Task::STATE_AVAILABLE, $this->service()->taskDataFor(item: $bare, actor: 'a')['state']);
	}//end testAHumanItemBecomesATaskThroughTheTrustedPath()

	/**
	 * A flow-bound stage queues a run against the plan's binding; an unbound
	 * stage and a milestone realise nothing.
	 *
	 * @return void
	 */
	public function testAFlowBoundStageQueuesARunAndOthersRealiseNothing(): void {
		$stage = CaseFixtures::row(id: 1, key: 'auto', type: CaseItem::TYPE_STAGE, state: CaseItem::STATE_AVAILABLE);
		$stage->setPlanSettings(['flows' => ['auto' => 'flow-7']]);
		$run = new FlowRun();
		$run->setUuid('run-3');
		$this->runs->expects($this->once())->method('queue')->with(
			'flow-7',
			['uuid' => CaseFixtures::OBJECT, 'register' => '1', 'schema' => '1'],
			CaseRealisationService::RUN_TRIGGER,
			['caseItem' => 'item-1'],
			'alice'
		)->willReturn($run);

		$service = $this->service();
		$service->realise(item: $stage, actor: 'alice');
		$this->assertSame(CaseItem::REALISATION_RUN, $stage->getRealisationKind());
		$this->assertSame('run-3', $stage->getRealisationUuid());

		$plain = CaseFixtures::row(id: 2, key: 'plain', type: CaseItem::TYPE_STAGE, state: CaseItem::STATE_AVAILABLE);
		$service->realise(item: $plain, actor: 'alice');
		$this->assertSame(CaseItem::REALISATION_NONE, $plain->getRealisationKind());
		$this->assertNull($plain->getRealisationUuid());
	}//end testAFlowBoundStageQueuesARunAndOthersRealiseNothing()

	/**
	 * Terminal outcomes: task completed/terminated, run completed/stopped,
	 * open = null, missing = terminated, none = null.
	 *
	 * @return void
	 */
	public function testTerminalOutcomes(): void {
		$service = $this->service();
		$taskItem = CaseFixtures::row(id: 1, key: 't', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ACTIVE);
		$taskItem->setRealisationKind(CaseItem::REALISATION_TASK);
		$taskItem->setRealisationUuid('task-1');
		$task = new Task();
		$this->taskRows->method('findByUuid')->willReturnCallback(
			static function (string $uuid) use ($task): Task {
				if ($uuid === 'gone') {
					throw new DoesNotExistException('gone');
				}

				return $task;
			}
		);

		$task->setState(Task::STATE_ACTIVE);
		$this->assertNull($service->terminalOutcome(item: $taskItem));
		$task->setState(Task::STATE_COMPLETED);
		$this->assertSame(CaseItem::STATE_COMPLETED, $service->terminalOutcome(item: $taskItem));
		$task->setState(Task::STATE_DISABLED);
		$this->assertSame(CaseItem::STATE_TERMINATED, $service->terminalOutcome(item: $taskItem));
		$taskItem->setRealisationUuid('gone');
		$this->assertSame(CaseItem::STATE_TERMINATED, $service->terminalOutcome(item: $taskItem));

		$runItem = CaseFixtures::row(id: 2, key: 'r', type: CaseItem::TYPE_STAGE, state: CaseItem::STATE_ACTIVE);
		$runItem->setRealisationKind(CaseItem::REALISATION_RUN);
		$runItem->setRealisationUuid('run-1');
		$run = new FlowRun();
		$this->runRows->method('findByUuid')->willReturn($run);
		$run->setStatus(FlowRun::STATUS_SUSPENDED);
		$this->assertNull($service->terminalOutcome(item: $runItem));
		$run->setStatus(FlowRun::STATUS_COMPLETED);
		$this->assertSame(CaseItem::STATE_COMPLETED, $service->terminalOutcome(item: $runItem));
		$run->setStatus(FlowRun::STATUS_STOPPED);
		$this->assertSame(CaseItem::STATE_TERMINATED, $service->terminalOutcome(item: $runItem));

		$none = CaseFixtures::row(id: 3, key: 'n', type: CaseItem::TYPE_MILESTONE, state: CaseItem::STATE_AVAILABLE);
		$this->assertNull($service->terminalOutcome(item: $none));
		$none->setRealisationKind('other');
		$none->setRealisationUuid('x');
		$this->assertNull($service->terminalOutcome(item: $none));
	}//end testTerminalOutcomes()

	/**
	 * Termination: a task is terminated as moot naming the item; a gone task
	 * is fine; another failure propagates; a run is logged, not touched; no
	 * realisation is a no-op.
	 *
	 * @return void
	 */
	public function testTerminateWritesOnlyToTasks(): void {
		$service = $this->service();
		$taskItem = CaseFixtures::row(id: 1, key: 't', type: CaseItem::TYPE_HUMAN_TASK, state: CaseItem::STATE_ACTIVE);
		$taskItem->setRealisationKind(CaseItem::REALISATION_TASK);
		$taskItem->setRealisationUuid('task-1');
		$this->tasks->expects($this->exactly(3))->method('terminateAsMoot')->willReturnCallback(
			static function (string $uuid, string $reason, string $source): Task {
				if ($reason === 'gone') {
					throw new DoesNotExistException('gone');
				}

				if ($reason === 'boom') {
					throw new RuntimeException('boom');
				}

				TestCase::assertSame('task-1', $uuid);
				TestCase::assertSame('case-item:item-1', $source);

				return new Task();
			}
		);

		$service->terminate(item: $taskItem, reason: 'stage exited');
		$service->terminate(item: $taskItem, reason: 'gone');
		try {
			$service->terminate(item: $taskItem, reason: 'boom');
			$this->fail('propagates');
		} catch (RuntimeException $failure) {
			$this->assertSame('boom', $failure->getMessage());
		}

		$runItem = CaseFixtures::row(id: 2, key: 'r', type: CaseItem::TYPE_STAGE, state: CaseItem::STATE_ACTIVE);
		$runItem->setRealisationKind(CaseItem::REALISATION_RUN);
		$runItem->setRealisationUuid('run-1');
		$this->runs->expects($this->never())->method($this->anything());
		$service->terminate(item: $runItem, reason: 'x');

		$none = CaseFixtures::row(id: 3, key: 'n', type: CaseItem::TYPE_MILESTONE, state: CaseItem::STATE_AVAILABLE);
		$service->terminate(item: $none, reason: 'x');
	}//end testTerminateWritesOnlyToTasks()
}//end class
