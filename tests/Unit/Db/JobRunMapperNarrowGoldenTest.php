<?php

/**
 * Golden test pinning the queries JobRunMapper builds for findRecent and countRecent.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\JobRunFilter;
use OCA\OpenRegister\Db\JobRunMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Pins the SQL calls and the bound parameters of the run-log reads.
 *
 * @covers \OCA\OpenRegister\Db\JobRunMapper
 */
final class JobRunMapperNarrowGoldenTest extends TestCase {

	/**
	 * Every query-builder call, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Every bound parameter, in order.
	 *
	 * @var array<int, array{0: mixed, 1: mixed}>
	 */
	private array $params = [];

	/**
	 * All four filters narrow the read, and the page is clamped.
	 *
	 * @return void
	 */
	public function testFindRecentWithEveryFilter(): void {
		$since = new DateTime('2026-01-02 03:04:05');
		$until = new DateTime('2026-01-03 04:05:06');

		$rows = $this->mapper(rows: [])->findRecent(
			filter: new JobRunFilter(jobClass: 'OCA\\Job', outcome: 'failed', since: $since, until: $until),
			limit: 900,
			offset: -4
		);

		$this->assertSame([], $rows);
		$this->assertSame(
			[
				'select(*)',
				'from(openregister_job_runs,)',
				'orderBy(started,DESC)',
				'addOrderBy(id,DESC)',
				'setMaxResults(500)',
				'setFirstResult(0)',
				'expr.eq(job_class,:p0)',
				'andWhere(eq:job_class)',
				'expr.eq(outcome,:p1)',
				'andWhere(eq:outcome)',
				'expr.gte(started,:p2)',
				'andWhere(gte:started)',
				'expr.lte(started,:p3)',
				'andWhere(lte:started)',
				'executeQuery',
			],
			$this->calls
		);
		$this->assertSame(
			[
				['OCA\\Job', IQueryBuilder::PARAM_STR],
				['failed', IQueryBuilder::PARAM_STR],
				[$since, IQueryBuilder::PARAM_DATETIME_MUTABLE],
				[$until, IQueryBuilder::PARAM_DATETIME_MUTABLE],
			],
			$this->params
		);
	}//end testFindRecentWithEveryFilter()

	/**
	 * Empty strings and nulls leave the read unnarrowed; a zero limit becomes one.
	 *
	 * @return void
	 */
	public function testFindRecentWithoutFilters(): void {
		$this->mapper(rows: [])->findRecent(filter: new JobRunFilter(jobClass: '', outcome: ''), limit: 0, offset: 7);

		$this->assertSame(
			[
				'select(*)',
				'from(openregister_job_runs,)',
				'orderBy(started,DESC)',
				'addOrderBy(id,DESC)',
				'setMaxResults(1)',
				'setFirstResult(7)',
				'executeQuery',
			],
			$this->calls
		);
		$this->assertSame([], $this->params);
	}//end testFindRecentWithoutFilters()

	/**
	 * The default page is the default limit from the first row.
	 *
	 * @return void
	 */
	public function testFindRecentDefaults(): void {
		$this->mapper(rows: [])->findRecent();

		$this->assertSame('setMaxResults(50)', $this->calls[4]);
		$this->assertSame('setFirstResult(0)', $this->calls[5]);
		$this->assertCount(7, $this->calls);
	}//end testFindRecentDefaults()

	/**
	 * The count narrows the same way and reads the total column.
	 *
	 * @return void
	 */
	public function testCountRecentNarrowsTheSameWay(): void {
		$since = new DateTime('2026-01-02 03:04:05');

		$total = $this->mapper(rows: [['total' => '12']])->countRecent(jobClass: 'OCA\\Job', since: $since);

		$this->assertSame(12, $total);
		$this->assertSame(
			[
				'func.count(*,total)',
				'select(COUNT)',
				'from(openregister_job_runs,)',
				'expr.eq(job_class,:p0)',
				'andWhere(eq:job_class)',
				'expr.gte(started,:p1)',
				'andWhere(gte:started)',
				'executeQuery',
			],
			$this->calls
		);
		$this->assertSame(
			[
				['OCA\\Job', IQueryBuilder::PARAM_STR],
				[$since, IQueryBuilder::PARAM_DATETIME_MUTABLE],
			],
			$this->params
		);
	}//end testCountRecentNarrowsTheSameWay()

	/**
	 * A count with no row answers zero.
	 *
	 * @return void
	 */
	public function testCountRecentWithoutARowIsZero(): void {
		$this->assertSame(0, $this->mapper(rows: [])->countRecent(outcome: 'success'));
		$this->assertSame([['success', IQueryBuilder::PARAM_STR]], $this->params);
	}//end testCountRecentWithoutARowIsZero()

	/**
	 * A mapper over a query builder that records what it is asked.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows the query serves.
	 *
	 * @return JobRunMapper The mapper.
	 */
	private function mapper(array $rows): JobRunMapper {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($this->builder(rows: $rows));

		return new JobRunMapper(db: $db);
	}//end mapper()

	/**
	 * The recording query builder.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows the query serves.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function builder(array $rows): IQueryBuilder {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'orderBy', 'addOrderBy', 'setMaxResults', 'setFirstResult', 'andWhere'] as $name) {
			$qb->method($name)->willReturnCallback(
				function (mixed ...$args) use ($qb, $name): IQueryBuilder {
					$this->calls[] = $name.'('.implode(',', array_map(static fn (mixed $arg): string => (string)$arg, $args)).')';
					return $qb;
				}
			);
		}

		$qb->method('expr')->willReturn($this->expressions());
		$qb->method('func')->willReturn($this->functions());
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value, mixed $type = IQueryBuilder::PARAM_STR): string {
				$this->params[] = [$value, $type];
				return ':p'.(count($this->params) - 1);
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use ($rows): IResult {
				$this->calls[] = 'executeQuery';
				$result = $this->createMock(IResult::class);
				$result->method('fetch')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
				$result->method('fetchAll')->willReturn($rows);
				$result->method('fetchAssociative')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));
				return $result;
			}
		);

		return $qb;
	}//end builder()

	/**
	 * An expression builder that records each comparison.
	 *
	 * @return IExpressionBuilder The expression builder.
	 */
	private function expressions(): IExpressionBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'gte', 'lte'] as $name) {
			$expr->method($name)->willReturnCallback(
				function (string $column, string $param) use ($name): string {
					$this->calls[] = 'expr.'.$name.'('.$column.','.$param.')';
					return $name.':'.$column;
				}
			);
		}

		return $expr;
	}//end expressions()

	/**
	 * A function builder that records the count.
	 *
	 * @return IFunctionBuilder The function builder.
	 */
	private function functions(): IFunctionBuilder {
		$count = $this->createMock(IQueryFunction::class);
		$count->method('__toString')->willReturn('COUNT');

		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturnCallback(
			function (mixed $input, string $alias) use ($count): IQueryFunction {
				$this->calls[] = 'func.count('.$input.','.$alias.')';
				return $count;
			}
		);

		return $func;
	}//end functions()
}//end class
