<?php

/**
 * SaveObjects bulk side-effects tests — batched audit trail + real update diffs.
 *
 * Covers the repaired bulk-save classification path (raw magic-table rows
 * carrying `object_status`) and its side effects:
 *  - raw rows are classified created/updated/unchanged (the old gate looked
 *    for `created`/`updated` keys raw rows never have and silently dropped
 *    everything),
 *  - audit rows for the whole chunk are written through ONE batched
 *    AuditTrailMapper::insertAuditTrails() call instead of per-object
 *    createAuditTrail() inserts,
 *  - update audit entries and ObjectUpdatedEvent carry the REAL pre-update
 *    entity (reconstructed from the mapper's `_pre_update_row`), not the
 *    post-update entity twice.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\SaveObjects;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class SaveObjectsBulkSideEffectsTest extends TestCase
{

    private SaveObjects $handler;

    private MagicMapper&MockObject $objectMapper;

    private SchemaMapper&MockObject $schemaMapper;

    private RegisterMapper&MockObject $registerMapper;

    private IUserSession&MockObject $userSession;

    private OrganisationService&MockObject $organisationService;

    private IEventDispatcher&MockObject $eventDispatcher;

    private AuditTrailMapper&MockObject $auditTrailMapper;

    /**
     * Events captured from the dispatcher mock.
     *
     * @var array<int, object>
     */
    private array $dispatchedEvents = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectMapper        = $this->createMock(MagicMapper::class);
        $this->schemaMapper        = $this->createMock(SchemaMapper::class);
        $this->registerMapper      = $this->createMock(RegisterMapper::class);
        $this->userSession         = $this->createMock(IUserSession::class);
        $this->organisationService = $this->createMock(OrganisationService::class);
        $this->eventDispatcher     = $this->createMock(IEventDispatcher::class);
        $this->auditTrailMapper    = $this->createMock(AuditTrailMapper::class);

        $this->dispatchedEvents = [];
        $this->eventDispatcher->method('dispatchTyped')
            ->willReturnCallback(
                function (object $event): void {
                    $this->dispatchedEvents[] = $event;
                }
            );

        $this->handler = new SaveObjects(
            $this->objectMapper,
            $this->schemaMapper,
            $this->registerMapper,
            $this->createMock(SaveObject::class),
            $this->userSession,
            $this->organisationService,
            $this->createMock(LoggerInterface::class),
            null,
            null,
            null,
            $this->eventDispatcher,
            $this->auditTrailMapper
        );

        // Clear static caches between tests.
        $ref = new ReflectionClass(SaveObjects::class);
        foreach (['schemaCache', 'schemaAnalysisCache', 'registerCache'] as $prop) {
            $property = $ref->getProperty($prop);
            $property->setAccessible(true);
            $property->setValue(null, []);
        }
    }//end setUp()

    /**
     * Build a Register with a reflected id.
     */
    private function createRegister(int $id): Register
    {
        $register = new Register();
        $ref      = new ReflectionClass($register);
        $prop     = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($register, $id);
        $register->setTitle('Test Register');
        return $register;
    }//end createRegister()

    /**
     * Build a Schema with a reflected id.
     */
    private function createSchema(int $id): Schema
    {
        $schema = new Schema();
        $ref    = new ReflectionClass($schema);
        $prop   = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($schema, $id);
        $schema->setSlug('test-schema');
        $schema->setTitle('Test Schema');
        $schema->setProperties([]);
        $schema->setConfiguration([]);
        return $schema;
    }//end createSchema()

    /**
     * A raw magic-table result row as the bulk mapper actually returns it:
     * underscore-prefixed metadata columns plus `object_status`.
     *
     * @param string     $uuid   Row uuid.
     * @param string     $status object_status value.
     * @param array|null $preRow Optional pre-update row (updates only).
     */
    private function rawRow(string $uuid, string $status, ?array $preRow=null): array
    {
        $row = [
            'id'            => 42,
            '_uuid'         => $uuid,
            '_register'     => 1,
            '_schema'       => 1,
            '_name'         => 'Row '.$uuid,
            '_created'      => '2026-01-01 00:00:00',
            '_updated'      => '2026-07-14 00:00:00',
            'title'         => 'current title',
            'object_status' => $status,
        ];

        if ($preRow !== null) {
            $row['_pre_update_row'] = $preRow;
        }

        return $row;
    }//end rawRow()

    /**
     * Wire convertRowToObjectEntity to build an entity from the raw row.
     */
    private function mockRowConversion(): void
    {
        $this->objectMapper->method('convertRowToObjectEntity')
            ->willReturnCallback(
                function (array $row, Register $_register, Schema $_schema): ObjectEntity {
                    $entity = new ObjectEntity();
                    $entity->setUuid($row['_uuid']);
                    $entity->setRegister((string) $_register->getId());
                    $entity->setSchema((string) $_schema->getId());
                    $entity->setName($row['_name'] ?? null);
                    $entity->setObject(['title' => ($row['title'] ?? null)]);
                    return $entity;
                }
            );
    }//end mockRowConversion()

    /**
     * Created objects produce ONE batched insertAuditTrails() call with
     * `create` entries (old=null), and per-object ObjectCreatedEvent when
     * events are enabled. The legacy per-object createAuditTrail() must not
     * be used by the bulk path.
     */
    public function testBulkCreateWritesBatchedAuditAndDispatchesEvents(): void
    {
        $register = $this->createRegister(1);
        $schema   = $this->createSchema(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);

        $this->objectMapper->method('ultraFastBulkSave')->willReturn(
            [
                $this->rawRow('uuid-created-1', 'created'),
                $this->rawRow('uuid-created-2', 'created'),
            ]
        );
        $this->mockRowConversion();

        $capturedEntries = null;
        $this->auditTrailMapper->expects($this->once())
            ->method('insertAuditTrails')
            ->willReturnCallback(
                function (array $entries) use (&$capturedEntries): array {
                    $capturedEntries = $entries;
                    return [];
                }
            );
        $this->auditTrailMapper->expects($this->never())->method('createAuditTrail');

        $result = $this->handler->saveObjects(
            objects: [['name' => 'A'], ['name' => 'B']],
            register: $register,
            schema: $schema,
            _events: true
        );

        // Classification repaired: raw rows land in the saved bucket.
        $this->assertSame(2, $result['statistics']['saved']);
        $this->assertCount(2, $result['saved']);

        // One batched audit call with two create entries.
        $this->assertIsArray($capturedEntries);
        $this->assertCount(2, $capturedEntries);
        foreach ($capturedEntries as $entry) {
            $this->assertSame('create', $entry['action']);
            $this->assertNull($entry['old']);
            $this->assertInstanceOf(ObjectEntity::class, $entry['new']);
        }

        // Per-object created events.
        $createdEvents = array_filter(
            $this->dispatchedEvents,
            static fn (object $e): bool => $e instanceof ObjectCreatedEvent
        );
        $this->assertCount(2, $createdEvents);
    }//end testBulkCreateWritesBatchedAuditAndDispatchesEvents()

    /**
     * Updated objects carry the REAL pre-update entity: the audit entry's
     * `old` and the ObjectUpdatedEvent's old object are reconstructed from
     * the mapper's `_pre_update_row`, not the new entity passed twice.
     */
    public function testBulkUpdateCarriesRealPreUpdateState(): void
    {
        $register = $this->createRegister(1);
        $schema   = $this->createSchema(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);

        $preRow = [
            'id'        => 42,
            '_uuid'     => 'uuid-updated-1',
            '_register' => 1,
            '_schema'   => 1,
            '_name'     => 'old name',
            'title'     => 'OLD title',
        ];

        $this->objectMapper->method('ultraFastBulkSave')->willReturn(
            [$this->rawRow('uuid-updated-1', 'updated', $preRow)]
        );
        $this->mockRowConversion();

        $capturedEntries = null;
        $this->auditTrailMapper->expects($this->once())
            ->method('insertAuditTrails')
            ->willReturnCallback(
                function (array $entries) use (&$capturedEntries): array {
                    $capturedEntries = $entries;
                    return [];
                }
            );

        $result = $this->handler->saveObjects(
            objects: [['name' => 'A']],
            register: $register,
            schema: $schema,
            _events: true
        );

        $this->assertSame(1, $result['statistics']['updated']);

        // Audit entry: action update, old is a DIFFERENT entity carrying the
        // pre-update state.
        $this->assertIsArray($capturedEntries);
        $this->assertCount(1, $capturedEntries);
        $entry = $capturedEntries[0];
        $this->assertSame('update', $entry['action']);
        $this->assertInstanceOf(ObjectEntity::class, $entry['old']);
        $this->assertInstanceOf(ObjectEntity::class, $entry['new']);
        $this->assertNotSame($entry['new'], $entry['old']);
        $this->assertSame('OLD title', ($entry['old']->getObject()['title'] ?? null));
        $this->assertSame('current title', ($entry['new']->getObject()['title'] ?? null));

        // Event: old object is the pre-update entity.
        $updatedEvents = array_values(
            array_filter(
                $this->dispatchedEvents,
                static fn (object $e): bool => $e instanceof ObjectUpdatedEvent
            )
        );
        $this->assertCount(1, $updatedEvents);
        $this->assertSame('OLD title', ($updatedEvents[0]->getOldObject()->getObject()['title'] ?? null));
        $this->assertSame('current title', ($updatedEvents[0]->getNewObject()->getObject()['title'] ?? null));
    }//end testBulkUpdateCarriesRealPreUpdateState()

    /**
     * Unchanged objects produce neither audit rows nor events, and events
     * stay suppressed when $_events is false (audits are still written).
     */
    public function testUnchangedRowsAndEventSuppression(): void
    {
        $register = $this->createRegister(1);
        $schema   = $this->createSchema(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);

        $this->objectMapper->method('ultraFastBulkSave')->willReturn(
            [
                $this->rawRow('uuid-created-1', 'created'),
                $this->rawRow('uuid-unchanged-1', 'unchanged'),
            ]
        );
        $this->mockRowConversion();

        $capturedEntries = null;
        $this->auditTrailMapper->expects($this->once())
            ->method('insertAuditTrails')
            ->willReturnCallback(
                function (array $entries) use (&$capturedEntries): array {
                    $capturedEntries = $entries;
                    return [];
                }
            );

        // Events disabled (the BulkController default).
        $result = $this->handler->saveObjects(
            objects: [['name' => 'A'], ['name' => 'B']],
            register: $register,
            schema: $schema,
            _events: false
        );

        $this->assertSame(1, $result['statistics']['saved']);
        $this->assertSame(1, $result['statistics']['unchanged']);

        // Only the created object gets an audit entry; no events at all.
        $this->assertIsArray($capturedEntries);
        $this->assertCount(1, $capturedEntries);
        $this->assertSame('create', $capturedEntries[0]['action']);
        $this->assertSame([], $this->dispatchedEvents);
    }//end testUnchangedRowsAndEventSuppression()

    /**
     * The internal `_pre_update_row` bookkeeping never leaks into the API
     * response buckets.
     */
    public function testPreUpdateRowDoesNotLeakIntoResponse(): void
    {
        $register = $this->createRegister(1);
        $schema   = $this->createSchema(1);

        $this->userSession->method('getUser')->willReturn(null);
        $this->organisationService->method('getOrganisationForNewEntity')->willReturn(null);

        $preRow = [
            '_uuid'     => 'uuid-updated-1',
            '_register' => 1,
            '_schema'   => 1,
            'title'     => 'OLD title',
        ];

        $this->objectMapper->method('ultraFastBulkSave')->willReturn(
            [$this->rawRow('uuid-updated-1', 'updated', $preRow)]
        );
        $this->mockRowConversion();

        $result = $this->handler->saveObjects(
            objects: [['name' => 'A']],
            register: $register,
            schema: $schema
        );

        $this->assertCount(1, $result['updated']);
        $this->assertArrayNotHasKey('_pre_update_row', $result['updated'][0]);
        $this->assertArrayNotHasKey('object_status', $result['updated'][0]);
    }//end testPreUpdateRowDoesNotLeakIntoResponse()
}//end class
