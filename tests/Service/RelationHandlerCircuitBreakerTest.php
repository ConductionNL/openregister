<?php

/**
 * Unit tests for `RelationHandler::extractAllRelationshipIds()` circuit
 * breakers — proves the requirements documented in the
 * referential-integrity spec (task 11):
 *
 * - Hard cap at 200 IDs across all input objects.
 * - Per-array property limit of 10 entries to bound per-object load.
 * - Single-string `$ref` properties contribute exactly 1 id.
 * - Empty / missing extend properties are skipped without error.
 *
 * The handler's only dependencies (MagicMapper, SchemaMapper,
 * PerformanceHandler, MagicRbacHandler) are unused by
 * `extractAllRelationshipIds`, so we mock them and exercise the
 * extraction loop in isolation. No DB access required.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PerformanceHandler;
use OCA\OpenRegister\Service\Object\RelationHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @group Unit
 */
class RelationHandlerCircuitBreakerTest extends TestCase
{

    private RelationHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new RelationHandler(
            objectEntityMapper: $this->createMock(MagicMapper::class),
            schemaMapper: $this->createMock(SchemaMapper::class),
            performanceHandler: $this->createMock(PerformanceHandler::class),
            rbacHandler: $this->createMock(MagicRbacHandler::class),
            logger: $this->createMock(LoggerInterface::class),
        );
    }//end setUp()

    public function testCapsTotalExtractedIdsAt200(): void
    {
        // 30 objects × 10 ids each (after the per-array cap) = 300 desired
        // → expect ≤ 200 extracted, not 300.
        $objects = [];
        for ($i = 0; $i < 30; $i++) {
            $obj = $this->createMock(ObjectEntity::class);
            $ids = [];
            for ($j = 0; $j < 15; $j++) {
                $ids[] = sprintf('id-%02d-%02d', $i, $j);
            }

            $obj->method('getObject')->willReturn(['related' => $ids]);
            $objects[] = $obj;
        }

        $extracted = $this->handler->extractAllRelationshipIds(
            objects: $objects,
            _extend: ['related'],
        );

        $this->assertLessThanOrEqual(
            200,
            count($extracted),
            'circuit breaker MUST cap total extracted IDs at 200; got '.count($extracted)
        );
    }//end testCapsTotalExtractedIdsAt200()

    public function testLimitsArrayRelationshipsPerObjectToTen(): void
    {
        // Single object with a 50-element array MUST produce ≤ 10 ids.
        $obj = $this->createMock(ObjectEntity::class);
        $ids = [];
        for ($j = 0; $j < 50; $j++) {
            $ids[] = sprintf('id-%02d', $j);
        }

        $obj->method('getObject')->willReturn(['related' => $ids]);

        $extracted = $this->handler->extractAllRelationshipIds(
            objects: [$obj],
            _extend: ['related'],
        );

        $this->assertCount(
            10,
            $extracted,
            'per-array limit MUST cap relationship arrays at 10 entries per object'
        );
    }//end testLimitsArrayRelationshipsPerObjectToTen()

    public function testStringRefPropertiesContributeExactlyOneId(): void
    {
        $obj = $this->createMock(ObjectEntity::class);
        $obj->method('getObject')->willReturn(['author' => 'author-uuid-1']);

        $extracted = $this->handler->extractAllRelationshipIds(
            objects: [$obj],
            _extend: ['author'],
        );

        $this->assertSame(['author-uuid-1'], $extracted);
    }//end testStringRefPropertiesContributeExactlyOneId()

    public function testMissingExtendPropertiesAreSkippedSafely(): void
    {
        $obj = $this->createMock(ObjectEntity::class);
        $obj->method('getObject')->willReturn(['unrelated' => 'value']);

        $extracted = $this->handler->extractAllRelationshipIds(
            objects: [$obj],
            _extend: ['nonexistent'],
        );

        $this->assertSame([], $extracted, 'missing extend properties MUST yield no IDs without error');
    }//end testMissingExtendPropertiesAreSkippedSafely()

    public function testEmptyAndNonStringValuesAreSkipped(): void
    {
        $obj = $this->createMock(ObjectEntity::class);
        $obj->method('getObject')->willReturn(
                [
                    'related' => [
                        'valid-id',
                        '',
                        42,
                        null,
                        false,
                    ],
                ]
                );

        $extracted = $this->handler->extractAllRelationshipIds(
            objects: [$obj],
            _extend: ['related'],
        );

        $this->assertSame(['valid-id'], $extracted);
    }//end testEmptyAndNonStringValuesAreSkipped()

    public function testDuplicateIdsAreDeduplicated(): void
    {
        $a = $this->createMock(ObjectEntity::class);
        $a->method('getObject')->willReturn(['related' => ['x', 'y']]);
        $b = $this->createMock(ObjectEntity::class);
        $b->method('getObject')->willReturn(['related' => ['y', 'z']]);

        $extracted = $this->handler->extractAllRelationshipIds(
            objects: [$a, $b],
            _extend: ['related'],
        );

        sort($extracted);
        $this->assertSame(['x', 'y', 'z'], $extracted);
    }//end testDuplicateIdsAreDeduplicated()

    public function testMultipleExtendPropertiesAreAllExtracted(): void
    {
        $obj = $this->createMock(ObjectEntity::class);
        $obj->method('getObject')->willReturn(
                [
                    'author' => 'a-1',
                    'tags'   => ['t-1', 't-2'],
                ]
                );

        $extracted = $this->handler->extractAllRelationshipIds(
            objects: [$obj],
            _extend: ['author', 'tags'],
        );

        sort($extracted);
        $this->assertSame(['a-1', 't-1', 't-2'], $extracted);
    }//end testMultipleExtendPropertiesAreAllExtracted()
}//end class
