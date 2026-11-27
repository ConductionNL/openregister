<?php

/**
 * The search and embedding aggregates reader.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service;

use OCA\OpenRegister\Search\Service\SearchMetricsReader;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Search\Service\SearchMetricsReader
 */
final class SearchMetricsReaderTest extends TestCase {

	/**
	 * The query builder calls, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * The parameter values bound, in order.
	 *
	 * @var list<mixed>
	 */
	private array $bound = [];

	/**
	 * One grouped query over the three search types, with a bucket sum per bound.
	 *
	 * @return void
	 */
	public function testSearchStatsGroupsByTypeWithCumulativeBuckets(): void {
		$rows = [
			[
				'metric_type' => 'search_semantic',
				'total' => '30',
				'timed' => '28',
				'sum_ms' => '15000',
				'le_50' => '0',
				'le_100' => '1',
				'le_250' => '5',
				'le_500' => '10',
				'le_1000' => '20',
				'le_2500' => '27',
				'le_5000' => '28',
			],
			['metric_type' => 'search_unknown', 'total' => '9'],
		];

		$stats = (new SearchMetricsReader($this->connection(rows: $rows)))->searchStats();

		$this->assertSame(
			[
				'select(metric_type)',
				'selectAlias(COUNT(*), total)',
				'selectAlias(COUNT(duration_ms), timed)',
				'selectAlias(SUM(duration_ms), sum_ms)',
				'selectAlias(SUM(CASE WHEN duration_ms <= 50 THEN 1 ELSE 0 END), le_50)',
				'selectAlias(SUM(CASE WHEN duration_ms <= 100 THEN 1 ELSE 0 END), le_100)',
				'selectAlias(SUM(CASE WHEN duration_ms <= 250 THEN 1 ELSE 0 END), le_250)',
				'selectAlias(SUM(CASE WHEN duration_ms <= 500 THEN 1 ELSE 0 END), le_500)',
				'selectAlias(SUM(CASE WHEN duration_ms <= 1000 THEN 1 ELSE 0 END), le_1000)',
				'selectAlias(SUM(CASE WHEN duration_ms <= 2500 THEN 1 ELSE 0 END), le_2500)',
				'selectAlias(SUM(CASE WHEN duration_ms <= 5000 THEN 1 ELSE 0 END), le_5000)',
				'from(openregister_metrics)',
				'where(in(metric_type, :p0))',
				'groupBy(metric_type)',
				'executeQuery()',
			],
			$this->calls
		);
		$this->assertSame([[['search_keyword', 'search_semantic', 'search_hybrid'], IQueryBuilder::PARAM_STR_ARRAY]], $this->bound);
		$this->assertSame(['keyword', 'semantic', 'hybrid'], array_keys($stats));
		$this->assertSame(
			['total' => 30, 'timed' => 28, 'sumMs' => 15000.0, 'buckets' => [50 => 0, 100 => 1, 250 => 5, 500 => 10, 1000 => 20, 2500 => 27, 5000 => 28]],
			$stats['semantic']
		);
		$this->assertSame(
			['total' => 0, 'timed' => 0, 'sumMs' => 0.0, 'buckets' => [50 => 0, 100 => 0, 250 => 0, 500 => 0, 1000 => 0, 2500 => 0, 5000 => 0]],
			$stats['keyword']
		);
	}//end testSearchStatsGroupsByTypeWithCumulativeBuckets()

	/**
	 * Embedding rows group by status; success stays success, every other status is failure.
	 *
	 * @return void
	 */
	public function testEmbeddingCountsFoldNonSuccessIntoFailure(): void {
		$rows = [
			['status' => 'success', 'total' => '500'],
			['status' => 'error', 'total' => '10'],
			['status' => null, 'total' => '2'],
		];

		$counts = (new SearchMetricsReader($this->connection(rows: $rows)))->embeddingCounts();

		$this->assertSame(['success' => 500, 'failure' => 12], $counts);
		$this->assertSame(
			[
				'select(status)',
				'selectAlias(COUNT(*), total)',
				'from(openregister_metrics)',
				'where(eq(metric_type, :p0))',
				'groupBy(status)',
				'executeQuery()',
			],
			$this->calls
		);
		$this->assertSame([['embedding_generated', IQueryBuilder::PARAM_STR]], $this->bound);
	}//end testEmbeddingCountsFoldNonSuccessIntoFailure()

	/**
	 * No embedding rows reads as zero of each.
	 *
	 * @return void
	 */
	public function testNoEmbeddingRowsIsZero(): void {
		$this->assertSame(['success' => 0, 'failure' => 0], (new SearchMetricsReader($this->connection(rows: [])))->embeddingCounts());
	}//end testNoEmbeddingRowsIsZero()

	/**
	 * A connection whose query builder records its calls and answers the rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The result rows.
	 *
	 * @return IDBConnection
	 */
	private function connection(array $rows): IDBConnection {
		$result = $this->createMock(IResult::class);
		$result->expects($this->once())->method('fetchAll')->willReturn($rows);
		$result->expects($this->once())->method('closeCursor');

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn (string $c, mixed $v): string => 'eq('.$c.', '.$v.')');
		$expr->method('in')->willReturnCallback(static fn (string $c, mixed $v): string => 'in('.$c.', '.$v.')');

		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturnCallback(fn (string $column = ''): IQueryFunction => $this->queryFunction(sql: 'COUNT('.$column.')'));

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createFunction')->willReturnCallback(fn (string $sql): IQueryFunction => $this->queryFunction(sql: $sql));
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value, mixed $type = IQueryBuilder::PARAM_STR): string {
				$this->bound[] = [$value, $type];
				return ':p'.(count($this->bound) - 1);
			}
		);
		foreach (['select', 'from', 'where', 'groupBy'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (mixed $arg) use ($qb, $method): IQueryBuilder {
					$this->calls[] = $method.'('.(string) $arg.')';
					return $qb;
				}
			);
		}

		$qb->method('selectAlias')->willReturnCallback(
			function (mixed $select, string $alias) use ($qb): IQueryBuilder {
				$this->calls[] = 'selectAlias('.(string) $select.', '.$alias.')';
				return $qb;
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use ($result): IResult {
				$this->calls[] = 'executeQuery()';
				return $result;
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}//end connection()

	/**
	 * A query function that prints as the given SQL.
	 *
	 * @param string $sql The SQL.
	 *
	 * @return IQueryFunction
	 */
	private function queryFunction(string $sql): IQueryFunction {
		$function = $this->createMock(IQueryFunction::class);
		$function->method('__toString')->willReturn($sql);
		return $function;
	}//end queryFunction()

	/**
	 * The restated metric types are the ones MetricsService records.
	 *
	 * @return void
	 */
	public function testTheMetricTypesMatchTheSearchMetricsService(): void {
		$this->assertSame(\OCA\OpenRegister\Search\Service\MetricsService::METRIC_SEARCH_KEYWORD, SearchMetricsReader::METRIC_SEARCH_KEYWORD);
		$this->assertSame(\OCA\OpenRegister\Search\Service\MetricsService::METRIC_SEARCH_SEMANTIC, SearchMetricsReader::METRIC_SEARCH_SEMANTIC);
		$this->assertSame(\OCA\OpenRegister\Search\Service\MetricsService::METRIC_SEARCH_HYBRID, SearchMetricsReader::METRIC_SEARCH_HYBRID);
		$this->assertSame(\OCA\OpenRegister\Search\Service\MetricsService::METRIC_EMBEDDING_GENERATED, SearchMetricsReader::METRIC_EMBEDDING_GENERATED);
	}//end testTheMetricTypesMatchTheSearchMetricsService()

}//end class
