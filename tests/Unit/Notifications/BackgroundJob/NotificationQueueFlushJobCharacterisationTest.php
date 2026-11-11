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
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Notifications\BackgroundJob\NotificationQueueFlushJob;
use OCA\OpenRegister\Notifications\BackgroundJob\QueueFlushDelivery;
use OCA\OpenRegister\Notifications\BackgroundJob\QueueFlushTiming;
use OCA\OpenRegister\Notifications\Db\QueuedNotification;
use OCA\OpenRegister\Notifications\Db\QueuedNotificationMapper;
use OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Notifications\Service\Notification\DigestScheduleEvaluator;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationDeliveryWindowService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the flush job's failure handling, grouping and digest-lookup fallbacks.
 *
 * @covers \OCA\OpenRegister\Notifications\BackgroundJob\NotificationQueueFlushJob
 */
class NotificationQueueFlushJobCharacterisationTest extends TestCase
{

    private QueuedNotificationMapper&MockObject $queued;

    private SchemaMapper&MockObject $schemas;

    private AnnotationNotificationDispatcher&MockObject $dispatcher;

    /** @var list<string> */
    private array $log = [];

    /** @var list<int> */
    private array $deleted = [];

    /** @var list<list<int>> */
    private array $batches = [];

    protected function setUp(): void
    {
        $this->queued     = $this->createMock(QueuedNotificationMapper::class);
        $this->schemas    = $this->createMock(SchemaMapper::class);
        $this->dispatcher = $this->createMock(AnnotationNotificationDispatcher::class);
        $this->log        = [];
        $this->deleted    = [];
        $this->batches    = [];
        $this->queued->method('deleteById')->willReturnCallback(
            function (int $id): void {
                $this->deleted[] = $id;
            }
        );
    }

    private function flush(): void
    {
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getDateTime')->willReturn(new DateTime('2026-03-04T12:00:00+00:00'));
        $config = $this->createMock(IConfig::class);
        $config->method('getUserValue')->willReturnCallback(fn ($uid, $app, $key, $default = '') => $default);
        $windows = new NotificationDeliveryWindowService($config, null);
        $logger  = $this->createMock(LoggerInterface::class);
        foreach (['info', 'warning'] as $level) {
            $logger->method($level)->willReturnCallback(
                function ($message) use ($level): void {
                    $this->log[] = $level.': '.$message;
                }
            );
        }

        $job = new NotificationQueueFlushJob(
            $time,
            new QueueFlushTiming($this->schemas, $windows, new DigestScheduleEvaluator($windows)),
            new QueueFlushDelivery($this->queued, $this->dispatcher, $logger)
        );
        $method = new \ReflectionMethod($job, 'run');
        $method->invoke($job, null);
    }

    private function row(int $id, int $schemaId, string $rule, string $recipient): QueuedNotification
    {
        $row = new QueuedNotification();
        $row->setId($id);
        $row->setSchemaId($schemaId);
        $row->setRuleKey($rule);
        $row->setRecipient($recipient);
        $row->setReason('digest');
        $row->setPayload('{}');
        $row->setCreatedAt(new DateTime('2026-03-04T11:00:00+00:00'));
        return $row;
    }

    private function recordBatches(?string $failRule = null): void
    {
        $this->dispatcher->method('dispatchQueued')->willReturnCallback(
            function (array $queuedRows) use ($failRule): void {
                if ($queuedRows[0]->getRuleKey() === $failRule) {
                    throw new RuntimeException('smtp down');
                }
                $this->batches[] = array_map(fn (QueuedNotification $r) => (int) $r->getId(), $queuedRows);
            }
        );
    }

    public function testScanFailureIsLoggedAndNothingElseHappens(): void
    {
        $this->queued->method('findAll')->willThrowException(new RuntimeException('no table'));
        $this->dispatcher->expects($this->never())->method('dispatchQueued');
        $this->flush();
        $this->assertSame(['warning: [NotificationQueueFlushJob] queue scan failed: no table'], $this->log);
    }

    public function testEmptyQueueLogsNothing(): void
    {
        $this->queued->method('findAll')->willReturn([]);
        $this->schemas->expects($this->never())->method('find');
        $this->flush();
        $this->assertSame([], $this->log);
    }

    public function testGroupsInScanOrderAndAFailedGroupKeepsItsRows(): void
    {
        $this->queued->method('findAll')->willReturn(
            [
                $this->row(1, 9, 'a', 'u1'),
                $this->row(2, 9, 'b', 'u1'),
                $this->row(3, 9, 'a', 'u1'),
                $this->row(4, 9, 'a', 'u2'),
                $this->row(5, 8, 'a', 'u1'),
            ]
        );
        $this->schemas->method('find')->willThrowException(new RuntimeException('gone'));
        $this->recordBatches('b');

        $this->flush();

        $this->assertSame([[1, 3], [4], [5]], $this->batches);
        $this->assertSame([1, 3, 4, 5], $this->deleted);
        $this->assertSame(
            [
                'warning: [NotificationQueueFlushJob] dispatchQueued failed for rule="b" recipient="u1": smtp down',
                'info: [NotificationQueueFlushJob] flushed 4 queued notification(s)',
            ],
            $this->log
        );
    }

    public function testAllGroupsFailingLogsNoFlushCount(): void
    {
        $this->queued->method('findAll')->willReturn([$this->row(1, 9, 'b', 'u1')]);
        $this->schemas->method('find')->willThrowException(new RuntimeException('gone'));
        $this->recordBatches('b');
        $this->flush();
        $this->assertSame([], $this->deleted);
        $this->assertSame(['warning: [NotificationQueueFlushJob] dispatchQueued failed for rule="b" recipient="u1": smtp down'], $this->log);
    }

    /** @return array<string, array{0: ?array}> */
    public static function noDigestProvider(): array
    {
        return [
            'no configuration'        => [null],
            'notifications not array' => [['x-openregister-notifications' => 'x']],
            'rule missing'            => [['x-openregister-notifications' => ['other' => []]]],
            'rule not array'          => [['x-openregister-notifications' => ['a' => 'x']]],
            'digest missing'          => [['x-openregister-notifications' => ['a' => []]]],
            'digest not array'        => [['x-openregister-notifications' => ['a' => ['digest' => 'daily']]]],
            'digest invalid'          => [['x-openregister-notifications' => ['a' => ['digest' => ['schedule' => 'hourly', 'at' => '09:00']]]]],
        ];
    }

    #[DataProvider('noDigestProvider')]
    public function testWithoutAUsableDigestTheRowIsDueNow(?array $configuration): void
    {
        $schema = new Schema();
        $schema->setConfiguration($configuration);
        $this->queued->method('findAll')->willReturn([$this->row(1, 9, 'a', 'u1')]);
        $this->schemas->expects($this->once())->method('find')->with(9, [], true, false)->willReturn($schema);
        $this->recordBatches();

        $this->flush();

        $this->assertSame([[1]], $this->batches);
    }

    public function testAValidDigestNotYetDueHoldsTheRow(): void
    {
        $schema = new Schema();
        $schema->setConfiguration(['x-openregister-notifications' => ['a' => ['digest' => ['schedule' => 'weekly', 'at' => '09:00', 'weekday' => 1]]]]);
        $this->queued->method('findAll')->willReturn([$this->row(1, 9, 'a', 'u1')]);
        $this->schemas->method('find')->willReturn($schema);
        $this->dispatcher->expects($this->never())->method('dispatchQueued');
        $this->flush();
        $this->assertSame([], $this->log);
    }
}
