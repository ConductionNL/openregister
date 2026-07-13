<?php

/**
 * ScheduledReportServiceValidationTest
 *
 * Unit tests for `ScheduledReportService::create()` validation: format
 * allow-list, CSV-requires-schema, schedule-type allow-list, and
 * weekly/monthly required-field validation.
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

use InvalidArgumentException;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
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

class ScheduledReportServiceValidationTest extends TestCase
{
    private ScheduledReportService $service;
    private ScheduledReportMapper&MockObject $mapper;
    private RegisterMapper&MockObject $registerMapper;
    private SchemaMapper&MockObject $schemaMapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper         = $this->createMock(ScheduledReportMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);

        $this->registerMapper->method('find')->willReturn(new Register());
        $this->schemaMapper->method('find')->willReturn(new Schema());

        $this->mapper->method('insert')->willReturnArgument(0);

        $this->service = new ScheduledReportService(
            $this->mapper,
            $this->registerMapper,
            $this->schemaMapper,
            $this->createMock(ExportService::class),
            $this->createMock(IRootFolder::class),
            $this->createMock(IUserManager::class),
            $this->createMock(IUserSession::class),
            $this->createMock(IManager::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge(
            [
                'name'         => 'Weekly cases',
                'registerId'   => 1,
                'schemaId'     => 2,
                'filters'      => [],
                'format'       => 'csv',
                'scheduleType' => 'weekly',
                'scheduleHour' => 8,
                'scheduleDayOfWeek' => 0,
            ],
            $overrides
        );
    }

    public function testValidCsvWeeklyReportIsCreated(): void
    {
        $report = $this->service->create(data: $this->validPayload(), ownerUid: 'alice');

        self::assertSame('alice', $report->getOwner());
        self::assertSame('csv', $report->getFormat());
        self::assertTrue($report->getEnabled());
        self::assertNull($report->getLastRunAt());
    }

    public function testUnsupportedFormatIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->create(data: $this->validPayload(['format' => 'json']), ownerUid: 'alice');
    }

    public function testCsvWithoutSchemaIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->create(
            data: $this->validPayload(['schemaId' => null]),
            ownerUid: 'alice'
        );
    }

    public function testExcelWithoutSchemaIsAllowed(): void
    {
        // Excel (like ExportService::exportToExcel) can export a whole
        // register without a specific schema — only CSV requires one.
        $report = $this->service->create(
            data: $this->validPayload(['format' => 'excel', 'schemaId' => null]),
            ownerUid: 'alice'
        );

        self::assertSame('excel', $report->getFormat());
        self::assertNull($report->getSchemaId());
    }

    public function testUnsupportedScheduleTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->create(
            data: $this->validPayload(['scheduleType' => 'hourly']),
            ownerUid: 'alice'
        );
    }

    public function testWeeklyWithoutDayOfWeekIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->create(
            data: $this->validPayload(['scheduleDayOfWeek' => null]),
            ownerUid: 'alice'
        );
    }

    public function testMonthlyWithoutDayOfMonthIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->create(
            data: $this->validPayload(['scheduleType' => 'monthly', 'scheduleDayOfMonth' => null]),
            ownerUid: 'alice'
        );
    }

    public function testMonthlyWithValidDayOfMonthIsAccepted(): void
    {
        $report = $this->service->create(
            data: $this->validPayload(['scheduleType' => 'monthly', 'scheduleDayOfMonth' => 15]),
            ownerUid: 'alice'
        );

        self::assertSame('monthly', $report->getScheduleType());
        self::assertSame(15, $report->getScheduleDayOfMonth());
    }

    public function testMissingNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->create(data: $this->validPayload(['name' => '']), ownerUid: 'alice');
    }

    public function testMissingRegisterIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->create(data: $this->validPayload(['registerId' => null]), ownerUid: 'alice');
    }
}//end class
