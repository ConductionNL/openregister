<?php

/**
 * The scheduled task sweep skips disabled rules, keeps going past a failed dispatch and survives a failed query.
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
 * @spec openspec/specs/notificatie-engine/spec.md
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
use OCA\OpenRegister\Notifications\Db\DedupeSighting;
use OCA\OpenRegister\Notifications\Db\NotificationDedupeKey;
use OCA\OpenRegister\Notifications\Db\NotificationDedupeStateMapper;
use OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterEvaluator;
use OCA\OpenRegister\Notifications\Service\Notification\TaskNotificationRules;
use OCA\OpenRegister\Service\Task\TaskInboxServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Behaviour of TaskScheduledNotificationJob::run().
 */
class TaskScheduledNotificationJobBehaviourTest extends TestCase
{

    private const NOW = '2026-09-02T09:00:00+00:00';

    private TaskMapper&MockObject $tasks;

    private NotificationDedupeStateMapper&MockObject $dedupe;

    private AnnotationNotificationDispatcher&MockObject $dispatcher;

    private IAppConfig&MockObject $appConfig;

    /** @var list<string> */
    private array $log = [];


    protected function setUp(): void
    {
        parent::setUp();
        $this->tasks      = $this->createMock(TaskMapper::class);
        $this->dedupe     = $this->createMock(NotificationDedupeStateMapper::class);
        $this->dispatcher = $this->createMock(AnnotationNotificationDispatcher::class);
        $this->appConfig  = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueString')->willReturnArgument(2);
        $this->log = [];

    }//end setUp()


    public function testDisabledAndNonScheduledRulesAreSkipped(): void
    {
        $this->tasks->expects($this->never())->method('findInbox');
        $this->appConfig->expects($this->never())->method('setValueString');

        $this->sweep(
            rules: [
                'off'     => ['enabled' => false, 'trigger' => ['type' => 'scheduled']],
                'created' => ['trigger' => ['type' => 'created']],
                'bare'    => [],
            ]
        );

        $this->assertSame([], $this->log);

    }//end testDisabledAndNonScheduledRulesAreSkipped()


    public function testExplicitlyEnabledRuleRunsAndStoresItsFireTime(): void
    {
        $this->tasks->method('findInbox')->willReturn([]);
        $this->appConfig->expects($this->once())->method('setValueString')
            ->with('openregister', 'sched_task:on', (string)(new DateTime(self::NOW))->getTimestamp());

        $this->sweep(rules: ['on' => ['enabled' => true, 'trigger' => ['type' => 'scheduled']]]);

        $this->assertSame(['info: [TaskScheduledNotificationJob] Rule "on": matched=0 dispatched=0'], $this->log);

    }//end testExplicitlyEnabledRuleRunsAndStoresItsFireTime()


    public function testFailedDispatchIsLoggedAndTheNextTaskStillDispatches(): void
    {
        $this->tasks->method('findInbox')->willReturnOnConsecutiveCalls([$this->task(uuid: 'bad'), $this->task(uuid: 'good')], []);
        $this->dedupe->method('findByKey')->willReturn(null);
        $this->dispatcher->method('dispatchWithSchema')->willReturnCallback(
            static function ($adapter): void {
                if ($adapter->getUuid() === 'bad') {
                    throw new RuntimeException('boom');
                }
            }
        );
        $this->dedupe->expects($this->once())->method('upsert')
            ->with(
                new NotificationDedupeKey(TaskScheduledNotificationJob::DEDUPE_SCHEMA_ID, 'r', 'good'),
                new DedupeSighting(sha1('[]'), new DateTime(self::NOW), true)
            );

        $this->sweep(rules: ['r' => ['trigger' => ['type' => 'scheduled']]]);

        $this->assertSame(
            [
                'warning: [TaskScheduledNotificationJob] Rule "r" failed for task bad: boom',
                'info: [TaskScheduledNotificationJob] Rule "r": matched=2 dispatched=1',
            ],
            $this->log
        );

    }//end testFailedDispatchIsLoggedAndTheNextTaskStillDispatches()


    public function testFailedCandidateQueryEndsTheRuleButStillStoresItsFireTime(): void
    {
        $this->tasks->method('findInbox')->willThrowException(new RuntimeException('db down'));
        $this->dispatcher->expects($this->never())->method('dispatchWithSchema');
        $this->appConfig->expects($this->once())->method('setValueString')
            ->with('openregister', 'sched_task:r', $this->isType('string'));

        $this->sweep(rules: ['r' => ['trigger' => ['type' => 'scheduled']]]);

        $this->assertSame(
            [
                'warning: [TaskScheduledNotificationJob] Candidate query failed: db down',
                'info: [TaskScheduledNotificationJob] Rule "r": matched=0 dispatched=0',
            ],
            $this->log
        );

    }//end testFailedCandidateQueryEndsTheRuleButStillStoresItsFireTime()


    private function task(string $uuid): Task
    {
        $task = new Task();
        $task->setUuid($uuid);
        $task->setTitle('T');
        $task->setState(Task::STATE_ACTIVE);
        $task->setIsTerminal(false);
        return $task;

    }//end task()


    /**
     * @param array<string, array<string, mixed>> $rules Task notification rules
     */
    private function sweep(array $rules): void
    {
        $now  = new DateTime(self::NOW);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn($now->getTimestamp());
        $time->method('getDateTime')->willReturnCallback(static fn (): DateTime => clone $now);

        $ruleSet = $this->createMock(TaskNotificationRules::class);
        $ruleSet->method('getRules')->willReturn($rules);
        $ruleSet->method('buildSchema')->willReturnCallback(static fn (): Schema => new Schema());

        $inbox = $this->createMock(TaskInboxServiceInterface::class);
        $inbox->method('enrich')->willReturn([]);

        $logger = $this->createMock(LoggerInterface::class);
        foreach (['info', 'warning'] as $level) {
            $logger->method($level)->willReturnCallback(
                function ($message) use ($level): void {
                    $this->log[] = $level.': '.$message;
                }
            );
        }

        $job = new TaskScheduledNotificationJob(
            $time,
            new TaskNotificationCandidates($ruleSet, $this->tasks, $inbox, new ScheduledFilterEvaluator()),
            new TaskNotificationDelivery($this->dedupe, $this->dispatcher, $this->appConfig, $logger)
        );
        $method = new \ReflectionMethod($job, 'run');
        $method->invoke($job, null);

    }//end sweep()
}//end class
