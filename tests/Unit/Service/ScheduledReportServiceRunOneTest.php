<?php

/**
 * ScheduledReportServiceRunOneTest
 *
 * Unit tests for `ScheduledReportService::runOne()` — success path (Files
 * delivery + success notification + lastStatus=success), `ExportTooLargeException`
 * handling (lastStatus=failed + failure notification, no retry, no throw),
 * and owner-account-missing handling. Mocks service boundaries
 * (`ExportService`, Files, notifications, session) — not business logic.
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

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ScheduledReport;
use OCA\OpenRegister\Db\ScheduledReportMapper;
use OCA\OpenRegister\Exception\ExportTooLargeException;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\ScheduledReportService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\INotification;
use OCP\Notification\IManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ScheduledReportServiceRunOneTest extends TestCase
{
    private ScheduledReportMapper&MockObject $mapper;
    private RegisterMapper&MockObject $registerMapper;
    private SchemaMapper&MockObject $schemaMapper;
    private ExportService&MockObject $exportService;
    private IRootFolder&MockObject $rootFolder;
    private IUserManager&MockObject $userManager;
    private IUserSession&MockObject $userSession;
    private IManager&MockObject $notificationManager;
    private ScheduledReportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper              = $this->createMock(ScheduledReportMapper::class);
        $this->registerMapper      = $this->createMock(RegisterMapper::class);
        $this->schemaMapper        = $this->createMock(SchemaMapper::class);
        $this->exportService       = $this->createMock(ExportService::class);
        $this->rootFolder          = $this->createMock(IRootFolder::class);
        $this->userManager         = $this->createMock(IUserManager::class);
        $this->userSession         = $this->createMock(IUserSession::class);
        $this->notificationManager = $this->createMock(IManager::class);

        $this->mapper->method('update')->willReturnArgument(0);

        $this->service = new ScheduledReportService(
            $this->mapper,
            $this->registerMapper,
            $this->schemaMapper,
            $this->exportService,
            $this->rootFolder,
            $this->userManager,
            $this->userSession,
            $this->notificationManager,
            $this->createMock(LoggerInterface::class)
        );
    }

    private function makeReport(string $format = 'csv'): ScheduledReport
    {
        $report = new ScheduledReport();
        $ref = new \ReflectionClass($report);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($report, 7);

        $report->setOwner('alice');
        $report->setName('Weekly cases');
        $report->setRegisterId(1);
        $report->setSchemaId(2);
        $report->setFilters('[]');
        $report->setFormat($format);
        $report->setScheduleType('weekly');
        $report->setScheduleHour(8);
        $report->setScheduleDayOfWeek(0);
        $report->setDeliveryFolder('Reports/');
        $report->setEnabled(true);

        return $report;
    }

    private function mockOwnerFolder(bool $folderExists, bool $fileExists): Folder&MockObject
    {
        $owner = $this->createMock(IUser::class);
        $owner->method('getUID')->willReturn('alice');
        $this->userManager->method('get')->with('alice')->willReturn($owner);

        // The delivery filename is dated (weekly-cases_<today>.csv), so
        // these mocks deliberately don't constrain on the exact filename
        // argument — only on which folder ('Reports') the calls land on.
        $folder = $this->createMock(Folder::class);
        $folder->method('nodeExists')->willReturn($fileExists);
        if ($fileExists === false) {
            $folder->expects(self::once())->method('newFile');
        } else {
            $existingFile = $this->createMock(\OCP\Files\File::class);
            $existingFile->expects(self::once())->method('putContent');
            $folder->method('get')->willReturn($existingFile);
        }

        $userFolder = $this->createMock(Folder::class);
        $userFolder->method('nodeExists')->with('Reports')->willReturn($folderExists);
        if ($folderExists === false) {
            $userFolder->expects(self::once())->method('newFolder')->with('Reports');
        }

        $userFolder->method('get')->with('Reports')->willReturn($folder);

        $this->rootFolder->method('getUserFolder')->with('alice')->willReturn($userFolder);

        return $folder;
    }

    public function testSuccessfulRunSetsSuccessStatusAndNotifies(): void
    {
        $report = $this->makeReport('csv');
        $this->mockOwnerFolder(folderExists: false, fileExists: false);

        $this->exportService->expects(self::once())
            ->method('exportToCsv')
            ->willReturn('id,name');

        $this->notificationManager->expects(self::once())->method('createNotification')
            ->willReturn($this->createMock(INotification::class));
        $this->notificationManager->expects(self::once())->method('notify');

        // Called twice: swap to the owner for RBAC-correct export, then
        // restore the previous session user in the finally block (exercised
        // in detail by testSessionIsSwappedToOwnerAndRestoredAfterRun below).
        $this->userSession->expects(self::exactly(2))->method('setUser');

        $this->service->runOne(report: $report);

        self::assertSame('success', $report->getLastStatus());
        self::assertNull($report->getLastError());
        self::assertNotNull($report->getLastRunAt());
    }

    public function testExportTooLargeMarksFailedAndNotifiesWithoutThrowing(): void
    {
        $report = $this->makeReport('pdf');
        $owner = $this->createMock(IUser::class);
        $owner->method('getUID')->willReturn('alice');
        $this->userManager->method('get')->with('alice')->willReturn($owner);

        $this->exportService->expects(self::once())
            ->method('exportToPdf')
            ->willThrowException(new ExportTooLargeException(rowCount: 9000, maxRows: 5000));

        $this->notificationManager->expects(self::once())->method('createNotification')
            ->willReturn($this->createMock(INotification::class));
        $this->notificationManager->expects(self::once())->method('notify');

        // Files delivery should never be attempted once export fails.
        $this->rootFolder->expects(self::never())->method('getUserFolder');

        // runOne() must not throw.
        $this->service->runOne(report: $report);

        self::assertSame('failed', $report->getLastStatus());
        self::assertStringContainsString('9000', (string) $report->getLastError());
    }

    public function testMissingOwnerAccountMarksFailedWithoutNotifying(): void
    {
        $report = $this->makeReport('csv');
        $this->userManager->method('get')->with('alice')->willReturn(null);

        $this->exportService->expects(self::never())->method('exportToCsv');
        // No notification target exists for a deleted owner account.
        $this->notificationManager->expects(self::never())->method('createNotification');

        $this->service->runOne(report: $report);

        self::assertSame('failed', $report->getLastStatus());
    }

    public function testSessionIsSwappedToOwnerAndRestoredAfterRun(): void
    {
        $report = $this->makeReport('csv');
        $this->mockOwnerFolder(folderExists: true, fileExists: true);

        $previousUser = $this->createMock(IUser::class);
        $this->userSession->method('getUser')->willReturn($previousUser);

        $setUserCalls = [];
        $this->userSession->expects(self::exactly(2))
            ->method('setUser')
            ->willReturnCallback(function ($user) use (&$setUserCalls, $previousUser): void {
                $setUserCalls[] = ($user === $previousUser) ? 'previous' : 'owner';
            });

        $this->exportService->method('exportToCsv')->willReturn('id,name');
        $this->notificationManager->method('createNotification')->willReturn($this->createMock(INotification::class));

        $this->service->runOne(report: $report);

        // First swap to the owner (for RBAC-correct export), then restore
        // the previously-active session user in the finally block.
        self::assertSame(['owner', 'previous'], $setUserCalls);
    }
}//end class
