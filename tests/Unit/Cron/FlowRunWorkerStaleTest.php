<?php

/**
 * The worker fails runs abandoned in `running`.
 *
 * `execute()` sets `running` and clears it when the walk returns. A pass that
 * dies instead — a fatal, a PHP timeout, an OOM, a container restart — never
 * clears it, and no `catch` can help because the process is gone. Nothing then
 * touches the row again: the worker reads only `queued` and due `suspended`
 * runs. That was invisible while nothing read `running`; it stops being
 * invisible the moment a dashboard widget shows live runs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Cron;

use DateTime;
use OCA\OpenRegister\Cron\FlowRunWorker;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class FlowRunWorkerStaleTest extends TestCase
{
    private FlowRunMapper&MockObject $mapper;

    private FlowRunService&MockObject $runner;

    private IAppConfig&MockObject $appConfig;

    private FlowRunWorker $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper    = $this->createMock(FlowRunMapper::class);
        $this->runner    = $this->createMock(FlowRunService::class);
        $this->appConfig = $this->createMock(IAppConfig::class);

        // Nothing queued, nothing due, and pruning off — this suite is only
        // about the reaper.
        $this->mapper->method('findQueued')->willReturn([]);
        $this->mapper->method('findDue')->willReturn([]);

        $this->worker = new FlowRunWorker(
            $this->createMock(ITimeFactory::class),
            $this->mapper,
            $this->runner,
            $this->createMock(FlowLocator::class),
            $this->appConfig,
            new NullLogger()
        );
    }

    /** Answer getValueString() from a map, falling back to the caller's default. */
    private function config(array $values): void
    {
        $this->appConfig->method('getValueString')->willReturnCallback(
            static fn (string $app, string $key, string $default = '') => ($values[$key] ?? $default)
        );
    }

    /** Run one pass of the worker. */
    private function pass(): void
    {
        $method = new \ReflectionMethod(FlowRunWorker::class, 'run');
        $method->invoke($this->worker, null);
    }

    private function runningRun(string $uuid = 'zombie-1'): FlowRun
    {
        $run = new FlowRun();
        $run->setUuid($uuid);
        $run->setFlowId('f1');
        $run->setStatus(FlowRun::STATUS_RUNNING);
        $run->setUpdated(new DateTime('-2 days'));

        return $run;
    }

    public function testAnAbandonedRunIsFailedWithAReadableReason(): void
    {
        // Pruning off so the pass does nothing else.
        $this->config(['flow_run_retention_days' => '0']);

        $run = $this->runningRun();
        $this->mapper->method('findStale')->willReturn([$run]);

        $saved = null;
        $this->mapper->expects($this->once())->method('update')
            ->willReturnCallback(function (FlowRun $r) use (&$saved) {
                $saved = $r;
                return $r;
            });

        $this->pass();

        $this->assertSame(FlowRun::STATUS_FAILED, $saved->getStatus());
        $this->assertStringContainsString('Abandoned', (string) $saved->getError());
        $this->assertStringContainsString('Retry', (string) $saved->getError());
    }

    public function testAnAbandonedRunIsNeverRequeued(): void
    {
        // Requeuing would repeat every side effect the dead pass already
        // performed — an object write, a mail, a webhook. Retry is a person's
        // decision, not a cron job's.
        $this->config(['flow_run_retention_days' => '0']);
        $this->mapper->method('findStale')->willReturn([$this->runningRun()]);
        $this->mapper->method('update')->willReturnArgument(0);

        $this->runner->expects($this->never())->method('retry');
        $this->runner->expects($this->never())->method('execute');

        $this->pass();
    }

    public function testTheStaleWindowIsConfigurableAndPassedAsACutoff(): void
    {
        $this->config(['flow_run_retention_days' => '0', 'flow_run_stale_minutes' => '90']);

        $this->mapper->expects($this->once())->method('findStale')
            ->with(
                $this->callback(static function (DateTime $before): bool {
                    $minutesAgo = ((time() - $before->getTimestamp()) / 60);
                    // ~90 minutes ago, with room for the test's own runtime.
                    return $minutesAgo > 89 && $minutesAgo < 92;
                }),
                $this->anything()
            )
            ->willReturn([]);

        $this->pass();
    }

    public function testAZeroWindowSwitchesTheReaperOff(): void
    {
        // An operator running very long single steps must be able to opt out
        // rather than have the reaper fail work that is still going.
        $this->config(['flow_run_retention_days' => '0', 'flow_run_stale_minutes' => '0']);

        $this->mapper->expects($this->never())->method('findStale');
        $this->mapper->expects($this->never())->method('update');

        $this->pass();
    }
}
