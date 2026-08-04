<?php

/**
 * Retention-purge tombstone tests for the audit hash chain (or#2265).
 *
 * The forensic hole being closed: `clearLogs()` HARD-deleted expired rows, and
 * `verifyChain()` walks rows in id order carrying each row's hash forward as
 * the next row's `previousHash`. Removing a row mid-chain therefore made the
 * FOLLOWING row fail verification — so a lawful retention purge and a tampering
 * event produced the identical symptom. For a system holding Dutch legal
 * retention data, "the chain is broken" has to mean something.
 *
 * A purge now blanks the payload and stamps `purged_at`, keeping id, created,
 * hash and previous_hash. This file proves the three things that has to be
 * true, and — importantly — includes the NEGATIVE control:
 *
 *   - {@see testChainStaysValidAcrossATombstone} — a purge no longer breaks it.
 *   - {@see testTamperedRowStillBreaksTheChain} — tampering STILL breaks it.
 *     Without this, the first test would also pass against a verifier that had
 *     simply stopped checking anything.
 *   - {@see testCanonicalJsonIsUnaffectedByPurgedAt} — adding the field did not
 *     silently invalidate every hash ever written.
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

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\AuditHashService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AuditChainTombstoneTest extends TestCase
{

    private AuditHashService $service;

    private IDBConnection&MockObject $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db      = $this->createMock(IDBConnection::class);
        $this->service = new AuditHashService(
            $this->db,
            $this->createMock(ILockingProvider::class),
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    /**
     * Wire the mocked connection so `verifyChain()` reads exactly these rows.
     *
     * @param array<int, array<string, mixed>> $rows Rows in id order.
     */
    private function wireRows(array $rows): void
    {
        $result = $this->createMock(IResult::class);

        $cursor = 0;
        $result->method('fetch')->willReturnCallback(
            static function () use (&$cursor, $rows) {
                if ($cursor >= count($rows)) {
                    return false;
                }

                $row = $rows[$cursor];
                $cursor++;
                return $row;
            }
        );

        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['select', 'from', 'orderBy', 'andWhere'] as $fluent) {
            $qb->method($fluent)->willReturnSelf();
        }

        $qb->method('executeQuery')->willReturn($result);
        $this->db->method('getQueryBuilder')->willReturn($qb);
    }//end wireRows()

    /**
     * Build a DB-shaped row and seal it against the given previous hash.
     *
     * Returns the row array with a correct `hash`, so a chain built from
     * successive calls verifies for real rather than by construction.
     *
     * @param int    $id           Row id.
     * @param string $action       Audit action.
     * @param string $previousHash The preceding row's hash.
     *
     * @return array<string, mixed>
     */
    private function sealedRow(int $id, string $action, string $previousHash): array
    {
        $row = [
            'id'          => $id,
            'uuid'        => 'uuid-'.$id,
            'action'      => $action,
            'object_uuid' => 'obj-'.$id,
            'hash'        => null,
            'purged_at'   => null,
        ];

        // Compute the hash exactly the way verifyChain() will re-derive it:
        // same row -> entity -> canonical-JSON path.
        $entry = new AuditTrail();
        $entry->hydrate($this->camelise($row));
        $row['hash'] = $this->service->computeHash(entry: $entry, previousHash: $previousHash);

        return $row;
    }//end sealedRow()

    /**
     * snake_case row keys to camelCase entity fields (mirrors mapRowToEntity()).
     *
     * @param array<string, mixed> $row The DB row.
     *
     * @return array<string, mixed>
     */
    private function camelise(array $row): array
    {
        $mapped = [];
        foreach ($row as $key => $value) {
            $mapped[lcfirst(str_replace('_', '', ucwords($key, '_')))] = $value;
        }

        return $mapped;
    }//end camelise()

    /**
     * A purged row keeps the chain intact and is reported as a tombstone.
     *
     * Row 2's payload is destroyed and `purged_at` stamped — exactly what
     * `clearLogs()` now does. Row 3 was sealed against row 2's hash, and that
     * hash is still present, so the link holds.
     */
    public function testChainStaysValidAcrossATombstone(): void
    {
        $genesis = $this->service->getGenesisHash();

        $row1 = $this->sealedRow(id: 1, action: 'create', previousHash: $genesis);
        $row2 = $this->sealedRow(id: 2, action: 'update', previousHash: $row1['hash']);
        $row3 = $this->sealedRow(id: 3, action: 'delete', previousHash: $row2['hash']);

        // Purge row 2: blank the payload, stamp the tombstone, keep the hash.
        $row2['action']      = null;
        $row2['object_uuid'] = null;
        $row2['purged_at']   = '2026-08-04 10:00:00';

        $this->wireRows([$row1, $row2, $row3]);

        $report = $this->service->verifyChain();

        $this->assertTrue($report['valid'], 'A lawful purge must not read as a broken chain.');
        $this->assertNull($report['brokenAt']);
        $this->assertSame(1, $report['purgedTombstones'], 'The tombstone must be declared, not hidden.');
        $this->assertSame(2, $report['entriesVerified'], 'The two intact rows must still be verified.');
    }//end testChainStaysValidAcrossATombstone()

    /**
     * NEGATIVE CONTROL — tampering must STILL break the chain.
     *
     * Same three rows, but row 2 is altered WITHOUT a `purged_at` stamp: the
     * shape an attacker produces. If this passed, the tombstone handling would
     * have turned verification into a no-op and the test above would be
     * meaningless.
     */
    public function testTamperedRowStillBreaksTheChain(): void
    {
        $genesis = $this->service->getGenesisHash();

        $row1 = $this->sealedRow(id: 1, action: 'create', previousHash: $genesis);
        $row2 = $this->sealedRow(id: 2, action: 'update', previousHash: $row1['hash']);
        $row3 = $this->sealedRow(id: 3, action: 'delete', previousHash: $row2['hash']);

        // Alter row 2's payload but leave purged_at NULL — undeclared change.
        $row2['action'] = 'approve';

        $this->wireRows([$row1, $row2, $row3]);

        $report = $this->service->verifyChain();

        $this->assertFalse($report['valid'], 'An undeclared payload change must still be detected.');
        $this->assertSame(2, $report['brokenAt']);
        $this->assertSame(0, $report['purgedTombstones']);
    }//end testTamperedRowStillBreaksTheChain()

    /**
     * NEGATIVE CONTROL — a row DELETED outright still breaks the chain.
     *
     * This is the pre-fix behaviour, kept as an explicit assertion so the
     * difference between a hard delete and a tombstone is pinned down: the
     * whole point of the change is that these two are no longer the same
     * event. Row 2 is simply absent, so row 3 is verified against row 1's
     * hash and fails.
     */
    public function testHardDeletedRowStillBreaksTheChain(): void
    {
        $genesis = $this->service->getGenesisHash();

        $row1 = $this->sealedRow(id: 1, action: 'create', previousHash: $genesis);
        $row2 = $this->sealedRow(id: 2, action: 'update', previousHash: $row1['hash']);
        $row3 = $this->sealedRow(id: 3, action: 'delete', previousHash: $row2['hash']);

        // Row 2 physically removed — what clearLogs() used to do.
        $this->wireRows([$row1, $row3]);

        $report = $this->service->verifyChain();

        $this->assertFalse($report['valid']);
        $this->assertSame(3, $report['brokenAt']);
        $this->assertSame(0, $report['purgedTombstones']);
    }//end testHardDeletedRowStillBreaksTheChain()

    /**
     * Adding `purgedAt` must NOT have entered the canonical JSON.
     *
     * If it had, the hash of every row ever written would change and the
     * entire existing chain would read as tampered on the next verification —
     * a catastrophic and entirely silent regression.
     */
    public function testCanonicalJsonIsUnaffectedByPurgedAt(): void
    {
        $intact = new AuditTrail();
        $intact->setUuid('same-uuid');
        $intact->setAction('update');

        $purged = new AuditTrail();
        $purged->setUuid('same-uuid');
        $purged->setAction('update');
        $purged->setPurgedAt(new \DateTime('2026-08-04 10:00:00'));

        $this->assertStringNotContainsString('purgedAt', $this->service->getCanonicalJson($purged));
        $this->assertSame(
            $this->service->getCanonicalJson($intact),
            $this->service->getCanonicalJson($purged),
            'purgedAt leaked into the canonical form; every existing hash would be invalidated.'
        );
        $this->assertTrue($purged->isPurged());
        $this->assertFalse($intact->isPurged());
    }//end testCanonicalJsonIsUnaffectedByPurgedAt()

    /**
     * `clearLogs()` must never issue a DELETE again.
     *
     * The chain-preservation argument only holds if the row physically
     * survives, so this pins the SQL verb itself rather than trusting the
     * surrounding prose: `delete()` must not be reached, `update()` must be,
     * and `purged_at` must be among the columns set.
     */
    public function testClearLogsTombstonesInsteadOfDeleting(): void
    {
        $db = $this->createMock(IDBConnection::class);
        $qb = $this->createMock(IQueryBuilder::class);

        $qb->expects($this->never())->method('delete');
        $qb->expects($this->once())->method('update')->with('openregister_audit_trails')->willReturnSelf();

        $setColumns = [];
        $qb->method('set')->willReturnCallback(
            static function (string $column) use (&$setColumns, $qb) {
                $setColumns[] = $column;
                return $qb;
            }
        );

        $qb->method('expr')->willReturn($this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class));
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('createFunction')->willReturnArgument(0);
        $qb->method('createNamedParameter')->willReturn(':p');
        $qb->method('executeStatement')->willReturn(7);

        $db->method('getQueryBuilder')->willReturn($qb);

        $mapper = new \OCA\OpenRegister\Db\AuditTrailMapper(
            $db,
            $this->createMock(\Psr\Container\ContainerInterface::class),
            $this->createMock(\OCP\IUserSession::class),
            $this->createMock(\OCP\IRequest::class),
            $this->createMock(LoggerInterface::class)
        );

        $this->assertTrue($mapper->clearLogs());
        $this->assertContains('purged_at', $setColumns, 'The tombstone marker was not stamped.');
        $this->assertContains('changed', $setColumns, 'The payload was not destroyed — a purge must still purge.');
        $this->assertContains('ip_address', $setColumns);
    }//end testClearLogsTombstonesInsteadOfDeleting()
}//end class
