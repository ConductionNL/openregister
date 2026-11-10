<?php

/**
 * One sweep pass: calendars reset, due expiries and rungs fired and counted,
 * failures counted and logged without stopping the pass, a full batch marked
 * truncated, and due task timeouts applied only when the task scan is wired.
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
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerSweep;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerSweepTasks;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerSweepTimers;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * FlowTimerSweep::run().
 */
class FlowTimerSweepBehaviourTest extends TestCase {

	private FlowTimerMapperInterface&MockObject $timers;

	private FlowTimerService&MockObject $service;

	private WorkingCalendarService&MockObject $calendars;

	private LoggerInterface&MockObject $logger;

	private DateTimeImmutable $now;

	protected function setUp(): void {
		$this->timers = $this->createMock(FlowTimerMapperInterface::class);
		$this->service = $this->createMock(FlowTimerService::class);
		$this->calendars = $this->createMock(WorkingCalendarService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->now = new DateTimeImmutable('2026-03-10T09:00:00+00:00');
	}//end setUp()

	private function sweep(?FlowTimerSweepTasks $tasks = null): FlowTimerSweep {
		return new FlowTimerSweep(new FlowTimerSweepTimers($this->timers, $this->service, $this->calendars), $this->logger, $tasks);
	}//end sweep()

	private function timer(string $uuid): FlowTimer {
		$timer = new FlowTimer();
		$timer->setUuid($uuid);
		return $timer;
	}//end timer()

	private function task(string $uuid, string $onTimeout): Task {
		$task = new Task();
		$task->setUuid($uuid);
		$task->setOnTimeout($onTimeout);
		$task->setExpiresAt(new DateTime('2026-03-09T17:00:00+00:00'));
		return $task;
	}//end task()

	public function testExpiriesAndRungsAreFiredAndCountedPerOutcome(): void {
		$this->calendars->expects($this->once())->method('reset');
		$this->timers->expects($this->once())->method('findDueExpiries')->with($this->now, 10)
			->willReturn([$this->timer('e1'), $this->timer('e2'), $this->timer('e3')]);
		$this->timers->expects($this->once())->method('findDueRungs')->with($this->now, 10)
			->willReturn([$this->timer('r1'), $this->timer('r2')]);
		$this->service->method('fireExpiry')->willReturnCallback(
			static fn (FlowTimer $timer): bool => $timer->getUuid() !== 'e2'
		);
		$this->service->method('fireRungs')->willReturnCallback(
			static fn (FlowTimer $timer): int => ['r1' => 2, 'r2' => 0][$timer->getUuid()]
		);
		$this->logger->expects($this->never())->method('error');

		$result = $this->sweep()->run($this->now, 10);

		$this->assertSame(
			['expiriesFired' => 2, 'rungsFired' => 2, 'taskTimeouts' => 0, 'truncated' => false, 'errors' => 0],
			$result
		);
	}//end testExpiriesAndRungsAreFiredAndCountedPerOutcome()

	public function testAFailingFireIsCountedLoggedAndTheRestOfThePassContinues(): void {
		$this->timers->method('findDueExpiries')->willReturn([$this->timer('bad-expiry'), $this->timer('e2')]);
		$this->timers->method('findDueRungs')->willReturn([$this->timer('bad-rung'), $this->timer('r2')]);
		$this->service->method('fireExpiry')->willReturnCallback(
			static function (FlowTimer $timer): bool {
				if ($timer->getUuid() === 'bad-expiry') {
					throw new RuntimeException('expiry boom');
				}
				return true;
			}
		);
		$this->service->method('fireRungs')->willReturnCallback(
			static function (FlowTimer $timer): int {
				if ($timer->getUuid() === 'bad-rung') {
					throw new RuntimeException('rung boom');
				}
				return 1;
			}
		);
		$logged = [];
		$this->logger->expects($this->exactly(2))->method('error')->willReturnCallback(
			static function (string $message, array $context) use (&$logged): void {
				$logged[] = [$message, $context['timer']];
			}
		);

		$result = $this->sweep()->run($this->now, 50);

		$this->assertSame(1, $result['expiriesFired']);
		$this->assertSame(1, $result['rungsFired']);
		$this->assertSame(2, $result['errors']);
		$this->assertSame(
			[
				['[FlowTimerSweep] Expiry fire failed: expiry boom', 'bad-expiry'],
				['[FlowTimerSweep] Rung fire failed: rung boom', 'bad-rung'],
			],
			$logged
		);
	}//end testAFailingFireIsCountedLoggedAndTheRestOfThePassContinues()

	public function testAFullBatchMarksThePassTruncatedAndABatchBelowOneIsRaisedToOne(): void {
		$this->timers->expects($this->once())->method('findDueExpiries')->with($this->now, 1)->willReturn([$this->timer('e1')]);
		$this->timers->method('findDueRungs')->willReturn([]);
		$this->service->method('fireExpiry')->willReturn(true);

		$result = $this->sweep()->run($this->now, 0);

		$this->assertTrue($result['truncated']);
		$this->assertSame(1, $result['expiriesFired']);
	}//end testAFullBatchMarksThePassTruncatedAndABatchBelowOneIsRaisedToOne()

	public function testAFullRungBatchAloneAlsoMarksThePassTruncated(): void {
		$this->timers->method('findDueExpiries')->willReturn([]);
		$this->timers->method('findDueRungs')->willReturn([$this->timer('r1'), $this->timer('r2')]);
		$this->service->method('fireRungs')->willReturn(1);

		$this->assertTrue($this->sweep()->run($this->now, 2)['truncated']);
		$this->assertFalse($this->sweep()->run($this->now, 3)['truncated']);
	}//end testAFullRungBatchAloneAlsoMarksThePassTruncated()

	public function testDueTaskTimeoutsAreAppliedWithTheirDeclaredOutcomeAndReason(): void {
		$this->timers->method('findDueExpiries')->willReturn([]);
		$this->timers->method('findDueRungs')->willReturn([]);
		$tasks = $this->createMock(TaskMapper::class);
		$tasks->expects($this->once())->method('findDueTimeouts')->with($this->now, 5)
			->willReturn([$this->task('t1', 'cancel'), $this->task('t2', 'escalate')]);
		$taskService = $this->createMock(TaskServiceInterface::class);
		$calls = [];
		$taskService->expects($this->exactly(2))->method('applyTimerOutcome')->willReturnCallback(
			function (string $uuid, string $outcome, string $source, string $reason) use (&$calls): Task {
				$calls[] = [$uuid, $outcome, $source, $reason];
				if ($uuid === 't2') {
					throw new RuntimeException('conflict');
				}
				return new Task();
			}
		);
		$this->logger->expects($this->once())->method('error')
			->with('[FlowTimerSweep] Task timeout failed: conflict', $this->callback(static fn (array $c): bool => $c['task'] === 't2'));

		$result = $this->sweep(new FlowTimerSweepTasks($tasks, $taskService))->run($this->now, 5);

		$this->assertSame(1, $result['taskTimeouts']);
		$this->assertSame(1, $result['errors']);
		$this->assertSame(
			[
				't1',
				'cancel',
				'task-expiry-sweep',
				"Task expired at 2026-03-09T17:00:00+00:00; declared onTimeout 'cancel' applied.",
			],
			$calls[0]
		);
	}//end testDueTaskTimeoutsAreAppliedWithTheirDeclaredOutcomeAndReason()

	public function testAFullTaskBatchMarksThePassTruncated(): void {
		$this->timers->method('findDueExpiries')->willReturn([]);
		$this->timers->method('findDueRungs')->willReturn([]);
		$tasks = $this->createMock(TaskMapper::class);
		$tasks->method('findDueTimeouts')->willReturn([$this->task('t1', 'cancel')]);
		$taskService = $this->createMock(TaskServiceInterface::class);
		$taskService->method('applyTimerOutcome')->willReturn(new Task());

		$result = $this->sweep(new FlowTimerSweepTasks($tasks, $taskService))->run($this->now, 1);

		$this->assertTrue($result['truncated']);
		$this->assertSame(1, $result['taskTimeouts']);
	}//end testAFullTaskBatchMarksThePassTruncated()

	public function testWithoutTheTaskServiceNoTaskScanRuns(): void {
		$this->timers->method('findDueExpiries')->willReturn([]);
		$this->timers->method('findDueRungs')->willReturn([]);
		$tasks = $this->createMock(TaskMapper::class);
		$tasks->expects($this->never())->method('findDueTimeouts');

		$result = $this->sweep(new FlowTimerSweepTasks($tasks, null))->run($this->now, 5);

		$this->assertSame(0, $result['taskTimeouts']);
		$this->assertFalse($result['truncated']);
	}//end testWithoutTheTaskServiceNoTaskScanRuns()
}//end class
