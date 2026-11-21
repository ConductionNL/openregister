<?php

/**
 * The projection of open timers onto their task: the earliest open due and
 * expiry deadline per purpose, the suspension, and the non-task, missing-task
 * and empty cases.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapperInterface;
use OCA\OpenRegister\Db\FlowTimer\TimerSubject;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerProjection;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * FlowTimerProjection.
 */
class FlowTimerProjectionBehaviourTest extends TestCase {

	private FlowTimerMapperInterface&MockObject $timers;

	private TaskMapper&MockObject $tasks;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->timers = $this->createMock(FlowTimerMapperInterface::class);
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function projection(): FlowTimerProjection {
		return new FlowTimerProjection($this->timers, $this->tasks, $this->logger);
	}//end projection()

	private function timer(string $subjectType, ?string $purpose = null, ?string $fireAt = null): FlowTimer {
		$timer = new FlowTimer();
		$timer->setSubjectType($subjectType);
		$timer->setSubjectUuid('task-1');
		$timer->setPurpose($purpose);
		$timer->setFireAt($fireAt === null ? null : new DateTime($fireAt));
		return $timer;
	}//end timer()

	public function testTheEarliestOpenDeadlinePerPurposeAndTheSuspensionLandOnTheTask(): void {
		$task = new Task();
		$this->tasks->method('findByUuid')->with('task-1')->willReturn($task);
		$this->timers->expects($this->once())->method('findBySubject')
			->with(new TimerSubject('task', 'task-1'), [FlowTimer::STATE_ARMED, FlowTimer::STATE_SUSPENDED])
			->willReturn(
				[
					$this->timer('task', 'due', '2026-05-10T12:00:00+00:00'),
					$this->timer('task', 'due', '2026-05-08T12:00:00+00:00'),
					$this->timer('task', 'due', '2026-05-09T12:00:00+00:00'),
					$this->timer('task', 'expiry', '2026-06-01T00:00:00+00:00'),
					$this->timer('task', 'reminder', '2026-01-01T00:00:00+00:00'),
					$this->timer('task', 'expiry', null),
				]
			);
		$this->tasks->expects($this->once())->method('update')->with($this->identicalTo($task))->willReturn($task);

		$this->projection()->project(
			$this->timer('task'),
			new DateTimeImmutable('2026-05-20T00:00:00+00:00')
		);

		$this->assertSame('2026-05-08T12:00:00+00:00', $task->getDueAt()?->format(DATE_ATOM));
		$this->assertSame('2026-06-01T00:00:00+00:00', $task->getExpiresAt()?->format(DATE_ATOM));
		$this->assertSame('2026-05-20T00:00:00+00:00', $task->getSuspendedUntil()?->format(DATE_ATOM));
	}//end testTheEarliestOpenDeadlinePerPurposeAndTheSuspensionLandOnTheTask()

	public function testWithNoOpenTimersTheTaskDeadlinesAreCleared(): void {
		$task = new Task();
		$task->setDueAt(new DateTime('2026-01-01T00:00:00+00:00'));
		$task->setExpiresAt(new DateTime('2026-01-02T00:00:00+00:00'));
		$this->tasks->method('findByUuid')->willReturn($task);
		$this->timers->method('findBySubject')->willReturn([]);
		$this->tasks->expects($this->once())->method('update')->with($this->identicalTo($task))->willReturn($task);

		$this->projection()->project($this->timer('task'));

		$this->assertNull($task->getDueAt());
		$this->assertNull($task->getExpiresAt());
		$this->assertNull($task->getSuspendedUntil());
	}//end testWithNoOpenTimersTheTaskDeadlinesAreCleared()

	public function testATimerOnAnotherSubjectTypeTouchesNoTask(): void {
		$this->tasks->expects($this->never())->method('findByUuid');
		$this->timers->expects($this->never())->method('findBySubject');
		$this->tasks->expects($this->never())->method('update');
		$this->logger->expects($this->never())->method('warning');

		$this->projection()->project($this->timer('run'));

		$this->assertNull($this->projection()->subjectTask($this->timer('run')));
	}//end testATimerOnAnotherSubjectTypeTouchesNoTask()

	public function testAMissingTaskIsLoggedAndNothingIsWritten(): void {
		$this->tasks->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));
		$this->timers->expects($this->never())->method('findBySubject');
		$this->tasks->expects($this->never())->method('update');
		$this->logger->expects($this->once())->method('warning')
			->with('[FlowTimerService] No task to project onto.', ['subject' => 'task-1']);

		$this->projection()->project($this->timer('task'));
	}//end testAMissingTaskIsLoggedAndNothingIsWritten()

	public function testSubjectTaskLooksTheTaskUpByTheTimerSubject(): void {
		$task = new Task();
		$this->tasks->expects($this->once())->method('findByUuid')->with('task-1')->willReturn($task);

		$this->assertSame($task, $this->projection()->subjectTask($this->timer('task')));
	}//end testSubjectTaskLooksTheTaskUpByTheTimerSubject()
}//end class
