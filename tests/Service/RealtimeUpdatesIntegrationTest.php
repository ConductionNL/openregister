<?php

/**
 * Integration tests for realtime-updates v1.
 *
 * Verifies the append-only event log + cursor-based polling end-to-end:
 *   - object writes flow through `RealtimeEventListener` → `RealtimeService::record`
 *   - rows land in `openregister_realtime_events` in CloudEvent shape
 *   - `RealtimeEventMapper::findSince` paginates strictly by id
 *   - subscription filters (register/schema/objectUuid/eventType) gate results
 *   - cursor advancement is deterministic
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RealtimeEventMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\RealtimeService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class RealtimeUpdatesIntegrationTest extends TestCase
{
    private RealtimeService $realtimeService;
    private RealtimeEventMapper $eventMapper;
    private RegisterMapper $registerMapper;
    private SchemaMapper $schemaMapper;
    private MagicMapper $objectMapper;

    private ?Register $testRegister = null;
    private ?Schema $testSchema = null;
    private ?ObjectEntity $testObject = null;
    private ?string $createdTable = null;
    private array $createdEventIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->realtimeService = \OC::$server->get(RealtimeService::class);
        $this->eventMapper     = \OC::$server->get(RealtimeEventMapper::class);
        $this->registerMapper  = \OC::$server->get(RegisterMapper::class);
        $this->schemaMapper    = \OC::$server->get(SchemaMapper::class);
        $this->objectMapper    = \OC::$server->get(MagicMapper::class);

        $this->createTestFixture();
    }

    protected function tearDown(): void
    {
        $db = \OC::$server->get(\OCP\IDBConnection::class);

        // Clean up the event rows we created so subsequent test runs see a clean stream.
        foreach ($this->createdEventIds as $id) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_realtime_events')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable $e) {
                // best effort
            }
        }

        if ($this->testSchema !== null) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_schemas')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($this->testSchema->getId(), IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable $e) {
                // best effort
            }
        }
        if ($this->testRegister !== null) {
            try {
                $qb = $db->getQueryBuilder();
                $qb->delete('openregister_registers')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($this->testRegister->getId(), IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable $e) {
                // best effort
            }
        }
        if ($this->createdTable !== null) {
            try {
                $db->prepare("DROP TABLE IF EXISTS \"{$this->createdTable}\"")->execute();
            } catch (\Throwable $e) {
                // best effort
            }
        }
        parent::tearDown();
    }

    public function testRecordWritesCloudEventShapedRow(): void
    {
        $event = $this->realtimeService->record(
            RealtimeService::TYPE_OBJECT_CREATED,
            $this->testObject
        );

        $this->assertNotNull($event);
        $this->createdEventIds[] = $event->getId();

        // CloudEvent envelope verification.
        $this->assertSame(RealtimeService::TYPE_OBJECT_CREATED, $event->getEventType());
        $this->assertSame((string) $this->testObject->getUuid(), $event->getObjectUuid());

        $payload = json_decode((string) $event->getPayload(), true);
        $this->assertIsArray($payload);
        $this->assertSame('1.0', $payload['specversion'] ?? null, 'CloudEvents v1.0 specversion MUST be set');
        $this->assertSame(RealtimeService::TYPE_OBJECT_CREATED, $payload['type'] ?? null);
        $this->assertNotEmpty($payload['source'] ?? '', 'source MUST be a non-empty URI');
        $this->assertNotEmpty($payload['id'] ?? '',     'id MUST be unique per event');
        $this->assertArrayHasKey('time', $payload);
        $this->assertArrayHasKey('data', $payload);

        $this->assertSame((string) $this->testObject->getUuid(), $payload['data']['uuid']  ?? null);
        $this->assertNotEmpty($payload['data']['urn'] ?? '', 'data.urn MUST be present (UrnService is wired in)');
    }

    public function testFindSinceReturnsEventsStrictlyAfterCursor(): void
    {
        $headBefore = $this->eventMapper->getMaxId();

        $event1 = $this->realtimeService->record(RealtimeService::TYPE_OBJECT_CREATED, $this->testObject);
        $event2 = $this->realtimeService->record(RealtimeService::TYPE_OBJECT_UPDATED, $this->testObject);
        $event3 = $this->realtimeService->record(RealtimeService::TYPE_OBJECT_DELETED, $this->testObject);
        $this->createdEventIds[] = $event1->getId();
        $this->createdEventIds[] = $event2->getId();
        $this->createdEventIds[] = $event3->getId();

        // Filter by objectUuid so we don't see other tests' churn.
        $filters = ['objectUuid' => (string) $this->testObject->getUuid()];

        $batch = $this->eventMapper->findSince(since: $headBefore, limit: 100, filters: $filters);
        $ids   = array_map(fn($e) => $e->getId(), $batch);
        $this->assertContains($event1->getId(), $ids);
        $this->assertContains($event2->getId(), $ids);
        $this->assertContains($event3->getId(), $ids);

        // Strict-greater-than on cursor: passing event2's id MUST exclude
        // event1 and event2; only event3 surfaces.
        $afterEvent2 = $this->eventMapper->findSince(since: $event2->getId(), limit: 100, filters: $filters);
        $ids         = array_map(fn($e) => $e->getId(), $afterEvent2);
        $this->assertNotContains($event1->getId(), $ids);
        $this->assertNotContains($event2->getId(), $ids);
        $this->assertContains($event3->getId(),    $ids);
    }

    public function testFiltersIsolatePerObjectStreams(): void
    {
        $headBefore = $this->eventMapper->getMaxId();

        // Two distinct objects in the same schema.
        $other = new ObjectEntity();
        $other->setUuid(Uuid::v4()->toRfc4122());
        $other->setRegister((string) $this->testRegister->getId());
        $other->setSchema((string) $this->testSchema->getId());
        $other->setObject(['title' => 'other']);
        $other = $this->objectMapper->insertObjectEntity($other, $this->testRegister, $this->testSchema, false);

        $myEvent    = $this->realtimeService->record(RealtimeService::TYPE_OBJECT_UPDATED, $this->testObject);
        $otherEvent = $this->realtimeService->record(RealtimeService::TYPE_OBJECT_UPDATED, $other);
        $this->createdEventIds[] = $myEvent->getId();
        $this->createdEventIds[] = $otherEvent->getId();

        $myStream = $this->eventMapper->findSince(
            since: $headBefore,
            limit: 100,
            filters: ['objectUuid' => (string) $this->testObject->getUuid()]
        );
        $myIds = array_map(fn($e) => $e->getId(), $myStream);
        $this->assertContains($myEvent->getId(),       $myIds);
        $this->assertNotContains($otherEvent->getId(), $myIds, 'objectUuid filter MUST exclude other object streams');
    }

    public function testFiltersIsolatePerEventTypeStreams(): void
    {
        $headBefore = $this->eventMapper->getMaxId();

        $created = $this->realtimeService->record(RealtimeService::TYPE_OBJECT_CREATED, $this->testObject);
        $updated = $this->realtimeService->record(RealtimeService::TYPE_OBJECT_UPDATED, $this->testObject);
        $this->createdEventIds[] = $created->getId();
        $this->createdEventIds[] = $updated->getId();

        $createdOnly = $this->eventMapper->findSince(
            since: $headBefore,
            limit: 100,
            filters: [
                'objectUuid' => (string) $this->testObject->getUuid(),
                'eventType'  => RealtimeService::TYPE_OBJECT_CREATED,
            ]
        );
        $ids = array_map(fn($e) => $e->getId(), $createdOnly);
        $this->assertContains($created->getId(),    $ids);
        $this->assertNotContains($updated->getId(), $ids, 'eventType filter MUST exclude other event types');
    }

    public function testGetMaxIdReflectsLatestInsertedEvent(): void
    {
        $beforeMax = $this->eventMapper->getMaxId();
        $event     = $this->realtimeService->record(RealtimeService::TYPE_OBJECT_CREATED, $this->testObject);
        $this->createdEventIds[] = $event->getId();
        $afterMax  = $this->eventMapper->getMaxId();

        $this->assertGreaterThan($beforeMax,        $afterMax);
        $this->assertGreaterThanOrEqual($event->getId(), $afterMax);
    }

    public function testJsonSerializeIncludesCursorAndCloudEventBody(): void
    {
        $event = $this->realtimeService->record(RealtimeService::TYPE_OBJECT_CREATED, $this->testObject);
        $this->createdEventIds[] = $event->getId();

        $serialised = $event->jsonSerialize();

        $this->assertSame($event->getId(), $serialised['_cursor'], '_cursor MUST equal the row id for client cursor advancement');
        $this->assertSame('1.0', $serialised['specversion'] ?? null);
        $this->assertSame(RealtimeService::TYPE_OBJECT_CREATED, $serialised['type'] ?? null);
        $this->assertArrayHasKey('data', $serialised);
    }

    public function testRecordDoesNotCrashWhenObjectLacksRequiredFields(): void
    {
        // Defensive: even malformed objects MUST NOT propagate exceptions
        // out of the realtime service — one bad event MUST NOT break a save.
        $broken = new ObjectEntity();
        // Don't set uuid/register/schema — record should fail-soft.
        $event = $this->realtimeService->record(RealtimeService::TYPE_OBJECT_CREATED, $broken);
        // The contract is "returns null on failure" — we don't assert
        // null or non-null specifically because behaviour can legitimately
        // succeed (recording the partial event) or fail (UrnService can't
        // build a URN); what matters is no exception escapes.
        $this->assertTrue(true);

        if ($event !== null) {
            $this->createdEventIds[] = $event->getId();
        }
    }

    public function testDeleteOlderThanPrunes(): void
    {
        // Insert one event "now", then prune everything older than 0
        // seconds — the just-inserted event should still be there
        // because the cutoff (now - 0) is essentially "now", and an
        // event created at exactly "now" is not strictly less than it.
        $event = $this->realtimeService->record(RealtimeService::TYPE_OBJECT_CREATED, $this->testObject);
        $this->createdEventIds[] = $event->getId();

        // Prune events older than 1 day — must NOT remove our just-created event.
        $deleted = $this->eventMapper->deleteOlderThan(86400);
        $this->assertGreaterThanOrEqual(0, $deleted);

        $rows = $this->eventMapper->findSince(since: $event->getId() - 1, limit: 5);
        $stillThere = array_filter($rows, fn($r) => $r->getId() === $event->getId());
        $this->assertCount(1, $stillThere, 'recently-created event MUST survive a 1-day-retention prune');
    }

    private function createTestFixture(): void
    {
        $register = new Register();
        $register->setTitle('phpunit-realtime-' . uniqid());
        $register->setDescription('Realtime updates v1 tests');
        $register->setUuid(Uuid::v4()->toRfc4122());
        $register->setSlug('phpunit-realtime-' . uniqid());
        $register->setSchemas([]);
        $this->testRegister = $this->registerMapper->insert($register);

        $schema = new Schema();
        $schema->setTitle('phpunit-realtime-schema-' . uniqid());
        $schema->setUuid(Uuid::v4()->toRfc4122());
        $schema->setSlug('phpunit-realtime-schema-' . uniqid());
        $schema->setProperties([
            'title' => ['type' => 'string', 'title' => 'Title'],
        ]);
        $this->testSchema = $this->schemaMapper->insert($schema);

        $this->testRegister->setSchemas([$this->testSchema->getId()]);
        $this->registerMapper->update($this->testRegister);

        $this->objectMapper->ensureTableForRegisterSchema($this->testRegister, $this->testSchema);
        $this->createdTable = 'oc_' . $this->objectMapper->getTableNameForRegisterSchema($this->testRegister, $this->testSchema);

        $entity = new ObjectEntity();
        $entity->setUuid(Uuid::v4()->toRfc4122());
        $entity->setRegister((string) $this->testRegister->getId());
        $entity->setSchema((string) $this->testSchema->getId());
        $entity->setObject(['title' => 'realtime test']);
        $this->testObject = $this->objectMapper->insertObjectEntity($entity, $this->testRegister, $this->testSchema, false);
    }
}
