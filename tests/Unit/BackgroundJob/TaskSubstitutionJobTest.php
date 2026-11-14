<?php

/**
 * TaskSubstitutionJob: an absence that starts moves the person's open tasks
 * to the stand-in; one that ends gives back what the stand-in never touched;
 * a run reads at most 200 tasks and requeues itself with a watermark.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-untouched-tasks-return-when-the-absence-ends
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\TaskSubstitutionJob;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Design D-5.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\TaskSubstitutionJob
 * @uses \OCA\OpenRegister\Db\Task
 */
class TaskSubstitutionJobTest extends TestCase {

	private TaskMapper&MockObject $tasks;

	private TaskService&MockObject $tasker;

	private IJobList&MockObject $jobs;

	private LoggerInterface&MockObject $logger;

	/**
	 * Fresh mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->tasker = $this->createMock(TaskService::class);
		$this->jobs = $this->createMock(IJobList::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The job over the mocks.
	 *
	 * @return TaskSubstitutionJob The job.
	 */
	private function job(): TaskSubstitutionJob {
		return new TaskSubstitutionJob(
			time: $this->createMock(ITimeFactory::class),
			tasks: $this->tasks,
			tasker: $this->tasker,
			jobs: $this->jobs,
			logger: $this->logger
		);
	}//end job()

	/**
	 * An open task.
	 *
	 * @param int         $id         The id.
	 * @param string      $assignee   The assignee.
	 * @param string|null $onBehalfOf Who it is handled for.
	 *
	 * @return Task The task.
	 */
	private function task(int $id, string $assignee, ?string $onBehalfOf = null): Task {
		$task = new Task();
		$task->setId($id);
		$task->setUuid('t-' . $id);
		$task->setAssignee($assignee);
		$task->setOnBehalfOf($onBehalfOf);

		return $task;
	}//end task()

	/**
	 * On start, each of Anna's open tasks goes through substitution.
	 *
	 * @return void
	 */
	public function testStartSubstitutesTheAbsentPersonsTasks(): void {
		$this->tasks->expects($this->once())->method('findOpenAssignedTo')->with('anna', 0, 200)
			->willReturn([$this->task(id: 1, assignee: 'anna'), $this->task(id: 2, assignee: 'anna')]);
		$substituted = [];
		$this->tasker->method('substituteForAbsence')->willReturnCallback(
			static function (string $uuid) use (&$substituted): ?Task {
				$substituted[] = $uuid;

				return null;
			}
		);
		$this->tasker->expects($this->never())->method('returnFromStandIn');
		$this->jobs->expects($this->never())->method('add');

		$read = $this->job()->process(argument: ['mode' => 'start', 'uid' => 'anna', 'absenceId' => '42', 'standIn' => 'chris']);

		$this->assertSame(2, $read);
		$this->assertSame(['t-1', 't-2'], $substituted);
	}//end testStartSubstitutesTheAbsentPersonsTasks()

	/**
	 * On end, Chris's tasks held for Anna are offered back; his own are not.
	 *
	 * @return void
	 */
	public function testEndReturnsOnlyTasksHeldForTheAbsentPerson(): void {
		$this->tasks->expects($this->once())->method('findOpenAssignedTo')->with('chris', 0, 200)
			->willReturn([
				$this->task(id: 3, assignee: 'chris', onBehalfOf: 'anna'),
				$this->task(id: 4, assignee: 'chris'),
				$this->task(id: 5, assignee: 'chris', onBehalfOf: 'ed'),
			]);
		$this->tasker->expects($this->once())->method('returnFromStandIn')->with('t-3', '42');
		$this->tasker->expects($this->never())->method('substituteForAbsence');

		$this->job()->process(argument: ['mode' => 'end', 'uid' => 'anna', 'absenceId' => '42', 'standIn' => 'chris']);
	}//end testEndReturnsOnlyTasksHeldForTheAbsentPerson()

	/**
	 * 450 tasks take three runs of at most 200, each requeueing from its last id.
	 *
	 * @return void
	 */
	public function testFourHundredFiftyTasksTakeThreeRuns(): void {
		$all = [];
		for ($id = 1; $id <= 450; $id++) {
			$all[] = $this->task(id: $id, assignee: 'anna');
		}

		$this->tasks->method('findOpenAssignedTo')->willReturnCallback(
			static fn (string $uid, int $afterId, int $limit): array => array_slice(
				array_values(array_filter($all, static fn (Task $t): bool => $t->getId() > $afterId)),
				0,
				$limit
			)
		);
		$queued = [];
		$this->jobs->method('add')->willReturnCallback(
			static function (string $class, mixed $argument) use (&$queued): void {
				$queued[] = $argument;
			}
		);
		$this->tasker->expects($this->exactly(450))->method('substituteForAbsence');

		$argument = ['mode' => 'start', 'uid' => 'anna', 'absenceId' => '42', 'standIn' => 'chris', 'after' => 0];
		$reads = [];
		$runs = 0;
		do {
			$reads[] = $this->job()->process(argument: $argument);
			$runs++;
			$argument = ($queued[$runs - 1] ?? null);
		} while ($argument !== null && $runs < 5);

		$this->assertSame([200, 200, 50], $reads);
		$this->assertSame([200, 400], array_map(static fn (array $a): int => $a['after'], $queued));
	}//end testFourHundredFiftyTasksTakeThreeRuns()

	/**
	 * One failing task is logged and the rest still run.
	 *
	 * @return void
	 */
	public function testAFailingTaskDoesNotStopTheBatch(): void {
		$this->tasks->method('findOpenAssignedTo')->willReturn([$this->task(id: 1, assignee: 'anna'), $this->task(id: 2, assignee: 'anna')]);
		$this->tasker->expects($this->exactly(2))->method('substituteForAbsence')
			->willReturnOnConsecutiveCalls($this->throwException(new RuntimeException('locked')), null);
		$this->logger->expects($this->once())->method('warning');

		$this->assertSame(2, $this->job()->process(argument: ['mode' => 'start', 'uid' => 'anna', 'absenceId' => '42']));
	}//end testAFailingTaskDoesNotStopTheBatch()

	/**
	 * Arguments that describe no absence do nothing.
	 *
	 * @return void
	 */
	public function testMalformedArgumentsDoNothing(): void {
		$this->tasks->expects($this->never())->method('findOpenAssignedTo');

		$this->assertSame(0, $this->job()->process(argument: 'anna'));
		$this->assertSame(0, $this->job()->process(argument: ['mode' => 'later', 'uid' => 'anna']));
		$this->assertSame(0, $this->job()->process(argument: ['mode' => 'start', 'uid' => ' ']));
		$this->assertSame(0, $this->job()->process(argument: ['mode' => 'end', 'uid' => 'anna', 'standIn' => '']));
	}//end testMalformedArgumentsDoNothing()
}//end class
