<?php

/**
 * The ordered task sequence: provisioning, in-request advance, rejection
 * propagation and termination (flow-approval-consolidation task 8.1).
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
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Db\TaskSequenceMapper;
use OCA\OpenRegister\Event\TaskSequenceCompletedEvent;
use OCA\OpenRegister\Service\Task\TaskSequenceService;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Task\TaskSequenceService
 * @covers \OCA\OpenRegister\Db\TaskSequence
 * @covers \OCA\OpenRegister\Event\TaskSequenceCompletedEvent
 */
class TaskSequenceServiceTest extends TestCase {

	private TaskSequenceMapper&MockObject $sequences;
	private TaskMapper&MockObject $taskReader;
	private TaskService&MockObject $tasks;
	private IEventDispatcher&MockObject $dispatcher;
	private TaskSequenceService $service;

	/**
	 * Every payload handed to TaskService::import(), in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $imported = [];

	protected function setUp(): void {
		parent::setUp();
		$this->sequences = $this->createMock(TaskSequenceMapper::class);
		$this->sequences->method('insert')->willReturnArgument(0);
		$this->sequences->method('update')->willReturnArgument(0);
		$this->taskReader = $this->createMock(TaskMapper::class);
		$this->tasks = $this->createMock(TaskService::class);
		$this->tasks->method('import')->willReturnCallback(
			function (array $data, ?string $actor): Task {
				$this->imported[] = $data;

				return new Task();
			}
		);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->service = new TaskSequenceService(
			sequences: $this->sequences,
			taskReader: $this->taskReader,
			tasks: $this->tasks,
			dispatcher: $this->dispatcher,
			logger: new NullLogger()
		);
	}//end setUp()

	/**
	 * A three-position template the way the compiler derives it.
	 *
	 * @return array<string, mixed> The template.
	 */
	private function template(): array {
		return [
			'templateId' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
			'templateVersion' => 1,
			'name' => 'submit-approval',
			'schemaId' => 5,
			'separationOfDuties' => true,
			'positions' => [
				['order' => 1, 'role' => 'clerks'],
				['order' => 2, 'role' => 'managers', 'statusOnApprove' => 'published', 'statusOnReject' => 'returned-to-draft'],
				['order' => 3, 'role' => 'directors'],
			],
		];
	}//end template()

	/**
	 * A sequence task at one ordinal.
	 *
	 * @param int $position The ordinal.
	 * @param string $state The task state.
	 * @param string|null $outcome The outcome, when terminal.
	 *
	 * @return Task The task.
	 */
	private function positionTask(int $position, string $state, ?string $outcome = null): Task {
		$task = new Task();
		$task->setUuid('task-' . $position);
		$task->setSequenceUuid('seq-1');
		$task->setSequencePosition($position);
		$task->setState($state);
		$task->setIsTerminal(in_array($state, Task::TERMINAL_STATES, true));
		$task->setOutcome($outcome);
		$task->setCompletedBy('decider-' . $position);

		return $task;
	}//end positionTask()

	/**
	 * A running sequence provisioned from the template above.
	 *
	 * @return TaskSequence The sequence.
	 */
	private function runningSequence(): TaskSequence {
		$sequence = new TaskSequence();
		$sequence->setUuid('seq-1');
		$sequence->setTemplateId('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
		$sequence->setTemplateSnapshot($this->template());
		$sequence->setStatus(TaskSequence::STATUS_RUNNING);
		$sequence->setPositionCursor(1);
		$this->sequences->method('findByUuid')->with(uuid: 'seq-1')->willReturn($sequence);

		return $sequence;
	}//end runningSequence()

	public function testProvisioningCreatesEveryPositionAndEnablesOnlyTheFirst(): void {
		$sequence = $this->service->provision(
			template: $this->template(),
			anchorObjectUuid: 'obj-1',
			requesterId: 'alice'
		);

		self::assertSame(TaskSequence::STATUS_RUNNING, $sequence->getStatus());
		self::assertSame('submit-approval', $sequence->getChainKey());
		self::assertSame($this->template(), $sequence->getTemplateSnapshot());
		self::assertNull($sequence->getRunUuid(), 'a gate-opened sequence has no run and is first-class');
		self::assertCount(3, $this->imported);
		self::assertSame(Task::STATE_ENABLED, $this->imported[0]['state']);
		self::assertSame(Task::STATE_AVAILABLE, $this->imported[1]['state']);
		self::assertSame(Task::STATE_AVAILABLE, $this->imported[2]['state']);
		self::assertSame(['clerks'], $this->imported[0]['candidateGroups']);
		self::assertSame('group', $this->imported[0]['performerType']);
		self::assertSame('single-role', $this->imported[0]['routingStrategy']);
		self::assertSame('alice', $this->imported[0]['requester']);
		self::assertSame(1, $this->imported[0]['sequencePosition']);
		self::assertSame(3, $this->imported[2]['sequencePosition']);
	}//end testProvisioningCreatesEveryPositionAndEnablesOnlyTheFirst()

	public function testAResolvedTierIsFrozenOntoTheSequence(): void {
		$tier = [['order' => 1, 'role' => 'directors', 'minAmount' => 100000]];
		$sequence = $this->service->provision(
			template: $this->template(),
			anchorObjectUuid: 'obj-1',
			requesterId: 'alice',
			tierPositions: $tier
		);

		self::assertSame(['positions' => $tier], $sequence->getResolvedTier());
		self::assertCount(1, $this->imported, 'the tier replaces the declared positions');
		self::assertSame(['directors'], $this->imported[0]['candidateGroups']);
	}//end testAResolvedTierIsFrozenOntoTheSequence()

	public function testAnApprovingDecisionEnablesTheNextPositionInTheSameCall(): void {
		$sequence = $this->runningSequence();
		$this->taskReader->method('findBySequence')->willReturn(
			[
				$this->positionTask(1, Task::STATE_COMPLETED, 'approved'),
				$this->positionTask(2, Task::STATE_AVAILABLE),
				$this->positionTask(3, Task::STATE_AVAILABLE),
			]
		);

		$this->tasks->expects(self::once())->method('enable')
			->with('task-2', 'task-sequence:seq-1', self::stringContains('position 2'));
		$this->dispatcher->expects(self::never())->method('dispatchTyped');

		$this->service->onTaskTerminal(task: $this->positionTask(1, Task::STATE_COMPLETED, 'approved'));

		self::assertSame(2, $sequence->getPositionCursor());
		self::assertSame(TaskSequence::STATUS_RUNNING, $sequence->getStatus());
	}//end testAnApprovingDecisionEnablesTheNextPositionInTheSameCall()

	public function testTheLastApprovingDecisionCompletesTheSequenceAndDispatches(): void {
		$sequence = $this->runningSequence();
		$this->taskReader->method('findBySequence')->willReturn(
			[
				$this->positionTask(1, Task::STATE_COMPLETED, 'approved'),
				$this->positionTask(2, Task::STATE_COMPLETED, 'approved'),
				$this->positionTask(3, Task::STATE_COMPLETED, 'approved'),
			]
		);

		$this->tasks->expects(self::never())->method('enable');
		$this->dispatcher->expects(self::once())->method('dispatchTyped')->with(
			self::callback(
				static fn (TaskSequenceCompletedEvent $event): bool => $event->getDecider() === 'decider-3'
					&& $event->getStatusOnApprove() === 'approved'
					&& $event->getSequence()->getStatus() === TaskSequence::STATUS_COMPLETED
			)
		);

		$this->service->onTaskTerminal(task: $this->positionTask(3, Task::STATE_COMPLETED, 'approved'));

		self::assertSame(TaskSequence::STATUS_COMPLETED, $sequence->getStatus());
		self::assertNotNull($sequence->getClosedAt());
	}//end testTheLastApprovingDecisionCompletesTheSequenceAndDispatches()

	public function testTheFrozenStatusOnApproveIsResolvedPerPosition(): void {
		$sequence = $this->runningSequence();
		$this->taskReader->method('findBySequence')->willReturn(
			[
				$this->positionTask(1, Task::STATE_COMPLETED, 'approved'),
				$this->positionTask(2, Task::STATE_COMPLETED, 'approved'),
			]
		);

		$this->dispatcher->expects(self::once())->method('dispatchTyped')->with(
			self::callback(
				static fn (TaskSequenceCompletedEvent $event): bool => $event->getStatusOnApprove() === 'published'
			)
		);

		// Position 2 declares statusOnApprove 'published' in the snapshot, and
		// its completion is the final one in this two-of-three fixture.
		$this->service->onTaskTerminal(task: $this->positionTask(2, Task::STATE_COMPLETED, 'approved'));
		self::assertSame('published', $sequence->getOutcome());
	}//end testTheFrozenStatusOnApproveIsResolvedPerPosition()

	public function testARejectionClosesTheSequenceAndTerminatesEveryRemainingTask(): void {
		$sequence = $this->runningSequence();
		$this->taskReader->method('findBySequence')->willReturn(
			[
				$this->positionTask(1, Task::STATE_COMPLETED, 'rejected'),
				$this->positionTask(2, Task::STATE_ENABLED),
				$this->positionTask(3, Task::STATE_AVAILABLE),
			]
		);

		$terminated = [];
		$this->tasks->method('terminateAsMoot')->willReturnCallback(
			function (string $uuid, string $reason, string $source) use (&$terminated): Task {
				$terminated[] = [$uuid, $reason, $source];

				return new Task();
			}
		);
		$this->tasks->expects(self::never())->method('enable');
		$this->dispatcher->expects(self::never())->method('dispatchTyped');

		$this->service->onTaskTerminal(task: $this->positionTask(1, Task::STATE_COMPLETED, 'rejected'));

		self::assertSame(TaskSequence::STATUS_REJECTED, $sequence->getStatus());
		self::assertSame('rejected', $sequence->getOutcome());
		self::assertCount(2, $terminated, 'the enabled task and every later position');
		self::assertSame('task-2', $terminated[0][0]);
		self::assertSame('task-3', $terminated[1][0]);
		self::assertStringContainsString('position 1', $terminated[0][1]);
		self::assertSame('task-sequence:seq-1', $terminated[0][2]);
	}//end testARejectionClosesTheSequenceAndTerminatesEveryRemainingTask()

	public function testANonDecisionEndTerminatesTheSequence(): void {
		$sequence = $this->runningSequence();
		$this->taskReader->method('findBySequence')->willReturn(
			[
				$this->positionTask(1, Task::STATE_TERMINATED, 'cancelled'),
				$this->positionTask(2, Task::STATE_AVAILABLE),
			]
		);

		$this->service->onTaskTerminal(task: $this->positionTask(1, Task::STATE_TERMINATED, 'cancelled'));

		self::assertSame(TaskSequence::STATUS_TERMINATED, $sequence->getStatus());
	}//end testANonDecisionEndTerminatesTheSequence()

	public function testATerminalSequenceAbsorbsFurtherReports(): void {
		$sequence = $this->runningSequence();
		$sequence->setStatus(TaskSequence::STATUS_REJECTED);

		$this->tasks->expects(self::never())->method('enable');
		$this->tasks->expects(self::never())->method('terminateAsMoot');
		$this->dispatcher->expects(self::never())->method('dispatchTyped');

		$this->service->onTaskTerminal(task: $this->positionTask(2, Task::STATE_TERMINATED, 'terminated'));
	}//end testATerminalSequenceAbsorbsFurtherReports()

	public function testATaskOutsideAnySequenceIsIgnored(): void {
		$task = new Task();
		$task->setUuid('lone-task');
		$task->setState(Task::STATE_COMPLETED);

		$this->sequences->expects(self::never())->method('findByUuid');

		$this->service->onTaskTerminal(task: $task);
	}//end testATaskOutsideAnySequenceIsIgnored()
}//end class
