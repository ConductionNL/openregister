<?php

/**
 * ScheduledReportJobTest
 *
 * Unit tests for `ScheduledReportJob` — per-report isolation (one report's
 * failure does not prevent another enabled, due report from running) and
 * the due-check gate (skips reports `ScheduledReportService::isDue()`
 * reports as not due).
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
 * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ScheduledReportJob;
use OCA\OpenRegister\Db\ScheduledReport;
use OCA\OpenRegister\Db\ScheduledReportMapper;
use OCA\OpenRegister\Service\ScheduledReportService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

class ScheduledReportJobTest extends TestCase {
	private ScheduledReportMapper&MockObject $mapper;
	private ScheduledReportService&MockObject $service;
	private ScheduledReportJob $job;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(ScheduledReportMapper::class);
		$this->service = $this->createMock(ScheduledReportService::class);

		$this->job = new ScheduledReportJob(
			$this->createMock(ITimeFactory::class),
			$this->mapper,
			$this->service,
			$this->createMock(LoggerInterface::class)
		);
	}

	private function makeReport(int $id): ScheduledReport {
		$report = new ScheduledReport();
		$ref = new \ReflectionClass($report);
		$prop = $ref->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($report, $id);
		$report->setEnabled(true);

		return $report;
	}

	private function runJob(): void {
		$method = new ReflectionMethod(ScheduledReportJob::class, 'run');
		$method->setAccessible(true);
		$method->invoke($this->job, null);
	}

	public function testOneReportFailingDoesNotBlockTheOther(): void {
		$reportA = $this->makeReport(1);
		$reportB = $this->makeReport(2);

		$this->mapper->method('findEnabled')->willReturn([$reportA, $reportB]);
		$this->service->method('isDue')->willReturn(true);

		$calledWith = [];
		$this->service->expects(self::exactly(2))
			->method('runOne')
			->willReturnCallback(function (ScheduledReport $report) use (&$calledWith): void {
				$calledWith[] = $report->getId();
				if ($report->getId() === 1) {
					throw new \RuntimeException('boom');
				}
			});

		$this->runJob();

		self::assertSame([1, 2], $calledWith);
	}

	public function testNotDueReportsAreSkipped(): void {
		$due = $this->makeReport(1);
		$notDue = $this->makeReport(2);

		$this->mapper->method('findEnabled')->willReturn([$due, $notDue]);
		$this->service->method('isDue')->willReturnCallback(
			static fn (ScheduledReport $r): bool => $r->getId() === 1
		);

		$this->service->expects(self::once())->method('runOne')->with($due);

		$this->runJob();
	}

	public function testEmptyCandidateListRunsNothing(): void {
		$this->mapper->method('findEnabled')->willReturn([]);
		$this->service->expects(self::never())->method('runOne');

		$this->runJob();
	}

	public function testMapperFailureIsHandledGracefully(): void {
		$this->mapper->method('findEnabled')->willThrowException(new \RuntimeException('db down'));
		$this->service->expects(self::never())->method('runOne');

		// Must not throw.
		$this->runJob();
	}
}//end class
