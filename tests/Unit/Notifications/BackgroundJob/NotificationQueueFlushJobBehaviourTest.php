<?php

/**
 * The queue flush holds rows behind an active window or a pending digest and keeps rows whose dispatch failed.
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
use DateTimeImmutable;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Notifications\BackgroundJob\NotificationQueueFlushJob;
use OCA\OpenRegister\Notifications\BackgroundJob\QueueFlushDelivery;
use OCA\OpenRegister\Notifications\BackgroundJob\QueueFlushTiming;
use OCA\OpenRegister\Notifications\Db\QueuedNotification;
use OCA\OpenRegister\Notifications\Db\QueuedNotificationMapper;
use OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Notifications\Service\Notification\DigestScheduleEvaluator;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationDeliveryWindowService;
use OCA\OpenRegister\Notifications\Service\Notification\QueueWait;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Behaviour of NotificationQueueFlushJob::run().
 */
class NotificationQueueFlushJobBehaviourTest extends TestCase
{

    private const NOW = '2026-03-04T12:00:00+00:00';

    private QueuedNotificationMapper&MockObject $queued;

    private SchemaMapperInterface&MockObject $schemas;

    private AnnotationNotificationDispatcher&MockObject $dispatcher;

    private NotificationDeliveryWindowService&MockObject $windows;

    private DigestScheduleEvaluator&MockObject $digests;

    private LoggerInterface&MockObject $logger;


    protected function setUp(): void
    {
        parent::setUp();
        $this->queued     = $this->createMock(QueuedNotificationMapper::class);
        $this->schemas    = $this->createMock(SchemaMapperInterface::class);
        $this->dispatcher = $this->createMock(AnnotationNotificationDispatcher::class);
        $this->windows    = $this->createMock(NotificationDeliveryWindowService::class);
        $this->digests    = $this->createMock(DigestScheduleEvaluator::class);
        $this->logger     = $this->createMock(LoggerInterface::class);

    }//end setUp()


    public function testUnreadableQueueIsLoggedAndNothingIsDispatched(): void
    {
        $this->queued->method('findAll')->willThrowException(new RuntimeException('no table'));
        $this->logger->expects($this->once())->method('warning')
            ->with('[NotificationQueueFlushJob] queue scan failed: no table');
        $this->dispatcher->expects($this->never())->method('dispatchQueued');

        $this->runJob();

    }//end testUnreadableQueueIsLoggedAndNothingIsDispatched()


    public function testEmptyQueueConsultsNoWindow(): void
    {
        $this->queued->method('findAll')->willReturn([]);
        $this->windows->expects($this->never())->method('getForUser');
        $this->logger->expects($this->never())->method('info');

        $this->runJob();

    }//end testEmptyQueueConsultsNoWindow()


    public function testActiveWindowHoldsTheWholeGroup(): void
    {
        $this->queued->method('findAll')->willReturn([$this->row(id: 1), $this->row(id: 2)]);
        $this->windows->method('getForUser')->with('alice')->willReturn(['start' => '22:00']);
        $this->windows->expects($this->once())->method('isInsideWindow')
            ->with(['start' => '22:00'], new DateTimeImmutable(self::NOW))
            ->willReturn(true);
        $this->schemas->expects($this->never())->method('find');
        $this->dispatcher->expects($this->never())->method('dispatchQueued');

        $this->runJob();

    }//end testActiveWindowHoldsTheWholeGroup()


    public function testClearedWindowFlushesEveryRowAndDeletesThem(): void
    {
        $rows = [$this->row(id: 1), $this->row(id: 2)];
        $this->queued->method('findAll')->willReturn($rows);
        $this->windows->method('getForUser')->willReturn(['start' => '22:00']);
        $this->windows->method('isInsideWindow')->willReturn(false);
        $this->schemas->method('find')->willThrowException(new RuntimeException('schema gone'));

        $this->dispatcher->expects($this->once())->method('dispatchQueued')->with($rows);
        $deleted = [];
        $this->queued->method('deleteById')->willReturnCallback(
            static function (int $id) use (&$deleted): void {
                $deleted[] = $id;
            }
        );
        $this->logger->expects($this->once())->method('info')
            ->with('[NotificationQueueFlushJob] flushed 2 queued notification(s)');

        $this->runJob();

        $this->assertSame([1, 2], $deleted);

    }//end testClearedWindowFlushesEveryRowAndDeletesThem()


    public function testFailedDispatchKeepsTheRowsAndLogsNoFlushCount(): void
    {
        $this->queued->method('findAll')->willReturn([$this->row(id: 1)]);
        $this->windows->method('getForUser')->willReturn(null);
        $this->schemas->method('find')->willReturn($this->schema(configuration: []));
        $this->dispatcher->method('dispatchQueued')->willThrowException(new RuntimeException('smtp down'));

        $this->queued->expects($this->never())->method('deleteById');
        $this->logger->expects($this->once())->method('warning')
            ->with('[NotificationQueueFlushJob] dispatchQueued failed for rule="rule" recipient="alice": smtp down');
        $this->logger->expects($this->never())->method('info');

        $this->runJob();

    }//end testFailedDispatchKeepsTheRowsAndLogsNoFlushCount()


    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function digestlessConfigurations(): array
    {
        return [
            'no notifications'      => [['x-openregister-notifications' => 'nope'], true],
            'rule missing'          => [['x-openregister-notifications' => ['other' => []]], true],
            'rule not an array'     => [['x-openregister-notifications' => ['rule' => 'x']], true],
            'digest not an array'   => [['x-openregister-notifications' => ['rule' => ['digest' => 'daily']]], true],
            'digest fails the spec' => [['x-openregister-notifications' => ['rule' => ['digest' => ['bad' => 1]]]], false],
        ];

    }//end digestlessConfigurations()


    #[DataProvider('digestlessConfigurations')]
    public function testWithoutAUsableDigestTheRowIsDueImmediately(array $configuration, bool $specUnchecked): void
    {
        $row = $this->row(id: 5);
        $this->queued->method('findAll')->willReturn([$row]);
        $this->windows->method('getForUser')->willReturn(null);
        $this->schemas->expects($this->once())->method('find')->with(3, [], true, false)
            ->willReturn($this->schema(configuration: $configuration));
        $this->digests->expects($specUnchecked === true ? $this->never() : $this->once())
            ->method('isValidDigestSpec')->willReturn(false);
        $this->digests->expects($this->never())->method('isDue');

        $this->dispatcher->expects($this->once())->method('dispatchQueued')->with([$row]);

        $this->runJob();

    }//end testWithoutAUsableDigestTheRowIsDueImmediately()


    public function testValidDigestFlushesOnlyTheRowsItDeclaresDue(): void
    {
        $early = $this->row(id: 1, createdAt: '2026-03-04T08:00:00+00:00');
        $late  = $this->row(id: 2, createdAt: '2026-03-04T11:30:00+00:00');
        $this->queued->method('findAll')->willReturn([$early, $late]);
        $this->windows->method('getForUser')->willReturn(null);
        $digest = ['schedule' => 'daily', 'time' => '09:00'];
        $this->schemas->method('find')->willReturn(
            $this->schema(configuration: ['x-openregister-notifications' => ['rule' => ['digest' => $digest]]])
        );
        $this->digests->method('isValidDigestSpec')->with($digest)->willReturn(true);
        $this->digests->method('isDue')->willReturnCallback(
            static fn (array $spec, QueueWait $wait): bool => $wait->enqueuedAt->format('H') === '08'
        );

        $this->dispatcher->expects($this->once())->method('dispatchQueued')->with([$early]);
        $this->queued->expects($this->once())->method('deleteById')->with(1);

        $this->runJob();

    }//end testValidDigestFlushesOnlyTheRowsItDeclaresDue()


    public function testValidDigestWithNothingDueDispatchesNothing(): void
    {
        $this->queued->method('findAll')->willReturn([$this->row(id: 1)]);
        $this->windows->method('getForUser')->willReturn(null);
        $this->schemas->method('find')->willReturn(
            $this->schema(configuration: ['x-openregister-notifications' => ['rule' => ['digest' => ['schedule' => 'daily']]]])
        );
        $this->digests->method('isValidDigestSpec')->willReturn(true);
        $this->digests->method('isDue')->willReturn(false);

        $this->dispatcher->expects($this->never())->method('dispatchQueued');

        $this->runJob();

    }//end testValidDigestWithNothingDueDispatchesNothing()


    private function row(int $id, string $createdAt = '2026-03-04T11:00:00+00:00'): QueuedNotification
    {
        $row = new QueuedNotification();
        $row->setId($id);
        $row->setSchemaId(3);
        $row->setRuleKey('rule');
        $row->setRecipient('alice');
        $row->setCreatedAt(new DateTime($createdAt));
        return $row;

    }//end row()


    /**
     * @param array<string, mixed> $configuration Schema configuration
     */
    private function schema(array $configuration): Schema
    {
        $schema = new Schema();
        $schema->setConfiguration($configuration);
        return $schema;

    }//end schema()


    private function runJob(): void
    {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getDateTime')->willReturn(new DateTime(self::NOW));
        $job = new NotificationQueueFlushJob(
            $time,
            new QueueFlushTiming($this->schemas, $this->windows, $this->digests),
            new QueueFlushDelivery($this->queued, $this->dispatcher, $this->logger)
        );
        $method = new \ReflectionMethod($job, 'run');
        $method->invoke($job, null);

    }//end runJob()
}//end class
