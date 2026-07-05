<?php

/**
 * MergeService unit tests.
 *
 * Drives the full reversible-merge lifecycle against mocked `ObjectService`
 * and `SchemaMapper` collaborators (real `SurvivorshipResolver` +
 * `TrustTierResolver` — pure, no I/O): a side-effect-free preview, an atomic
 * execute (source relink, status flip, survivor recompute, mergeOperation
 * persist, event dispatch), idempotency + self-merge rejection, reversal that
 * restores the snapshot inside the window, and rejection beyond the window.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Merge
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/mdm-merge-engine/tasks.md#6.1
 */

declare(strict_types=1);

namespace Unit\Service\Merge;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCA\OpenRegister\Service\Merge\MergeService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Survivorship\SourceRecordResolver;
use OCA\OpenRegister\Service\Survivorship\SurvivorshipResolver;
use OCA\OpenRegister\Service\Survivorship\TrustTierResolver;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class MergeServiceTest extends TestCase
{

    private ObjectService&MockObject $objectService;

    private SchemaMapper&MockObject $schemaMapper;

    private IEventDispatcher&MockObject $eventDispatcher;

    private LoggerInterface&MockObject $logger;

    private MergeService $service;

    protected function setUp(): void
    {
        $this->objectService  = $this->createMock(ObjectService::class);
        $this->schemaMapper   = $this->createMock(SchemaMapper::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $this->service = new MergeService(
            $this->objectService,
            $this->schemaMapper,
            new SurvivorshipResolver(),
            new TrustTierResolver(),
            new SourceRecordResolver($this->objectService, $this->schemaMapper, $this->logger),
            $this->eventDispatcher,
            $this->logger
        );
    }//end setUp()

    /**
     * Build a schema carrying both merge + survivorship annotations.
     *
     * @return Schema
     */
    private function schemaWithConfig(): Schema
    {
        $schema = new Schema();
        $schema->setSlug('organisation');
        $schema->setConfiguration(
            [
                'x-openregister-merge'        => [
                    'sourceLinkField' => 'sources',
                    'entityType'      => 'organisation',
                ],
                'x-openregister-survivorship' => [
                    'sourceLinkField'   => 'sources',
                    'goldenRecordField' => 'goldenRecord',
                    'provenanceField'   => 'attributeProvenance',
                    'tierOrder'         => ['discard', 'bronze', 'silver', 'gold'],
                    'defaultTier'       => 'bronze',
                    'discardTier'       => 'discard',
                ],
            ]
        );

        return $schema;
    }//end schemaWithConfig()

    /**
     * Build a from/into object pair with embedded source records.
     *
     * @return array{0: ObjectEntity, 1: ObjectEntity}
     */
    private function buildPair(): array
    {
        $from = new ObjectEntity();
        $from->setUuid('from-uuid');
        $from->setSchema('organisation');
        $from->setRegister('organisations');
        $from->setObject(
            [
                'status'  => 'active',
                'sources' => [
                    ['sourceSystem' => 'crm', 'lastUpdated' => '2026-05-01', 'values' => ['phone' => '020-9999999']],
                ],
            ]
        );

        $into = new ObjectEntity();
        $into->setUuid('into-uuid');
        $into->setSchema('organisation');
        $into->setRegister('organisations');
        $into->setObject(
            [
                'status'  => 'active',
                'sources' => [
                    ['sourceSystem' => 'kvk-api', 'lastUpdated' => '2026-05-01', 'values' => ['name' => 'Voorbeeld B.V.']],
                ],
            ]
        );

        return [$from, $into];
    }//end buildPair()

    public function testPreviewHasNoSideEffects(): void
    {
        [$from, $into] = $this->buildPair();
        $this->schemaMapper->method('find')->willReturn($this->schemaWithConfig());
        $this->objectService->method('find')->willReturnMap(
            [
                ['from-uuid', [], false, null, null, true, true, $from],
                ['into-uuid', [], false, null, null, true, true, $into],
            ]
        );
        $this->objectService->method('findAll')->willReturn([]);

        $this->objectService->expects($this->never())->method('saveObject');
        $this->eventDispatcher->expects($this->never())->method('dispatchTyped');

        $preview = $this->service->previewMerge('from-uuid', 'into-uuid');

        $this->assertSame('Voorbeeld B.V.', $preview['postMergeGoldenRecord']['name']);
        $this->assertSame('020-9999999', $preview['postMergeGoldenRecord']['phone']);
        $this->assertNotEmpty($preview['reversalDeadline']);
    }//end testPreviewHasNoSideEffects()

    public function testPreviewRejectsSelfMerge(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->previewMerge('same-uuid', 'same-uuid');
    }//end testPreviewRejectsSelfMerge()

    public function testExecuteMergeRelinksRecomputesLogsAndDispatches(): void
    {
        [$from, $into] = $this->buildPair();
        $this->schemaMapper->method('find')->willReturn($this->schemaWithConfig());
        $this->objectService->method('find')->willReturnMap(
            [
                ['from-uuid', [], false, null, null, true, true, $from],
                ['into-uuid', [], false, null, null, true, true, $into],
            ]
        );
        $this->objectService->method('findAll')->willReturn([]);

        $savedFrom = new ObjectEntity();
        $savedFrom->setUuid('from-uuid');
        $savedInto = new ObjectEntity();
        $savedInto->setUuid('into-uuid');
        $savedOperation = new ObjectEntity();
        $savedOperation->setUuid('op-uuid');
        $savedOperation->setObject(['mergedIntoUuid' => 'into-uuid', 'mergedFromUuids' => ['from-uuid'], 'reversible' => true]);

        $saveCalls = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            function ($object, ...$rest) use (&$saveCalls, $savedFrom, $savedInto, $savedOperation) {
                $saveCalls[] = $object;
                if ($object instanceof ObjectEntity && $object->getUuid() === 'from-uuid') {
                    return $savedFrom;
                }

                if ($object instanceof ObjectEntity && $object->getUuid() === 'into-uuid') {
                    return $savedInto;
                }

                return $savedOperation;
            }
        );

        $this->eventDispatcher->expects($this->once())
            ->method('dispatchTyped')
            ->with($this->callback(function ($event) {
                $this->assertInstanceOf(ObjectsMergedEvent::class, $event);
                $this->assertSame('into-uuid', $event->getSurvivorUuid());
                $this->assertSame(['from-uuid'], $event->getMergedFromUuids());
                $this->assertFalse($event->isReversal());
                return true;
            }));

        $operation = $this->service->executeMerge('from-uuid', 'into-uuid', 'data-stewardship-review', 'alice');

        $this->assertSame('into-uuid', $operation['mergedIntoUuid']);
        $this->assertSame(['from-uuid'], $operation['mergedFromUuids']);
        $this->assertTrue($operation['reversible']);

        // 3 saves: from (status flip), into (relink+recompute+status), mergeOperation.
        $this->assertCount(3, $saveCalls);
    }//end testExecuteMergeRelinksRecomputesLogsAndDispatches()

    public function testExecuteMergeRejectsSelfMerge(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->executeMerge('same-uuid', 'same-uuid', 'reason', 'alice');
    }//end testExecuteMergeRejectsSelfMerge()

    public function testExecuteMergeRejectsAlreadyMergedSource(): void
    {
        [$from, $into] = $this->buildPair();
        $from->setObject(['status' => 'merged-into-other', 'sources' => []]);

        $this->schemaMapper->method('find')->willReturn($this->schemaWithConfig());
        $this->objectService->method('find')->willReturnMap(
            [
                ['from-uuid', [], false, null, null, true, true, $from],
                ['into-uuid', [], false, null, null, true, true, $into],
            ]
        );

        $this->objectService->expects($this->never())->method('saveObject');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been merged');
        $this->service->executeMerge('from-uuid', 'into-uuid', 'reason', 'alice');
    }//end testExecuteMergeRejectsAlreadyMergedSource()

    public function testExecuteMergeRejectsInactiveSurvivor(): void
    {
        [$from, $into] = $this->buildPair();
        $into->setObject(['status' => 'merged-into-other', 'sources' => []]);

        $this->schemaMapper->method('find')->willReturn($this->schemaWithConfig());
        $this->objectService->method('find')->willReturnMap(
            [
                ['from-uuid', [], false, null, null, true, true, $from],
                ['into-uuid', [], false, null, null, true, true, $into],
            ]
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not active');
        $this->service->executeMerge('from-uuid', 'into-uuid', 'reason', 'alice');
    }//end testExecuteMergeRejectsInactiveSurvivor()

    public function testReverseMergeRestoresSnapshotWithinWindow(): void
    {
        $now      = new DateTimeImmutable();
        $mergedAt = $now->format(DATE_ATOM);

        $operationEntity = new ObjectEntity();
        $operationEntity->setUuid('op-uuid');
        $operationEntity->setObject(
            [
                'mergedIntoUuid'   => 'into-uuid',
                'mergedFromUuids'  => ['from-uuid'],
                'reversible'       => true,
                'mergedAt'         => $mergedAt,
                'preMergeSnapshot' => [
                    'objects'     => [
                        'from-uuid' => ['status' => 'active', 'sources' => []],
                        'into-uuid' => ['status' => 'active', 'sources' => []],
                    ],
                    'sourceLinks' => [],
                ],
            ]
        );

        $fromEntity = new ObjectEntity();
        $fromEntity->setUuid('from-uuid');
        $fromEntity->setSchema('organisation');
        $fromEntity->setRegister('organisations');
        $fromEntity->setObject(['status' => 'merged-into-other']);

        $intoEntity = new ObjectEntity();
        $intoEntity->setUuid('into-uuid');
        $intoEntity->setSchema('organisation');
        $intoEntity->setRegister('organisations');
        $intoEntity->setObject(['status' => 'active']);

        $this->objectService->method('find')->willReturnMap(
            [
                ['op-uuid', [], false, null, MergeService::MERGE_SCHEMA, true, true, $operationEntity],
                ['from-uuid', [], false, null, null, true, true, $fromEntity],
                ['into-uuid', [], false, null, null, true, true, $intoEntity],
            ]
        );

        $savedOperation = new ObjectEntity();
        $savedOperation->setUuid('op-uuid');
        $savedOperation->setObject(
            [
                'mergedIntoUuid'  => 'into-uuid',
                'mergedFromUuids' => ['from-uuid'],
                'reversible'      => false,
                'reversedBy'      => 'bob',
            ]
        );

        $this->objectService->method('saveObject')->willReturn($savedOperation);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatchTyped')
            ->with($this->callback(function ($event) {
                $this->assertInstanceOf(ObjectsMergedEvent::class, $event);
                $this->assertTrue($event->isReversal());
                return true;
            }));

        $result = $this->service->reverseMerge('op-uuid', 'bob');

        $this->assertFalse($result['reversible']);
        $this->assertSame('bob', $result['reversedBy']);
    }//end testReverseMergeRestoresSnapshotWithinWindow()

    public function testReverseMergeRejectedOutsideWindowNoMutation(): void
    {
        $operationEntity = new ObjectEntity();
        $operationEntity->setUuid('op-uuid');
        $operationEntity->setObject(
            [
                'mergedIntoUuid'   => 'into-uuid',
                'mergedFromUuids'  => ['from-uuid'],
                'reversible'       => true,
                'mergedAt'         => '2026-01-01T00:00:00+00:00',
                'preMergeSnapshot' => ['objects' => ['from-uuid' => []], 'sourceLinks' => []],
            ]
        );

        $this->objectService->method('find')->willReturn($operationEntity);
        $this->objectService->expects($this->never())->method('saveObject');
        $this->eventDispatcher->expects($this->never())->method('dispatchTyped');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('window has expired');
        $this->service->reverseMerge('op-uuid', 'bob');
    }//end testReverseMergeRejectedOutsideWindowNoMutation()

    public function testIsReversibleWindowBoundary(): void
    {
        $this->assertTrue(
            $this->service->isReversible(
                ['reversible' => true, 'mergedAt' => '2026-05-20T00:00:00+00:00'],
                '2026-06-03T00:00:00+00:00'
            )
        );
        $this->assertFalse(
            $this->service->isReversible(
                ['reversible' => true, 'mergedAt' => '2026-01-01T00:00:00+00:00'],
                '2026-06-03T00:00:00+00:00'
            )
        );
        $this->assertFalse(
            $this->service->isReversible(['reversible' => false, 'mergedAt' => '2026-06-01T00:00:00+00:00'])
        );
        $this->assertFalse(
            $this->service->isReversible(
                [
                    'reversible' => true,
                    'reversedAt' => '2026-06-02T00:00:00+00:00',
                    'mergedAt'   => '2026-06-01T00:00:00+00:00',
                ]
            )
        );
    }//end testIsReversibleWindowBoundary()

    public function testReversalDeadlineComputesWindow(): void
    {
        $deadline = $this->service->reversalDeadline('2026-01-01T00:00:00+00:00', ['reversalWindowDays' => 10]);
        $this->assertSame('2026-01-11', $deadline);
    }//end testReversalDeadlineComputesWindow()

    public function testReversalDeadlineFallsBackToDefaultWindow(): void
    {
        $deadline = $this->service->reversalDeadline('2026-01-01T00:00:00+00:00');
        $this->assertSame('2026-01-31', $deadline);
    }//end testReversalDeadlineFallsBackToDefaultWindow()

    public function testBuildSnapshotCapturesBothObjectsAndSourceLinks(): void
    {
        [$from, $into] = $this->buildPair();
        $this->schemaMapper->method('find')->willReturn($this->schemaWithConfig());

        $snapshot = $this->service->buildSnapshot($from, $into);

        $this->assertArrayHasKey('from-uuid', $snapshot['objects']);
        $this->assertArrayHasKey('into-uuid', $snapshot['objects']);
        $this->assertIsArray($snapshot['sourceLinks']);
    }//end testBuildSnapshotCapturesBothObjectsAndSourceLinks()
}//end class
