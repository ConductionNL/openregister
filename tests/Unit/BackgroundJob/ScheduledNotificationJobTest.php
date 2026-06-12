<?php

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ScheduledNotificationJob;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\NotificationDedupeState;
use OCA\OpenRegister\Db\NotificationDedupeStateMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Service\Notification\ScheduledFilterEvaluator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Verifies the scheduled notification job's correctness contract:
 *  - fires once per intervalSec window (not on every 60s tick)
 *  - dispatches one event per object that matches `trigger.filter`
 *  - swallows per-object failures so one bad object doesn't block the rest
 *  - skips notifications without trigger.type=scheduled (other triggers
 *    are dispatched by the inline event listener, not this job)
 *
 * If this fails, scheduled notifications would either spam every minute
 * or silently never fire.
 */
class ScheduledNotificationJobTest extends TestCase
{
    private SchemaMapper&MockObject $schemaMapper;
    private MagicMapper&MockObject $objectMapper;
    private AnnotationNotificationDispatcher&MockObject $dispatcher;
    private LoggerInterface&MockObject $logger;
    private ICacheFactory&MockObject $cacheFactory;
    private ICache&MockObject $stateCache;
    private ITimeFactory&MockObject $time;
    private NotificationDedupeStateMapper&MockObject $dedupeMapper;
    private IAppConfig&MockObject $appConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->objectMapper = $this->createMock(MagicMapper::class);
        $this->dispatcher   = $this->createMock(AnnotationNotificationDispatcher::class);
        $this->logger       = $this->createMock(LoggerInterface::class);
        $this->cacheFactory = $this->createMock(ICacheFactory::class);
        $this->stateCache   = $this->createMock(ICache::class);
        $this->time         = $this->createMock(ITimeFactory::class);
        $this->dedupeMapper = $this->createMock(NotificationDedupeStateMapper::class);
        $this->appConfig    = $this->createMock(IAppConfig::class);

        $this->cacheFactory->method('createDistributed')
            ->with('openregister_scheduled_notifs')
            ->willReturn($this->stateCache);

        // Default: no prior dedup state — every match is treated as a fresh
        // dispatch. Per-test overrides exercise the dedup paths.
        $this->dedupeMapper->method('findOne')->willReturn(null);
        $this->appConfig->method('getValueString')->willReturnArgument(2);
    }

    public function testFiresWhenIntervalElapsed(): void
    {
        $schema = $this->scheduledSchema('action-item', 60, []);
        $object = $this->object('action-item');

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->objectMapper->method('findBySchema')->willReturn([$object]);

        // No prior fire → "due" → dispatch + record state.
        $this->stateCache->method('get')->willReturn(null);
        $this->stateCache->expects($this->once())->method('set');

        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($object, 'scheduled', $this->arrayHasKey('notificationName'));

        $this->runJob();
    }

    public function testDoesNotFireWhenIntervalNotElapsed(): void
    {
        $schema = $this->scheduledSchema('action-item', 3600, []);
        $object = $this->object('action-item');

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->objectMapper->method('findBySchema')->willReturn([$object]);

        // The job uses time() directly, so anchor `last` in real time:
        // last fired 10 min ago, interval is 1 hour → not yet due.
        $this->stateCache->method('get')->willReturn(time() - 600);
        $this->stateCache->expects($this->never())->method('set');
        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->runJob();
    }

    public function testFiresAfterIntervalEvenWithCachedFireTime(): void
    {
        $schema = $this->scheduledSchema('action-item', 3600, []);
        $object = $this->object('action-item');

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->objectMapper->method('findBySchema')->willReturn([$object]);

        // Last fired 2 hours ago, interval is 1 hour → due.
        $this->stateCache->method('get')->willReturn(time() - 7200);
        $this->stateCache->expects($this->once())->method('set');
        $this->dispatcher->expects($this->once())->method('dispatch');

        $this->runJob();
    }

    public function testFilterRestrictsToMatchingObjects(): void
    {
        $schema = $this->scheduledSchema('meeting', 60, ['lifecycle' => 'scheduled']);

        $matching = $this->object('meeting');
        $matching->setObject(['lifecycle' => 'scheduled', 'title' => 'A']);
        $unmatched = $this->object('meeting');
        $unmatched->setObject(['lifecycle' => 'closed', 'title' => 'B']);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->objectMapper->method('findBySchema')->willReturn([$matching, $unmatched]);
        $this->stateCache->method('get')->willReturn(null);

        $dispatched = [];
        $this->dispatcher->method('dispatch')
            ->willReturnCallback(function (ObjectEntity $obj, string $trigger) use (&$dispatched) {
                $dispatched[] = $obj->getObject()['title'] ?? null;
            });

        $this->runJob();
        $this->assertSame(['A'], $dispatched);
    }

    public function testEmptyFilterMatchesEveryObject(): void
    {
        $schema  = $this->scheduledSchema('meeting', 60, []);
        $object1 = $this->object('meeting');
        $object1->setObject(['title' => 'A']);
        $object2 = $this->object('meeting');
        $object2->setObject(['title' => 'B']);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->objectMapper->method('findBySchema')->willReturn([$object1, $object2]);
        $this->stateCache->method('get')->willReturn(null);

        $this->dispatcher->expects($this->exactly(2))->method('dispatch');

        $this->runJob();
    }

    public function testNonScheduledTriggersAreIgnored(): void
    {
        // The same job runs against ALL schemas; notifications with other
        // trigger types (created/updated/transition/threshold) must be
        // skipped silently — those are dispatched by inline listeners.
        $schema = new Schema();
        $schema->setId(1);
        $schema->setSlug('s');
        $schema->setConfiguration([
            'x-openregister-notifications' => [
                'inlineHook' => [
                    'trigger'    => ['type' => 'updated'],
                    'channels'   => ['nc-notification'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'subject'    => 'on update',
                ],
            ],
        ]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->dispatcher->expects($this->never())->method('dispatch');
        $this->stateCache->expects($this->never())->method('set');

        $this->runJob();
    }

    public function testIntervalBelowMinimumIsSkipped(): void
    {
        // intervalSec < 60 must be skipped — the validator already rejects
        // these, but defense-in-depth ensures a saved-but-invalid annotation
        // (e.g. via a direct DB write) doesn't melt the loop.
        $schema = $this->scheduledSchema('s', 30, []);
        $this->schemaMapper->method('findAll')->willReturn([$schema]);

        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->runJob();
    }

    public function testPerObjectDispatchFailureDoesNotBlockOthers(): void
    {
        $schema = $this->scheduledSchema('meeting', 60, []);
        $a      = $this->object('meeting');
        $a->setObject(['title' => 'A']);
        $b      = $this->object('meeting');
        $b->setObject(['title' => 'B']);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->objectMapper->method('findBySchema')->willReturn([$a, $b]);
        $this->stateCache->method('get')->willReturn(null);

        $callCount = 0;
        $this->dispatcher->method('dispatch')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    throw new \RuntimeException('first object exploded');
                }
            });

        $this->runJob();
        $this->assertSame(2, $callCount, 'second object should still be dispatched after the first throws');
    }

    public function testRecordsFireTimeOnCacheSet(): void
    {
        $schema = $this->scheduledSchema('meeting', 60, []);
        $object = $this->object('meeting');

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->objectMapper->method('findBySchema')->willReturn([$object]);
        $this->stateCache->method('get')->willReturn(null);

        $captured = null;
        $this->stateCache->expects($this->once())
            ->method('set')
            ->willReturnCallback(function (string $key, mixed $value, int $ttl) use (&$captured) {
                $captured = ['key' => $key, 'value' => $value, 'ttl' => $ttl];
                return true;
            });

        $this->runJob();
        $this->assertStringStartsWith('sched:1:', $captured['key']);
        $this->assertIsInt($captured['value']);
        // 30 day TTL — long enough that monthly-cadence schedules survive eviction cycles.
        $this->assertSame(60 * 60 * 24 * 30, $captured['ttl']);
    }

    private function runJob(): void
    {
        $job = new ScheduledNotificationJob(
            $this->time,
            $this->schemaMapper,
            $this->objectMapper,
            $this->dispatcher,
            $this->logger,
            $this->cacheFactory,
            new ScheduledFilterEvaluator(logger: $this->logger),
            $this->dedupeMapper,
            $this->appConfig
        );
        $reflection = new \ReflectionClass($job);
        $method     = $reflection->getMethod('run');
        $method->setAccessible(true);
        $method->invoke($job, null);
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function scheduledSchema(string $slug, int $intervalSec, array $filter): Schema
    {
        $schema = new Schema();
        $schema->setId(1);
        $schema->setSlug($slug);
        $schema->setConfiguration([
            'x-openregister-notifications' => [
                'scheduled-test' => [
                    'trigger'    => ['type' => 'scheduled', 'intervalSec' => $intervalSec, 'filter' => $filter],
                    'channels'   => ['nc-notification'],
                    'recipients' => [['kind' => 'users', 'users' => ['admin']]],
                    'subject'    => 'scheduled fired',
                ],
            ],
        ]);
        return $schema;
    }

    private function object(string $schemaSlug): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid('uuid-' . bin2hex(random_bytes(4)));
        $object->setRegister('r');
        $object->setSchema($schemaSlug);
        return $object;
    }

    // ---- Phase 3 — per-object dedup ----

    public function testFirstMatchDispatchesAndUpsertsState(): void
    {
        $schema = $this->scheduledWithDedupeFields('inbox', 60, ['status']);
        $object = $this->object('inbox');
        $object->setObject(['status' => 'open']);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->objectMapper->method('findBySchema')->willReturn([$object]);
        $this->stateCache->method('get')->willReturn(null);

        // First time: findOne returns null → dispatch + upsert(dispatched=true).
        $this->dedupeMapper->expects($this->once())
            ->method('upsert')
            ->with(
                $this->equalTo(1),
                $this->equalTo('scheduled-test'),
                $this->equalTo($object->getUuid()),
                $this->isType('string'),
                $this->isInstanceOf(\DateTime::class),
                $this->equalTo(true)
            );
        $this->dispatcher->expects($this->once())->method('dispatch');

        $this->runJob();
    }

    public function testFingerprintEqualSkipsDispatch(): void
    {
        $schema = $this->scheduledWithDedupeFields('inbox', 60, ['status']);
        $object = $this->object('inbox');
        $object->setObject(['status' => 'open']);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->objectMapper->method('findBySchema')->willReturn([$object]);
        $this->stateCache->method('get')->willReturn(null);

        // Pre-existing dedup row with a fingerprint that matches what the
        // job will compute for ['status' => 'open'].
        $fingerprint = sha1(json_encode(['status' => 'open']));
        $row         = new NotificationDedupeState();
        $row->setSchemaId(1);
        $row->setRuleKey('scheduled-test');
        $row->setObjectUuid((string) $object->getUuid());
        $row->setFingerprint($fingerprint);
        $row->setDispatchedAt(new \DateTime('-1 hour'));
        $row->setSeenAt(new \DateTime('-1 hour'));

        $this->dedupeMapper = $this->createMock(NotificationDedupeStateMapper::class);
        $this->dedupeMapper->method('findOne')->willReturn($row);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueString')->willReturnArgument(2);

        // Dispatch must be skipped; upsert is still called to touch seen_at.
        $this->dispatcher->expects($this->never())->method('dispatch');
        $this->dedupeMapper->expects($this->atLeastOnce())
            ->method('upsert')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->equalTo(false)
            );

        $this->runJob();
    }

    public function testFingerprintChangeReArmsDispatch(): void
    {
        $schema = $this->scheduledWithDedupeFields('inbox', 60, ['status']);
        $object = $this->object('inbox');
        $object->setObject(['status' => 'open']);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->objectMapper->method('findBySchema')->willReturn([$object]);
        $this->stateCache->method('get')->willReturn(null);

        // Stored fingerprint is for an older ['status' => 'done'] payload.
        $oldFingerprint = sha1(json_encode(['status' => 'done']));
        $row            = new NotificationDedupeState();
        $row->setSchemaId(1);
        $row->setRuleKey('scheduled-test');
        $row->setObjectUuid((string) $object->getUuid());
        $row->setFingerprint($oldFingerprint);
        $row->setDispatchedAt(new \DateTime('-1 hour'));
        $row->setSeenAt(new \DateTime('-1 hour'));

        $this->dedupeMapper = $this->createMock(NotificationDedupeStateMapper::class);
        $this->dedupeMapper->method('findOne')->willReturn($row);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueString')->willReturnArgument(2);

        $this->dispatcher->expects($this->once())->method('dispatch');
        $this->dedupeMapper->expects($this->once())
            ->method('upsert')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->equalTo(true)
            );

        $this->runJob();
    }

    public function testDedupeFieldsOverrideWatchedSet(): void
    {
        // Filter has a withinNext on dueAt; dedupeFields forces status only.
        $schema = $this->scheduledSchema('inbox', 60, [
            'dueAt' => ['operator' => 'withinNext', 'value' => 'PT24H'],
        ]);
        $cfg = $schema->getConfiguration();
        $cfg['x-openregister-notifications']['scheduled-test']['trigger']['dedupeFields'] = ['status'];
        $schema->setConfiguration($cfg);

        $object = $this->object('inbox');
        // dueAt changes between scans, but dedupeFields excludes it from the fingerprint.
        $object->setObject([
            'status' => 'open',
            'dueAt'  => (new \DateTimeImmutable('+12 hours'))->format(\DateTimeImmutable::ATOM),
        ]);

        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->objectMapper->method('findBySchema')->willReturn([$object]);
        $this->stateCache->method('get')->willReturn(null);

        $fingerprint = sha1(json_encode(['status' => 'open']));
        $row         = new NotificationDedupeState();
        $row->setSchemaId(1);
        $row->setRuleKey('scheduled-test');
        $row->setObjectUuid((string) $object->getUuid());
        $row->setFingerprint($fingerprint);
        $row->setDispatchedAt(new \DateTime('-1 hour'));
        $row->setSeenAt(new \DateTime('-1 hour'));

        $this->dedupeMapper = $this->createMock(NotificationDedupeStateMapper::class);
        $this->dedupeMapper->method('findOne')->willReturn($row);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueString')->willReturnArgument(2);

        // Status unchanged → fingerprint match → no dispatch.
        $this->dispatcher->expects($this->never())->method('dispatch');

        $this->runJob();
    }

    public function testRetentionSweepRunsAfterScan(): void
    {
        $schema = $this->scheduledSchema('inbox', 60, []);
        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->objectMapper->method('findBySchema')->willReturn([]);
        $this->stateCache->method('get')->willReturn(null);

        $this->dedupeMapper->expects($this->once())
            ->method('deleteSeenBefore')
            ->with($this->isInstanceOf(\DateTime::class));

        $this->runJob();
    }

    public function testRetentionSweepRespectsZeroDays(): void
    {
        $schema = $this->scheduledSchema('inbox', 60, []);
        $this->schemaMapper->method('findAll')->willReturn([$schema]);
        $this->objectMapper->method('findBySchema')->willReturn([]);
        $this->stateCache->method('get')->willReturn(null);

        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueString')->willReturn('0');

        $this->dedupeMapper->expects($this->never())->method('deleteSeenBefore');

        $this->runJob();
    }

    /**
     * @param array<int, string> $dedupeFields
     */
    private function scheduledWithDedupeFields(string $slug, int $intervalSec, array $dedupeFields): Schema
    {
        $schema = $this->scheduledSchema($slug, $intervalSec, []);
        $cfg    = $schema->getConfiguration();
        $cfg['x-openregister-notifications']['scheduled-test']['trigger']['dedupeFields'] = $dedupeFields;
        $schema->setConfiguration($cfg);
        return $schema;
    }
}
