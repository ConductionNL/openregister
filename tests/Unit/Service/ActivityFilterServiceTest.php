<?php

/**
 * Unit tests for ActivityFilterService — the Tier-2 read surface for the
 * `activity` integration leaf.
 *
 * Covers the availability gate, cursor-based pagination (limit+1 probe,
 * slicing, nextCursor derivation), row normalisation, and the distinct
 * type/actor dropdown helpers. The DB layer is exercised through a
 * fluent IQueryBuilder mock that returns staged rows.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-activity/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\ActivityFilterService;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ActivityFilterService.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ActivityFilterServiceTest extends TestCase
{
    /**
     * Build a service whose DB connection yields a query builder that
     * returns the supplied rows from executeQuery()->fetch().
     *
     * @param array<int,array<string,mixed>> $rows      Rows to stage.
     * @param bool                           $installed App availability.
     *
     * @return ActivityFilterService
     */
    private function buildService(array $rows, bool $installed=true): ActivityFilterService
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isInstalled')->willReturn($installed);

        $db = $this->createMock(IDBConnection::class);
        if ($installed === true) {
            $db->method('getQueryBuilder')->willReturnCallback(
                function () use ($rows): IQueryBuilder {
                    return $this->makeQueryBuilder($rows);
                }
            );
        }

        return new ActivityFilterService(db: $db, appManager: $appManager);
    }//end buildService()

    /**
     * Build a fluent IQueryBuilder mock returning the staged rows.
     *
     * @param array<int,array<string,mixed>> $rows Rows to stage.
     *
     * @return IQueryBuilder
     */
    private function makeQueryBuilder(array $rows): IQueryBuilder
    {
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('iLike')->willReturn('ilike');
        $expr->method('eq')->willReturn('eq');
        $expr->method('gte')->willReturn('gte');
        $expr->method('lt')->willReturn('lt');

        $result = $this->createMock(\OCP\DB\IResult::class);
        $queue  = $rows;
        $result->method('fetch')->willReturnCallback(
            function () use (&$queue) {
                if (empty($queue) === true) {
                    return false;
                }

                return array_shift($queue);
            }
        );
        $result->method('closeCursor')->willReturn(true);

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('expr')->willReturn($expr);
        $qb->method('func')->willReturn($this->makeFunc());
        $qb->method('select')->willReturnSelf();
        $qb->method('selectDistinct')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('addOrderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('createNamedParameter')->willReturn('?');
        $qb->method('executeQuery')->willReturn($result);

        return $qb;
    }//end makeQueryBuilder()

    /**
     * Build a func() stub whose count() returns a placeholder.
     *
     * @return \OCP\DB\QueryBuilder\IFunctionBuilder
     */
    private function makeFunc(): \OCP\DB\QueryBuilder\IFunctionBuilder
    {
        $func = $this->createMock(\OCP\DB\QueryBuilder\IFunctionBuilder::class);
        $func->method('count')->willReturn($this->createMock(IQueryFunction::class));
        return $func;
    }//end makeFunc()

    public function testReturnsEmptyWhenAppMissing(): void
    {
        $service = $this->buildService(rows: [], installed: false);
        self::assertFalse($service->isActivityAvailable());

        $result = $service->getActivityEntries(objectUuid: 'u');
        self::assertSame([], $result['results']);
        self::assertSame(0, $result['total']);
        self::assertNull($result['nextCursor']);
    }//end testReturnsEmptyWhenAppMissing()

    public function testNormalisesAndReturnsRows(): void
    {
        $rows    = [
            [
                'activity_id'  => 5,
                'subject'      => 'Alice changed X [or:u]',
                'type'         => 'files',
                'timestamp'    => 1779002099,
                'affecteduser' => 'alice',
                'object_id'    => 'u',
            ],
        ];
        $service = $this->buildService(rows: $rows);

        $result = $service->getActivityEntries(objectUuid: 'u', limit: 10);
        self::assertCount(1, $result['results']);
        $row = $result['results'][0];
        self::assertSame('5', $row['id']);
        self::assertSame('files', $row['type']);
        self::assertSame(1779002099, $row['timestamp']);
        self::assertSame('alice', $row['actor_id']);
        self::assertSame('/index.php/apps/activity/5', $row['url']);
        // Limit not exceeded, so there is no further page.
        self::assertNull($result['nextCursor']);
    }//end testNormalisesAndReturnsRows()

    public function testCursorPaginationSlicesAndSetsNextCursor(): void
    {
        // Stage limit+1 (=3) rows for a limit of 2; service must slice to
        // 2 and surface the last kept row's timestamp as the next cursor.
        $rows    = [
            ['activity_id' => 3, 'subject' => 'c [or:u]', 'type' => 'files', 'timestamp' => 300, 'affecteduser' => 'a', 'object_id' => 'u'],
            ['activity_id' => 2, 'subject' => 'b [or:u]', 'type' => 'files', 'timestamp' => 200, 'affecteduser' => 'a', 'object_id' => 'u'],
            ['activity_id' => 1, 'subject' => 'a [or:u]', 'type' => 'files', 'timestamp' => 100, 'affecteduser' => 'a', 'object_id' => 'u'],
        ];
        $service = $this->buildService(rows: $rows);

        $result = $service->getActivityEntries(objectUuid: 'u', limit: 2);
        self::assertCount(2, $result['results']);
        self::assertSame(200, $result['nextCursor']);
    }//end testCursorPaginationSlicesAndSetsNextCursor()

    public function testGetActivityTypesReturnsDistinctValues(): void
    {
        $rows    = [
            ['type' => 'files'],
            ['type' => 'or:decision'],
            ['type' => ''],
        ];
        $service = $this->buildService(rows: $rows);

        $types = $service->getActivityTypes(objectUuid: 'u');
        self::assertSame(['files', 'or:decision'], $types);
    }//end testGetActivityTypesReturnsDistinctValues()

    public function testGetActivityActorsReturnsDistinctValues(): void
    {
        $rows    = [
            ['affecteduser' => 'alice'],
            ['affecteduser' => 'bob'],
        ];
        $service = $this->buildService(rows: $rows);

        $actors = $service->getActivityActors(objectUuid: 'u');
        self::assertSame(['alice', 'bob'], $actors);
    }//end testGetActivityActorsReturnsDistinctValues()

    public function testTypesEmptyWhenAppMissing(): void
    {
        $service = $this->buildService(rows: [], installed: false);
        self::assertSame([], $service->getActivityTypes(objectUuid: 'u'));
        self::assertSame([], $service->getActivityActors(objectUuid: 'u'));
    }//end testTypesEmptyWhenAppMissing()
}//end class
