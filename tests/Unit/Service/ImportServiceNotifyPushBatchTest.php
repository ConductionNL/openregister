<?php

declare(strict_types=1);

/**
 * ImportService NotifyPush Batch Flush Tests
 *
 * Proves the import → notify_push batch contract end to end: during a bulk
 * import per-object pushes are suppressed (batch mode), and on completion —
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
 * Tests that bulk imports flush deduplicated notify_push collection events.
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
     * Real listener instance used to simulate lifecycle events during the bulk save.
     *
     * @var NotifyPushListener
     */
    private NotifyPushListener $listener;

    /**
     * ObjectService mock whose saveObjects() simulates event dispatch.
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

        // Slug resolution for the listener.
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
     * Build a Register entity with an id set via reflection.
     *
     * @param int $id Database id.
     *
     * @return Register
     */
    private function createRegister(int $id): Register
    {
        $register = new Register();
        $register->setTitle('TestRegister');
        $ref  = new ReflectionClass($register);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($register, $id);
        return $register;
    }//end createRegister()

    /**
     * Build a Schema entity with an id set via reflection.
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
     * Simulate the bulk save dispatching N ObjectCreatedEvents through the listener.
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
     * Successful import: N object saves in batch mode emit ZERO per-object pushes
     * and exactly ONE deduplicated broadcast collection event on completion.
     *
     * @return void
     *
     * @spec openspec/specs/realtime-updates/spec.md
     */
    public function testImportFlushesDeduplicatedCollectionEventAndNoObjectEvents(): void
    {
        $tmpFile  = $this->createTempCsv();
        $register = $this->createRegister(1);
        $schema   = $this->createSchema(
                2,
                [
                    'name'  => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                ]
                );

        $pushesDuringSave = null;
        $this->objectService->method('saveObjects')
            ->willReturnCallback(
                    function () use (&$pushesDuringSave): array {
                        // Simulate the 3 lifecycle events the bulk save dispatches.
                        $this->simulateObjectSaves(3);
                        // Capture push count DURING the save — must stay zero (batch mode).
                        $pushesDuringSave = count($this->queue->pushes);
                        return [
                            'saved'     => [
                                ['@self' => ['id' => 'imported-uuid-0'], 'name' => 'John'],
                                ['@self' => ['id' => 'imported-uuid-1'], 'name' => 'Jane'],
                                ['@self' => ['id' => 'imported-uuid-2'], 'name' => 'Jim'],
                            ],
                            'updated'   => [],
                            'unchanged' => [],
                        ];
                    }
                    );

        try {
            $result = $this->service->importFromCsv($tmpFile, $register, $schema);
        } finally {
            @unlink($tmpFile);
        }

        $this->assertIsArray($result);
        $this->assertSame(0, $pushesDuringSave, 'Batch mode must suppress all pushes during the bulk save');

        // Exactly ONE push total: the deduplicated collection broadcast.
        $this->assertCount(1, $this->queue->pushes, 'Import completion must flush exactly one collection event');

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

    }//end testImportFlushesDeduplicatedCollectionEventAndNoObjectEvents()

    /**
     * Failure path: when the bulk save throws AFTER partial saves, the accumulated
     * collection events are still flushed (finally) and batch mode is cleared.
     *
     * @return void
     *
     * @spec openspec/specs/realtime-updates/spec.md
     */
    public function testImportFailurePathStillFlushesAccumulatedCollectionEvents(): void
    {
        $tmpFile  = $this->createTempCsv();
        $register = $this->createRegister(1);
        $schema   = $this->createSchema(
                2,
                [
                    'name'  => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                ]
                );

        $this->objectService->method('saveObjects')
            ->willReturnCallback(
                    function (): array {
                        // Two objects were saved (events fired) before the failure.
                        $this->simulateObjectSaves(2);
                        throw new \RuntimeException('bulk save exploded mid-import');
                    }
                    );

        try {
            $this->expectException(\RuntimeException::class);
            $this->service->importFromCsv($tmpFile, $register, $schema);
        } finally {
            @unlink($tmpFile);

            // The finally-path flush must have emitted the collection broadcast.
            $this->assertCount(1, $this->queue->pushes, 'Failure path must still flush accumulated collection events');
            $this->assertSame('or-collection-test-register-test-schema', $this->queue->pushes[0][1]['message']);

            // Accumulator cleared and batch mode disabled.
            $this->assertFalse(NotifyPushListener::hasBatchedCollections());
        }

    }//end testImportFailurePathStillFlushesAccumulatedCollectionEvents()
}//end class
