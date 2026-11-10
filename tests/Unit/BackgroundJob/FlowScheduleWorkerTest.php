<?php

/**
 * FlowScheduleWorker fires due flows at the injected clock time and contains failures.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\JobRuntime;
use DateTime;
use OCA\OpenRegister\BackgroundJob\FlowScheduleWorker;
use OCA\OpenRegister\Service\Flow\FlowScheduleServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Behaviour of the job's run().
 */
class FlowScheduleWorkerTest extends TestCase {

	/** @var FlowScheduleServiceInterface&MockObject */
	private FlowScheduleServiceInterface $schedule;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private DateTime $now;

	private FlowScheduleWorker $job;

	protected function setUp(): void {
		$this->schedule = $this->createMock(FlowScheduleServiceInterface::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
		$this->now      = new DateTime('2026-01-15T10:05:00+00:00');
		$time           = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn($this->now);
		$this->job = new FlowScheduleWorker(new JobRuntime($time, $this->logger), $this->schedule);
	}

	private function runJob(): void {
		$method = new ReflectionMethod(FlowScheduleWorker::class, 'run');
		$method->invoke($this->job, null);
	}

	public function testIntervalIsFiveMinutes(): void {
		$this->assertSame(300, $this->job->getInterval());
	}

	public function testFiredFlowsAreLoggedWithTheirCount(): void {
		$this->schedule->expects($this->once())->method('fireDueFlows')
			->with($this->identicalTo($this->now))->willReturn(['flow-a', 'flow-b']);
		$this->logger->expects($this->once())->method('info')->with(
			'[FlowScheduleWorker] Queued 2 scheduled flow run(s)',
			$this->callback(fn (array $context): bool => $context['flows'] === ['flow-a', 'flow-b'])
		);
		$this->logger->expects($this->never())->method('error');

		$this->runJob();
	}

	public function testNothingDueLogsNothing(): void {
		$this->schedule->expects($this->once())->method('fireDueFlows')->willReturn([]);
		$this->logger->expects($this->never())->method('info');
		$this->logger->expects($this->never())->method('error');

		$this->runJob();
	}

	public function testTickFailureIsLoggedNotThrown(): void {
		$this->schedule->method('fireDueFlows')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->never())->method('info');
		$this->logger->expects($this->once())->method('error')->with(
			'[FlowScheduleWorker] Schedule tick failed: db down',
			$this->callback(fn (array $context): bool => isset($context['file'], $context['line']) === true)
		);

		$this->runJob();
	}
}
