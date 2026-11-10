<?php

/**
 * Golden test pinning the stuck-where queries CaseItemMapper builds.
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

use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\CaseItemMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Pins the page and the total over the type-and-state predicate, with their parameters.
 *
 * @covers \OCA\OpenRegister\Db\CaseItemMapper
 */
final class CaseItemMapperTypeAndStateGoldenTest extends TestCase {

	/**
	 * Every query-builder call, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Both filters narrow the page, and the page is clamped.
	 *
	 * @return void
	 */
	public function testThePageWithBothFilters(): void {
		$page = $this->mapper(total: 0)->findByTypeAndState(query: new ListQuery(limit: 0, offset: -3, filters: ['type' => 'humanTask', 'state' => ' active ']));

		$this->assertSame([], $page);
		$this->assertSame(
			[
				'select(*)',
				'from(openregister_case_items,)',
				'param(humanTask)',
				'expr.eq(plan_item_type,:humanTask)',
				'andWhere(eq:plan_item_type)',
				'param( active )',
				'expr.eq(state,: active )',
				'andWhere(eq:state)',
				'orderBy(id,ASC)',
				'setMaxResults(1)',
				'setFirstResult(0)',
				'executeQuery',
			],
			$this->calls
		);
	}//end testThePageWithBothFilters()

	/**
	 * Null and blank filters leave the page unnarrowed.
	 *
	 * @return void
	 */
	public function testThePageWithoutFilters(): void {
		$this->mapper(total: 0)->findByTypeAndState(query: new ListQuery(limit: 7, offset: 14, filters: ['type' => '  ', 'state' => null]));

		$this->assertSame(
			[
				'select(*)',
				'from(openregister_case_items,)',
				'orderBy(id,ASC)',
				'setMaxResults(7)',
				'setFirstResult(14)',
				'executeQuery',
			],
			$this->calls
		);
	}//end testThePageWithoutFilters()

	/**
	 * The total narrows the same way.
	 *
	 * @return void
	 */
	public function testTheTotalNarrowsTheSameWay(): void {
		$total = $this->mapper(total: 4)->countByTypeAndState(type: null, state: 'failed');

		$this->assertSame(4, $total);
		$this->assertSame(
			[
				'func.count(*,total)',
				'select(COUNT)',
				'from(openregister_case_items,)',
				'param(failed)',
				'expr.eq(state,:failed)',
				'andWhere(eq:state)',
				'executeQuery',
			],
			$this->calls
		);
	}//end testTheTotalNarrowsTheSameWay()

	/**
	 * The mapper over a recording query builder.
	 *
	 * @param int $total What a count query answers.
	 *
	 * @return CaseItemMapper The mapper.
	 */
	private function mapper(int $total): CaseItemMapper {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'andWhere', 'orderBy', 'setMaxResults', 'setFirstResult'] as $name) {
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
			function (mixed $value): string {
				$this->calls[] = 'param('.$value.')';
				return ':'.$value;
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use ($total): IResult {
				$this->calls[] = 'executeQuery';
				$result = $this->createMock(IResult::class);
				$result->method('fetch')->willReturn(false);
				$result->method('fetchOne')->willReturn((string)$total);
				return $result;
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new CaseItemMapper(db: $db);
	}//end mapper()

	/**
	 * An expression builder that records eq.
	 *
	 * @return IExpressionBuilder The expression builder.
	 */
	private function expressions(): IExpressionBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			function (string $column, string $param): string {
				$this->calls[] = 'expr.eq('.$column.','.$param.')';
				return 'eq:'.$column;
			}
		);

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
