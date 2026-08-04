<?php

/**
 * SourceRecordChangeListener unit tests — reverse-FK source-change recompute.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/mdm-reverse-fk-source-resolution/tasks.md#6.1
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Listener\SourceRecordChangeListener;
use OCA\OpenRegister\Service\ObjectService;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SourceRecordChangeListenerTest extends TestCase
{

    private SchemaMapper&MockObject $schemaMapper;

    private ObjectService&MockObject $objectService;

    private LoggerInterface&MockObject $logger;

    private ICacheFactory&MockObject $cacheFactory;

    private SourceRecordChangeListener $listener;

    protected function setUp(): void
    {
        $this->schemaMapper  = $this->createMock(SchemaMapper::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->cacheFactory  = $this->createMock(ICacheFactory::class);
        // No distributed cache in unit tests — the listener falls back to
        // its per-instance memoisation when cache creation fails.
        $this->cacheFactory->method('createDistributed')
            ->willThrowException(new \RuntimeException('no cache in tests'));
        $this->listener = new SourceRecordChangeListener(
            $this->schemaMapper,
            $this->objectService,
            $this->logger,
            $this->cacheFactory
        );
    }//end setUp()

    /**
     * A master schema declaring a reverse-FK sourceLink onto `sourceRecord`.
     * Uses a real Schema entity — `Schema` exposes magic getters that cannot
     * be configured on a PHPUnit mock.
     *
     * @return Schema
     */
    private function masterSchema(): Schema
    {
        $schema = new Schema();
        $schema->setConfiguration([
            'x-openregister-survivorship' => [
                'sourceLink' => [
                    'mode'           => 'reverseFk',
                    'sourceSchema'   => 'sourceRecord',
                    'referenceField' => 'currentMasterEntity',
                ],
            ],
        ]);
        return $schema;
    }//end masterSchema()

    /**
     * A real Schema entity with the given slug.
     *
     * @param string $slug Schema slug.
     *
     * @return Schema
     */
    private function schemaWith(string $slug): Schema
    {
        $schema = new Schema();
        $schema->setSlug($slug);
        $schema->setConfiguration([]);
        return $schema;
    }//end schemaWith()

    /**
     * Build a source ObjectEntity.
     *
     * @param string               $schemaRef Schema reference to set.
     * @param array<string, mixed> $payload   Object payload.
     *
     * @return ObjectEntity
     */
    private function source(string $schemaRef, array $payload): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setSchema($schemaRef);
        $object->setObject($payload);
        return $object;
    }//end source()

    public function testSourceSaveRecomputesReferencedMaster(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([$this->masterSchema()]);
        $this->schemaMapper->method('find')->willReturn($this->schemaWith('sourceRecord'));

        $master = new ObjectEntity();
        $master->setObject(['goldenRecord' => []]);

        // The referenced master is loaded and re-persisted (triggering recompute).
        $this->objectService->expects($this->once())
            ->method('find')
            ->with($this->equalTo('master-1'))
            ->willReturn($master);
        $this->objectService->expects($this->once())->method('saveObject');

        $source = $this->source('106', ['currentMasterEntity' => 'master-1', 'mappedAttributes' => ['name' => 'Acme']]);
        $this->listener->handle(new ObjectCreatedEvent($source));
    }//end testSourceSaveRecomputesReferencedMaster()

    public function testNonSourceObjectIsIgnored(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([$this->masterSchema()]);
        // The saved object's schema is not the reverse-FK source schema.
        $this->schemaMapper->method('find')->willReturn($this->schemaWith('contact'));

        $this->objectService->expects($this->never())->method('find');
        $this->objectService->expects($this->never())->method('saveObject');

        $object = $this->source('999', ['currentMasterEntity' => 'master-1']);
        $this->listener->handle(new ObjectCreatedEvent($object));
    }//end testNonSourceObjectIsIgnored()

    public function testRecomputeFailureIsSwallowed(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([$this->masterSchema()]);
        $this->schemaMapper->method('find')->willReturn($this->schemaWith('sourceRecord'));

        // The master lookup throws — must be logged, never re-thrown.
        $this->objectService->method('find')->willThrowException(new \RuntimeException('boom'));
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $source = $this->source('106', ['currentMasterEntity' => 'master-1']);
        // No exception escapes.
        $this->listener->handle(new ObjectCreatedEvent($source));
        $this->addToAssertionCount(1);
    }//end testRecomputeFailureIsSwallowed()
}//end class
