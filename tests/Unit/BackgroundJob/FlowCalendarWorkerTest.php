<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\FlowCalendarWorker;
use OCA\OpenRegister\Service\Flow\FlowCalendarService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The calendar tick: asks the service for the due moments at the job's clock and never throws.
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */
class FlowCalendarWorkerTest extends TestCase {

	/**
	 * @var FlowCalendarService&MockObject
	 */
	private FlowCalendarService&MockObject $calendar;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The job's clock.
	 *
	 * @var DateTime
	 */
	private DateTime $now;

	/**
	 * The job.
	 *
	 * @var FlowCalendarWorker
	 */
	private FlowCalendarWorker $job;

	protected function setUp(): void {
		$this->calendar = $this->createMock(FlowCalendarService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->now = new DateTime('2027-03-01T00:05:00+00:00');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn($this->now);
		$this->job = new FlowCalendarWorker($time, $this->logger, $this->calendar);
	}//end setUp()

	private function runJob(): void {
		(new ReflectionMethod(FlowCalendarWorker::class, 'run'))->invoke($this->job, null);
	}//end runJob()

	public function testIntervalIsFiveMinutes(): void {
		$this->assertSame(300, $this->job->getInterval());
	}//end testIntervalIsFiveMinutes()

	public function testQueuedRunsAreLoggedWithTheirCount(): void {
		$this->calendar->expects($this->once())->method('fireDue')->with($this->identicalTo($this->now))->willReturn(['run-1', 'run-2']);
		$this->logger->expects($this->once())->method('info')->with(
			'[FlowCalendarWorker] Queued 2 calendar flow run(s)',
			$this->callback(static fn (array $context): bool => $context['runs'] === ['run-1', 'run-2'])
		);

		$this->runJob();
	}//end testQueuedRunsAreLoggedWithTheirCount()

	public function testNothingDueLogsNothing(): void {
		$this->calendar->method('fireDue')->willReturn([]);
		$this->logger->expects($this->never())->method('info');
		$this->logger->expects($this->never())->method('error');

		$this->runJob();
	}//end testNothingDueLogsNothing()

	public function testATickFailureIsLoggedNotThrown(): void {
		$this->calendar->method('fireDue')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with($this->stringContains('db down'));

		$this->runJob();
	}//end testATickFailureIsLoggedNotThrown()
}//end class
