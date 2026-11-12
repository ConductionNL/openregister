<?php

/**
 * ScheduledReportJobLogGoldenTest
 *
 * Pins the log lines and call order of one ScheduledReportJob pass: the
 * enumeration failure, and a pass mixing skipped, run and failing reports.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTimeInterface;
use OCA\OpenRegister\Notifications\BackgroundJob\ScheduledReportJob;
use OCA\OpenRegister\Notifications\Db\ScheduledReport;
use OCA\OpenRegister\Notifications\Db\ScheduledReportMapper;
use OCA\OpenRegister\Service\ScheduledReportService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Golden log lines and call order of ScheduledReportJob::run().
 */
class ScheduledReportJobLogGoldenTest extends TestCase {

	/**
	 * The mapper.
	 *
	 * @var ScheduledReportMapper&MockObject
	 */
	private ScheduledReportMapper&MockObject $mapper;

	/**
	 * The service.
	 *
	 * @var ScheduledReportService&MockObject
	 */
	private ScheduledReportService&MockObject $service;

	/**
	 * Every service call and log line, in order; log context without `line`.
	 *
	 * @var list<array>
	 */
	private array $events = [];

	/**
	 * The `now` passed to each isDue() call.
	 *
	 * @var list<DateTimeInterface>
	 */
	private array $nows = [];

	/**
	 * The job under test.
	 *
	 * @var ScheduledReportJob
	 */
	private ScheduledReportJob $job;

	/**
	 * Build the job over recording fakes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(ScheduledReportMapper::class);
		$this->service = $this->createMock(ScheduledReportService::class);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['line']);
					$this->events[] = [$level, $message, $context];
				}
			);
		}

		$this->job = new ScheduledReportJob(
			$this->createMock(ITimeFactory::class),
			$this->mapper,
			$this->service,
			$logger
		);
	}//end setUp()

	/**
	 * An enabled report with an id.
	 *
	 * @param int $id The report id.
	 *
	 * @return ScheduledReport
	 */
	private function report(int $id): ScheduledReport {
		$report = new ScheduledReport();
		$prop = (new \ReflectionClass($report))->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($report, $id);
		$report->setEnabled(true);
		return $report;
	}//end report()

	/**
	 * Run the job once.
	 *
	 * @return void
	 */
	private function runJob(): void {
		$method = new ReflectionMethod(ScheduledReportJob::class, 'run');
		$method->setAccessible(true);
		$method->invoke($this->job, null);
	}//end runJob()

	/**
	 * A failing enumeration logs one warning and runs nothing.
	 *
	 * @return void
	 */
	public function testEnumerationFailure(): void {
		$this->mapper->method('findEnabled')->willThrowException(new RuntimeException('db down'));
		$this->service->expects(self::never())->method('isDue');

		$this->runJob();

		self::assertSame(
			[
				[
					'warning',
					'[ScheduledReportJob] Failed to enumerate enabled scheduled reports',
					['file' => (new \ReflectionClass(ScheduledReportJob::class))->getFileName(), 'error' => 'db down'],
				],
			],
			$this->events
		);
	}//end testEnumerationFailure()

	/**
	 * Skipped, run and failing reports, in order, then the pass summary.
	 *
	 * @return void
	 */
	public function testMixedPass(): void {
		$this->mapper->method('findEnabled')->willReturn([$this->report(1), $this->report(2), $this->report(3), $this->report(4)]);
		$this->service->method('isDue')->willReturnCallback(
			function (ScheduledReport $report, DateTimeInterface $now): bool {
				$this->nows[] = $now;
				$this->events[] = ['isDue', $report->getId()];
				return $report->getId() !== 2;
			}
		);
		$this->service->method('runOne')->willReturnCallback(
			function (ScheduledReport $report): void {
				$this->events[] = ['runOne', $report->getId()];
				if ($report->getId() === 3) {
					throw new RuntimeException('boom');
				}
			}
		);

		$this->runJob();

		$file = (new \ReflectionClass(ScheduledReportJob::class))->getFileName();
		self::assertSame(
			[
				['isDue', 1],
				['runOne', 1],
				['isDue', 2],
				['isDue', 3],
				['runOne', 3],
				[
					'warning',
					'[ScheduledReportJob] Run failed for scheduled report',
					['file' => $file, 'reportId' => 3, 'error' => 'boom'],
				],
				['isDue', 4],
				['runOne', 4],
				[
					'info',
					'[ScheduledReportJob] Scheduled-report pass complete',
					['file' => $file, 'candidates' => 4, 'ran' => 2, 'skipped' => 1],
				],
			],
			$this->events
		);
		self::assertCount(4, $this->nows);
		self::assertSame($this->nows[0], $this->nows[3], 'one `now` is shared by the whole pass');
	}//end testMixedPass()

	/**
	 * An empty pass still logs its summary.
	 *
	 * @return void
	 */
	public function testEmptyPass(): void {
		$this->mapper->method('findEnabled')->willReturn([]);

		$this->runJob();

		self::assertSame(
			[
				[
					'info',
					'[ScheduledReportJob] Scheduled-report pass complete',
					['file' => (new \ReflectionClass(ScheduledReportJob::class))->getFileName(), 'candidates' => 0, 'ran' => 0, 'skipped' => 0],
				],
			],
			$this->events
		);
	}//end testEmptyPass()
}//end class
