<?php

/**
 * SweepExpiredExportRunsJob unit tests.
 *
 * The job asks the recorder to sweep up to five batches a tick, stops on the
 * first short batch, stops on the first failure, and logs only when it removed
 * something.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\JobRuntime;
use OCA\OpenRegister\BackgroundJob\SweepExpiredExportRunsJob;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Unit tests for the export-run sweep job.
 */
class SweepExpiredExportRunsJobTest extends TestCase {

	/**
	 * The mocked recorder.
	 *
	 * @var ExportRunRecorder&MockObject
	 */
	private ExportRunRecorder&MockObject $runs;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The job under test.
	 *
	 * @var SweepExpiredExportRunsJob
	 */
	private SweepExpiredExportRunsJob $job;

	/**
	 * Build the job over a mocked recorder and logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->runs   = $this->createMock(ExportRunRecorder::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->job    = new SweepExpiredExportRunsJob(
			new JobRuntime($this->createMock(ITimeFactory::class), $this->logger),
			$this->runs,
		);
	}//end setUp()

	/**
	 * Run one tick.
	 *
	 * @return void
	 */
	private function tick(): void {
		(new ReflectionMethod(SweepExpiredExportRunsJob::class, 'run'))->invoke($this->job, null);
	}//end tick()

	/**
	 * The sweep runs hourly.
	 *
	 * @return void
	 */
	public function testIntervalIsHourly(): void {
		$this->assertSame(3600, $this->job->getInterval());
	}//end testIntervalIsHourly()

	/**
	 * Nothing due: one sweep, no log at all.
	 *
	 * @return void
	 */
	public function testNothingSweptAsksOnceAndStaysSilent(): void {
		$this->runs->expects($this->once())->method('sweep')->willReturn(0);
		$this->logger->expects($this->never())->method('info');
		$this->logger->expects($this->never())->method('error');

		$this->tick();
	}//end testNothingSweptAsksOnceAndStaysSilent()

	/**
	 * A full batch is followed by another; a short one ends the tick and the total is logged.
	 *
	 * @return void
	 */
	public function testShortBatchEndsTheTickAndTotalIsLogged(): void {
		$this->runs->expects($this->exactly(2))->method('sweep')
			->willReturnOnConsecutiveCalls(ExportRunRecorder::SWEEP_BATCH, 3);
		$this->logger->expects($this->once())->method('info')->with(
			'[SweepExpiredExportRunsJob] Expired export files removed',
			$this->callback(static fn (array $ctx): bool => $ctx['swept'] === ExportRunRecorder::SWEEP_BATCH + 3)
		);

		$this->tick();
	}//end testShortBatchEndsTheTickAndTotalIsLogged()

	/**
	 * A backlog of full batches is capped at five sweeps per tick.
	 *
	 * @return void
	 */
	public function testFullBatchesAreCappedAtFivePasses(): void {
		$this->runs->expects($this->exactly(5))->method('sweep')->willReturn(ExportRunRecorder::SWEEP_BATCH);
		$this->logger->expects($this->once())->method('info')->with(
			$this->anything(),
			$this->callback(static fn (array $ctx): bool => $ctx['swept'] === 5 * ExportRunRecorder::SWEEP_BATCH)
		);

		$this->tick();
	}//end testFullBatchesAreCappedAtFivePasses()

	/**
	 * A failing sweep is logged as an error and ends the tick without a further sweep or info log.
	 *
	 * @return void
	 */
	public function testFailureLogsErrorAndStops(): void {
		$this->runs->expects($this->exactly(2))->method('sweep')->willReturnCallback(
			static function (): int {
				static $calls = 0;
				$calls++;
				if ($calls === 2) {
					throw new RuntimeException('disk gone');
				}

				return ExportRunRecorder::SWEEP_BATCH;
			}
		);
		$this->logger->expects($this->once())->method('error')->with(
			'[SweepExpiredExportRunsJob] The sweep failed',
			$this->callback(static fn (array $ctx): bool => $ctx['error'] === 'disk gone')
		);
		$this->logger->expects($this->never())->method('info');

		$this->tick();
	}//end testFailureLogsErrorAndStops()
}//end class
