<?php

/**
 * The sweep reads only rows it acts on: a due timer beyond the batch of
 * not-yet-due ones is still reached, counts report work performed, a hit
 * batch limit is reported as truncated, and one failure does not stop the pass.
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

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerSweep;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Timer\FlowTimerSweep
 * @covers \OCA\OpenRegister\Db\FlowTimer
 * @covers \OCA\OpenRegister\Db\FlowTimerMapper
 *
 * @uses \OCA\OpenRegister\Db\Task
 */
class FlowTimerSweepTest extends TestCase {

	private InMemoryTimerStore $store;

	private FlowTimerMapper $mapper;

	private FlowTimerService&MockObject $service;

	private WorkingCalendarService&MockObject $calendars;

	private LoggerInterface&MockObject $logger;

	private FlowTimerSweep $sweep;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryTimerStore(db: $this->createMock(IDBConnection::class));
		$this->mapper = $this->store->timerMapper();
		$this->service = $this->createMock(FlowTimerService::class);
		$this->calendars = $this->createMock(WorkingCalendarService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->sweep = new FlowTimerSweep(timers: $this->mapper, service: $this->service, calendars: $this->calendars, logger: $this->logger);
	}//end setUp()

	private function seed(string $uuid, string $purpose, string $fireAt, ?string $nextRungAt = null, string $state = FlowTimer::STATE_ARMED): FlowTimer {
		$timer = new FlowTimer();
		$timer->setUuid($uuid);
		$timer->setPurpose($purpose);
		$timer->setState($state);
		$timer->setFireAt(new DateTime($fireAt));
		if ($nextRungAt !== null) {
			$timer->setNextRungAt(new DateTime($nextRungAt));
		}

		return $this->mapper->insert($timer);
	}//end seed()

	public function testADueTimerBeyondTheBatchSizeIsStillReached(): void {
		// 250 armed expiry timers that are NOT due, and one that is, with a batch of 200.
		for ($i = 0; $i < 250; $i++) {
			$this->seed('later-' . $i, 'expiry', '2027-01-01 00:00:00');
		}

		$due = $this->seed('due-now', 'expiry', '2026-08-31 00:00:00');
		$this->service->expects(self::once())->method('fireExpiry')->with($due, self::anything())->willReturn(true);
		$this->calendars->expects(self::once())->method('reset');

		$result = $this->sweep->run(now: new DateTimeImmutable('2026-09-01 10:00:00'), batch: 200);
		self::assertSame(['expiriesFired' => 1, 'rungsFired' => 0, 'taskTimeouts' => 0, 'truncated' => false, 'errors' => 0], $result);
	}//end testADueTimerBeyondTheBatchSizeIsStillReached()

	public function testCountsReportWorkNotReadsAndTruncationIsVisible(): void {
		foreach (['a', 'b', 'c'] as $name) {
			$this->seed('exp-' . $name, 'expiry', '2026-08-30 00:00:00');
		}

		$this->seed('due-x', 'due', '2026-08-30 00:00:00', '2026-08-30 00:00:00');
		$this->seed('due-suspended', 'due', '2026-08-30 00:00:00', '2026-08-30 00:00:00', FlowTimer::STATE_SUSPENDED);

		// One of the three expiries was claimed by another pass: work performed is two.
		$this->service->method('fireExpiry')->willReturnOnConsecutiveCalls(true, false, true);
		$this->service->method('fireRungs')->willReturn(2);

		$result = $this->sweep->run(now: new DateTimeImmutable('2026-09-01 10:00:00'), batch: 3);
		self::assertSame(2, $result['expiriesFired']);
		self::assertSame(2, $result['rungsFired']);
		self::assertTrue($result['truncated'], 'the expiry scan hit the batch limit');
		self::assertSame(0, $result['errors']);
	}//end testCountsReportWorkNotReadsAndTruncationIsVisible()

	public function testASuspendedTimerIsNeverSelected(): void {
		$this->seed('s-1', 'expiry', '2026-08-30 00:00:00', '2026-08-30 00:00:00', FlowTimer::STATE_SUSPENDED);
		$this->service->expects(self::never())->method('fireExpiry');
		$this->service->expects(self::never())->method('fireRungs');
		self::assertSame(['expiriesFired' => 0, 'rungsFired' => 0, 'taskTimeouts' => 0, 'truncated' => false, 'errors' => 0], $this->sweep->run(now: new DateTimeImmutable('2026-09-01'), batch: 10));
	}//end testASuspendedTimerIsNeverSelected()

	public function testOneFailureIsCountedAndDoesNotStopThePass(): void {
		$this->seed('exp-1', 'expiry', '2026-08-30 00:00:00');
		$this->seed('exp-2', 'expiry', '2026-08-30 01:00:00');
		$this->seed('rung-1', 'due', '2026-10-30 00:00:00', '2026-08-30 00:00:00');
		$this->service->method('fireExpiry')->willReturnCallback(static function (FlowTimer $timer): bool {
			if ($timer->getUuid() === 'exp-1') {
				throw new RuntimeException('outcome failed');
			}

			return true;
		});
		$this->service->method('fireRungs')->willThrowException(new RuntimeException('ladder failed'));
		$this->logger->expects(self::exactly(2))->method('error');

		$result = $this->sweep->run(now: new DateTimeImmutable('2026-09-01'), batch: 10);
		self::assertSame(['expiriesFired' => 1, 'rungsFired' => 0, 'taskTimeouts' => 0, 'truncated' => false, 'errors' => 2], $result);
	}//end testOneFailureIsCountedAndDoesNotStopThePass()

	/**
	 * A sweep wired with the task scan closes each due task through the
	 * timer-outcome path with the TASK's declared behaviour, counts the work,
	 * reports truncation when the scan hits the batch limit, and one failure
	 * does not stop the pass (task-expiry-and-outcomes D-3).
	 */
	public function testDueTaskTimeoutsAreAppliedCountedAndErrorTolerant(): void {
		$due = [];
		foreach ([['t-1', 'error'], ['t-2', 'dead_letter'], ['t-3', 'skip']] as [$uuid, $behaviour]) {
			$task = new Task();
			$task->setUuid($uuid);
			$task->setOnTimeout($behaviour);
			$task->setExpiresAt(new DateTime('2026-08-30 00:00:00'));
			$due[] = $task;
		}

		$tasks = $this->createMock(TaskMapper::class);
		$tasks->expects(self::once())->method('findDueTimeouts')->willReturn($due);
		$taskService = $this->createMock(TaskService::class);
		$applied = [];
		$taskService->method('applyTimerOutcome')->willReturnCallback(
			static function (string $uuid, string $outcome, string $source, string $reason) use (&$applied): Task {
				if ($uuid === 't-2') {
					throw new RuntimeException('row is poisoned');
				}

				$applied[$uuid] = [$outcome, $source, $reason];

				return new Task();
			}
		);
		$this->logger->expects(self::once())->method('error');

		$sweep = new FlowTimerSweep(
			timers: $this->mapper,
			service: $this->service,
			calendars: $this->calendars,
			logger: $this->logger,
			tasks: $tasks,
			taskService: $taskService
		);
		$result = $sweep->run(now: new DateTimeImmutable('2026-09-01 10:00:00'), batch: 3);

		self::assertSame(2, $result['taskTimeouts']);
		self::assertSame(1, $result['errors']);
		self::assertTrue($result['truncated'], 'the task scan hit the batch limit');
		self::assertSame(['error', FlowTimerSweep::TIMEOUT_SOURCE], [$applied['t-1'][0], $applied['t-1'][1]]);
		self::assertSame('skip', $applied['t-3'][0]);
		self::assertStringContainsString('2026-08-30', $applied['t-1'][2], 'the reason names the deadline that passed');
	}//end testDueTaskTimeoutsAreAppliedCountedAndErrorTolerant()

	/**
	 * A sweep constructed WITHOUT the task scan (the pre-change positional
	 * shape) keeps running the two timer scans and reports zero task work.
	 */
	public function testASweepWithoutTheTaskScanStillSweepsTimers(): void {
		$this->seed('exp-1', 'expiry', '2026-08-30 00:00:00');
		$this->service->method('fireExpiry')->willReturn(true);

		$result = $this->sweep->run(now: new DateTimeImmutable('2026-09-01'), batch: 10);
		self::assertSame(['expiriesFired' => 1, 'rungsFired' => 0, 'taskTimeouts' => 0, 'truncated' => false, 'errors' => 0], $result);
	}//end testASweepWithoutTheTaskScanStillSweepsTimers()
}//end class
