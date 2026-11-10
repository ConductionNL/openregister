<?php

/**
 * HandoffQueueDrainJob logs only sweeps that did something and contains failures.
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
use OCA\OpenRegister\BackgroundJob\HandoffQueueDrainJob;
use OCA\OpenRegister\Service\Handoff\HandoffServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Behaviour of the job's run().
 */
class HandoffQueueDrainJobTest extends TestCase {

	/** @var HandoffServiceInterface&MockObject */
	private HandoffServiceInterface $handoff;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private HandoffQueueDrainJob $job;

	protected function setUp(): void {
		$this->handoff = $this->createMock(HandoffServiceInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->job     = new HandoffQueueDrainJob(new JobRuntime($this->createMock(ITimeFactory::class), $this->logger), $this->handoff);
	}

	private function runJob(): void {
		$method = new ReflectionMethod(HandoffQueueDrainJob::class, 'run');
		$method->invoke($this->job, null);
	}

	public function testIntervalIsFiveMinutes(): void {
		$this->assertSame(300, $this->job->getInterval());
	}

	public function testEmptySweepLogsNothing(): void {
		$this->handoff->expects($this->once())->method('drainParked')->with()
			->willReturn(['drained' => 0, 'failed' => 0, 'still_parked' => 3]);
		$this->logger->expects($this->never())->method('info');
		$this->logger->expects($this->never())->method('error');

		$this->runJob();
	}

	public function testDrainedEntriesAreLogged(): void {
		$summary = ['drained' => 2, 'failed' => 0];
		$this->handoff->method('drainParked')->willReturn($summary);
		$this->logger->expects($this->once())->method('info')->with(
			'[HandoffQueueDrainJob] Fallback drain sweep completed',
			$this->callback(fn (array $context): bool => $context['summary'] === $summary)
		);

		$this->runJob();
	}

	public function testFailedEntriesAloneAreLogged(): void {
		$summary = ['drained' => 0, 'failed' => 1];
		$this->handoff->method('drainParked')->willReturn($summary);
		$this->logger->expects($this->once())->method('info')->with(
			'[HandoffQueueDrainJob] Fallback drain sweep completed',
			$this->callback(fn (array $context): bool => $context['summary'] === $summary)
		);

		$this->runJob();
	}

	public function testSweepFailureIsLoggedNotThrown(): void {
		$this->handoff->method('drainParked')->willThrowException(new RuntimeException('queue table missing'));
		$this->logger->expects($this->never())->method('info');
		$this->logger->expects($this->once())->method('error')->with(
			'[HandoffQueueDrainJob] Fallback drain sweep failed: queue table missing',
			$this->callback(fn (array $context): bool => isset($context['file'], $context['line']) === true)
		);

		$this->runJob();
	}
}
