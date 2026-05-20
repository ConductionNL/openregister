<?php

/**
 * Unit tests for the EntityRelationMapper constructor and the
 * `existsForFileAtPosition` + `insertBatch` extensions added by
 * the `manual-entity-anonymisation` change.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests\Unit\Db
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\DetectionMethod;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCP\AppFramework\Db\Entity;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Test subclass exposing the inherited `insert` hook + the inherited
 * `findEntities` so we can intercept persistence without booting a
 * real DB.
 */
class TestableEntityRelationMapper extends EntityRelationMapper
{
    /**
     * Captured EntityRelation entities passed to insert().
     *
     * @var EntityRelation[]
     */
    public array $insertedEntities = [];

    /**
     * Optional throwable: set to a Throwable to make insert() throw on
     * the next call (simulates a DB error inside a transaction).
     *
     * @var \Throwable|null
     */
    public ?\Throwable $throwOnNthInsert = null;

    /**
     * Number of insert() calls so far (used together with the throw counter).
     *
     * @var int
     */
    public int $insertCalls = 0;


    /**
     * Override the inherited QBMapper::insert to capture without DB I/O.
     *
     * @param Entity $entity Relation being persisted.
     *
     * @return Entity The same entity, with a generated id assigned.
     */
    public function insert(Entity $entity): Entity
    {
        $this->insertCalls++;
        if ($this->throwOnNthInsert !== null && $this->insertCalls === 1) {
            throw $this->throwOnNthInsert;
        }

        // Assign a deterministic id based on the call count.
        $entity->setId(1000 + $this->insertCalls);
        $this->insertedEntities[] = $entity;
        return $entity;

    }//end insert()
}//end class


/**
 * Verifies the construction wiring + the public surface added by
 * the `manual-entity-anonymisation` change.
 *
 * DB-heavy query behaviour (SQL semantics, JOIN correctness) is
 * covered by integration tests; these tests focus on the entity-row
 * mapping + return-value contract.
 */
class EntityRelationMapperTest extends TestCase
{
    /**
     * DB connection mock — supplies the QueryBuilder chain.
     *
     * @var IDBConnection&MockObject
     */
    private IDBConnection&MockObject $db;


    /**
     * Wire fresh mocks for every test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->db = $this->createMock(originalClassName: IDBConnection::class);

    }//end setUp()


    /**
     * Build a TestableEntityRelationMapper with the standard deps wired.
     *
     * @return TestableEntityRelationMapper
     */
    private function makeMapper(): TestableEntityRelationMapper
    {
        return new TestableEntityRelationMapper(db: $this->db);

    }//end makeMapper()


    /**
     * Sanity wiring test.
     *
     * @return void
     */
    public function testConstructsWithInjectedDependencies(): void
    {
        $mapper = new EntityRelationMapper(db: $this->db);

        $this->assertInstanceOf(expected: EntityRelationMapper::class, actual: $mapper);

    }//end testConstructsWithInjectedDependencies()


    // =====================================================================
    // insertBatch
    // =====================================================================


    /**
     * insertBatch passes each row through `buildRelationFromRow` then
     * `insert`, returning the inserted entities in input order.
     *
     * @return void
     */
    public function testInsertBatchInsertsEachRowInOrder(): void
    {
        $mapper = $this->makeMapper();

        $rows = [
            [
                'entityId'        => 7,
                'fileId'          => 42,
                'chunkId'         => 100,
                'positionStart'   => 13,
                'positionEnd'     => 23,
                'context'         => '... Jan Jansen ...',
                'detectionMethod' => DetectionMethod::MANUAL,
                'role'            => 'anonymisable',
                'confidence'      => 1.0,
                'anonymized'      => false,
                'skipAnonymization' => false,
            ],
            [
                'entityId'        => 7,
                'fileId'          => 42,
                'chunkId'         => 101,
                'positionStart'   => 80,
                'positionEnd'     => 90,
                'detectionMethod' => DetectionMethod::MANUAL,
            ],
        ];

        $inserted = $mapper->insertBatch(rows: $rows);

        $this->assertCount(expectedCount: 2, haystack: $inserted);
        $this->assertSame(expected: 1001, actual: (int) $inserted[0]->getId());
        $this->assertSame(expected: 1002, actual: (int) $inserted[1]->getId());

        // Verify each row's fields were transferred onto its entity.
        $this->assertSame(expected: 7, actual: $inserted[0]->getEntityId());
        $this->assertSame(expected: 42, actual: $inserted[0]->getFileId());
        $this->assertSame(expected: 100, actual: $inserted[0]->getChunkId());
        $this->assertSame(expected: 13, actual: $inserted[0]->getPositionStart());
        $this->assertSame(expected: 23, actual: $inserted[0]->getPositionEnd());
        $this->assertSame(
            expected: DetectionMethod::MANUAL,
            actual: $inserted[0]->getDetectionMethod()
        );
        $this->assertSame(expected: 1.0, actual: $inserted[0]->getConfidence());
        $this->assertFalse(condition: $inserted[0]->getAnonymized());
        $this->assertFalse(condition: $inserted[0]->getSkipAnonymization());

    }//end testInsertBatchInsertsEachRowInOrder()


    /**
     * Empty input → empty output, no insert call.
     *
     * @return void
     */
    public function testInsertBatchWithEmptyRowsReturnsEmpty(): void
    {
        $mapper = $this->makeMapper();

        $result = $mapper->insertBatch(rows: []);

        $this->assertSame(expected: [], actual: $result);
        $this->assertSame(expected: 0, actual: $mapper->insertCalls);

    }//end testInsertBatchWithEmptyRowsReturnsEmpty()


    /**
     * If insert() throws, the exception propagates to the caller (which
     * manages the transaction). Subsequent rows MUST NOT be inserted.
     *
     * @return void
     */
    public function testInsertBatchPropagatesExceptions(): void
    {
        $mapper                  = $this->makeMapper();
        $mapper->throwOnNthInsert = new RuntimeException(message: 'unique key violation');

        $rows = [
            ['entityId' => 7, 'fileId' => 42, 'chunkId' => 100, 'positionStart' => 13, 'positionEnd' => 23],
            ['entityId' => 7, 'fileId' => 42, 'chunkId' => 100, 'positionStart' => 50, 'positionEnd' => 60],
        ];

        try {
            $mapper->insertBatch(rows: $rows);
            $this->fail(message: 'Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame(expected: 'unique key violation', actual: $e->getMessage());
        }

        // Only one insert was attempted before the throw, and nothing was captured.
        $this->assertSame(expected: 1, actual: $mapper->insertCalls);
        $this->assertSame(expected: [], actual: $mapper->insertedEntities);

    }//end testInsertBatchPropagatesExceptions()


    /**
     * Without an explicit `createdAt`, the row builder fills in a
     * timestamp via `new DateTime()`. Verify it ends up populated.
     *
     * @return void
     */
    public function testInsertBatchDefaultsCreatedAt(): void
    {
        $mapper = $this->makeMapper();

        $mapper->insertBatch(
            rows: [['entityId' => 7, 'fileId' => 42, 'chunkId' => 100, 'positionStart' => 13, 'positionEnd' => 23]]
        );

        $this->assertInstanceOf(
            expected: DateTime::class,
            actual: $mapper->insertedEntities[0]->getCreatedAt()
        );

    }//end testInsertBatchDefaultsCreatedAt()


    /**
     * Explicit `createdAt` is preserved on the inserted row.
     *
     * @return void
     */
    public function testInsertBatchKeepsExplicitCreatedAt(): void
    {
        $mapper = $this->makeMapper();

        $when = new DateTime(datetime: '2026-05-20T12:34:56Z');
        $mapper->insertBatch(
            rows: [
                [
                    'entityId'      => 7,
                    'fileId'        => 42,
                    'chunkId'       => 100,
                    'positionStart' => 13,
                    'positionEnd'   => 23,
                    'createdAt'     => $when,
                ],
            ]
        );

        $this->assertSame(
            expected: $when,
            actual: $mapper->insertedEntities[0]->getCreatedAt()
        );

    }//end testInsertBatchKeepsExplicitCreatedAt()


    // =====================================================================
    // existsForFileAtPosition
    // =====================================================================


    /**
     * Wire a mock IQueryBuilder + IResult chain onto $this->db so the
     * SUT's `existsForFileAtPosition` can run without a real DB.
     *
     * @param mixed $fetchResult Value returned by IResult::fetch(). false
     *                           when no row matches; array otherwise.
     *
     * @return void
     */
    private function setupExistsQueryReturning(mixed $fetchResult): void
    {
        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturn(value: $fetchResult);
        $result->method('closeCursor');

        $composite = $this->createMock(ICompositeExpression::class);

        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('andX')->willReturn(value: $composite);
        $expr->method('eq')->willReturn(value: 'eq');

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('expr')->willReturn(value: $expr);
        $qb->method('createNamedParameter')->willReturn(value: ':p');
        $qb->method('executeQuery')->willReturn(value: $result);

        $this->db->method('getQueryBuilder')->willReturn(value: $qb);

    }//end setupExistsQueryReturning()


    /**
     * Row exists → returns true.
     *
     * @return void
     */
    public function testExistsForFileAtPositionTrue(): void
    {
        $this->setupExistsQueryReturning(fetchResult: ['id' => 200]);

        $mapper = $this->makeMapper();

        $this->assertTrue(
            condition: $mapper->existsForFileAtPosition(
                fileId: 42,
                entityId: 7,
                chunkId: 100,
                positionStart: 13,
                positionEnd: 23
            )
        );

    }//end testExistsForFileAtPositionTrue()


    /**
     * No row matches → returns false.
     *
     * @return void
     */
    public function testExistsForFileAtPositionFalse(): void
    {
        $this->setupExistsQueryReturning(fetchResult: false);

        $mapper = $this->makeMapper();

        $this->assertFalse(
            condition: $mapper->existsForFileAtPosition(
                fileId: 42,
                entityId: 7,
                chunkId: 100,
                positionStart: 13,
                positionEnd: 23
            )
        );

    }//end testExistsForFileAtPositionFalse()
}//end class
