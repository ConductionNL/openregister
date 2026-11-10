<?php

/**
 * Failure handling, skip rules and fingerprint derivation of the scheduled notification job.
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
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Notifications\BackgroundJob\ScheduledNotificationDelivery;
use OCA\OpenRegister\Notifications\BackgroundJob\ScheduledNotificationJob;
use OCA\OpenRegister\Notifications\BackgroundJob\ScheduledObjectSources;
use OCA\OpenRegister\Notifications\Db\DedupeSighting;
use OCA\OpenRegister\Notifications\Db\NotificationDedupeState;
use OCA\OpenRegister\Notifications\Db\NotificationDedupeStateMapper;
use OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledFilterEvaluator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\RegisterMapperInterface;

/**
 * Behaviour of ScheduledNotificationJob on the paths the contract tests leave out.
 */
class ScheduledNotificationJobBehaviourTest extends TestCase
{

    private SchemaMapperInterface&MockObject $schemaMapper;

    private MagicMapperInterface&MockObject $objectMapper;

    private AnnotationNotificationDispatcher&MockObject $dispatcher;

    private NotificationDedupeStateMapper&MockObject $dedupeMapper;

    private IAppConfig&MockObject $appConfig;

    private LoggerInterface&MockObject $logger;

    private ICacheFactory&MockObject $cacheFactory;

    private ICache&MockObject $stateCache;


    protected function setUp(): void
    {
        parent::setUp();
        $this->schemaMapper = $this->createMock(SchemaMapperInterface::class);
        $this->objectMapper = $this->createMock(MagicMapperInterface::class);
        $this->dispatcher   = $this->createMock(AnnotationNotificationDispatcher::class);
        $this->dedupeMapper = $this->createMock(NotificationDedupeStateMapper::class);
        $this->appConfig    = $this->createMock(IAppConfig::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->stateCache   = $this->createMock(ICache::class);
        $this->cacheFactory->method('createDistributed')->willReturn($this->stateCache);

    }//end setUp()


    public function testNoStateCacheMeansNoRuleEverFires(): void
    {
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cacheFactory->method('createDistributed')->willThrowException(new RuntimeException('no cache'));
        $this->schemaMapper->method('findAll')->willReturn([$this->schema(rules: $this->rule())]);
        $this->appConfig->method('getValueString')->willReturnArgument(2);

        $this->objectMapper->expects($this->never())->method('findBySchema');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->runJob();

    }//end testNoStateCacheMeansNoRuleEverFires()


    public function testUnreadableSchemaListLogsAndSkipsTheRetentionSweep(): void
    {
        $this->schemaMapper->method('findAll')->willThrowException(new RuntimeException('db down'));

        $this->logger->expects($this->once())->method('warning')
            ->with('[ScheduledNotificationJob] schema list failed: db down');
        $this->dedupeMapper->expects($this->never())->method('deleteSeenBefore');

        $this->runJob();

    }//end testUnreadableSchemaListLogsAndSkipsTheRetentionSweep()


    public function testNonSchemaEntriesAndRuleLessSchemasAreSkippedButTheSweepRuns(): void
    {
        $empty = $this->schema(rules: []);
        $none  = new Schema();
        $none->setId(2);
        $this->schemaMapper->method('findAll')->willReturn(['not-a-schema', $empty, $none]);
        $this->appConfig->method('getValueString')->willReturnArgument(2);

        $this->stateCache->expects($this->never())->method('get');
        $this->dedupeMapper->expects($this->once())->method('deleteSeenBefore');

        $this->runJob();

    }//end testNonSchemaEntriesAndRuleLessSchemasAreSkippedButTheSweepRuns()


    public function testRulesThatAreNotArraysOrHaveNoArrayTriggerAreSkipped(): void
    {
        $schema = $this->schema(rules: ['scalar' => 'x', 'noTrigger' => ['trigger' => 'scheduled'], 'typeless' => ['trigger' => ['intervalSec' => 120]]]);
        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->appConfig->method('getValueString')->willReturnArgument(2);

        $this->stateCache->expects($this->never())->method('get');

        $this->runJob();

    }//end testRulesThatAreNotArraysOrHaveNoArrayTriggerAreSkipped()


    public function testUnreadableRetentionSettingFallsBackToTheMapperDefault(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([]);
        $this->appConfig->method('getValueString')->willThrowException(new RuntimeException('config down'));

        $expected = (time() - (NotificationDedupeStateMapper::DEFAULT_RETENTION_DAYS * 86400));
        $this->dedupeMapper->expects($this->once())->method('deleteSeenBefore')
            ->with(
                $this->callback(
                    static fn (DateTime $cutoff): bool => abs($cutoff->getTimestamp() - $expected) <= 5
                )
            );

        $this->runJob();

    }//end testUnreadableRetentionSettingFallsBackToTheMapperDefault()


    public function testUnreadableObjectsLogAWarningButStillRecordTheFire(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([$this->schema(rules: $this->rule())]);
        $this->objectMapper->method('findBySchema')->willThrowException(new RuntimeException('table gone'));
        $this->appConfig->method('getValueString')->willReturnArgument(2);
        $this->stateCache->method('get')->willReturn(null);

        $this->logger->expects($this->once())->method('warning')
            ->with('[ScheduledNotificationJob] findBySchema(7, "rule") failed: table gone');
        $this->stateCache->expects($this->once())->method('set')
            ->with('sched:7:rule', $this->isType('int'), (60 * 60 * 24 * 30));
        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->runJob();

    }//end testUnreadableObjectsLogAWarningButStillRecordTheFire()


    public function testCacheWriteFailureDoesNotUndoTheDispatch(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([$this->schema(rules: $this->rule())]);
        $this->objectMapper->method('findBySchema')->willReturn([$this->object(uuid: 'u-1', data: [])]);
        $this->appConfig->method('getValueString')->willReturnArgument(2);
        $this->stateCache->method('get')->willReturn(null);
        $this->stateCache->method('set')->willThrowException(new RuntimeException('cache full'));

        $this->dispatcher->expects($this->once())->method('dispatch')
            ->with($this->isInstanceOf(ObjectEntity::class), 'scheduled', ['notificationName' => 'rule']);

        $this->runJob();

    }//end testCacheWriteFailureDoesNotUndoTheDispatch()


    public function testStringLastFireTimeInTheFutureIsNotDue(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([$this->schema(rules: $this->rule())]);
        $this->appConfig->method('getValueString')->willReturnArgument(2);
        $this->stateCache->method('get')->willReturn((string)(time() + 3600));

        $this->objectMapper->expects($this->never())->method('findBySchema');

        $this->runJob();

    }//end testStringLastFireTimeInTheFutureIsNotDue()


    public function testNonEntityObjectsAndUuidLessObjectsAreNotDispatched(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([$this->schema(rules: $this->rule())]);
        $this->objectMapper->method('findBySchema')->willReturn(['junk', $this->object(uuid: '', data: [])]);
        $this->appConfig->method('getValueString')->willReturnArgument(2);
        $this->stateCache->method('get')->willReturn(null);

        $this->dedupeMapper->expects($this->never())->method('findByKey');
        $this->dispatcher->expects($this->never())->method('dispatch');
        $this->logger->expects($this->once())->method('info')
            ->with('[ScheduledNotificationJob] fired "rule" on schema 7: matched=1 dispatched=0 deduped=0 of 2');

        $this->runJob();

    }//end testNonEntityObjectsAndUuidLessObjectsAreNotDispatched()


    public function testUnchangedFingerprintIsCountedAsDedupedEvenWhenTheTouchFails(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([$this->schema(rules: $this->rule())]);
        $this->objectMapper->method('findBySchema')->willReturn([$this->object(uuid: 'u-1', data: [])]);
        $this->appConfig->method('getValueString')->willReturnArgument(2);
        $this->stateCache->method('get')->willReturn(null);
        $row = new NotificationDedupeState();
        $row->setFingerprint(sha1('constant'));
        $this->dedupeMapper->method('findByKey')->willReturn($row);
        $this->dedupeMapper->method('upsert')->willThrowException(new RuntimeException('locked'));

        $this->dispatcher->expects($this->never())->method('dispatch');
        $this->logger->expects($this->once())->method('info')
            ->with('[ScheduledNotificationJob] fired "rule" on schema 7: matched=1 dispatched=0 deduped=1 of 1');

        $this->runJob();

    }//end testUnchangedFingerprintIsCountedAsDedupedEvenWhenTheTouchFails()


    public function testFingerprintWatchesOnlyTheTimeWindowFieldsOfTheFilter(): void
    {
        $filter = [
            'status'    => 'open',
            'kind'      => ['operator' => 'notEquals', 'value' => 'x'],
            'createdAt' => ['operator' => 'olderThan', 'value' => 'P30D'],
        ];
        $rule   = $this->rule(trigger: ['filter' => $filter, 'dedupeFields' => 'status']);
        $this->schemaMapper->method('findAll')->willReturn([$this->schema(rules: $rule)]);
        $data = ['status' => 'open', 'kind' => 'y', 'createdAt' => '2000-01-01T00:00:00+00:00'];
        $this->objectMapper->method('findBySchema')->willReturn([$this->object(uuid: 'u-1', data: $data)]);
        $this->appConfig->method('getValueString')->willReturnArgument(2);
        $this->stateCache->method('get')->willReturn(null);

        $expected = sha1((string)json_encode(['createdAt' => '2000-01-01T00:00:00+00:00'], (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        $this->dedupeMapper->expects($this->once())->method('upsert')
            ->with($this->anything(), $this->callback(static fn (DedupeSighting $sighting): bool => $sighting->fingerprint === $expected && $sighting->dispatched === true));

        $this->runJob();

    }//end testFingerprintWatchesOnlyTheTimeWindowFieldsOfTheFilter()


    public function testExplicitDedupeFieldsAreFilteredDeduplicatedAndSorted(): void
    {
        $rule = $this->rule(trigger: ['dedupeFields' => ['b', 'a', 'a', '', 5]]);
        $this->schemaMapper->method('findAll')->willReturn([$this->schema(rules: $rule)]);
        $this->objectMapper->method('findBySchema')->willReturn([$this->object(uuid: 'u-1', data: ['b' => 2, 'a' => 1])]);
        $this->appConfig->method('getValueString')->willReturnArgument(2);
        $this->stateCache->method('get')->willReturn(null);

        $expected = sha1('{"a":1,"b":2}');
        $this->dedupeMapper->expects($this->once())->method('upsert')
            ->with($this->anything(), $this->callback(static fn (DedupeSighting $sighting): bool => $sighting->fingerprint === $expected && $sighting->dispatched === true));

        $this->runJob();

    }//end testExplicitDedupeFieldsAreFilteredDeduplicatedAndSorted()


    public function testUnencodableWatchedValueFallsBackToVarExport(): void
    {
        $rule = $this->rule(trigger: ['dedupeFields' => ['bad', 'missing']]);
        $this->schemaMapper->method('findAll')->willReturn([$this->schema(rules: $rule)]);
        $this->objectMapper->method('findBySchema')->willReturn([$this->object(uuid: 'u-1', data: ['bad' => "\xB1\x31"])]);
        $this->appConfig->method('getValueString')->willReturnArgument(2);
        $this->stateCache->method('get')->willReturn(null);

        $expected = sha1(var_export(['bad' => "\xB1\x31", 'missing' => null], true));
        $this->dedupeMapper->expects($this->once())->method('upsert')
            ->with($this->anything(), $this->callback(static fn (DedupeSighting $sighting): bool => $sighting->fingerprint === $expected && $sighting->dispatched === true));

        $this->runJob();

    }//end testUnencodableWatchedValueFallsBackToVarExport()


    /**
     * @return array<string, array{0: callable}>
     */
    public static function unusableOffsets(): array
    {
        return [
            'offset unreadable'    => [static function (string $app, string $key, string $default): string {
                if (str_starts_with($key, 'sched_offset') === true) {
                    throw new RuntimeException('config down');
                }

                return $default;
            },
            ],
            'offset negative'      => [static fn (string $app, string $key, string $default): string => str_starts_with($key, 'sched_offset') === true ? '-3' : $default],
            'offset beyond the end' => [static fn (string $app, string $key, string $default): string => str_starts_with($key, 'sched_offset') === true ? '6000' : $default],
        ];

    }//end unusableOffsets()


    #[DataProvider('unusableOffsets')]
    public function testUnusableWindowOffsetRestartsAtTheFirstObject(callable $reader): void
    {
        $this->appConfig->method('getValueString')->willReturnCallback($reader);
        $this->appConfig->expects($this->once())->method('setValueString')
            ->with('openregister', 'sched_offset:7:rule', '5000');
        $first = null;
        $this->dispatcher->method('dispatch')->willReturnCallback(
            static function (ObjectEntity $object) use (&$first): void {
                $first = ($first ?? $object->getUuid());
            }
        );

        $this->runLargeSchema(count: 5001);

        $this->assertSame('u-0', $first);

    }//end testUnusableWindowOffsetRestartsAtTheFirstObject()


    public function testOffsetWriteFailureIsLogged(): void
    {
        $this->appConfig->method('getValueString')->willReturnArgument(2);
        $this->appConfig->method('setValueString')->willThrowException(new RuntimeException('read only'));
        $warnings = [];
        $this->logger->method('warning')->willReturnCallback(
            static function (string $message) use (&$warnings): void {
                $warnings[] = $message;
            }
        );

        $this->runLargeSchema(count: 5001);

        $this->assertContains(
            '[ScheduledNotificationJob] failed to persist rotation offset for schema 7 / "rule": read only',
            $warnings
        );

    }//end testOffsetWriteFailureIsLogged()


    private function runLargeSchema(int $count): void
    {
        $objects = [];
        for ($i = 0; $i < $count; $i++) {
            $objects[] = $this->object(uuid: 'u-'.$i, data: []);
        }

        $this->schemaMapper->method('findAll')->willReturn([$this->schema(rules: $this->rule())]);
        $this->objectMapper->method('findBySchema')->willReturn($objects);
        $this->stateCache->method('get')->willReturn(null);
        $this->runJob();

    }//end runLargeSchema()


    /**
     * @param array<string, mixed> $trigger Extra trigger keys
     *
     * @return array<string, mixed>
     */
    private function rule(array $trigger = []): array
    {
        return ['rule' => ['trigger' => array_merge(['type' => 'scheduled', 'intervalSec' => 60], $trigger)]];

    }//end rule()


    /**
     * @param array<string|int, mixed> $rules Notification rules
     */
    private function schema(array $rules): Schema
    {
        $schema = new Schema();
        $schema->setId(7);
        $schema->setConfiguration(['x-openregister-notifications' => $rules]);
        return $schema;

    }//end schema()


    /**
     * @param array<string, mixed> $data Object payload
     */
    private function object(string $uuid, array $data): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid($uuid);
        $object->setObject($data);
        return $object;

    }//end object()


    private function runJob(): void
    {
        $job = new ScheduledNotificationJob(
            $this->createMock(ITimeFactory::class),
            new ScheduledObjectSources(new SchemaCatalog(registers: $this->createMock(RegisterMapperInterface::class), schemas: $this->schemaMapper, tables: $this->objectMapper), new ScheduledFilterEvaluator(logger: $this->logger)),
            new ScheduledNotificationDelivery($this->dispatcher, $this->dedupeMapper, $this->appConfig, $this->logger),
            $this->cacheFactory
        );
        $method = new \ReflectionMethod($job, 'run');
        $method->invoke($job, null);

    }//end runJob()
}//end class
