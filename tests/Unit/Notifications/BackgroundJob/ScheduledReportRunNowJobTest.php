<?php

/**
 * The run-now job runs exactly the report its argument names, and nothing for a bad or stale id.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

namespace OCA\OpenRegister\Tests\Unit\Notifications\BackgroundJob;

use OCA\OpenRegister\Kernel\Contracts\ScheduledReportsInterface;
use OCA\OpenRegister\Notifications\BackgroundJob\ScheduledReportRunNowJob;
use OCA\OpenRegister\Notifications\Db\ScheduledReport;
use OCA\OpenRegister\Notifications\Db\ScheduledReportMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Behaviour of ScheduledReportRunNowJob::run().
 */
class ScheduledReportRunNowJobTest extends TestCase
{

    private ScheduledReportMapper&MockObject $mapper;

    private ScheduledReportsInterface&MockObject $service;

    private LoggerInterface&MockObject $logger;


    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper  = $this->createMock(ScheduledReportMapper::class);
        $this->service = $this->createMock(ScheduledReportsInterface::class);
        $this->logger  = $this->createMock(LoggerInterface::class);

    }//end setUp()


    public function testRunsTheNamedReport(): void
    {
        $report = new ScheduledReport();
        $this->mapper->expects($this->once())->method('find')->with(42)->willReturn($report);
        $this->service->expects($this->once())->method('runOne')->with($this->identicalTo($report));
        $this->logger->expects($this->never())->method('warning');

        $this->runJob(argument: ['scheduledReportId' => '42']);

    }//end testRunsTheNamedReport()


    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidArguments(): array
    {
        return [
            'not an array' => [42],
            'null'         => [null],
            'key missing'  => [['other' => 1]],
            'zero'         => [['scheduledReportId' => 0]],
            'negative'     => [['scheduledReportId' => -5]],
        ];

    }//end invalidArguments()


    #[DataProvider('invalidArguments')]
    public function testInvalidArgumentIsLoggedAndNothingRuns(mixed $argument): void
    {
        $this->mapper->expects($this->never())->method('find');
        $this->service->expects($this->never())->method('runOne');
        $this->logger->expects($this->once())->method('warning')
            ->with(
                '[ScheduledReportRunNowJob] Missing/invalid scheduledReportId argument',
                $this->callback(static fn (array $context): bool => $context['argument'] === $argument)
            );

        $this->runJob(argument: $argument);

    }//end testInvalidArgumentIsLoggedAndNothingRuns()


    public function testDeletedReportIsLoggedAndNothingRuns(): void
    {
        $this->mapper->method('find')->willThrowException(new DoesNotExistException('gone'));
        $this->service->expects($this->never())->method('runOne');
        $this->logger->expects($this->once())->method('warning')
            ->with(
                '[ScheduledReportRunNowJob] Scheduled report no longer exists',
                $this->callback(static fn (array $context): bool => $context['reportId'] === 9)
            );

        $this->runJob(argument: ['scheduledReportId' => 9]);

    }//end testDeletedReportIsLoggedAndNothingRuns()


    private function runJob(mixed $argument): void
    {
        $job    = new ScheduledReportRunNowJob($this->createMock(ITimeFactory::class), $this->mapper, $this->service, $this->logger);
        $method = new \ReflectionMethod($job, 'run');
        $method->invoke($job, $argument);

    }//end runJob()
}//end class
