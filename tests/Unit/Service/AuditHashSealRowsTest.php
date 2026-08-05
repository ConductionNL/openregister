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
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AuditHashSealRowsTest extends TestCase
{

    private AuditHashService $service;

    private IDBConnection&MockObject $db;

    private ILockingProvider&MockObject $lockingProvider;

    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db              = $this->createMock(IDBConnection::class);
        $this->lockingProvider = $this->createMock(ILockingProvider::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
        $this->service         = new AuditHashService(
            $this->db,
            $this->lockingProvider,
            $this->logger,
            $this->createMock(IAppConfig::class)
        );
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
     * Empty / invalid id input never touches the database — and never
     * touches the seal lock either.
     */
    public function testSealRowsWithNoIdsIsANoOp(): void
    {
        $this->db->expects($this->never())->method('getQueryBuilder');
        $this->db->expects($this->never())->method('executeStatement');
        $this->lockingProvider->expects($this->never())->method('acquireLock');
        $this->lockingProvider->expects($this->never())->method('releaseLock');

        $this->assertSame(0, $this->service->sealRows([]));
        $this->assertSame(0, $this->service->sealRows([0, -5]));
    }//end testSealRowsWithNoIdsIsANoOp()

    /**
     * sealRows() serializes the whole seal pass under the well-known
     * exclusive advisory lock and always releases it afterwards.
     */
    public function testSealRowsAcquiresAndReleasesExclusiveSealLock(): void
    {
        $row = [
            'id'            => 1,
            'uuid'          => 'uuid-1',
            'action'        => 'create',
            'hash'          => null,
            'previous_hash' => null,
        ];

        $rangeQb  = $this->buildQueryBuilder([$row], false);
        $beforeQb = $this->buildQueryBuilder([], false);
        $tableQb  = $this->buildQueryBuilder([], false);
        $this->db->method('getQueryBuilder')
            ->willReturnOnConsecutiveCalls($rangeQb, $beforeQb, $tableQb);
        $this->db->method('executeStatement')->willReturn(1);

        $this->lockingProvider->expects($this->once())
            ->method('acquireLock')
            ->with('openregister/audit-seal', ILockingProvider::LOCK_EXCLUSIVE);
        $this->lockingProvider->expects($this->once())
            ->method('releaseLock')
            ->with('openregister/audit-seal', ILockingProvider::LOCK_EXCLUSIVE);

        $this->assertSame(1, $this->service->sealRows([1]));
    }//end testSealRowsAcquiresAndReleasesExclusiveSealLock()

    /**
     * Lock contention is fail-soft: when the seal lock cannot be acquired
     * within the bounded retry window, sealRows() logs a warning, leaves
     * the rows unsealed, never touches the database, and never releases a
     * lock it does not hold.
     */
    public function testSealRowsLockUnavailableFailsSoft(): void
    {
        $this->lockingProvider->method('acquireLock')
            ->willThrowException(new LockedException('openregister/audit-seal'));
        $this->lockingProvider->expects($this->never())->method('releaseLock');

        $this->db->expects($this->never())->method('getQueryBuilder');
        $this->db->expects($this->never())->method('executeStatement');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('seal lock unavailable'));

        $this->assertSame(0, $this->service->sealRows([1, 2]));
    }//end testSealRowsLockUnavailableFailsSoft()

    /**
     * The seal lock is released even when the seal pass itself throws —
     * a DB hiccup must not leave the advisory lock dangling.
     */
    public function testSealRowsReleasesLockWhenSealPassThrows(): void
    {
        $rangeQb = $this->buildQueryBuilder([], false);
        $rangeQb->method('executeQuery')
            ->willThrowException(new \RuntimeException('db gone'));
        $this->db->method('getQueryBuilder')->willReturn($rangeQb);

        $this->lockingProvider->expects($this->once())->method('acquireLock');
        $this->lockingProvider->expects($this->once())
            ->method('releaseLock')
            ->with('openregister/audit-seal', ILockingProvider::LOCK_EXCLUSIVE);

        $this->expectException(\RuntimeException::class);
        $this->service->sealRows([1]);
    }//end testSealRowsReleasesLockWhenSealPassThrows()

    /**
     * sealRow() takes the SAME lock as sealRows() (they race each other)
     * and is equally fail-soft on contention: warning + row left unsealed,
     * no database access.
     */
    public function testSealRowLockUnavailableFailsSoft(): void
    {
        $this->lockingProvider->expects($this->exactly(3))
            ->method('acquireLock')
            ->with('openregister/audit-seal', ILockingProvider::LOCK_EXCLUSIVE)
            ->willThrowException(new LockedException('openregister/audit-seal'));
        $this->lockingProvider->expects($this->never())->method('releaseLock');

        $this->db->expects($this->never())->method('getQueryBuilder');
        $this->db->expects($this->never())->method('executeStatement');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('seal lock unavailable'));

        $this->assertFalse($this->service->sealRow(42));
    }//end testSealRowLockUnavailableFailsSoft()

    /**
     * A transient lock conflict is retried: first acquisition attempt
     * throws, the second succeeds, and the seal pass proceeds normally.
     */
    public function testSealLockAcquisitionRetriesAfterTransientConflict(): void
    {
        $attempt = 0;
        $this->lockingProvider->method('acquireLock')
            ->willReturnCallback(
                function () use (&$attempt): void {
                    $attempt++;
                    if ($attempt === 1) {
                        throw new LockedException('openregister/audit-seal');
                    }
                }
            );
        $this->lockingProvider->expects($this->once())->method('releaseLock');

        $rangeQb  = $this->buildQueryBuilder([], false);
        $beforeQb = $this->buildQueryBuilder([], false);
        $this->db->method('getQueryBuilder')
            ->willReturnOnConsecutiveCalls($rangeQb, $beforeQb);

        // Empty range -> nothing sealed, but the pass ran under the lock.
        $this->assertSame(0, $this->service->sealRows([7]));
        $this->assertSame(2, $attempt);
    }//end testSealLockAcquisitionRetriesAfterTransientConflict()

    /**
     * getHashBefore() fix: a seal pass chains from the nearest PRIOR
     * SEALED row — skipping unsealed fail-soft leftovers exactly like
     * verifyChain()'s walk does — so "row N unsealed, row N+1 sealed"
     * verifies instead of permanently breaking at row N+1.
     *
     * Row 1 is sealed (h1), row 2 is an unsealed leftover, row 3 is sealed
     * through sealRow(). The predecessor query must filter unsealed rows
     * (isNotNull(hash) + hash != ''), row 3 must chain onto h1 (NOT
     * genesis), and a subsequent verifyChain() over all three rows must
     * report the chain valid with exactly one skipped null-hash row.
     */
    public function testSealChainsFromNearestSealedPredecessorAndVerifies(): void
    {
        $genesis = $this->service->getGenesisHash();

        $row1 = [
            'id'            => 1,
            'uuid'          => 'uuid-1',
            'action'        => 'create',
            'hash'          => null,
            'previous_hash' => null,
        ];
        $hash1         = $this->service->computeHash($this->entityFromRow($row1), $genesis);
        $row1['hash']  = $hash1;
        $row1['previous_hash'] = $genesis;

        // Row 2: unsealed fail-soft leftover that STAYS unsealed.
        $row2 = [
            'id'            => 2,
            'uuid'          => 'uuid-2',
            'action'        => 'update',
            'hash'          => null,
            'previous_hash' => null,
        ];

        $row3 = [
            'id'            => 3,
            'uuid'          => 'uuid-3',
            'action'        => 'update',
            'hash'          => null,
            'previous_hash' => null,
        ];

        // --- sealRow(3): row SELECT, predecessor SELECT (filtered to sealed
        // rows -> returns h1), UPDATE.
        $rowQb = $this->buildQueryBuilder([], $row3);

        $beforeExpr = $this->createMock(IExpressionBuilder::class);
        $beforeExpr->method('lt')->willReturn('lt');
        $beforeExpr->method('neq')->willReturn('neq');
        $beforeExpr->expects($this->once())
            ->method('isNotNull')
            ->with('hash')
            ->willReturn('isnotnull');

        $beforeCursor = $this->createMock(IResult::class);
        $beforeCursor->method('fetch')->willReturn(['hash' => $hash1]);

        $beforeQb = $this->createMock(IQueryBuilder::class);
        $beforeQb->method('select')->willReturnSelf();
        $beforeQb->method('from')->willReturnSelf();
        $beforeQb->method('where')->willReturnSelf();
        $beforeQb->method('andWhere')->willReturnSelf();
        $beforeQb->method('orderBy')->willReturnSelf();
        $beforeQb->method('setMaxResults')->willReturnSelf();
        $beforeQb->method('expr')->willReturn($beforeExpr);
        $beforeQb->method('createNamedParameter')->willReturn(':p');
        $beforeQb->method('executeQuery')->willReturn($beforeCursor);

        $capturedValues = [];
        $updateExpr     = $this->createMock(IExpressionBuilder::class);
        $updateExpr->method('eq')->willReturn('eq');

        $updateQb = $this->createMock(IQueryBuilder::class);
        $updateQb->method('update')->willReturnSelf();
        $updateQb->method('set')->willReturnSelf();
        $updateQb->method('where')->willReturnSelf();
        $updateQb->method('expr')->willReturn($updateExpr);
        $updateQb->method('createNamedParameter')
            ->willReturnCallback(
                function (mixed $value) use (&$capturedValues): string {
                    $capturedValues[] = $value;
                    return ':p';
                }
            );
        $updateQb->method('executeStatement')->willReturn(1);

        // --- verifyChain(): windowed walk over rows 1..3.
        // verifyChain() pages by id rather than issuing one unbounded query,
        // because libpq buffers a whole result set client-side and the real
        // trail is large enough for that to get the process OOM-killed. So the
        // mock must serve one populated window and then an empty one, which is
        // how the walk terminates.
        $verifyCursor = $this->createMock(IResult::class);
        $verifyExpr   = $this->createMock(IExpressionBuilder::class);
        $verifyExpr->method('gt')->willReturn('gt');
        $verifyExpr->method('lte')->willReturn('lte');

        $verifyQb = $this->createMock(IQueryBuilder::class);
        $verifyQb->method('select')->willReturnSelf();
        $verifyQb->method('from')->willReturnSelf();
        $verifyQb->method('where')->willReturnSelf();
        $verifyQb->method('andWhere')->willReturnSelf();
        $verifyQb->method('orderBy')->willReturnSelf();
        $verifyQb->method('setMaxResults')->willReturnSelf();
        $verifyQb->method('expr')->willReturn($verifyExpr);
        $verifyQb->method('createNamedParameter')->willReturn(':p');
        $verifyQb->method('executeQuery')->willReturn($verifyCursor);

        // The seal path consumes exactly three builders; every builder after
        // that belongs to the verify walk, which asks for one per window.
        $sealBuilders = [$rowQb, $beforeQb, $updateQb];
        $this->db->method('getQueryBuilder')
            ->willReturnCallback(
                function () use (&$sealBuilders, $verifyQb): IQueryBuilder {
                    if (empty($sealBuilders) === false) {
                        return array_shift($sealBuilders);
                    }

                    return $verifyQb;
                }
            );

        $this->assertTrue($this->service->sealRow(3));

        // Row 3 chained onto h1 — the nearest SEALED predecessor — not genesis.
        $expectedHash3 = $this->service->computeHash($this->entityFromRow($row3), $hash1);
        // Captured named parameters: [hash, previousHash, id].
        $this->assertSame($expectedHash3, $capturedValues[0]);
        $this->assertSame($hash1, $capturedValues[1]);
        $this->assertNotSame($genesis, $capturedValues[1]);

        // The verify walk over [sealed row1, unsealed row2, sealed row3]
        // carries h1 across the unsealed row and re-derives row 3 exactly.
        $row3['hash']          = $expectedHash3;
        $row3['previous_hash'] = $hash1;
        $verifyCursor->method('fetchAll')
            ->willReturnOnConsecutiveCalls([$row1, $row2, $row3], []);

        $verification = $this->service->verifyChain();

        $this->assertTrue($verification['valid']);
        $this->assertSame(2, $verification['entriesVerified']);
        $this->assertSame(1, $verification['skippedNullHashes']);
        $this->assertNull($verification['brokenAt']);
    }//end testSealChainsFromNearestSealedPredecessorAndVerifies()
}//end class
