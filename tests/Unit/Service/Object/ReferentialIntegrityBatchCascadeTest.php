<?php

declare(strict_types=1);

/**
 * ReferentialIntegrityService batched CASCADE delete unit tests.
 *
 * Covers the integrity-analysis cascade batching (issue #409):
 * - CASCADE targets are resolved with ONE batched cross-table lookup and
 *   soft-deleted via MagicMapper::softDeleteMultipleObjectEntities (one
 *   UPDATE per magic table) with ONE multi-row audit INSERT whose rows carry
 *   the canonical cascade-context fold — instead of the per-object
 *   deleteObjects() re-resolution plus a single-row audit INSERT per target;
 * - targets the batch lookup cannot resolve fall back to the unchanged
 *   per-object pipeline (deleteObjects + legacy-shape audit insert);
 * - batched resolve/write failures route every target to the fallback,
 *   fail-soft;
 * - hook-skipped targets are not force-retried per object;
 * - SET_NULL / SET_DEFAULT handling stays untouched.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the batched referential-integrity CASCADE delete path.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class ReferentialIntegrityBatchCascadeTest extends TestCase
{

    /**
     * Subject under test.
     *
     * @var ReferentialIntegrityService
     */
    private ReferentialIntegrityService $service;

    /**
     * Schema mapper mock.
     *
     * @var SchemaMapper&MockObject
     */
    private SchemaMapper $schemaMapper;

    /**
     * Register mapper mock.
     *
     * @var RegisterMapper&MockObject
     */
    private RegisterMapper $registerMapper;

    /**
     * Object entity mapper mock.
     *
     * @var MagicMapper&MockObject
     */
    private MagicMapper $objectMapper;

    /**
     * Audit-trail mapper mock.
     *
     * @var AuditTrailMapper&MockObject
     */
    private AuditTrailMapper $auditTrailMapper;

    /**
     * Logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaMapper     = $this->createMock(SchemaMapper::class);
        $this->registerMapper   = $this->createMock(RegisterMapper::class);
        $this->objectMapper     = $this->createMock(MagicMapper::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->logger           = $this->createMock(LoggerInterface::class);

        $this->service = new ReferentialIntegrityService(
            $this->schemaMapper,
            $this->registerMapper,
            $this->objectMapper,
            $this->auditTrailMapper,
            $this->logger,
            $this->createMock(IDBConnection::class),
            $this->createNullCacheFactory()
        );
    }//end setUp()


    /**
     * Build a cache factory whose cache never reports a hit.
     *
     * @return \OCP\ICacheFactory
     */
    private function createNullCacheFactory(): \OCP\ICacheFactory
    {
        $factory = $this->createMock(\OCP\ICacheFactory::class);
        $factory->method('createDistributed')
            ->willReturn($this->createMock(\OCP\ICache::class));

        return $factory;

    }//end createNullCacheFactory()

    /**
     * Create an ObjectEntity cascade target.
     *
     * @param string $uuid     The object UUID.
     * @param string $register The register id.
     * @param string $schema   The schema id.
     *
     * @return ObjectEntity
     */
    private function createEntity(string $uuid, string $register='5', string $schema='10'): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setRegister($register);
        $entity->setSchema($schema);
        $entity->setObject(['name' => 'target-'.$uuid]);
        return $entity;
    }//end createEntity()

    /**
     * Build a DeletionAnalysis with the given cascade targets.
     *
     * @param array $targets The cascade target arrays.
     *
     * @return DeletionAnalysis
     */
    private function analysisWithCascade(array $targets): DeletionAnalysis
    {
        return new DeletionAnalysis(deletable: true, cascadeTargets: $targets);
    }//end analysisWithCascade()

    /**
     * Build a cascade target array entry.
     *
     * @param string $uuid     The target UUID.
     * @param string $property The referencing property.
     *
     * @return array The target entry.
     */
    private function target(string $uuid, string $property='parentRef'): array
    {
        return [
            'objectUuid' => $uuid,
            'register'   => '5',
            'schema'     => '10',
            'property'   => $property,
        ];
    }//end target()

    // =========================================================================
    // Fully batched path
    // =========================================================================

    /**
     * N targets → ONE batched resolve, ONE batched soft delete, ONE multi-row
     * audit INSERT — and zero per-object deleteObjects()/insert() calls.
     *
     * @return void
     */
    public function testBatchedCascadeResolvesSoftDeletesAndAuditsOnce(): void
    {
        $entities = [
            $this->createEntity('uuid-1'),
            $this->createEntity('uuid-2'),
            $this->createEntity('uuid-3'),
        ];

        // Targets are reversed by applyDeletionActions (deepest first).
        $this->objectMapper->expects($this->once())
            ->method('findMultipleAcrossAllMagicTables')
            ->with(
                $this->equalTo(['uuid-3', 'uuid-2', 'uuid-1']),
                $this->equalTo(false)
            )
            ->willReturn($entities);

        $capturedDeleted = [];
        $this->objectMapper->expects($this->once())
            ->method('softDeleteMultipleObjectEntities')
            ->willReturnCallback(
                function (array $entities, array $oldEntities) use (&$capturedDeleted) {
                    foreach ($entities as $entity) {
                        $capturedDeleted[$entity->getUuid()] = $entity->getDeleted();
                    }

                    $this->assertCount(3, $oldEntities);
                    return $entities;
                }
            );

        $builtRows = [];
        $this->auditTrailMapper->method('buildAuditTrail')
            ->willReturnCallback(
                function (?ObjectEntity $old, ?ObjectEntity $new, ?string $action, ?array $cascadeContext) use (&$builtRows) {
                    $builtRows[] = [
                        'uuid'           => $old?->getUuid(),
                        'new'            => $new,
                        'action'         => $action,
                        'cascadeContext' => $cascadeContext,
                    ];
                    $trail = new AuditTrail();
                    $trail->setUuid('audit-'.count($builtRows));
                    return $trail;
                }
            );

        $insertedRowCount = null;
        $this->auditTrailMapper->expects($this->once())
            ->method('insertAuditTrails')
            ->willReturnCallback(
                function (array $entries) use (&$insertedRowCount) {
                    $insertedRowCount = count($entries);
                    return $entries;
                }
            );

        // The per-object pipeline must not run.
        $this->objectMapper->expects($this->never())->method('deleteObjects');
        $this->auditTrailMapper->expects($this->never())->method('insert');

        $analysis = $this->analysisWithCascade(
            [
                $this->target('uuid-1', 'refA'),
                $this->target('uuid-2', 'refB'),
                $this->target('uuid-3', 'refC'),
            ]
        );

        $this->service->applyDeletionActions($analysis, 'admin', 'root-uuid', 'org-1', 'root-slug');

        // Deletion attribution metadata shape (deletedBy/deletedAt/objectId/organisation).
        $this->assertCount(3, $capturedDeleted);
        foreach (['uuid-1', 'uuid-2', 'uuid-3'] as $uuid) {
            $this->assertSame('admin', $capturedDeleted[$uuid]['deletedBy']);
            $this->assertSame($uuid, $capturedDeleted[$uuid]['objectId']);
            $this->assertSame('org-1', $capturedDeleted[$uuid]['organisation']);
            $this->assertArrayHasKey('deletedAt', $capturedDeleted[$uuid]);
        }

        // One audit row per target, canonical cascade-context fold shape.
        $this->assertSame(3, $insertedRowCount);
        $this->assertCount(3, $builtRows);
        // Rows follow the reversed target order.
        $this->assertSame(['uuid-3', 'uuid-2', 'uuid-1'], array_column($builtRows, 'uuid'));
        foreach ($builtRows as $row) {
            $this->assertNull($row['new']);
            $this->assertSame('referential_integrity.cascade_delete', $row['action']);
            $this->assertSame(
                [
                    'triggerObject' => 'root-uuid',
                    'triggerSchema' => 'root-slug',
                    'action_type'   => 'referential_integrity.cascade_delete',
                    'property'      => ['uuid-3' => 'refC', 'uuid-2' => 'refB', 'uuid-1' => 'refA'][$row['uuid']],
                ],
                $row['cascadeContext']
            );
        }
    }//end testBatchedCascadeResolvesSoftDeletesAndAuditsOnce()

    /**
     * Duplicate targets (same uuid via two properties) resolve/delete once but
     * still produce one audit row per analysis target.
     *
     * @return void
     */
    public function testDuplicateTargetsDeleteOnceButAuditPerTarget(): void
    {
        $entity = $this->createEntity('uuid-1');

        $this->objectMapper->expects($this->once())
            ->method('findMultipleAcrossAllMagicTables')
            ->with($this->equalTo(['uuid-1']), $this->equalTo(false))
            ->willReturn([$entity]);

        $this->objectMapper->expects($this->once())
            ->method('softDeleteMultipleObjectEntities')
            ->willReturnArgument(0);

        $properties = [];
        $this->auditTrailMapper->method('buildAuditTrail')
            ->willReturnCallback(
                function (?ObjectEntity $old, ?ObjectEntity $new, ?string $action, ?array $cascadeContext) use (&$properties) {
                    $properties[] = $cascadeContext['property'];
                    $trail        = new AuditTrail();
                    $trail->setUuid('audit-'.count($properties));
                    return $trail;
                }
            );

        $this->auditTrailMapper->expects($this->once())
            ->method('insertAuditTrails')
            ->with($this->callback(fn(array $entries): bool => count($entries) === 2))
            ->willReturn([]);

        $this->objectMapper->expects($this->never())->method('deleteObjects');

        $analysis = $this->analysisWithCascade(
            [
                $this->target('uuid-1', 'refA'),
                $this->target('uuid-1', 'refB'),
            ]
        );

        $this->service->applyDeletionActions($analysis, 'admin', 'root-uuid');

        // Reversed target order: refB first.
        $this->assertSame(['refB', 'refA'], $properties);
    }//end testDuplicateTargetsDeleteOnceButAuditPerTarget()

    // =========================================================================
    // Fallback paths
    // =========================================================================

    /**
     * A batch-resolve miss routes ONLY that target through the per-object
     * pipeline (deleteObjects + legacy-shape single-row audit insert).
     *
     * @return void
     */
    public function testBatchMissFallsBackPerObjectForThatTargetOnly(): void
    {
        $resolved = $this->createEntity('uuid-1');

        $this->objectMapper->expects($this->once())
            ->method('findMultipleAcrossAllMagicTables')
            ->willReturn([$resolved]);

        $this->objectMapper->expects($this->once())
            ->method('softDeleteMultipleObjectEntities')
            ->willReturnArgument(0);

        $trail = new AuditTrail();
        $trail->setUuid('audit-1');
        $this->auditTrailMapper->method('buildAuditTrail')->willReturn($trail);

        $this->auditTrailMapper->expects($this->once())
            ->method('insertAuditTrails')
            ->with($this->callback(fn(array $entries): bool => count($entries) === 1))
            ->willReturn([]);

        // The miss goes through the legacy pipeline.
        $this->objectMapper->expects($this->once())
            ->method('deleteObjects')
            ->with($this->equalTo(['uuid-missing']), $this->equalTo(false))
            ->willReturn([]);

        // Legacy single-row audit with the unchanged legacy changed-shape.
        $this->auditTrailMapper->expects($this->once())
            ->method('insert')
            ->with(
                $this->callback(
                    function (AuditTrail $row): bool {
                        return $row->getAction() === 'referential_integrity.cascade_delete'
                            && $row->getObjectUuid() === 'uuid-missing'
                            && $row->getChanged() === [
                                'deletedBecause' => 'cascade',
                                'triggerObject'  => 'root-uuid',
                                'triggerSchema'  => 'root-slug',
                                'property'       => 'refMissing',
                            ];
                    }
                )
            );

        $analysis = $this->analysisWithCascade(
            [
                $this->target('uuid-missing', 'refMissing'),
                $this->target('uuid-1', 'refA'),
            ]
        );

        $this->service->applyDeletionActions($analysis, 'admin', 'root-uuid', null, 'root-slug');
    }//end testBatchMissFallsBackPerObjectForThatTargetOnly()

    /**
     * A throwing batch resolve routes every target through the per-object
     * pipeline without throwing.
     *
     * @return void
     */
    public function testResolveFailureFallsBackEntirely(): void
    {
        $this->objectMapper->method('findMultipleAcrossAllMagicTables')
            ->willThrowException(new \RuntimeException('union query failed'));

        $this->objectMapper->expects($this->never())
            ->method('softDeleteMultipleObjectEntities');
        $this->auditTrailMapper->expects($this->never())->method('insertAuditTrails');

        $this->objectMapper->expects($this->once())
            ->method('deleteObjects')
            ->with($this->equalTo(['uuid-2', 'uuid-1']), $this->equalTo(false))
            ->willReturn([]);

        $this->auditTrailMapper->expects($this->exactly(2))->method('insert');
        $this->logger->expects($this->atLeastOnce())->method('warning');

        $analysis = $this->analysisWithCascade(
            [
                $this->target('uuid-1'),
                $this->target('uuid-2'),
            ]
        );

        $this->service->applyDeletionActions($analysis, 'admin', 'root-uuid');
    }//end testResolveFailureFallsBackEntirely()

    /**
     * A throwing batched soft-delete write routes every target through the
     * per-object pipeline without throwing.
     *
     * @return void
     */
    public function testBatchWriteFailureFallsBackEntirely(): void
    {
        $this->objectMapper->method('findMultipleAcrossAllMagicTables')
            ->willReturn([$this->createEntity('uuid-1'), $this->createEntity('uuid-2')]);

        $this->objectMapper->method('softDeleteMultipleObjectEntities')
            ->willThrowException(new \RuntimeException('batched UPDATE failed'));

        $this->auditTrailMapper->expects($this->never())->method('insertAuditTrails');

        $this->objectMapper->expects($this->once())
            ->method('deleteObjects')
            ->with($this->equalTo(['uuid-2', 'uuid-1']), $this->equalTo(false))
            ->willReturn([]);

        $this->auditTrailMapper->expects($this->exactly(2))->method('insert');
        $this->logger->expects($this->atLeastOnce())->method('error');

        $analysis = $this->analysisWithCascade(
            [
                $this->target('uuid-1'),
                $this->target('uuid-2'),
            ]
        );

        $this->service->applyDeletionActions($analysis, 'admin', 'root-uuid');
    }//end testBatchWriteFailureFallsBackEntirely()

    /**
     * A target whose write the mapper skipped (hook stopped propagation) is
     * neither audited nor force-retried through the per-object pipeline.
     *
     * @return void
     */
    public function testHookSkippedTargetIsNotRetriedPerObject(): void
    {
        $kept    = $this->createEntity('uuid-1');
        $skipped = $this->createEntity('uuid-2');

        $this->objectMapper->method('findMultipleAcrossAllMagicTables')
            ->willReturn([$kept, $skipped]);

        // Mapper only reports uuid-1 as actually soft-deleted.
        $this->objectMapper->method('softDeleteMultipleObjectEntities')
            ->willReturn([$kept]);

        $trail = new AuditTrail();
        $trail->setUuid('audit-1');
        $this->auditTrailMapper->method('buildAuditTrail')->willReturn($trail);

        $this->auditTrailMapper->expects($this->once())
            ->method('insertAuditTrails')
            ->with($this->callback(fn(array $entries): bool => count($entries) === 1))
            ->willReturn([]);

        // uuid-2 was handled (deliberately skipped) — no per-object retry.
        $this->objectMapper->expects($this->never())->method('deleteObjects');
        $this->auditTrailMapper->expects($this->never())->method('insert');

        $analysis = $this->analysisWithCascade(
            [
                $this->target('uuid-1'),
                $this->target('uuid-2'),
            ]
        );

        $this->service->applyDeletionActions($analysis, 'admin', 'root-uuid');
    }//end testHookSkippedTargetIsNotRetriedPerObject()

    // =========================================================================
    // Fail-soft audit
    // =========================================================================

    /**
     * A throwing multi-row audit INSERT is logged and never aborts the cascade
     * or triggers a per-object retry of already-deleted targets.
     *
     * @return void
     */
    public function testAuditInsertFailureIsFailSoft(): void
    {
        $this->objectMapper->method('findMultipleAcrossAllMagicTables')
            ->willReturn([$this->createEntity('uuid-1')]);

        $this->objectMapper->method('softDeleteMultipleObjectEntities')
            ->willReturnArgument(0);

        $trail = new AuditTrail();
        $trail->setUuid('audit-1');
        $this->auditTrailMapper->method('buildAuditTrail')->willReturn($trail);

        $this->auditTrailMapper->method('insertAuditTrails')
            ->willThrowException(new \RuntimeException('bulk insert failed'));

        $this->logger->expects($this->atLeastOnce())->method('warning');
        $this->objectMapper->expects($this->never())->method('deleteObjects');

        $analysis = $this->analysisWithCascade([$this->target('uuid-1')]);

        // Should not throw.
        $this->service->applyDeletionActions($analysis, 'admin', 'root-uuid');
    }//end testAuditInsertFailureIsFailSoft()

    /**
     * A throwing row build skips that row but the remaining rows are inserted.
     *
     * @return void
     */
    public function testRowBuildFailureSkipsOnlyThatRow(): void
    {
        $this->objectMapper->method('findMultipleAcrossAllMagicTables')
            ->willReturn([$this->createEntity('uuid-1'), $this->createEntity('uuid-2')]);

        $this->objectMapper->method('softDeleteMultipleObjectEntities')
            ->willReturnArgument(0);

        $calls = 0;
        $this->auditTrailMapper->method('buildAuditTrail')
            ->willReturnCallback(
                function () use (&$calls) {
                    $calls++;
                    if ($calls === 1) {
                        throw new \RuntimeException('row build failed');
                    }

                    $trail = new AuditTrail();
                    $trail->setUuid('audit-'.$calls);
                    return $trail;
                }
            );

        $this->auditTrailMapper->expects($this->once())
            ->method('insertAuditTrails')
            ->with($this->callback(fn(array $entries): bool => count($entries) === 1))
            ->willReturn([]);

        $this->logger->expects($this->atLeastOnce())->method('warning');

        $analysis = $this->analysisWithCascade(
            [
                $this->target('uuid-1'),
                $this->target('uuid-2'),
            ]
        );

        // Should not throw.
        $this->service->applyDeletionActions($analysis, 'admin', 'root-uuid');
    }//end testRowBuildFailureSkipsOnlyThatRow()

    // =========================================================================
    // Non-CASCADE actions untouched
    // =========================================================================

    /**
     * SET_NULL targets never touch the batched cascade machinery.
     *
     * @return void
     */
    public function testSetNullDoesNotUseBatchedCascadeMachinery(): void
    {
        $object = new ObjectEntity();
        $object->setUuid('dep-uuid');
        $object->setObject(['parentRef' => 'root-uuid']);

        $this->objectMapper->method('findAcrossAllSources')
            ->willReturn(
                [
                    'object'   => $object,
                    'register' => $this->createMock(\OCA\OpenRegister\Db\Register::class),
                    'schema'   => $this->createMock(\OCA\OpenRegister\Db\Schema::class),
                ]
            );

        $this->objectMapper->expects($this->never())->method('findMultipleAcrossAllMagicTables');
        $this->objectMapper->expects($this->never())->method('softDeleteMultipleObjectEntities');
        $this->auditTrailMapper->expects($this->never())->method('insertAuditTrails');
        $this->objectMapper->expects($this->once())->method('update');

        $analysis = new DeletionAnalysis(
            deletable: true,
            nullifyTargets: [
                [
                    'objectUuid' => 'dep-uuid',
                    'schema'     => '1',
                    'property'   => 'parentRef',
                    'isArray'    => false,
                    'sourceUuid' => 'root-uuid',
                ],
            ]
        );

        $this->service->applyDeletionActions($analysis, 'admin', 'root-uuid');
    }//end testSetNullDoesNotUseBatchedCascadeMachinery()
}//end class
