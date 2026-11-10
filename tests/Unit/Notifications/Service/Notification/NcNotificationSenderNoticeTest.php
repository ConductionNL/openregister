<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Service\Notification\InAppNotice;
use OCA\OpenRegister\Notifications\Service\Notification\NcNotificationSender;
use OCA\OpenRegister\Notifications\Service\Notification\NoticeContent;
use OCA\OpenRegister\Notifications\Service\Notification\NoticeRule;
use OCA\OpenRegister\Notifications\Service\Notification\PushRideAlong;
use OCP\BackgroundJob\IJobList;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The value-object methods notify() and enqueuePush() produce exactly what send() and enqueueWebPush() produce.
 *
 * @covers \OCA\OpenRegister\Notifications\Service\Notification\NcNotificationSender
 */
class NcNotificationSenderNoticeTest extends TestCase
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
    private function manager(): INotificationManager
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
            function (): void {
                $this->calls[] = ['notify'];
            }
        );

        return $manager;
    }

    /**
     * An object with a uuid, a name, a register and a schema.
     *
     * @return ObjectEntity The object.
     */
    private function object(): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid('obj-1');
        $object->setName('Case 1');
        $object->setRegister('4');
        $object->setSchema('9');
        return $object;
    }

    public function testNotifyPersistsWhatSendPersists(): void
    {
        $sender = new NcNotificationSender($this->manager(), $this->createMock(LoggerInterface::class));

        $sent     = $sender->send('alice', $this->object(), 'object_updated', 'rule-a', 'Title', 'Body', ['action' => 'update'], 'procest', [['x' => 1]], true);
        $viaSend  = $this->calls;
        $this->calls = [];
        $notified = $sender->notify(
            new InAppNotice(
                uid: 'alice',
                object: $this->object(),
                rule: new NoticeRule(name: 'rule-a', originApp: 'procest', actions: [['x' => 1]], webPushActive: true),
                content: new NoticeContent(subjectKey: 'object_updated', subject: 'Title', message: 'Body', context: ['action' => 'update'])
            )
        );

        $this->assertSame(NcNotificationSender::OUTCOME_DISPATCHED, $notified);
        $this->assertSame($sent, $notified);
        $this->assertSame($viaSend, $this->calls);
        $this->assertNotSame([], $this->calls);
    }

    public function testEnqueuePushAddsTheJobsEnqueueWebPushAdds(): void
    {
        $jobs    = [];
        $jobList = $this->createMock(IJobList::class);
        $jobList->method('add')->willReturnCallback(
            function (string $job, mixed $argument) use (&$jobs): void {
                $jobs[] = [$job, $argument];
            }
        );
        $users = $this->createMock(IUserManager::class);
        $users->method('userExists')->willReturnCallback(fn (string $uid): bool => $uid !== 'ghost');
        $sender = new NcNotificationSender($this->manager(), $this->createMock(LoggerInterface::class), $users, $jobList);

        $sender->enqueueWebPush(['alice', 'ghost', 'bob'], 'rule-a', 'procest', 'Title', 'Body', [['x' => 1]], $this->object());
        $viaOld = $jobs;
        $jobs   = [];
        $sender->enqueuePush(
            new PushRideAlong(
                recipients: ['alice', 'ghost', 'bob'],
                object: $this->object(),
                rule: new NoticeRule(name: 'rule-a', originApp: 'procest', actions: [['x' => 1]]),
                content: new NoticeContent(subjectKey: '', subject: 'Title', message: 'Body')
            )
        );

        $this->assertCount(2, $jobs);
        $this->assertSame($viaOld, $jobs);
    }
}
