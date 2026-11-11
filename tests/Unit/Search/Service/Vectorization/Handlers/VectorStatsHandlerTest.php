<?php

/**
 * Unit tests for VectorStatsHandler: the three count queries and the failure fallback.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Vectorization\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Vectorization\Handlers;

use Exception;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStatsHandler;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorStatsHandler
 */
class VectorStatsHandlerTest extends TestCase
{
    /** @var list<array{select: array<int, mixed>, from: string, groupBy: ?string}> */
    private array $queries = [];

    /**
     * Builds a query builder that records select/from/groupBy and answers with $result.
     */
    private function queryBuilder(IResult $result): IQueryBuilder
    {
        $index = count($this->queries);
        $this->queries[$index] = ['select' => [], 'from' => '', 'groupBy' => null];

        $func = $this->createMock(IFunctionBuilder::class);
        $func->method('count')->willReturnCallback(
            function (string $column, string $alias): IQueryFunction {
                $fn = $this->createMock(IQueryFunction::class);
                $fn->method('__toString')->willReturn('COUNT('.$column.') AS '.$alias);
                return $fn;
            }
        );

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('func')->willReturn($func);
        $qb->method('select')->willReturnCallback(
            function (...$columns) use ($qb, $index): IQueryBuilder {
                $this->queries[$index]['select'] = array_map(static fn($c): string => (string) $c, $columns);
                return $qb;
            }
        );
        $qb->method('from')->willReturnCallback(
            function (string $table) use ($qb, $index): IQueryBuilder {
                $this->queries[$index]['from'] = $table;
                return $qb;
            }
        );
        $qb->method('groupBy')->willReturnCallback(
            function (string $column) use ($qb, $index): IQueryBuilder {
                $this->queries[$index]['groupBy'] = $column;
                return $qb;
            }
        );
        $qb->method('executeQuery')->willReturn($result);
        return $qb;
    }//end queryBuilder()

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function rows(array $rows): IResult
    {
        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
        $result->expects($this->once())->method('closeCursor');
        return $result;
    }//end rows()

    public function testStatsAggregateTotalTypeAndModelCounts(): void
    {
        $total = $this->createMock(IResult::class);
        $total->method('fetchOne')->willReturn('7');

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
            $this->queryBuilder($total),
            $this->queryBuilder(
                $this->rows([['entity_type' => 'object', 'count' => '5'], ['entity_type' => 'file', 'count' => '2']])
            ),
            $this->queryBuilder(
                $this->rows([['embedding_model' => 'nomic', 'count' => '4'], ['embedding_model' => 'ada', 'count' => '3']])
            )
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $stats = (new VectorStatsHandler($db, $logger))->getStats();

        $this->assertSame(
            [
                'total_vectors'  => 7,
                'by_type'        => ['object' => 5, 'file' => 2],
                'by_model'       => ['nomic' => 4, 'ada' => 3],
                'object_vectors' => 5,
                'file_vectors'   => 2,
            ],
            $stats
        );
        $this->assertSame(
            [
                ['select' => ['COUNT(id) AS total'], 'from' => 'openregister_vectors', 'groupBy' => null],
                ['select' => ['entity_type', 'COUNT(id) AS count'], 'from' => 'openregister_vectors', 'groupBy' => 'entity_type'],
                ['select' => ['embedding_model', 'COUNT(id) AS count'], 'from' => 'openregister_vectors', 'groupBy' => 'embedding_model'],
            ],
            $this->queries
        );
    }//end testStatsAggregateTotalTypeAndModelCounts()

    public function testEmptyTableYieldsZeroCountsAndEmptyGroups(): void
    {
        $total = $this->createMock(IResult::class);
        $total->method('fetchOne')->willReturn(false);

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
            $this->queryBuilder($total),
            $this->queryBuilder($this->rows([])),
            $this->queryBuilder($this->rows([]))
        );

        $stats = (new VectorStatsHandler($db, $this->createMock(LoggerInterface::class)))->getStats();

        $this->assertSame(
            ['total_vectors' => 0, 'by_type' => [], 'by_model' => [], 'object_vectors' => 0, 'file_vectors' => 0],
            $stats
        );
    }//end testEmptyTableYieldsZeroCountsAndEmptyGroups()

    public function testOnlyChunkTypesLeaveObjectAndFileCountsAtZero(): void
    {
        $total = $this->createMock(IResult::class);
        $total->method('fetchOne')->willReturn(3);

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnOnConsecutiveCalls(
            $this->queryBuilder($total),
            $this->queryBuilder($this->rows([['entity_type' => 'chunk', 'count' => 3]])),
            $this->queryBuilder($this->rows([['embedding_model' => 'm', 'count' => 3]]))
        );

        $stats = (new VectorStatsHandler($db, $this->createMock(LoggerInterface::class)))->getStats();

        $this->assertSame(['chunk' => 3], $stats['by_type']);
        $this->assertSame(0, $stats['object_vectors']);
        $this->assertSame(0, $stats['file_vectors']);
    }//end testOnlyChunkTypesLeaveObjectAndFileCountsAtZero()

    public function testDatabaseFailureIsLoggedAndYieldsEmptyStats(): void
    {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willThrowException(new Exception('table missing'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                '[VectorStatsHandler] Failed to get vector stats',
                $this->callback(static fn(array $ctx): bool => $ctx['error'] === 'table missing')
            );

        $stats = (new VectorStatsHandler($db, $logger))->getStats();

        $this->assertSame(
            ['total_vectors' => 0, 'by_type' => [], 'by_model' => [], 'object_vectors' => 0, 'file_vectors' => 0],
            $stats
        );
    }//end testDatabaseFailureIsLoggedAndYieldsEmptyStats()
}//end class
