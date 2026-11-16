<?php

/**
 * FlowTaskBridge at its edges: the audit delegate, a run whose version cannot
 * be read at task creation, and an in-request continuation that cannot find a
 * stream to continue.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-user-task-node/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowStreamMapperInterface;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Kernel\Contracts\Value\TaskAuditEntry;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunAdvancer;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The bridge degrades to "the worker continues it" rather than failing a completion.
 */
class FlowTaskBridgeEdgeTest extends TestCase {

	/**
	 * The task service.
	 *
	 * @var TaskService&MockObject
	 */
	private TaskService&MockObject $tasks;

	/**
	 * The run mapper.
	 *
	 * @var FlowRunMapper&MockObject
	 */
	private FlowRunMapper&MockObject $runs;

	/**
	 * The run service the completion signals.
	 *
	 * @var FlowRunService&MockObject
	 */
	private FlowRunService&MockObject $runService;

	/**
	 * The advancer an in-request continuation would use.
	 *
	 * @var FlowRunAdvancer&MockObject
	 */
	private FlowRunAdvancer&MockObject $advancer;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The bridge under test; the stream layer is never available.
	 *
	 * @var FlowTaskBridge
	 */
	private FlowTaskBridge $bridge;

	/**
	 * Build the bridge over a container without a stream mapper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->tasks      = $this->createMock(TaskService::class);
		$this->runs       = $this->createMock(FlowRunMapper::class);
		$this->runService = $this->createMock(FlowRunService::class);
		$this->advancer   = $this->createMock(FlowRunAdvancer::class);
		$this->logger     = $this->createMock(LoggerInterface::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				FlowRunServiceInterface::class => $this->runService,
				FlowRunAdvancer::class => $this->advancer,
				FlowStreamMapperInterface::class => throw new RuntimeException('streams not installed'),
				default => throw new RuntimeException('unexpected service ' . $id),
			}
		);

		$this->bridge = new FlowTaskBridge($this->tasks, $this->runs, $container, $this->logger);
	}//end setUp()

	/**
	 * A suspended run whose node `ask` stored an advance budget of 2.
	 *
	 * @param string $nodeId The node the budget is stored under.
	 *
	 * @return FlowRun
	 */
	private function suspendedRun(string $nodeId): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setStatus(FlowRun::STATUS_SUSPENDED);
		$run->setContext([FlowResumeState::CONTEXT_KEY => [$nodeId => ['taskUuid' => 't-1', FlowTaskBridge::SLOT_ADVANCE => 2]]]);

		return $run;
	}//end suspendedRun()

	/**
	 * A completed task of run-1 on the given node.
	 *
	 * @param string $nodeId The node.
	 *
	 * @return Task
	 */
	private static function completedTask(string $nodeId): Task {
		$task = new Task();
		$task->setUuid('t-1');
		$task->setRunUuid('run-1');
		$task->setNodeId($nodeId);
		$task->setState(Task::STATE_COMPLETED);
		$task->setIsTerminal(true);
		$task->setOutcome('approved');

		return $task;
	}//end completedTask()

	/**
	 * Recording a fact passes the exact entry to the task service.
	 *
	 * @return void
	 */
	public function testRecordHandsTheEntryToTheTaskService(): void {
		$entry = new TaskAuditEntry(uuid: 't-1', action: 'portal.party.matched', actor: 'alice', reason: 'aanvrager');
		$this->tasks->expects($this->once())->method('record')->with($entry);

		$this->bridge->record(entry: $entry);
	}//end testRecordHandsTheEntryToTheTaskService()

	/**
	 * A run that cannot be read stamps the task with no version pin instead of failing.
	 *
	 * @return void
	 */
	public function testAnUnreadableRunStampsNoVersionPin(): void {
		$this->runs->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));
		$created = new Task();
		$this->tasks->expects($this->once())
			->method('import')
			->with(
				$this->callback(
					static fn (array $data): bool => array_key_exists('definitionVersion', $data)
						&& $data['definitionVersion'] === null
						&& $data['runUuid'] === 'run-x'
						&& $data['requester'] === 'bob'
				),
				'bob'
			)
			->willReturn($created);

		$this->assertSame($created, $this->bridge->createTask(data: ['title' => 'Check'], runUuid: 'run-x', nodeId: 'ask', actor: 'bob'));
	}//end testAnUnreadableRunStampsNoVersionPin()

	/**
	 * Without a stream layer, the woken run is left for the worker and never advanced here.
	 *
	 * @return void
	 */
	public function testAMissingStreamLayerLeavesTheRunForTheWorker(): void {
		$run = $this->suspendedRun(nodeId: 'ask');
		$this->runs->method('findByUuid')->willReturn($run);
		$this->runService->method('signal')->willReturn($run);
		$this->advancer->expects($this->never())->method('advanceStream');
		$this->logger->expects($this->once())
			->method('debug')
			->with('[FlowTaskBridge] The stream layer is not available: streams not installed');

		$this->assertSame($run, $this->bridge->continueRun(task: self::completedTask(nodeId: 'ask')));
	}//end testAMissingStreamLayerLeavesTheRunForTheWorker()

	/**
	 * A task with no node id has no stream to look for, so the stream layer is not asked.
	 *
	 * @return void
	 */
	public function testATaskWithoutANodeIdIsLeftForTheWorker(): void {
		$run = $this->suspendedRun(nodeId: '');
		$this->runs->method('findByUuid')->willReturn($run);
		$this->runService->method('signal')->willReturn($run);
		$this->advancer->expects($this->never())->method('advanceStream');
		$this->logger->expects($this->never())->method('debug');
		$this->logger->expects($this->once())
			->method('info')
			->with($this->stringContains('No stream of run run-1 stands on node ;'));

		$this->assertSame($run, $this->bridge->continueRun(task: self::completedTask(nodeId: '')));
	}//end testATaskWithoutANodeIdIsLeftForTheWorker()
}//end class
