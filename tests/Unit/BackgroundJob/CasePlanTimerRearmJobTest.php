<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-plan-item-deadlines-run-on-the-shared-clock
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\CasePlanTimerRearmJob;
use OCA\OpenRegister\Service\Case\CasePlanTimerRearm;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\BackgroundJob\CasePlanTimerRearmJob
 */
class CasePlanTimerRearmJobTest extends TestCase {

	private CasePlanTimerRearm&MockObject $rearm;

	private LoggerInterface&MockObject $logger;

	private CasePlanTimerRearmJob $job;

	protected function setUp(): void {
		parent::setUp();
		$this->rearm = $this->createMock(CasePlanTimerRearm::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-09-01 10:00:00'));
		$this->job = new CasePlanTimerRearmJob(time: $time, rearm: $this->rearm, logger: $this->logger);
	}//end setUp()

	private function tick(): void {
		(new ReflectionMethod(CasePlanTimerRearmJob::class, 'run'))->invoke($this->job, null);
	}//end tick()

	public function testRunsOnTheTimerSweepsInterval(): void {
		self::assertSame(300, $this->job->getInterval());
	}//end testRunsOnTheTimerSweepsInterval()

	public function testTwoPassesNeverRunAtOnce(): void {
		$property = new \ReflectionProperty(\OCP\BackgroundJob\Job::class, 'allowParallelRuns');
		self::assertFalse($property->getValue($this->job));
	}//end testTwoPassesNeverRunAtOnce()

	public function testAPassThatDidWorkIsLoggedAndAQuietOneIsNot(): void {
		$this->rearm->expects(self::exactly(2))->method('run')->willReturnOnConsecutiveCalls(
			['armed' => 1, 'enforced' => 1, 'dropped' => 0, 'failed' => 2],
			['armed' => 0, 'enforced' => 0, 'dropped' => 0, 'failed' => 0]
		);
		$this->logger->expects(self::once())->method('info')
			->with(self::stringContains('Armed 1 statutory timer(s), enforced 1 expired term(s), dropped 0 marker(s); 2 still failing'), self::anything());

		$this->tick();
		$this->tick();
	}//end testAPassThatDidWorkIsLoggedAndAQuietOneIsNot()

	public function testAFailingPassIsLoggedNotThrown(): void {
		$this->rearm->method('run')->willThrowException(new RuntimeException('db gone'));
		$this->logger->expects(self::once())->method('error')->with(self::stringContains('db gone'), self::anything());

		$this->tick();
	}//end testAFailingPassIsLoggedNotThrown()
}//end class
