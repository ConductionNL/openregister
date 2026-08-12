<?php

/**
 * The run guard: liveness, and the runtime ceiling.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Exception\FlowRunExpired;
use OCA\OpenRegister\Service\Flow\FlowRunGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers what the stale reaper could not previously distinguish.
 */
class FlowRunGuardTest extends TestCase
{

    /**
     * A run that is still walking marks itself alive.
     *
     * This is the whole repair. The reaper decides on `updated`, nothing wrote
     * `updated` during a walk, so it failed runs for being SLOW while calling
     * them abandoned.
     *
     * @return void
     */
    public function testACheckpointRecordsLiveness(): void
    {
        $mapper = $this->createMock(FlowRunMapper::class);
        $mapper->expects($this->once())
            ->method('touch')
            ->with('run-1', $this->anything())
            ->willReturn(true);

        $guard = new FlowRunGuard(
            mapper: $mapper,
            logger: $this->createMock(LoggerInterface::class),
            runUuid: 'run-1',
            startedAt: microtime(true),
            budget: 3600
        );

        $guard->checkpoint('openregister.object-write');

    }//end testACheckpointRecordsLiveness()


    /**
     * Beats are throttled, so a thousand-step flow does not write a thousand rows.
     *
     * @return void
     */
    public function testBeatsAreThrottled(): void
    {
        $mapper = $this->createMock(FlowRunMapper::class);
        $mapper->expects($this->once())->method('touch')->willReturn(true);

        $guard = new FlowRunGuard(
            mapper: $mapper,
            logger: $this->createMock(LoggerInterface::class),
            runUuid: 'run-1',
            startedAt: microtime(true),
            budget: 3600,
            minInterval: 30
        );

        for ($i = 0; $i < 50; $i++) {
            $guard->checkpoint();
        }

    }//end testBeatsAreThrottled()


    /**
     * A run past its budget is stopped, by the executor that owns it.
     *
     * `startedAt` is placed in the past rather than sleeping: the deadline is
     * arithmetic on elapsed time, and a test that slept would buy nothing but
     * seconds. The budget is deliberately tiny so the assertion is unambiguous.
     *
     * @return void
     */
    public function testARunOverItsBudgetIsStopped(): void
    {
        $guard = new FlowRunGuard(
            mapper: $this->createMock(FlowRunMapper::class),
            logger: $this->createMock(LoggerInterface::class),
            runUuid: 'run-1',
            startedAt: (microtime(true) - 120.0),
            budget: 60
        );

        $this->assertTrue($guard->expired());

        $this->expectException(FlowRunExpired::class);
        $this->expectExceptionMessageMatches('/maximum runtime of 60 seconds/');
        $guard->checkpoint('openconnector.synchronization-run');

    }//end testARunOverItsBudgetIsStopped()


    /**
     * The failure names WHERE the run ran out, not merely that it did.
     *
     * A ceiling that reports only "the run expired" leaves the operator to guess
     * which of fifteen steps ate the hour.
     *
     * @return void
     */
    public function testTheFailureNamesTheStepItStoppedAt(): void
    {
        $guard = new FlowRunGuard(
            mapper: $this->createMock(FlowRunMapper::class),
            logger: $this->createMock(LoggerInterface::class),
            runUuid: 'run-1',
            startedAt: (microtime(true) - 120.0),
            budget: 60
        );

        try {
            $guard->checkpoint('openconnector.synchronization-run');
            $this->fail('the guard should have stopped an over-budget run');
        } catch (FlowRunExpired $e) {
            $this->assertStringContainsString('openconnector.synchronization-run', $e->getMessage());
        }

    }//end testTheFailureNamesTheStepItStoppedAt()


    /**
     * A zero budget means no ceiling, for deliberately long imports.
     *
     * @return void
     */
    public function testAZeroBudgetNeverExpires(): void
    {
        $guard = new FlowRunGuard(
            mapper: $this->createMock(FlowRunMapper::class),
            logger: $this->createMock(LoggerInterface::class),
            runUuid: 'run-1',
            startedAt: (microtime(true) - 86400.0),
            budget: 0
        );

        $this->assertFalse($guard->expired());
        $guard->checkpoint();

    }//end testAZeroBudgetNeverExpires()


    /**
     * The deadline is tested BEFORE the beat.
     *
     * Otherwise an over-budget run is first refreshed into looking healthy and
     * only then killed, leaving a row whose `updated` says it was alive at the
     * moment it was stopped.
     *
     * @return void
     */
    public function testAnExpiredRunIsNotFirstMarkedAlive(): void
    {
        $mapper = $this->createMock(FlowRunMapper::class);
        $mapper->expects($this->never())->method('touch');

        $guard = new FlowRunGuard(
            mapper: $mapper,
            logger: $this->createMock(LoggerInterface::class),
            runUuid: 'run-1',
            startedAt: (microtime(true) - 120.0),
            budget: 60
        );

        $this->expectException(FlowRunExpired::class);
        $guard->checkpoint();

    }//end testAnExpiredRunIsNotFirstMarkedAlive()


    /**
     * A beat that cannot be written must not fail the run.
     *
     * The guard exists to stop healthy runs being killed; a database hiccup in
     * the liveness write turning into a run failure would be the same bug wearing
     * a different hat.
     *
     * @return void
     */
    public function testAFailedBeatIsSwallowed(): void
    {
        $mapper = $this->createMock(FlowRunMapper::class);
        $mapper->method('touch')->willThrowException(new RuntimeException('db gone'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $guard = new FlowRunGuard(
            mapper: $mapper,
            logger: $logger,
            runUuid: 'run-1',
            startedAt: microtime(true),
            budget: 3600
        );

        $guard->checkpoint();
        $this->assertFalse($guard->expired());

    }//end testAFailedBeatIsSwallowed()

}//end class
