<?php

/**
 * The cumulative request metrics rows.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Observability;

use OCA\OpenRegister\Service\Observability\RequestMetricsStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Observability\RequestMetricsStore
 */
final class RequestMetricsStoreTest extends TestCase {

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
	 * An existing row is added to in place and stamped with the flush time; nothing is inserted.
	 *
	 * @return void
	 */
	public function testAddUpdatesAnExistingRow(): void {
		$this->store(updated: 1)->add(metricType: 'http_request', entityType: 'GET', entityId: 'objects.index', status: '200', count: 5, sumMs: 300);

		$this->assertSame(
			[
				'update(openregister_metrics)',
				'set(sample_count, COALESCE(sample_count, 0) + :p0)',
				'set(sample_sum_ms, COALESCE(sample_sum_ms, 0) + :p1)',
				'set(created_at, :p2)',
				'where(eq(metric_type, :p3))',
				'andWhere(eq(entity_type, :p4))',
				'andWhere(eq(entity_id, :p5))',
				'andWhere(eq(status, :p6))',
				'executeStatement()',
			],
			$this->calls
		);
		$this->assertSame([5, 300, 1700000000, 'http_request', 'GET', 'objects.index', '200'], $this->bound);
	}//end testAddUpdatesAnExistingRow()

	/**
	 * Without an existing row one is inserted; over-long parts are cut to the column lengths.
	 *
	 * @return void
	 */
	public function testAddInsertsWhenNoRowMatched(): void {
		$route = str_repeat('r', 80);
		$this->store(updated: 0)->add(metricType: 'http_error', entityType: 'validation', entityId: $route, status: '', count: 2, sumMs: 0);

		$this->assertSame('insert(openregister_metrics)', $this->calls[9]);
		$this->assertSame('executeStatement()', $this->calls[11]);
		$this->assertSame(
			['http_error', 'validation', str_repeat('r', 64), '', 2, 0, 1700000000],
			array_slice($this->bound, 7)
		);
	}//end testAddInsertsWhenNoRowMatched()

	/**
	 * Request, bucket and error rows are read grouped per label set and typed.
	 *
	 * @return void
	 */
	public function testTotalsAreGroupedAndTyped(): void {
		$rows = [['entity_type' => 'GET', 'entity_id' => 'objects.index', 'status' => '200', 'total' => '7', 'sum_ms' => '900']];

		$this->assertSame(
			[['method' => 'GET', 'route' => 'objects.index', 'status' => '200', 'count' => 7, 'sumMs' => 900]],
			$this->store(rows: $rows)->requestTotals()
		);
		$this->assertContains('where(eq(metric_type, :p0))', $this->calls);
		$this->assertSame(['http_request'], $this->bound);
		$this->assertContains('groupBy(entity_type, entity_id, status)', $this->calls);

		$this->assertSame(
			[['method' => 'GET', 'route' => 'objects.index', 'bucket' => '200', 'count' => 7]],
			$this->store(rows: $rows)->bucketTotals()
		);

		$errors = [['entity_type' => 'database', 'entity_id' => '', 'status' => '', 'total' => '2', 'sum_ms' => null], ['entity_type' => 'database', 'entity_id' => null, 'status' => null, 'total' => '1', 'sum_ms' => null]];
		$this->assertSame(['database' => 3], $this->store(rows: $errors)->errorTotals());
	}//end testTotalsAreGroupedAndTyped()

	/**
	 * A store over a recording query builder.
	 *
	 * @param int                              $updated The rows the UPDATE reports.
	 * @param array<int, array<string, mixed>> $rows    The rows a SELECT answers.
	 *
	 * @return RequestMetricsStore
	 */
	private function store(int $updated = 0, array $rows = []): RequestMetricsStore {
		$this->calls = [];
		$this->bound = [];

		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn (string $c, mixed $v): string => 'eq('.$c.', '.$v.')');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createFunction')->willReturnCallback(
			function (string $sql): IQueryFunction {
				$function = $this->createMock(IQueryFunction::class);
				$function->method('__toString')->willReturn($sql);
				return $function;
			}
		);
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value): string {
				$this->bound[] = $value;
				return ':p'.(count($this->bound) - 1);
			}
		);
		foreach (['update', 'insert', 'from', 'where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (mixed $arg) use ($qb, $method): IQueryBuilder {
					$this->calls[] = $method.'('.(string) $arg.')';
					return $qb;
				}
			);
		}

		foreach (['select', 'groupBy'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (mixed ...$args) use ($qb, $method): IQueryBuilder {
					$this->calls[] = $method.'('.implode(', ', $args).')';
					return $qb;
				}
			);
		}

		$qb->method('set')->willReturnCallback(
			function (string $key, mixed $value) use ($qb): IQueryBuilder {
				$this->calls[] = 'set('.$key.', '.(string) $value.')';
				return $qb;
			}
		);
		$qb->method('values')->willReturnCallback(
			function (array $values) use ($qb): IQueryBuilder {
				$this->calls[] = 'values('.implode(', ', array_keys($values)).')';
				return $qb;
			}
		);
		$qb->method('selectAlias')->willReturn($qb);
		$qb->method('executeStatement')->willReturnCallback(
			function () use ($updated): int {
				$this->calls[] = 'executeStatement()';
				return $updated;
			}
		);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1700000000);

		return new RequestMetricsStore(db: $db, time: $time);
	}//end store()
}//end class
