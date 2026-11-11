<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\BackgroundJob\WebPushDispatchJob;
use OCA\OpenRegister\Notifications\Service\Notification\InAppNotice;
use OCA\OpenRegister\Notifications\Service\Notification\NcNotificationSender;
use OCA\OpenRegister\Notifications\Service\Notification\NoticeContent;
use OCA\OpenRegister\Notifications\Service\Notification\NoticeRule;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationChannelPolicy;
use OCA\OpenRegister\Notifications\Service\Notification\PushRideAlong;
use OCP\BackgroundJob\IJobList;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the notification row and the web-push jobs NcNotificationSender produces.
 *
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\NcNotificationSender
 */
class NcNotificationSenderCharacterisationTest extends TestCase
{

    /**
     * The calls made on the notification, in order.
     *
     * @var array<int, array<int, mixed>>
     */
    private array $calls = [];

    /**
     * A notification manager whose notification records every setter.
     *
     * @return INotificationManager The manager.
     */
    private function manager(?\Throwable $failure = null): INotificationManager
    {
        $notification = $this->createMock(INotification::class);
        foreach (['setApp', 'setUser', 'setObject', 'setSubject'] as $method) {
            $notification->method($method)->willReturnCallback(
                function (...$args) use ($method, $notification): INotification {
                    $this->calls[] = array_merge([$method], $args);
                    return $notification;
                }
            );
        }

        $notification->method('setDateTime')->willReturnSelf();

        $manager = $this->createMock(INotificationManager::class);
        $manager->method('createNotification')->willReturn($notification);
        $manager->method('notify')->willReturnCallback(
            function () use ($failure): void {
                $this->calls[] = ['notify'];
                if ($failure !== null) {
                    throw $failure;
                }
            }
        );

        return $manager;
    }

    /**
     * A channel policy with the nc-notification switch set.
     *
     * @return NotificationChannelPolicy The policy.
     */
    private function policy(bool $enabled): NotificationChannelPolicy
    {
        $policy = $this->createMock(NotificationChannelPolicy::class);
        $policy->method('isChannelEnabled')->willReturnCallback(
            fn (string $channel): bool => $channel === 'nc-notification' ? $enabled : true
        );
        return $policy;
    }

    /**
     * An object with the given uuid and name.
     *
     * @return ObjectEntity The object.
     */
    private function object(?string $uuid, ?string $name): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid($uuid);
        $object->setName($name);
        $object->setRegister('4');
        $object->setSchema('9');
        return $object;
    }

    public function testNotifyPersistsTheFullNotificationKeyedByTheObject(): void
    {
        $sender = new NcNotificationSender($this->manager(), $this->createMock(LoggerInterface::class));

        $outcome = $sender->notify(
            new InAppNotice(
                uid: 'alice',
                object: $this->object('obj-1', 'Case 1'),
                rule: new NoticeRule(name: 'rule-a', originApp: 'procest', actions: [['label' => ['en' => 'Open']]], webPushActive: true),
                content: new NoticeContent(subjectKey: 'object_updated', subject: 'Title', message: 'Body', context: ['action' => 'update'])
            )
        );

        $this->assertSame(NcNotificationSender::OUTCOME_DISPATCHED, $outcome);
        $this->assertSame(
            [
                ['setApp', 'openregister'],
                ['setUser', 'alice'],
                ['setObject', 'object', 'obj-1'],
                [
                    'setSubject',
                    'object_updated',
                    [
                        'action' => 'update',
                        'objectTitle' => 'Case 1',
                        'registerId' => '4',
                        'schemaId' => '9',
                        'objectUuid' => 'obj-1',
                        'originApp' => 'procest',
                        '_actions' => [['label' => ['en' => 'Open']]],
                        '_message' => 'Body',
                        '_tag' => 'openregister-rule-a-obj-1',
                        '_suppressForegroundPopup' => true,
                        '_text' => 'Title',
                        'notificationType' => 'rule-a',
                    ],
                ],
                ['notify'],
            ],
            $this->calls
        );
    }

    public function testNotifyWithoutUuidFallsBackToTheRuleNameAndDefaults(): void
    {
        $sender = new NcNotificationSender($this->manager(), $this->createMock(LoggerInterface::class), null, null, $this->policy(true));

        $sender->notify(
            new InAppNotice(
                uid: 'bob',
                object: $this->object(null, null),
                rule: new NoticeRule(name: 'rule-b'),
                content: new NoticeContent(subjectKey: 'object_created', subject: 'T', message: '', context: ['objectTitle' => 'ctx', '_text' => 'ctx'])
            )
        );

        $this->assertSame(['setObject', 'object', 'rule-b'], $this->calls[2]);
        $subject = $this->calls[3][2];
        $this->assertSame('', $subject['objectTitle']);
        $this->assertSame('', $subject['objectUuid']);
        $this->assertSame('openregister', $subject['originApp']);
        $this->assertSame([], $subject['_actions']);
        $this->assertSame('openregister-rule-b-rule-b', $subject['_tag']);
        $this->assertFalse($subject['_suppressForegroundPopup']);
        $this->assertSame('T', $subject['_text']);
    }

    public function testNotifyWithTheChannelSwitchedOffDoesNothing(): void
    {
        $sender = new NcNotificationSender($this->manager(), $this->createMock(LoggerInterface::class), null, null, $this->policy(false));

        $this->assertSame(
            NcNotificationSender::OUTCOME_KILL_SWITCH,
            $sender->notify(
                new InAppNotice(
                    uid: 'a',
                    object: $this->object('o', 'n'),
                    rule: new NoticeRule(name: 'r'),
                    content: new NoticeContent(subjectKey: 'k', subject: 's', message: 'm', context: [])
                )
            )
        );
        $this->assertSame([], $this->calls);
    }

    public function testNotifyFailureIsLoggedAndReported(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('Notification "rule-c" to "carol" failed: boom');
        $sender = new NcNotificationSender($this->manager(new RuntimeException('boom')), $logger);

        $this->assertSame(
            NcNotificationSender::OUTCOME_FAILED,
            $sender->notify(
                new InAppNotice(
                    uid: 'carol',
                    object: $this->object('o', 'n'),
                    rule: new NoticeRule(name: 'rule-c'),
                    content: new NoticeContent(subjectKey: 'k', subject: 's', message: 'm', context: [])
                )
            )
        );
    }

    /**
     * A job list recording what is added.
     *
     * @param array<int, array<int, mixed>> $jobs Receives every added job.
     *
     * @return IJobList The job list.
     */
    private function jobList(array &$jobs): IJobList
    {
        $jobList = $this->createMock(IJobList::class);
        $jobList->method('add')->willReturnCallback(
            function (string $job, mixed $argument) use (&$jobs): void {
                $jobs[] = [$job, $argument];
            }
        );
        return $jobList;
    }

    public function testEnqueuePushAddsOneJobPerExistingRecipient(): void
    {
        $jobs  = [];
        $users = $this->createMock(IUserManager::class);
        $users->method('userExists')->willReturnCallback(
            function (string $uid): bool {
                if ($uid === 'broken') {
                    throw new RuntimeException('ldap down');
                }

                return $uid !== 'ghost';
            }
        );
        $sender = new NcNotificationSender($this->manager(), $this->createMock(LoggerInterface::class), $users, $this->jobList($jobs), $this->policy(true));

        $sender->enqueuePush(
            new PushRideAlong(
                recipients: ['alice', '', 'ghost', 'broken', 'bob'],
                object: $this->object('obj-1', 'n'),
                rule: new NoticeRule(name: 'rule-a', originApp: 'procest', actions: [['x' => 1]]),
                content: new NoticeContent(subjectKey: '', subject: 'Title', message: 'Body')
            )
        );

        $payload = ['ruleId' => 'rule-a', 'originApp' => 'procest', 'title' => 'Title', 'body' => 'Body', 'tag' => 'openregister-rule-a-obj-1', 'actions' => [['x' => 1]]];
        $this->assertSame(
            [
                [WebPushDispatchJob::class, ['uid' => 'alice'] + $payload],
                [WebPushDispatchJob::class, ['uid' => 'bob'] + $payload],
            ],
            $jobs
        );
    }

    public function testEnqueuePushWithoutUserManagerTrustsTheRecipientsAndTagsByRule(): void
    {
        $jobs   = [];
        $sender = new NcNotificationSender($this->manager(), $this->createMock(LoggerInterface::class), null, $this->jobList($jobs));

        $sender->enqueuePush(
            new PushRideAlong(
                recipients: ['zed'],
                object: $this->object(null, null),
                rule: new NoticeRule(name: 'rule-z', originApp: 'openregister', actions: []),
                content: new NoticeContent(subjectKey: '', subject: 'T', message: 'B')
            )
        );

        $this->assertCount(1, $jobs);
        $this->assertSame('openregister-rule-z-rule-z', $jobs[0][1]['tag']);
    }

    public function testEnqueuePushIsSkippedWithoutJobListOrWithTheSwitchOff(): void
    {
        $jobs = [];
        (new NcNotificationSender($this->manager(), $this->createMock(LoggerInterface::class), null, null))
            ->enqueuePush(
                new PushRideAlong(
                    recipients: ['a'],
                    object: $this->object('o', 'n'),
                    rule: new NoticeRule(name: 'r', originApp: 'o', actions: []),
                    content: new NoticeContent(subjectKey: '', subject: 't', message: 'b')
                )
            );
        (new NcNotificationSender($this->manager(), $this->createMock(LoggerInterface::class), null, $this->jobList($jobs), $this->policy(false)))
            ->enqueuePush(
                new PushRideAlong(
                    recipients: ['a'],
                    object: $this->object('o', 'n'),
                    rule: new NoticeRule(name: 'r', originApp: 'o', actions: []),
                    content: new NoticeContent(subjectKey: '', subject: 't', message: 'b')
                )
            );

        $this->assertSame([], $jobs);
    }
}
