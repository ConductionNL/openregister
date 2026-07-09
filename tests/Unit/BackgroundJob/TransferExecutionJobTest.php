<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\BackgroundJob\TransferExecutionJob}.
 *
 * Covers the durable-retry state machine (archival-transfer-hardening,
 * OR-AD-2): backoff formula (exponential, capped, jittered), one attempt per
 * run, re-enqueue on non-terminal failure, terminal completion, and attempt-cap
 * escalation to the archivists.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-durable-retry/spec.md
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\TransferExecutionJob;
use OCA\OpenRegister\Service\Edepot\EdepotTransferService;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferRecordService;
use OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransport;
use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransport;
use OCA\OpenRegister\Service\Edepot\Transport\SftpTransport;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * TransferExecutionJobTest.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The job wires many
 *   collaborators; its test mirrors that surface.
 */
class TransferExecutionJobTest extends TestCase
{

    private EdepotTransferService&MockObject $transferService;

    private TransferRecordService&MockObject $recordService;

    private TransferListService&MockObject $listService;

    private IJobList&MockObject $jobList;

    private IAppConfig&MockObject $appConfig;

    private TransferExecutionJob $job;


    protected function setUp(): void
    {
        $this->transferService = $this->createMock(EdepotTransferService::class);
        $this->recordService   = $this->createMock(TransferRecordService::class);
        $this->listService     = $this->createMock(TransferListService::class);
        $this->jobList         = $this->createMock(IJobList::class);
        $this->appConfig       = $this->createMock(IAppConfig::class);

        $this->appConfig->method('getValueString')->willReturnCallback(
            static fn($app, $key, $default = '') => $default
        );

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1000000);

        $this->job = new TransferExecutionJob(
            time: $time,
            transferService: $this->transferService,
            transferRecordService: $this->recordService,
            transferListService: $this->listService,
            sftpTransport: $this->createMock(SftpTransport::class),
            restTransport: $this->createMock(RestApiTransport::class),
            ocTransport: $this->createMock(OpenConnectorTransport::class),
            jobList: $this->jobList,
            appConfig: $this->appConfig,
            logger: $this->createMock(LoggerInterface::class),
        );

    }//end setUp()


    /**
     * Backoff is exponential (60·2^(n-1)), capped at 8h, and jittered ±10 %.
     *
     * @return void
     */
    public function testBackoffFormula(): void
    {
        // attempt 1 → ~60 s, attempt 2 → ~120 s, attempt 3 → ~240 s (±10 %).
        $this->assertEqualsWithDelta(60, $this->job->backoffSeconds(1), 6);
        $this->assertEqualsWithDelta(120, $this->job->backoffSeconds(2), 12);
        $this->assertEqualsWithDelta(240, $this->job->backoffSeconds(3), 24);

        // Deep attempts saturate at the 8h cap (±10 %).
        $capped = $this->job->backoffSeconds(30);
        $this->assertGreaterThanOrEqual((int) (28800 * 0.9), $capped);
        $this->assertLessThanOrEqual((int) (28800 * 1.1) + 1, $capped);

    }//end testBackoffFormula()


    /**
     * A completed attempt persists the list and does NOT re-enqueue.
     *
     * @return void
     */
    public function testCompletedAttemptDoesNotReenqueue(): void
    {
        $this->recordService->method('loadTransferList')->willReturn(
            ['uuid' => 't1', 'status' => TransferListService::STATUS_APPROVED, 'objectReferences' => []]
        );
        $this->transferService->method('executeAttempt')->willReturn(
            ['uuid' => 't1', 'status' => TransferListService::STATUS_COMPLETED]
        );

        $this->recordService->expects($this->once())->method('saveTransferList');
        $this->jobList->expects($this->never())->method('scheduleAfter');
        $this->listService->expects($this->never())->method('notifyArchivists');

        $this->runJob(['transferListId' => 't1', 'attempt' => 1]);

    }//end testCompletedAttemptDoesNotReenqueue()


    /**
     * A non-terminal failure below the cap re-enqueues the next attempt.
     *
     * @return void
     */
    public function testFailedAttemptReenqueues(): void
    {
        $this->recordService->method('loadTransferList')->willReturn(
            ['uuid' => 't1', 'status' => TransferListService::STATUS_APPROVED, 'objectReferences' => []]
        );
        $this->transferService->method('executeAttempt')->willReturn(
            ['uuid' => 't1', 'status' => TransferListService::STATUS_PARTIALLY_FAILED]
        );

        $scheduled = null;
        $this->jobList->expects($this->once())->method('scheduleAfter')->willReturnCallback(
            function ($job, $runAfter, $argument) use (&$scheduled): void {
                $scheduled = ['job' => $job, 'runAfter' => $runAfter, 'argument' => $argument];
            }
        );
        $this->listService->expects($this->never())->method('notifyArchivists');

        $this->runJob(['transferListId' => 't1', 'attempt' => 2]);

        $this->assertSame(TransferExecutionJob::class, $scheduled['job']);
        $this->assertSame(3, $scheduled['argument']['attempt']);
        // runAfter is now (1_000_000) + a positive backoff.
        $this->assertGreaterThan(1000000, $scheduled['runAfter']);

    }//end testFailedAttemptReenqueues()


    /**
     * At the attempt cap, the transfer fails and the archivists are notified —
     * no further re-enqueue.
     *
     * @return void
     */
    public function testAttemptCapEscalates(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function ($app, $key, $default = '') {
                if ($key === 'edepot_transfer_max_attempts') {
                    return '3';
                }

                return $default;
            }
        );

        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(1000000);

        $job = new TransferExecutionJob(
            time: $time,
            transferService: $this->transferService,
            transferRecordService: $this->recordService,
            transferListService: $this->listService,
            sftpTransport: $this->createMock(SftpTransport::class),
            restTransport: $this->createMock(RestApiTransport::class),
            ocTransport: $this->createMock(OpenConnectorTransport::class),
            jobList: $this->jobList,
            appConfig: $appConfig,
            logger: $this->createMock(LoggerInterface::class),
        );

        $this->recordService->method('loadTransferList')->willReturn(
            ['uuid' => 't1', 'status' => TransferListService::STATUS_APPROVED, 'objectReferences' => []]
        );
        $this->transferService->method('executeAttempt')->willReturn(
            ['uuid' => 't1', 'status' => TransferListService::STATUS_FAILED]
        );

        $savedStatus = null;
        $this->recordService->method('saveTransferList')->willReturnCallback(
            function (array $list) use (&$savedStatus): array {
                $savedStatus = $list['status'];
                return $list;
            }
        );
        $this->jobList->expects($this->never())->method('scheduleAfter');
        $this->listService->expects($this->once())->method('notifyArchivists');

        $method = new \ReflectionMethod($job, 'run');
        $method->setAccessible(true);
        $method->invoke($job, ['transferListId' => 't1', 'attempt' => 3]);

        $this->assertSame(TransferListService::STATUS_FAILED, $savedStatus);

    }//end testAttemptCapEscalates()


    /**
     * A missing transfer list is a clean no-op (no execute, no reschedule).
     *
     * @return void
     */
    public function testMissingListIsNoOp(): void
    {
        $this->recordService->method('loadTransferList')->willReturn(null);
        $this->transferService->expects($this->never())->method('executeAttempt');
        $this->jobList->expects($this->never())->method('scheduleAfter');

        $this->runJob(['transferListId' => 'ghost', 'attempt' => 1]);

    }//end testMissingListIsNoOp()


    /**
     * Invoke the protected run().
     *
     * @param array<string, mixed> $argument The job argument.
     *
     * @return void
     */
    private function runJob(array $argument): void
    {
        $method = new \ReflectionMethod($this->job, 'run');
        $method->setAccessible(true);
        $method->invoke($this->job, $argument);

    }//end runJob()
}//end class
