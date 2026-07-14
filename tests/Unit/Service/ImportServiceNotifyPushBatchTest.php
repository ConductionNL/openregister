<?php

declare(strict_types=1);

/**
 * ImportService NotifyPush Batch Flush Tests
 *
 * Proves the import → notify_push batch contract end to end THROUGH THE REAL
 * DEFAULT PATH: bulk saves run with lifecycle events disabled (`events=false`
 * everywhere in the import call chain), so the collection hint MUST be derived
 * from the save result — not from listener event accumulation. On completion —
 * including the failure path — the accumulated collection events are flushed
 * exactly once per (register, schema) pair as untargeted broadcasts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/realtime-updates/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Listener\NotifyPushListener;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Translation\TranslationCsvCodec;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Tests that bulk imports flush deduplicated notify_push collection events
 * on the default import path (lifecycle events disabled).
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\ImportService
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) End-to-end wiring test requires the real listener plus import mocks
 */
class ImportServiceNotifyPushBatchTest extends TestCase
{

    /**
     * Recording queue spy standing in for OCA\NotifyPush\Queue\IQueue.
     *
     * @var object
     */
    private object $queue;

    /**
     * Real listener instance used to verify per-object pushes resume after the
     * import and to simulate the events-enabled import variant.
     *
     * @var NotifyPushListener
     */
    private NotifyPushListener $listener;

    /**
     * ObjectService mock whose saveObjects() is stubbed per test.
     *
     * @var ObjectService&\PHPUnit\Framework\MockObject\MockObject
     */
    private ObjectService $objectService;

    /**
     * Service under test.
     *
     * @var ImportService
     */
    private ImportService $service;

    /**
     * Set up the queue spy, a real NotifyPushListener, and the ImportService.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        NotifyPushListener::resetStaticState();

        // Recording queue spy — captures every push() call.
        $this->queue = new class {

            /**
             * Recorded push calls as [type, payload] tuples.
             *
             * @var array<int, array{0: string, 1: array}>
             */
            public array $pushes = [];

            /**
             * Record a push call.
             *
             * @param string $type    Event type (notify_custom).
             * @param array  $payload Push payload.
             *
             * @return void
             */
            public function push(string $type, array $payload): void
            {
                $this->pushes[] = [$type, $payload];
            }
        };

        // Container used by BOTH the listener and the ImportService flush helper.
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->with('OCA\NotifyPush\Queue\IQueue')
            ->willReturn($this->queue);

        // Slug resolution for the listener (events-enabled variant + post-import checks).
        $register = $this->getMockBuilder(Register::class)->addMethods(['getSlug'])->getMock();
        $register->method('getSlug')->willReturn('test-register');
        $registerMapper = $this->createMock(RegisterMapper::class);
        $registerMapper->method('find')->willReturn($register);

        $schemaEntity = $this->getMockBuilder(Schema::class)->addMethods(['getSlug'])->getMock();
        $schemaEntity->method('getSlug')->willReturn('test-schema');
        $listenerSchemaMapper = $this->createMock(SchemaMapper::class);
        $listenerSchemaMapper->method('find')->willReturn($schemaEntity);

        $permissionHandler = $this->createMock(PermissionHandler::class);
        $permissionHandler->method('getReadableByUsers')->willReturn(['user1', 'user2']);

        $this->listener = new NotifyPushListener(
            appManager: $this->createMock(IAppManager::class),
            logger: $this->createMock(LoggerInterface::class),
            container: $container,
            permissionHandler: $permissionHandler,
            appConfig: $this->createMock(IAppConfig::class),
            registerMapper: $registerMapper,
            schemaMapper: $listenerSchemaMapper,
        );

        $this->objectService = $this->createMock(ObjectService::class);

        $translationCsvCodec = $this->createMock(TranslationCsvCodec::class);
        $translationCsvCodec->method('unflattenFromCsv')
            ->willReturnCallback(static fn(array $row) => $row);

        $this->service = new ImportService(
            $this->createMock(SchemaMapper::class),
            $this->objectService,
            $this->createMock(LoggerInterface::class),
            $this->createMock(IGroupManager::class),
            $translationCsvCodec,
            $this->createMock(AuditTrailMapper::class),
            $container
        );

    }//end setUp()

    /**
     * Reset listener static state after each test.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        NotifyPushListener::resetStaticState();
        parent::tearDown();
    }//end tearDown()

    /**
     * Build a Register entity with an id and slug set — the collection hint is
     * derived from the real entity's slug, exactly like a production import.
     *
     * @param int $id Database id.
     *
     * @return Register
     */
    private function createRegister(int $id): Register
    {
        $register = new Register();
        $register->setTitle('TestRegister');
        $register->setSlug('test-register');
        $ref  = new ReflectionClass($register);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($register, $id);
        return $register;
    }//end createRegister()

    /**
     * Build a Schema entity with an id and slug set via the real entity API.
     *
     * @param int   $id         Database id.
     * @param array $properties Schema properties.
     *
     * @return Schema
     */
    private function createSchema(int $id, array $properties=[]): Schema
    {
        $schema = new Schema();
        $schema->setTitle('TestSchema');
        $schema->setSlug('test-schema');
        $schema->setProperties($properties);
        $ref  = new ReflectionClass($schema);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($schema, $id);
        return $schema;
    }//end createSchema()

    /**
     * Default name/email schema used by every test.
     *
     * @return Schema
     */
    private function createDefaultSchema(): Schema
    {
        return $this->createSchema(
                2,
                [
                    'name'  => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                ]
                );
    }//end createDefaultSchema()

    /**
     * Simulate the bulk save dispatching N ObjectCreatedEvents through the listener.
     *
     * Used ONLY by the events-enabled variant — the default import path never
     * dispatches lifecycle events.
     *
     * @param int $count Number of object saves to simulate.
     *
     * @return void
     */
    private function simulateObjectSaves(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $object = new ObjectEntity();
            $object->setUuid('imported-uuid-'.$i);
            $object->setRegister('reg-uuid');
            $object->setSchema('schema-uuid');
            $object->setVersion('1');
            $this->listener->handle(new ObjectCreatedEvent($object));
        }
    }//end simulateObjectSaves()

    /**
     * Write a 3-row CSV to a temp file.
     *
     * @return string Path to the temp file.
     */
    private function createTempCsv(): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_import_push_');
        file_put_contents($tmpFile, "name,email\nJohn,john@test.nl\nJane,jane@test.nl\nJim,jim@test.nl\n");
        return $tmpFile;
    }//end createTempCsv()

    /**
     * A canned successful saveObjects result with three created objects.
     *
     * @return array
     */
    private function savedResult(): array
    {
        return [
            'saved'     => [
                ['@self' => ['id' => 'imported-uuid-0'], 'name' => 'John'],
                ['@self' => ['id' => 'imported-uuid-1'], 'name' => 'Jane'],
                ['@self' => ['id' => 'imported-uuid-2'], 'name' => 'Jim'],
            ],
            'updated'   => [],
            'unchanged' => [],
        ];
    }//end savedResult()

    /**
     * REAL DEFAULT PATH: importFromCsv with default flags (events=false, so no
     * lifecycle events fire) still flushes exactly one broadcast collection
     * event, derived from the save result — and zero per-object events.
     *
     * @return void
     *
     * @spec openspec/specs/realtime-updates/spec.md
     */
    public function testDefaultImportFlushesCollectionEventDerivedFromSaveResult(): void
    {
        $tmpFile  = $this->createTempCsv();
        $register = $this->createRegister(1);
        $schema   = $this->createDefaultSchema();

        $pushesDuringSave = null;
        $this->objectService->method('saveObjects')
            ->willReturnCallback(
                    function () use (&$pushesDuringSave): array {
                        // Default path: NO lifecycle events are dispatched here.
                        // Capture push count DURING the save — must stay zero.
                        $pushesDuringSave = count($this->queue->pushes);
                        return $this->savedResult();
                    }
                    );

        try {
            // Default flags — exactly what RegistersController/the UI import sends.
            $result = $this->service->importFromCsv($tmpFile, $register, $schema);
        } finally {
            @unlink($tmpFile);
        }

        $this->assertIsArray($result);
        $this->assertSame(0, $pushesDuringSave, 'No pushes may be emitted during the bulk save');

        // Exactly ONE push total: the deduplicated collection broadcast.
        $this->assertCount(1, $this->queue->pushes, 'Default import must flush exactly one collection event');

        [$type, $payload] = $this->queue->pushes[0];
        $this->assertSame('notify_custom', $type);
        $this->assertSame('or-collection-test-register-test-schema', $payload['message']);
        $this->assertArrayNotHasKey('user', $payload, 'Batch flush is a broadcast without per-user targeting');
        $this->assertSame(
                [
                    'action'   => 'batch',
                    'register' => 'test-register',
                    'schema'   => 'test-schema',
                ],
                $payload['body'],
                'Batch payload carries slugs + action only (no object data)'
                );

        // No or-object-* event was ever emitted.
        foreach ($this->queue->pushes as $push) {
            $this->assertStringStartsWith('or-collection-', $push[1]['message']);
        }

        // Batch mode is off again: a subsequent single save pushes per-object events.
        $object = new ObjectEntity();
        $object->setUuid('post-import-uuid');
        $object->setRegister('reg-uuid');
        $object->setSchema('schema-uuid');
        $object->setVersion('1');
        $this->listener->handle(new ObjectCreatedEvent($object));

        $postImportMessages = array_map(
                static fn(array $push) => $push[1]['message'],
                array_slice($this->queue->pushes, 1)
                );
        $this->assertContains('or-object-post-import-uuid', $postImportMessages, 'Per-object pushes must resume after import');

    }//end testDefaultImportFlushesCollectionEventDerivedFromSaveResult()

    /**
     * Failure path on the default flags: when the bulk save throws, partial
     * saves may have landed — the collection event is still flushed (finally)
     * and batch mode is cleared.
     *
     * @return void
     *
     * @spec openspec/specs/realtime-updates/spec.md
     */
    public function testImportFailurePathStillFlushesCollectionEvent(): void
    {
        $tmpFile  = $this->createTempCsv();
        $register = $this->createRegister(1);
        $schema   = $this->createDefaultSchema();

        $this->objectService->method('saveObjects')
            ->willThrowException(new \RuntimeException('bulk save exploded mid-import'));

        try {
            $this->expectException(\RuntimeException::class);
            $this->service->importFromCsv($tmpFile, $register, $schema);
        } finally {
            @unlink($tmpFile);

            // The finally-path flush must have emitted the collection broadcast.
            $this->assertCount(1, $this->queue->pushes, 'Failure path must still flush the collection event');
            $this->assertSame('or-collection-test-register-test-schema', $this->queue->pushes[0][1]['message']);

            // Accumulator cleared and batch mode disabled.
            $this->assertFalse(NotifyPushListener::hasBatchedCollections());
        }

    }//end testImportFailurePathStillFlushesCollectionEvent()

    /**
     * An import where every row is unchanged (smart dedup skipped all writes)
     * must NOT emit a collection event — nothing changed, no refetch needed.
     *
     * @return void
     *
     * @spec openspec/specs/realtime-updates/spec.md
     */
    public function testAllUnchangedImportEmitsNoCollectionEvent(): void
    {
        $tmpFile  = $this->createTempCsv();
        $register = $this->createRegister(1);
        $schema   = $this->createDefaultSchema();

        $this->objectService->method('saveObjects')
            ->willReturn(
                    [
                        'saved'     => [],
                        'updated'   => [],
                        'unchanged' => [
                            ['@self' => ['id' => 'imported-uuid-0'], 'name' => 'John'],
                            ['@self' => ['id' => 'imported-uuid-1'], 'name' => 'Jane'],
                            ['@self' => ['id' => 'imported-uuid-2'], 'name' => 'Jim'],
                        ],
                    ]
                    );

        try {
            $this->service->importFromCsv($tmpFile, $register, $schema);
        } finally {
            @unlink($tmpFile);
        }

        $this->assertCount(0, $this->queue->pushes, 'All-unchanged import must not emit any push');

    }//end testAllUnchangedImportEmitsNoCollectionEvent()

    /**
     * Events-enabled variant: when lifecycle events DO fire during the bulk
     * save, listener accumulation and the result-derived hint land on the same
     * accumulator key — still exactly one collection event, no double emit.
     *
     * @return void
     *
     * @spec openspec/specs/realtime-updates/spec.md
     */
    public function testEventsEnabledImportDoesNotDoubleEmit(): void
    {
        $tmpFile  = $this->createTempCsv();
        $register = $this->createRegister(1);
        $schema   = $this->createDefaultSchema();

        $this->objectService->method('saveObjects')
            ->willReturnCallback(
                    function (): array {
                        // Simulate the lifecycle events an events=true bulk save dispatches.
                        $this->simulateObjectSaves(3);
                        return $this->savedResult();
                    }
                    );

        try {
            $this->service->importFromCsv(
                    $tmpFile,
                    $register,
                    $schema,
                    false,
                    // events=true.
                    true
                    );
        } finally {
            @unlink($tmpFile);
        }

        // Listener accumulation + result-derived hint deduplicate onto one key.
        $this->assertCount(1, $this->queue->pushes, 'Event accumulation and result hint must not double-emit');
        $this->assertSame('or-collection-test-register-test-schema', $this->queue->pushes[0][1]['message']);

    }//end testEventsEnabledImportDoesNotDoubleEmit()
}//end class
