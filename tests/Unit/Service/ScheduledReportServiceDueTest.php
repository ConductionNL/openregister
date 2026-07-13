<?php

/**
 * ScheduledReportServiceDueTest
 *
 * Unit tests for `ScheduledReportService::isDue()` — the catch-up-safe
 * elapsed-period due-check matrix (daily/weekly/monthly, never-run,
 * disabled).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateInterval;
use DateTime;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ScheduledReport;
use OCA\OpenRegister\Db\ScheduledReportMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ScheduledReportService;
use OCP\Files\IRootFolder;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ScheduledReportServiceDueTest extends TestCase
{
    private ScheduledReportService $service;
    private ScheduledReportMapper&MockObject $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = $this->createMock(ScheduledReportMapper::class);

        $this->service = new ScheduledReportService(
            $this->mapper,
            $this->createMock(RegisterMapper::class),
            $this->createMock(SchemaMapper::class),
            $this->createMock(ExportService::class),
            $this->createMock(IRootFolder::class),
            $this->createMock(IUserManager::class),
            $this->createMock(IUserSession::class),
            $this->createMock(IManager::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * Build a ScheduledReport with the given scheduleType/enabled/lastRunAt.
     */
    private function makeReport(string $scheduleType, bool $enabled, ?DateTime $lastRunAt): ScheduledReport
    {
        $report = new ScheduledReport();
        $report->setScheduleType($scheduleType);
        $report->setEnabled($enabled);
        $report->setLastRunAt($lastRunAt);
        return $report;
    }

    public function testDailyReportDueAfter24Hours(): void
    {
        $now = new DateTime();
        $lastRunAt = (clone $now)->sub(new DateInterval('PT25H'));
        $report = $this->makeReport('daily', true, $lastRunAt);

        self::assertTrue($this->service->isDue(report: $report, now: $now));
    }

    public function testDailyReportNotDueBefore24Hours(): void
    {
        $now = new DateTime();
        $lastRunAt = (clone $now)->sub(new DateInterval('PT2H'));
        $report = $this->makeReport('daily', true, $lastRunAt);

        self::assertFalse($this->service->isDue(report: $report, now: $now));
    }

    public function testWeeklyReportDueAfter7Days(): void
    {
        $now = new DateTime();
        $lastRunAt = (clone $now)->sub(new DateInterval('P8D'));
        $report = $this->makeReport('weekly', true, $lastRunAt);

        self::assertTrue($this->service->isDue(report: $report, now: $now));
    }

    public function testWeeklyReportNotDueBefore7Days(): void
    {
        $now = new DateTime();
        $lastRunAt = (clone $now)->sub(new DateInterval('P3D'));
        $report = $this->makeReport('weekly', true, $lastRunAt);

        self::assertFalse($this->service->isDue(report: $report, now: $now));
    }

    public function testMonthlyReportDueAfter30Days(): void
    {
        $now = new DateTime();
        $lastRunAt = (clone $now)->sub(new DateInterval('P31D'));
        $report = $this->makeReport('monthly', true, $lastRunAt);

        self::assertTrue($this->service->isDue(report: $report, now: $now));
    }

    public function testMonthlyReportNotDueBefore30Days(): void
    {
        $now = new DateTime();
        $lastRunAt = (clone $now)->sub(new DateInterval('P20D'));
        $report = $this->makeReport('monthly', true, $lastRunAt);

        self::assertFalse($this->service->isDue(report: $report, now: $now));
    }

    public function testNeverRunReportIsAlwaysDue(): void
    {
        $now = new DateTime();
        $report = $this->makeReport('monthly', true, null);

        self::assertTrue($this->service->isDue(report: $report, now: $now));
    }

    public function testDisabledReportIsNeverDue(): void
    {
        $now = new DateTime();
        $lastRunAt = (clone $now)->sub(new DateInterval('P60D'));
        $report = $this->makeReport('daily', false, $lastRunAt);

        self::assertFalse($this->service->isDue(report: $report, now: $now));
    }

    public function testDisabledReportNeverRunIsStillNotDue(): void
    {
        $now = new DateTime();
        $report = $this->makeReport('daily', false, null);

        self::assertFalse($this->service->isDue(report: $report, now: $now));
    }

    /**
     * Catch-up: three reports of every schedule type, all overdue because the
     * job itself didn't run for a while, are all due on the same pass.
     */
    public function testCatchUpAfterDowntimeAllElapsedReportsAreDue(): void
    {
        $now = new DateTime();

        $daily   = $this->makeReport('daily', true, (clone $now)->sub(new DateInterval('P5D')));
        $weekly  = $this->makeReport('weekly', true, (clone $now)->sub(new DateInterval('P10D')));
        $monthly = $this->makeReport('monthly', true, (clone $now)->sub(new DateInterval('P40D')));

        self::assertTrue($this->service->isDue(report: $daily, now: $now));
        self::assertTrue($this->service->isDue(report: $weekly, now: $now));
        self::assertTrue($this->service->isDue(report: $monthly, now: $now));
    }
}//end class
