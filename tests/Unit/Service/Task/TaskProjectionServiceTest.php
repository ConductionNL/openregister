<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Delivery failure is isolated: a calendar backend that fails every write
 * leaves the assignment committed, is logged naming the task and the
 * surface, and never propagates.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-delivery-failure-never-fails-the-task
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-truth-flows-one-way-and-the-one-path-back-is-a-gate
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Event\TaskTransitionedEvent;
use OCA\OpenRegister\Listener\TaskCalendarProjectionListener;
use OCA\OpenRegister\Service\Task\TaskCalendarProjector;
use OCA\OpenRegister\Service\Task\TaskProjectionService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TaskProjectionServiceTest extends TestCase {
	private TaskCalendarProjector&MockObject $projector;

	private TaskMapper&MockObject $tasks;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->projector = $this->createMock(TaskCalendarProjector::class);
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}

	private function service(): TaskProjectionService {
		return new TaskProjectionService($this->projector, $this->tasks, $this->logger);
	}

	public function testACalendarOutageIsLoggedNamingTheTaskAndSurfaceAndNeverThrown(): void {
		$task = TaskCalendarProjectorTest::assignedTask();
		$this->projector->method('project')->willThrowException(new \RuntimeException('calendar backend fails every write'));
		$this->logger->expects($this->once())->method('warning')
			->with(
				$this->logicalAnd($this->stringContains(TaskCalendarProjectorTest::PROJECTED_UUID), $this->stringContains('caldav')),
				$this->callback(static fn (array $ctx): bool => $ctx['task'] === TaskCalendarProjectorTest::PROJECTED_UUID && $ctx['surface'] === 'caldav')
			);

		(new TaskCalendarProjectionListener($this->service()))->handle(new TaskTransitionedEvent($task, null, 'enabled', 'clerk'));
		$this->addToAssertionCount(1);
	}

	public function testATransitionHandsThePreviousAssigneeToTheProjector(): void {
		$task = TaskCalendarProjectorTest::assignedTask();
		$this->projector->expects($this->once())->method('project')->with($task, 'former');

		$this->service()->afterTransition(new TaskTransitionedEvent($task, 'former', 'active', 'manager'));
	}

	public function testReconcileResolvesTheTaskAndReportsTheOutcome(): void {
		$task = TaskCalendarProjectorTest::assignedTask();
		$this->tasks->method('findByUuid')->with(TaskCalendarProjectorTest::PROJECTED_UUID)->willReturn($task);
		$this->projector->expects($this->once())->method('reconcile')->with($task);

		$this->assertTrue($this->service()->reconcile(TaskCalendarProjectorTest::PROJECTED_UUID));
	}

	public function testReconcilingAnUnknownTaskIsFalseNotAnError(): void {
		$this->tasks->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));
		$this->projector->expects($this->never())->method('reconcile');

		$this->assertFalse($this->service()->reconcile('nope'));
	}

	public function testAFailedReconcileIsFalseAndLogged(): void {
		$task = TaskCalendarProjectorTest::assignedTask();
		$this->projector->method('reconcile')->willThrowException(new \RuntimeException('down'));
		$this->logger->expects($this->once())->method('warning');

		$this->assertFalse($this->service()->reconcileTask($task));
	}
}
