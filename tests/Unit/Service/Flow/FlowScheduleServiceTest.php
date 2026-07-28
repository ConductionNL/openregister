<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowScheduleService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FlowScheduleServiceTest extends TestCase
{
    private ObjectService&MockObject $objects;

    private FlowRunService&MockObject $runs;

    private IAppConfig&MockObject $config;

    /** last-fire values keyed by config key, mutated by setValueString. */
    private array $store = [];

    private FlowScheduleService $service;

    protected function setUp(): void
    {
        $this->objects = $this->createMock(ObjectService::class);
        $this->runs    = $this->createMock(FlowRunService::class);
        $this->config  = $this->createMock(IAppConfig::class);

        $this->config->method('getValueString')->willReturnCallback(
            fn (string $app, string $key, string $default = '') => $this->store[$key] ?? $default
        );
        $this->config->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value): bool {
                $this->store[$key] = $value;
                return true;
            }
        );

        $this->service = new FlowScheduleService(
            $this->objects,
            $this->runs,
            $this->config,
            $this->createMock(\Psr\Log\LoggerInterface::class)
        );
    }

    private function flow(string $uuid, array $data): ObjectEntity
    {
        $o = new ObjectEntity();
        $o->setUuid($uuid);
        $o->setObject($data);
        return $o;
    }

    private function schedule(string $uuid, string $cron, ?string $owner=null): ObjectEntity
    {
        $flow = $this->flow($uuid, ['enabled' => true, 'trigger' => 'schedule', 'cron' => $cron]);
        if ($owner !== null) {
            $flow->setOwner($owner);
        }

        return $flow;
    }

    public function testADueScheduledFlowFiresAndRecordsTheFire(): void
    {
        // No last-fire recorded -> due on first sight.
        $this->objects->method('findAll')->willReturn([$this->schedule('f1', '*/5 * * * *')]);

        $this->runs->expects($this->once())->method('queue')
            ->with('f1', $this->anything(), 'schedule', $this->anything());

        $fired = $this->service->fireDueFlows(new DateTimeImmutable('2026-07-25 10:00:00'));

        $this->assertSame(['f1'], $fired);
        // A last-fire was recorded, so the next tick will not re-fire immediately.
        $this->assertArrayHasKey('flow_sched_last_f1', $this->store);
    }

    /**
     * FAILING PATH (or#2158, fourth instance): a scheduled run has no session,
     * so its owner must come from the flow object — the person who created and
     * enabled it. Queued without one, `context['triggeredBy']` is null and every
     * attribution-requiring node refuses; ObjectWriteNode returns "this flow run
     * has no owner". Every natively-scheduled flow was silently unable to write.
     *
     * @return void
     */
    public function testAScheduledRunIsAttributedToTheFlowsOwner(): void
    {
        $this->objects->method('findAll')->willReturn([$this->schedule('f1', '*/5 * * * *', 'alice')]);

        $this->runs->expects($this->once())->method('queue')
            ->with('f1', $this->anything(), 'schedule', $this->anything(), 'alice');

        $this->service->fireDueFlows(new DateTimeImmutable('2026-07-25 10:00:00'));
    }

    public function testAFlowThatFiredRecentlyIsNotDueAgain(): void
    {
        // Fired at 10:00; the next */5 occurrence is 10:05, so at 10:02 it is
        // not due yet.
        $this->store['flow_sched_last_f1'] = '2026-07-25T10:00:00+00:00';
        $this->objects->method('findAll')->willReturn([$this->schedule('f1', '*/5 * * * *')]);

        $this->runs->expects($this->never())->method('queue');

        $this->assertSame([], $this->service->fireDueFlows(new DateTimeImmutable('2026-07-25 10:02:00')));
    }

    public function testADisabledScheduleDoesNotFire(): void
    {
        $this->objects->method('findAll')->willReturn([
            $this->flow('f1', ['enabled' => false, 'trigger' => 'schedule', 'cron' => '*/5 * * * *']),
        ]);
        $this->runs->expects($this->never())->method('queue');
        $this->assertSame([], $this->service->fireDueFlows(new DateTimeImmutable('2026-07-25 10:00:00')));
    }

    public function testANonScheduleTriggerDoesNotFire(): void
    {
        $this->objects->method('findAll')->willReturn([
            $this->flow('f1', ['enabled' => true, 'trigger' => 'object.created', 'cron' => '*/5 * * * *']),
        ]);
        $this->runs->expects($this->never())->method('queue');
        $this->assertSame([], $this->service->fireDueFlows(new DateTimeImmutable('2026-07-25 10:00:00')));
    }

    public function testAnInvalidOrMissingCronIsSkipped(): void
    {
        $this->objects->method('findAll')->willReturn([
            $this->flow('bad', ['enabled' => true, 'trigger' => 'schedule', 'cron' => 'not a cron']),
            $this->flow('none', ['enabled' => true, 'trigger' => 'schedule']),
        ]);
        $this->runs->expects($this->never())->method('queue');
        $this->assertSame([], $this->service->fireDueFlows(new DateTimeImmutable('2026-07-25 10:00:00')));
    }

    public function testNoFlowStoreFiresNothing(): void
    {
        $this->objects->method('findAll')->willThrowException(new \RuntimeException('no such register'));
        $this->assertSame([], $this->service->fireDueFlows(new DateTimeImmutable('2026-07-25 10:00:00')));
    }
}
