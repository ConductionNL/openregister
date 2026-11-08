<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

namespace OCA\OpenRegister\Tests\Unit\Notifications\BackgroundJob;

use DateTime;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Notifications\BackgroundJob\TaskNotificationCandidates;
use OCA\OpenRegister\Notifications\BackgroundJob\TaskNotificationDelivery;
use OCA\OpenRegister\Notifications\BackgroundJob\TaskScheduledNotificationJob;
use OCA\OpenRegister\Notifications\Db\NotificationDedupeState;
use OCA\OpenRegister\Notifications\Db\NotificationDedupeStateMapper;
use OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterEvaluator;
use OCA\OpenRegister\Notifications\Service\Notification\TaskNotificationRules;
use OCA\OpenRegister\Service\Task\TaskInboxService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins rule selection, interval floor, paging, dedupe and failure handling of
 * the scheduled task sweep over synthetic rules.
 *
 * @covers \OCA\OpenRegister\Notifications\BackgroundJob\TaskScheduledNotificationJob
 */
class TaskScheduledNotificationJobCharacterisationTest extends TestCase
{

    private TaskMapper&MockObject $tasks;

    private NotificationDedupeStateMapper&MockObject $dedupe;

    private AnnotationNotificationDispatcher&MockObject $dispatcher;

    /** @var array<string, string> */
    private array $config = [];

    /** @var list<string> */
    private array $log = [];

    private const NOW = '2026-09-02T09:00:00+00:00';

    protected function setUp(): void
    {
        $this->tasks      = $this->createMock(TaskMapper::class);
        $this->dedupe     = $this->createMock(NotificationDedupeStateMapper::class);
        $this->dispatcher = $this->createMock(AnnotationNotificationDispatcher::class);
        $this->config     = [];
        $this->log        = [];
    }

    private function job(array $rules): TaskScheduledNotificationJob
    {
        $now  = new DateTime(self::NOW);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn($now->getTimestamp());
        $time->method('getDateTime')->willReturnCallback(static fn (): DateTime => clone $now);

        $ruleSet = $this->createMock(TaskNotificationRules::class);
        $ruleSet->method('getRules')->willReturn($rules);
        $ruleSet->method('buildSchema')->willReturnCallback(static fn (): Schema => new Schema());

        $inbox = $this->createMock(TaskInboxService::class);
        $inbox->method('enrich')->willReturnCallback(static fn (Task $task): array => ['displayTitle' => (string) $task->getTitle()]);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            fn (string $app, string $key, string $default = ''): string => $this->config[$key] ?? $default
        );
        $appConfig->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value): bool {
                $this->config[$key] = $value;
                return true;
            }
        );

        $logger = $this->createMock(LoggerInterface::class);
        foreach (['info', 'warning'] as $level) {
            $logger->method($level)->willReturnCallback(
                function ($message) use ($level): void {
                    $this->log[] = $level.': '.$message;
                }
            );
        }

        return new TaskScheduledNotificationJob(
            $time,
            new TaskNotificationCandidates($ruleSet, $this->tasks, $inbox, new ScheduledFilterEvaluator()),
            new TaskNotificationDelivery($this->dedupe, $this->dispatcher, $appConfig, $logger)
        );
    }

    private function sweep(TaskScheduledNotificationJob $job): void
    {
        $method = new \ReflectionMethod($job, 'run');
        $method->invoke($job, null);
    }

    private function task(string $uuid, string $title = 'T'): Task
    {
        $task = new Task();
        $task->setUuid($uuid);
        $task->setTitle($title);
        $task->setState(Task::STATE_ACTIVE);
        $task->setIsTerminal(false);
        return $task;
    }

    private function rule(array $trigger = [], array $extra = []): array
    {
        return array_merge(['trigger' => array_merge(['type' => 'scheduled'], $trigger)], $extra);
    }

    public function testOnlyEnabledScheduledRulesRunAndTheirStateIsStored(): void
    {
        $this->tasks->method('findInbox')->willReturn([]);
        $this->sweep(
            $this->job(
                [
                    'noTrigger'  => ['enabled' => true],
                    'badTrigger' => ['trigger' => 'scheduled'],
                    'transition' => ['trigger' => ['type' => 'transition']],
                    'disabled'   => $this->rule([], ['enabled' => false]),
                    'falsy'      => $this->rule([], ['enabled' => 0]),
                    7            => $this->rule(),
                    'daily'      => $this->rule(),
                ]
            )
        );

        $stamp = (string) (new DateTime(self::NOW))->getTimestamp();
        $this->assertSame(['sched_task:falsy' => $stamp, 'sched_task:7' => $stamp, 'sched_task:daily' => $stamp], $this->config);
        $this->assertSame(
            [
                'info: [TaskScheduledNotificationJob] Rule "falsy": matched=0 dispatched=0',
                'info: [TaskScheduledNotificationJob] Rule "7": matched=0 dispatched=0',
                'info: [TaskScheduledNotificationJob] Rule "daily": matched=0 dispatched=0',
            ],
            $this->log
        );
    }

    public function testIntervalHasASixtySecondFloorAndDefaultsToADay(): void
    {
        $now = (new DateTime(self::NOW))->getTimestamp();
        $this->config['sched_task:fast'] = (string) ($now - 59);
        $this->config['sched_task:ok']   = (string) ($now - 60);
        $this->config['sched_task:day']  = (string) ($now - 86399);
        $this->tasks->method('findInbox')->willReturn([]);

        $this->sweep($this->job(['fast' => $this->rule(['intervalSec' => 1]), 'ok' => $this->rule(['intervalSec' => 1]), 'day' => $this->rule()]));

        $this->assertSame(['info: [TaskScheduledNotificationJob] Rule "ok": matched=0 dispatched=0'], $this->log);
    }

    public function testPagesUntilAShortPageAndCountsMatchesAndDispatches(): void
    {
        $offsets = [];
        $pages   = [
            0   => array_map(fn (int $i) => $this->task('t-'.$i, $i % 2 === 0 ? 'keep' : 'drop'), range(0, 199)),
            200 => [$this->task('t-200', 'keep'), $this->task('t-201', 'keep'), $this->task('t-202', 'keep')],
        ];
        $this->tasks->method('findInbox')->willReturnCallback(
            function ($criteria, int $limit, int $offset) use (&$offsets, $pages): array {
                $offsets[] = [$limit, $offset];
                return $pages[$offset] ?? [];
            }
        );
        $same = new NotificationDedupeState();
        $same->setFingerprint(sha1((string) json_encode(['title' => 'keep', 'zz' => null])));
        $other = new NotificationDedupeState();
        $other->setFingerprint('stale');
        $this->dedupe->method('findOne')->willReturnCallback(
            fn (int $schemaId, string $ruleKey, string $objectUuid) => match ($objectUuid) {
                't-0' => $same,
                't-2' => $other,
                default => null,
            }
        );
        $upserts = [];
        $this->dedupe->method('upsert')->willReturnCallback(
            function (int $schemaId, string $ruleKey, string $uuid, string $fingerprint, DateTime $now, bool $dispatched) use (&$upserts) {
                $upserts[] = [$schemaId, $ruleKey, $uuid, $fingerprint, $now->format('c'), $dispatched];
                return new NotificationDedupeState();
            }
        );
        $dispatched = [];
        $this->dispatcher->method('dispatchWithSchema')->willReturnCallback(
            function ($adapter, string $trigger, array $context, Schema $schema) use (&$dispatched): void {
                if ($adapter->getUuid() === 't-4') {
                    throw new RuntimeException('boom');
                }
                $dispatched[] = [$adapter->getUuid(), $trigger, $context, array_keys($schema->getConfiguration()['x-openregister-notifications'])];
            }
        );

        $this->sweep(
            $this->job(
                [
                    'sweep' => $this->rule(
                        [
                            'filter'       => ['title' => 'keep'],
                            'dedupeFields' => ['zz', 'title', 'zz', '', 5],
                        ]
                    ),
                ]
            )
        );

        $this->assertSame([[200, 0], [200, 200]], $offsets);
        // 100 "keep" on page one, 3 on page two; t-0 deduped, t-4 failed.
        $this->assertCount(101, $dispatched);
        $this->assertSame(['t-2', 'scheduled', ['notificationName' => 'sweep'], ['sweep']], $dispatched[0]);
        $this->assertCount(101, $upserts);
        $this->assertSame([-1, 'sweep', 't-2', sha1((string) json_encode(['title' => 'keep', 'zz' => null])), self::NOW, true], $upserts[0]);
        $this->assertSame(
            [
                'warning: [TaskScheduledNotificationJob] Rule "sweep" failed for task t-4: boom',
                'info: [TaskScheduledNotificationJob] Rule "sweep": matched=103 dispatched=101',
            ],
            $this->log
        );
    }

    public function testStopsAtTheRunCap(): void
    {
        $calls = 0;
        $this->tasks->method('findInbox')->willReturnCallback(
            function () use (&$calls): array {
                $calls++;
                return array_fill(0, 200, $this->task('t'));
            }
        );
        $this->dedupe->method('findOne')->willReturn(null);

        $this->sweep($this->job(['cap' => $this->rule()]));

        $this->assertSame(25, $calls);
        $this->assertSame(['info: [TaskScheduledNotificationJob] Rule "cap": matched=5000 dispatched=5000'], $this->log);
    }

    public function testCandidateQueryFailureEndsTheRuleQuietly(): void
    {
        $this->tasks->method('findInbox')->willThrowException(new RuntimeException('db down'));
        $this->dispatcher->expects($this->never())->method('dispatchWithSchema');

        $this->sweep($this->job(['r' => $this->rule()]));

        $this->assertSame(
            [
                'warning: [TaskScheduledNotificationJob] Candidate query failed: db down',
                'info: [TaskScheduledNotificationJob] Rule "r": matched=0 dispatched=0',
            ],
            $this->log
        );
        $this->assertArrayHasKey('sched_task:r', $this->config);
    }
}
