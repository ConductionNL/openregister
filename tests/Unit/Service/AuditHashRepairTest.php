<?php

/**
 * Tests for the audit-trail repair surface: rechainAll() and getIntegrityStatus().
 *
 * rechainAll() is the only method here that rewrites hashes which already
 * exist, so it is the one most worth pinning down. Its whole reason to exist is
 * that historical sealing produced a FAN-OUT — many rows chained onto one
 * predecessor, because concurrent passes each read the same predecessor before
 * either wrote. A repair that reproduced that shape would look like it worked
 * and leave the chain just as broken, so the central assertion here is that
 * each row is chained onto the row actually before it.
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
 *
 * @spec openspec/specs/audit-hash-chain/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Service\AuditHashService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Proves the repair rebuilds a chain, and that the status summary is honest.
 */
class AuditHashRepairTest extends TestCase
{

    /**
     * Service under test.
     *
     * @var AuditHashService
     */
    private AuditHashService $service;

    /**
     * Mocked database connection.
     *
     * @var IDBConnection&MockObject
     */
    private IDBConnection&MockObject $db;

    /**
     * Mocked lock provider gating every seal pass.
     *
     * @var ILockingProvider&MockObject
     */
    private ILockingProvider&MockObject $lockingProvider;

    /**
     * Wire the service.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->db              = $this->createMock(IDBConnection::class);
        $this->lockingProvider = $this->createMock(ILockingProvider::class);
        $this->service         = new AuditHashService(
            $this->db,
            $this->lockingProvider,
            $this->createMock(LoggerInterface::class),
            $this->createMock(IAppConfig::class)
        );

    }//end setUp()

    /**
     * Replicate the service's snake_case row to hydrated entity path.
     *
     * @param array<string, mixed> $row The raw row.
     *
     * @return AuditTrail The hydrated entity.
     */
    private function entityFromRow(array $row): AuditTrail
    {
        $mapped = [];
        foreach ($row as $key => $value) {
            $mapped[lcfirst(str_replace('_', '', ucwords($key, '_')))] = $value;
        }

        $entry = new AuditTrail();
        $entry->hydrate($mapped);

        return $entry;

    }//end entityFromRow()

    /**
     * rechainAll() links every row to the row actually before it.
     *
     * The regression this guards is subtle and was live: if the repair derived
     * previousHash from a single value read once — rather than from the row it
     * just wrote — every row would chain onto the SAME predecessor. The result
     * still looks sealed, every row has a hash, and the chain is still broken.
     * So the assertion is not "rows got hashes" but "row N's previousHash is
     * row N-1's hash", which a fan-out cannot satisfy.
     *
     * @return void
     *
     * @spec openspec/specs/audit-hash-chain/spec.md
     */
    public function testRechainAllProducesOneChainNotAFanOut(): void
    {
        $rows = [];
        for ($i = 1; $i <= 3; $i++) {
            $rows[] = [
                'id'            => $i,
                'uuid'          => 'uuid-'.$i,
                'action'        => 'update',
                // Deliberately WRONG stored values, as a broken chain would
                // have: all three pointing at one predecessor.
                'hash'          => 'stale-hash-'.$i,
                'previous_hash' => 'one-shared-predecessor',
            ];
        }

        $written = [];
        $window  = 0;

        $this->db->method('getQueryBuilder')
            ->willReturnCallback(
                function () use ($rows, &$written, &$window): IQueryBuilder {
                    $expr = $this->createMock(IExpressionBuilder::class);
                    $expr->method('gt')->willReturn('gt');
                    $expr->method('eq')->willReturn('eq');

                    $qb = $this->createMock(IQueryBuilder::class);
                    $qb->method('select')->willReturnSelf();
                    $qb->method('from')->willReturnSelf();
                    $qb->method('where')->willReturnSelf();
                    $qb->method('andWhere')->willReturnSelf();
                    $qb->method('orderBy')->willReturnSelf();
                    $qb->method('setMaxResults')->willReturnSelf();
                    $qb->method('update')->willReturnSelf();
                    $qb->method('set')->willReturnSelf();
                    $qb->method('expr')->willReturn($expr);
                    $qb->method('executeStatement')->willReturn(1);

                    // Capture the values bound by each UPDATE, in order:
                    // [hash, previousHash, id].
                    $captured = [];
                    $qb->method('createNamedParameter')
                        ->willReturnCallback(
                            function (mixed $value) use (&$captured, &$written): string {
                                $captured[] = $value;
                                if (count($captured) === 3) {
                                    $written[] = [
                                        'hash'     => $captured[0],
                                        'previous' => $captured[1],
                                        'id'       => $captured[2],
                                    ];
                                }

                                return ':p';
                            }
                        );

                    $cursor = $this->createMock(IResult::class);
                    // First SELECT yields every row; the second ends the walk.
                    $cursor->method('fetchAll')->willReturn(($window === 0 ? $rows : []));
                    $qb->method('executeQuery')
                        ->willReturnCallback(
                            function () use ($cursor, &$window): IResult {
                                $window++;
                                return $cursor;
                            }
                        );

                    return $qb;
                }
            );

        $result = $this->service->rechainAll();

        $this->assertSame(3, $result['rechained']);
        $this->assertCount(3, $written);

        // Row 1 anchors on genesis; each later row anchors on its predecessor's
        // freshly computed hash — the property a fan-out cannot have.
        $expectedPrevious = $this->service->getGenesisHash();
        foreach ($rows as $index => $row) {
            $expectedHash = $this->service->computeHash($this->entityFromRow($row), $expectedPrevious);

            $this->assertSame($expectedPrevious, $written[$index]['previous']);
            $this->assertSame($expectedHash, $written[$index]['hash']);
            $this->assertSame($row['id'], $written[$index]['id']);

            $expectedPrevious = $expectedHash;
        }

        // And the stale values really were replaced rather than carried over.
        $this->assertNotSame('one-shared-predecessor', $written[1]['previous']);
        $this->assertNotSame('one-shared-predecessor', $written[2]['previous']);

    }//end testRechainAllProducesOneChainNotAFanOut()

    /**
     * rechainAll() refuses to run when the seal lock is held elsewhere.
     *
     * Rewriting hashes while another pass is writing them is how the fan-out
     * happened in the first place, so the repair must decline rather than
     * compete — and must report having written nothing, not silently return a
     * count that suggests it did.
     *
     * @return void
     *
     * @spec openspec/specs/audit-hash-chain/spec.md
     */
    public function testRechainAllAbortsWithoutTheSealLock(): void
    {
        $this->lockingProvider->method('acquireLock')
            ->willThrowException(new LockedException('audit-seal'));

        $this->db->expects($this->never())->method('getQueryBuilder');

        $result = $this->service->rechainAll();

        $this->assertSame(0, $result['rechained']);

    }//end testRechainAllAbortsWithoutTheSealLock()

    /**
     * getIntegrityStatus() reports coverage over a partly sealed trail.
     *
     * @return void
     *
     * @spec openspec/specs/audit-hash-chain/spec.md
     */
    public function testIntegrityStatusReportsCoverage(): void
    {
        // Queries run in order: total, unsealed (countUnsealed), max sealed id.
        $this->stubScalarQueries([1000, 250, 987]);

        $status = $this->service->getIntegrityStatus();

        $this->assertSame(1000, $status['total']);
        $this->assertSame(250, $status['unsealed']);
        $this->assertSame(750, $status['sealed']);
        $this->assertSame(75.0, $status['coverage']);
        $this->assertSame(987, $status['lastSealedId']);

    }//end testIntegrityStatusReportsCoverage()

    /**
     * An empty trail reports 100% rather than dividing by zero.
     *
     * A fresh install has no audit rows at all. Without the guard this is a
     * division by zero on a page an administrator opens, and "no entries" is
     * genuinely full coverage — there is nothing the chain has failed to vouch
     * for.
     *
     * @return void
     *
     * @spec openspec/specs/audit-hash-chain/spec.md
     */
    public function testIntegrityStatusOnEmptyTrailDoesNotDivideByZero(): void
    {
        $this->stubScalarQueries([0, 0, false]);

        $status = $this->service->getIntegrityStatus();

        $this->assertSame(0, $status['total']);
        $this->assertSame(0, $status['sealed']);
        $this->assertSame(100.0, $status['coverage']);
        $this->assertNull($status['lastSealedId'], 'With no sealed rows there is no last sealed id to report');

    }//end testIntegrityStatusOnEmptyTrailDoesNotDivideByZero()

    /**
     * Serve a fixed sequence of single-value query results.
     *
     * @param array<int, mixed> $values One value per executeQuery(), in order.
     *
     * @return void
     */
    private function stubScalarQueries(array $values): void
    {
        $index = 0;

        $this->db->method('getQueryBuilder')
            ->willReturnCallback(
                function () use ($values, &$index): IQueryBuilder {
                    $value = ($values[$index] ?? false);
                    $index++;

                    $cursor = $this->createMock(IResult::class);
                    $cursor->method('fetchOne')->willReturn($value);

                    $function = $this->createMock(IFunctionBuilder::class);
                    $function->method('count')->willReturn($this->createMock(IQueryFunction::class));
                    $function->method('max')->willReturn($this->createMock(IQueryFunction::class));

                    $expr = $this->createMock(IExpressionBuilder::class);
                    $expr->method('isNull')->willReturn('isnull');
                    $expr->method('isNotNull')->willReturn('isnotnull');

                    $qb = $this->createMock(IQueryBuilder::class);
                    $qb->method('select')->willReturnSelf();
                    $qb->method('from')->willReturnSelf();
                    $qb->method('where')->willReturnSelf();
                    $qb->method('func')->willReturn($function);
                    $qb->method('expr')->willReturn($expr);
                    $qb->method('executeQuery')->willReturn($cursor);

                    return $qb;
                }
            );

    }//end stubScalarQueries()
}//end class
