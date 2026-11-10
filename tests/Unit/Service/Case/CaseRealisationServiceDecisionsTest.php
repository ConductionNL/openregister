<?php

/**
 * Decision tests for {@see \OCA\OpenRegister\Service\Case\CaseRealisationService}:
 * how a plan item is realised (task, run or none), which actor a run gets,
 * the task data it sends, the terminal outcome of a realisation and how a
 * realisation is terminated.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use DateTime;
use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Case\CaseRealisationRuns;
use OCA\OpenRegister\Service\Case\CaseRealisationService;
use OCA\OpenRegister\Service\Case\CaseRealisationTasks;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * CaseRealisationServiceDecisionsTest.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class CaseRealisationServiceDecisionsTest extends TestCase {
	private TaskServiceInterface&MockObject $tasks;
	private TaskMapper&MockObject $taskRows;
	private FlowRunServiceInterface&MockObject $runs;
	private FlowRunMapperInterface&MockObject $runRows;
	private LoggerInterface&MockObject $logger;
	private CaseRealisationService $service;

	protected function setUp(): void {
		$this->tasks = $this->createMock(TaskServiceInterface::class);
		$this->taskRows = $this->createMock(TaskMapper::class);
		$this->runs = $this->createMock(FlowRunServiceInterface::class);
		$this->runRows = $this->createMock(FlowRunMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new CaseRealisationService(
			new CaseRealisationTasks($this->tasks, $this->taskRows),
			new CaseRealisationRuns($this->runs, $this->runRows),
			$this->logger
		);
	}

	private function item(string $type, string $key = 'review', array $flows = []): CaseItem {
		$item = new CaseItem();
		$item->setUuid('item-1');
		$item->setPlanItemType($type);
		$item->setItemKey($key);
		$item->setObjectUuid('case-1');
		$item->setRegisterId(7);
		$item->setSchemaId(8);
		$item->setPlanSettings(['flows' => $flows]);
		return $item;
	}

	private function flowRun(string $uuid, string $status = FlowRun::STATUS_QUEUED): FlowRun {
		$run = new FlowRun();
		$run->setUuid($uuid);
		$run->setStatus($status);
		return $run;
	}

	private function task(string $uuid, string $state): Task {
		$task = new Task();
		$task->setUuid($uuid);
		$task->setState($state);
		return $task;
	}

	public function testAHumanTaskIsRealisedAsATask(): void {
		$item = $this->item(CaseItem::TYPE_HUMAN_TASK);
		$this->tasks->expects($this->once())->method('import')
			->with($this->callback(static fn (array $data): bool => $data['title'] === 'review' && $data['state'] === Task::STATE_AVAILABLE), 'alice')
			->willReturn($this->task('task-1', Task::STATE_AVAILABLE));

		$this->service->realise($item, 'alice');

		$this->assertSame(CaseItem::REALISATION_TASK, $item->getRealisationKind());
		$this->assertSame('task-1', $item->getRealisationUuid());
	}

	public function testAStageWithAFlowIsRealisedAsARunForTheAccountActor(): void {
		$item = $this->item(CaseItem::TYPE_STAGE, 'intake', ['intake' => ' flow-9 ']);
		$this->runs->expects($this->once())->method('queue')->with(
			'flow-9',
			['uuid' => 'case-1', 'register' => '7', 'schema' => '8'],
			CaseRealisationService::RUN_TRIGGER,
			['caseItem' => 'item-1'],
			'alice'
		)->willReturn($this->flowRun('run-1'));

		$this->service->realise($item, 'alice');

		$this->assertSame(CaseItem::REALISATION_RUN, $item->getRealisationKind());
		$this->assertSame('run-1', $item->getRealisationUuid());
	}

	public function testARunQueuedByASystemActorHasNoUser(): void {
		$item = $this->item(CaseItem::TYPE_PROCESS_TASK, 'calc', ['calc' => 'flow-9']);
		$this->runs->expects($this->once())->method('queue')
			->with('flow-9', $this->anything(), $this->anything(), $this->anything(), null)
			->willReturn($this->flowRun('run-1'));

		$this->service->realise($item, 'system:procest');
	}

	public function testAProcessTaskWithoutAFlowAndAMilestoneAreNotRealised(): void {
		foreach ([$this->item(CaseItem::TYPE_PROCESS_TASK, 'calc', ['other' => 'flow-9']), $this->item(CaseItem::TYPE_MILESTONE, 'done', ['done' => 'flow-9'])] as $item) {
			$item->setRealisationUuid('stale');
			$this->service->realise($item, 'alice');

			$this->assertSame(CaseItem::REALISATION_NONE, $item->getRealisationKind());
			$this->assertNull($item->getRealisationUuid());
		}
		$this->runs->expects($this->never())->method('queue');
	}

	public function testOnlyRealAccountsCountAsAccountActors(): void {
		$this->assertTrue($this->service->isAccountActor(' alice '));
		$this->assertFalse($this->service->isAccountActor(null));
		$this->assertFalse($this->service->isAccountActor(''));
		$this->assertFalse($this->service->isAccountActor('case-plan'));
		$this->assertFalse($this->service->isAccountActor('system:procest'));
	}

	public function testTaskDataCarriesCandidatesDeadlinesAndMetadata(): void {
		$item = $this->item(CaseItem::TYPE_HUMAN_TASK);
		$item->setName('Review the request');
		$item->setCandidateGroups(['g' => 'reviewers']);
		$item->setCandidateRole('  ');
		$item->setDueAt(new DateTime('2026-05-01T12:00:00+00:00'));
		$item->setCreatedBy('bob');

		$data = $this->service->taskDataFor($item, 'alice');

		$this->assertSame('Review the request', $data['title']);
		$this->assertSame(Task::STATE_ENABLED, $data['state']);
		$this->assertSame(Task::PERFORMER_GROUP, $data['performerType']);
		$this->assertSame('bob', $data['requester']);
		$this->assertSame(['reviewers'], $data['candidateGroups']);
		$this->assertArrayNotHasKey('candidateUsers', $data);
		$this->assertArrayNotHasKey('candidateRole', $data);
		$this->assertSame('2026-05-01T12:00:00+00:00', $data['dueAt']);
		$this->assertArrayNotHasKey('expiresAt', $data);
		$this->assertSame('item-1', $data['metadata']['caseItem']);
	}

	public function testTaskDataForNamedUsersOrARoleIsEnabledForAUserPerformer(): void {
		$withUsers = $this->item(CaseItem::TYPE_HUMAN_TASK);
		$withUsers->setCandidateUsers(['carol']);
		$withUsers->setCandidateGroups(['reviewers']);
		$withRole = $this->item(CaseItem::TYPE_HUMAN_TASK);
		$withRole->setCandidateRole('behandelaar');

		$users = $this->service->taskDataFor($withUsers, 'alice');
		$role = $this->service->taskDataFor($withRole, 'alice');

		$this->assertSame([Task::STATE_ENABLED, Task::PERFORMER_USER], [$users['state'], $users['performerType']]);
		$this->assertSame([Task::STATE_ENABLED, Task::PERFORMER_USER, 'behandelaar'], [$role['state'], $role['performerType'], $role['candidateRole']]);
		$this->assertSame('alice', $role['requester']);
	}

	public function testAnItemWithoutRealisationHasNoOutcome(): void {
		$this->assertNull($this->service->terminalOutcome($this->item(CaseItem::TYPE_STAGE)));
	}

	public function testATaskOutcomeFollowsTheTaskState(): void {
		$item = $this->item(CaseItem::TYPE_HUMAN_TASK);
		$item->setRealisationKind(CaseItem::REALISATION_TASK);
		$item->setRealisationUuid('task-1');
		$this->taskRows->method('findByUuid')->with('task-1')->willReturnOnConsecutiveCalls(
			$this->task('task-1', Task::STATE_ENABLED),
			$this->task('task-1', Task::STATE_COMPLETED),
			$this->task('task-1', Task::STATE_TERMINATED)
		);

		$this->assertNull($this->service->terminalOutcome($item));
		$this->assertSame(CaseItem::STATE_COMPLETED, $this->service->terminalOutcome($item));
		$this->assertSame(CaseItem::STATE_TERMINATED, $this->service->terminalOutcome($item));
	}

	public function testARunOutcomeFollowsTheRunStatus(): void {
		$item = $this->item(CaseItem::TYPE_STAGE);
		$item->setRealisationKind(CaseItem::REALISATION_RUN);
		$item->setRealisationUuid('run-1');
		$this->runRows->method('findByUuid')->with('run-1')->willReturnOnConsecutiveCalls(
			$this->flowRun('run-1', FlowRun::STATUS_RUNNING),
			$this->flowRun('run-1', FlowRun::STATUS_COMPLETED),
			$this->flowRun('run-1', FlowRun::STATUS_FAILED)
		);

		$this->assertNull($this->service->terminalOutcome($item));
		$this->assertSame(CaseItem::STATE_COMPLETED, $this->service->terminalOutcome($item));
		$this->assertSame(CaseItem::STATE_TERMINATED, $this->service->terminalOutcome($item));
	}

	public function testAVanishedRealisationCountsAsTerminatedAndAnUnknownKindHasNoOutcome(): void {
		$item = $this->item(CaseItem::TYPE_STAGE);
		$item->setRealisationKind(CaseItem::REALISATION_RUN);
		$item->setRealisationUuid('run-1');
		$this->runRows->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));
		$other = $this->item(CaseItem::TYPE_STAGE);
		$other->setRealisationKind(CaseItem::REALISATION_NONE);
		$other->setRealisationUuid('x');

		$this->assertSame(CaseItem::STATE_TERMINATED, $this->service->terminalOutcome($item));
		$this->assertNull($this->service->terminalOutcome($other));
	}

	public function testTerminatingATaskRealisationMarksTheTaskMoot(): void {
		$item = $this->item(CaseItem::TYPE_HUMAN_TASK);
		$item->setRealisationKind(CaseItem::REALISATION_TASK);
		$item->setRealisationUuid('task-1');
		$this->tasks->expects($this->once())->method('terminateAsMoot')
			->with('task-1', 'stage exited', 'case-item:item-1')
			->willReturn($this->task('task-1', Task::STATE_TERMINATED));

		$this->service->terminate($item, 'stage exited');
	}

	public function testTerminatingAnAlreadyDeletedTaskIsQuiet(): void {
		$item = $this->item(CaseItem::TYPE_HUMAN_TASK);
		$item->setRealisationKind(CaseItem::REALISATION_TASK);
		$item->setRealisationUuid('task-1');
		$this->tasks->method('terminateAsMoot')->willThrowException(new DoesNotExistException('gone'));
		$this->logger->expects($this->never())->method('warning');

		$this->service->terminate($item, 'stage exited');
		$this->assertSame('task-1', $item->getRealisationUuid());
	}

	public function testAFailedTaskTerminationIsLoggedAndRethrown(): void {
		$item = $this->item(CaseItem::TYPE_HUMAN_TASK);
		$item->setRealisationKind(CaseItem::REALISATION_TASK);
		$item->setRealisationUuid('task-1');
		$this->tasks->method('terminateAsMoot')->willThrowException(new RuntimeException('locked'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[CaseRealisationService] Could not terminate a task realisation: locked',
			['item' => 'item-1', 'task' => 'task-1']
		);

		$this->expectExceptionMessage('locked');
		$this->service->terminate($item, 'stage exited');
	}

	public function testTerminatingARunRealisationOnlyLogsAndNoRealisationDoesNothing(): void {
		$run = $this->item(CaseItem::TYPE_STAGE);
		$run->setRealisationKind(CaseItem::REALISATION_RUN);
		$run->setRealisationUuid('run-1');
		$this->tasks->expects($this->never())->method('terminateAsMoot');
		$this->logger->expects($this->once())->method('info')
			->with("[CaseRealisationService] Plan item item-1 exited while run run-1 is open; the run keeps the engine's status.");

		$this->service->terminate($run, 'stage exited');
		$this->service->terminate($this->item(CaseItem::TYPE_STAGE), 'stage exited');
	}
}
