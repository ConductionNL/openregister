<?php

/**
 * Tests for the audit seal sweeper's background job.
 *
 * The job's reason for existing is that a fail-soft seal used to promise "a
 * later seal pass will chain it" and no later pass existed. Its own alarm for
 * the case where the sweep itself stops working therefore has to be provably
 * reachable — an unreachable alarm reproduces exactly the silence the job was
 * written to end.
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
 *
 * @spec openspec/specs/audit-hash-chain/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\AuditSealJob;
use OCA\OpenRegister\Service\AuditHashService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Class AuditSealJobTest
 */
class AuditSealJobTest extends TestCase
{
    /**
     * Build the job over a mocked service and logger, and run one tick.
     *
     * @param int   $remaining What countUnsealed() reports after the passes.
     * @param int[] $passes    What each sealUnsealed() call returns, in order.
     *
     * @return LoggerInterface&\PHPUnit\Framework\MockObject\MockObject The logger, for assertions.
     */
    private function runTick(int $remaining, array $passes)
    {
        $hashes = $this->createMock(AuditHashService::class);
        $hashes->method('sealUnsealed')->willReturnOnConsecutiveCalls(...array_merge($passes, [0, 0, 0, 0, 0, 0, 0, 0, 0, 0]));
        $hashes->method('countUnsealed')->willReturn($remaining);

        $logger = $this->createMock(LoggerInterface::class);

        $job = new AuditSealJob($this->createMock(ITimeFactory::class), $hashes, $logger);

        $run = new ReflectionMethod($job, 'run');
        $run->setAccessible(true);
        $run->invoke($job, null);

        return $logger;
    }//end runTick()

    /**
     * The backlog-not-draining alarm must actually fire.
     *
     * 🔴 This is the assertion that was impossible to satisfy. An early
     * `if ($sealed === 0) { return; }` sat above the warning, so the one state
     * that could reach it — sealed nothing, backlog non-empty — had already left
     * the method. Psalm called it a ParadoxicalCondition; in operational terms
     * the chain could stop closing its gaps and nothing would ever say so.
     *
     * @return void
     */
    public function testWarnsWhenNothingSealedAndABacklogRemains(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $hashes = $this->createMock(AuditHashService::class);
        $hashes->method('sealUnsealed')->willReturn(0);
        $hashes->method('countUnsealed')->willReturn(1200);

        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('not closing'));

        $job = new AuditSealJob($this->createMock(ITimeFactory::class), $hashes, $logger);
        $run = new ReflectionMethod($job, 'run');
        $run->setAccessible(true);
        $run->invoke($job, null);
    }//end testWarnsWhenNothingSealedAndABacklogRemains()

    /**
     * The steady state — nothing sealed, nothing outstanding — stays silent.
     *
     * The negative control for the test above: if the warning fired here too it
     * would be noise rather than a signal, and an operator would learn to ignore
     * the one line that matters.
     *
     * @return void
     */
    public function testStaysSilentWhenThereIsNothingToSeal(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $hashes = $this->createMock(AuditHashService::class);
        $hashes->method('sealUnsealed')->willReturn(0);
        $hashes->method('countUnsealed')->willReturn(0);

        $logger->expects($this->never())->method('warning');
        $logger->expects($this->never())->method('info');

        $job = new AuditSealJob($this->createMock(ITimeFactory::class), $hashes, $logger);
        $run = new ReflectionMethod($job, 'run');
        $run->setAccessible(true);
        $run->invoke($job, null);
    }//end testStaysSilentWhenThereIsNothingToSeal()

    /**
     * A productive pass reports progress and does NOT raise the alarm.
     *
     * @return void
     */
    public function testReportsProgressWithoutWarningWhenRowsWereSealed(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $hashes = $this->createMock(AuditHashService::class);
        $hashes->method('sealUnsealed')->willReturnOnConsecutiveCalls(500, 500, 0, 0, 0, 0, 0, 0, 0, 0);
        $hashes->method('countUnsealed')->willReturn(300);

        $logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('Sealed 1000 audit row(s)'));
        $logger->expects($this->never())->method('warning');

        $job = new AuditSealJob($this->createMock(ITimeFactory::class), $hashes, $logger);
        $run = new ReflectionMethod($job, 'run');
        $run->setAccessible(true);
        $run->invoke($job, null);
    }//end testReportsProgressWithoutWarningWhenRowsWereSealed()

    /**
     * A throwing service must not break cron for every other job.
     *
     * @return void
     */
    public function testSwallowsServiceFailuresAndLogsThem(): void
    {
        $logger = $this->createMock(LoggerInterface::class);

        $hashes = $this->createMock(AuditHashService::class);
        $hashes->method('sealUnsealed')->willThrowException(new \RuntimeException('db gone'));

        $logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Seal sweep failed'));

        $job = new AuditSealJob($this->createMock(ITimeFactory::class), $hashes, $logger);
        $run = new ReflectionMethod($job, 'run');
        $run->setAccessible(true);
        $run->invoke($job, null);
    }//end testSwallowsServiceFailuresAndLogsThem()
}//end class
