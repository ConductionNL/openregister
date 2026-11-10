<?php

/**
 * The presence sweep logs what it expired, stays quiet on nothing, and never throws.
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
 * @spec openspec/changes/object-presence/specs/realtime-updates/spec.md#requirement-presence-changes-are-pushed-not-polled
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

namespace OCA\OpenRegister\Tests\Unit\Notifications\BackgroundJob;

use OCA\OpenRegister\Notifications\BackgroundJob\PresenceExpiryJob;
use OCA\OpenRegister\Notifications\Service\PresenceService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Behaviour of PresenceExpiryJob::run().
 */
class PresenceExpiryJobTest extends TestCase
{

    private PresenceService&MockObject $presence;

    private LoggerInterface&MockObject $logger;


    protected function setUp(): void
    {
        parent::setUp();
        $this->presence = $this->createMock(PresenceService::class);
        $this->logger   = $this->createMock(LoggerInterface::class);

    }//end setUp()


    public function testSweepRunsEveryMinute(): void
    {
        $this->assertSame(60, $this->job()->getInterval());

    }//end testSweepRunsEveryMinute()


    public function testExpiredRowsAreCountedInADebugLine(): void
    {
        $this->presence->expects($this->once())->method('expire')->with()->willReturn(['a', 'b', 'c']);
        $this->logger->expects($this->once())->method('debug')
            ->with('[PresenceExpiryJob] expired 3 presence rows', $this->arrayHasKey('file'));
        $this->logger->expects($this->never())->method('warning');

        $this->runJob();

    }//end testExpiredRowsAreCountedInADebugLine()


    public function testNothingExpiredLogsNothing(): void
    {
        $this->presence->expects($this->once())->method('expire')->willReturn([]);
        $this->logger->expects($this->never())->method('debug');
        $this->logger->expects($this->never())->method('warning');

        $this->runJob();

    }//end testNothingExpiredLogsNothing()


    public function testSweepFailureIsLoggedNotThrown(): void
    {
        $this->presence->method('expire')->willThrowException(new RuntimeException('db gone'));
        $this->logger->expects($this->once())->method('warning')
            ->with('[PresenceExpiryJob] the presence sweep failed: db gone', $this->arrayHasKey('line'));
        $this->logger->expects($this->never())->method('debug');

        $this->runJob();

    }//end testSweepFailureIsLoggedNotThrown()


    private function job(): PresenceExpiryJob
    {
        return new PresenceExpiryJob($this->createMock(ITimeFactory::class), $this->presence, $this->logger);

    }//end job()


    private function runJob(): void
    {
        $job    = $this->job();
        $method = new \ReflectionMethod($job, 'run');
        $method->invoke($job, null);

    }//end runJob()
}//end class
