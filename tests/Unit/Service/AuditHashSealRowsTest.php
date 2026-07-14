<?php

/**
 * AuditHashService::sealRows batched sealing tests.
 *
 * Proves the batched counterpart of sealRow() preserves the exact chain
 * semantics: rows are hashed in id order, the first row chains onto the
 * hash before the range (or genesis), already-sealed rows are left
 * untouched but contribute their stored hash as the chain link, and the
 * whole batch is persisted through a single CASE-based UPDATE statement.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\AuditHashService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AuditHashSealRowsTest extends TestCase
{

    private AuditHashService $service;

    private IDBConnection&MockObject $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db      = $this->createMock(IDBConnection::class);
        $this->service = new AuditHashService($this->db);
    }//end setUp()

    /**
     * Build a generic query-builder mock whose executeQuery() yields the
     * given fetchAll()/fetch() results.
     *
     * @param array $fetchAllRows  Rows for fetchAll().
     * @param mixed $fetchResult   Result of a single fetch() call.
     */
    private function buildQueryBuilder(array $fetchAllRows, mixed $fetchResult): IQueryBuilder&MockObject
    {
        $cursor = $this->createMock(IResult::class);
        $cursor->method('fetchAll')->willReturn($fetchAllRows);
        $cursor->method('fetch')->willReturn($fetchResult);

        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('gte')->willReturn('gte');
        $expr->method('lte')->willReturn('lte');
        $expr->method('lt')->willReturn('lt');
        $expr->method('eq')->willReturn('eq');

        $queryBuilder = $this->createMock(IQueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expr);
        $queryBuilder->method('createNamedParameter')->willReturn(':p');
        $queryBuilder->method('getTableName')->willReturn('"oc_openregister_audit_trails"');
        $queryBuilder->method('executeQuery')->willReturn($cursor);

        return $queryBuilder;
    }//end buildQueryBuilder()

    /**
     * Replicate the service's snake_case row → hydrated entity path so the
     * test computes its expected hashes through the same public formula.
     */
    private function entityFromRow(array $row): AuditTrail
    {
        $mapped = [];
        foreach ($row as $key => $value) {
            $camelKey          = lcfirst(str_replace('_', '', ucwords($key, '_')));
            $mapped[$camelKey] = $value;
        }

        $entry = new AuditTrail();
        $entry->hydrate($mapped);

        return $entry;
    }//end entityFromRow()

    /**
     * Two unsealed rows are chained genesis -> h1 -> h2 and persisted with
     * one CASE-based UPDATE carrying exactly those hashes.
     */
    public function testSealRowsChainsFromGenesisAndUpdatesOnce(): void
    {
        $row1 = [
            'id'            => 1,
            'uuid'          => 'uuid-1',
            'action'        => 'create',
            'hash'          => null,
            'previous_hash' => null,
        ];
        $row2 = [
            'id'            => 2,
            'uuid'          => 'uuid-2',
            'action'        => 'update',
            'hash'          => null,
            'previous_hash' => null,
        ];

        // getQueryBuilder() is used for (1) the range SELECT, (2) the
        // hash-before SELECT (no prior row -> genesis), (3) the table name.
        $rangeQb  = $this->buildQueryBuilder([$row1, $row2], false);
        $beforeQb = $this->buildQueryBuilder([], false);
        $tableQb  = $this->buildQueryBuilder([], false);
        $this->db->method('getQueryBuilder')
            ->willReturnOnConsecutiveCalls($rangeQb, $beforeQb, $tableQb);

        $genesis = $this->service->getGenesisHash();
        $hash1   = $this->service->computeHash($this->entityFromRow($row1), $genesis);
        $hash2   = $this->service->computeHash($this->entityFromRow($row2), $hash1);

        $capturedSql    = null;
        $capturedParams = null;
        $this->db->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(
                function (string $sql, array $params) use (&$capturedSql, &$capturedParams): int {
                    $capturedSql    = $sql;
                    $capturedParams = $params;
                    return 2;
                }
            );

        $sealed = $this->service->sealRows([1, 2]);

        $this->assertSame(2, $sealed);
        $this->assertStringContainsString('WHEN 1 THEN ?', $capturedSql);
        $this->assertStringContainsString('WHEN 2 THEN ?', $capturedSql);
        $this->assertStringContainsString('WHERE id IN (1,2)', $capturedSql);
        // Params: [hash(id1), hash(id2), previous(id1)=genesis, previous(id2)=hash1].
        $this->assertSame([$hash1, $hash2, $genesis, $hash1], $capturedParams);
    }//end testSealRowsChainsFromGenesisAndUpdatesOnce()

    /**
     * An already-sealed row inside the range is not re-written, but its
     * stored hash becomes the chain link for the next unsealed row.
     */
    public function testSealRowsAdoptsStoredHashOfSealedRows(): void
    {
        $storedHash = str_repeat('a', 64);

        $row1 = [
            'id'            => 10,
            'uuid'          => 'uuid-10',
            'action'        => 'create',
            'hash'          => $storedHash,
            'previous_hash' => str_repeat('b', 64),
        ];
        $row2 = [
            'id'            => 11,
            'uuid'          => 'uuid-11',
            'action'        => 'update',
            'hash'          => null,
            'previous_hash' => null,
        ];

        $rangeQb  = $this->buildQueryBuilder([$row1, $row2], false);
        $beforeQb = $this->buildQueryBuilder([], false);
        $tableQb  = $this->buildQueryBuilder([], false);
        $this->db->method('getQueryBuilder')
            ->willReturnOnConsecutiveCalls($rangeQb, $beforeQb, $tableQb);

        $expectedHash = $this->service->computeHash($this->entityFromRow($row2), $storedHash);

        $capturedSql    = null;
        $capturedParams = null;
        $this->db->expects($this->once())
            ->method('executeStatement')
            ->willReturnCallback(
                function (string $sql, array $params) use (&$capturedSql, &$capturedParams): int {
                    $capturedSql    = $sql;
                    $capturedParams = $params;
                    return 1;
                }
            );

        $sealed = $this->service->sealRows([10, 11]);

        // Only the unsealed row is written.
        $this->assertSame(1, $sealed);
        $this->assertStringNotContainsString('WHEN 10 THEN', $capturedSql);
        $this->assertStringContainsString('WHEN 11 THEN ?', $capturedSql);
        $this->assertStringContainsString('WHERE id IN (11)', $capturedSql);
        $this->assertSame([$expectedHash, $storedHash], $capturedParams);
    }//end testSealRowsAdoptsStoredHashOfSealedRows()

    /**
     * Empty / invalid id input never touches the database.
     */
    public function testSealRowsWithNoIdsIsANoOp(): void
    {
        $this->db->expects($this->never())->method('getQueryBuilder');
        $this->db->expects($this->never())->method('executeStatement');

        $this->assertSame(0, $this->service->sealRows([]));
        $this->assertSame(0, $this->service->sealRows([0, -5]));
    }//end testSealRowsWithNoIdsIsANoOp()
}//end class
