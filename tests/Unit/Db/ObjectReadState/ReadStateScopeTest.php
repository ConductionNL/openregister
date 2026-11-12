<?php

/**
 * A read-state scope narrows a query on register and schema only when each is given.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\ObjectReadState;

use OCA\OpenRegister\Db\ObjectReadState\ReadStateScope;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\ObjectReadState\ReadStateScope
 */
class ReadStateScopeTest extends TestCase {

	public function testBothColumnsAreConstrainedInOrder(): void {
		$qb   = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->expects($this->exactly(2))
			->method('createNamedParameter')
			->willReturnCallback(static fn ($value) => ':p_' . $value);
		$expr->expects($this->exactly(2))
			->method('eq')
			->willReturnCallback(static fn ($column, $param) => $column . '=' . $param);

		$where = [];
		$qb->expects($this->exactly(2))
			->method('andWhere')
			->willReturnCallback(
				function ($clause) use (&$where, $qb) {
					$where[] = $clause;
					return $qb;
				}
			);

		(new ReadStateScope(register: '5', schema: '7'))->applyTo($qb);

		$this->assertSame(['register=:p_5', 'schema=:p_7'], $where);
	}//end testBothColumnsAreConstrainedInOrder()

	public function testOnlyTheGivenColumnIsConstrained(): void {
		$qb   = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->expects($this->once())->method('createNamedParameter')->with('7')->willReturn(':s');
		$expr->expects($this->once())->method('eq')->with('schema', ':s')->willReturn('schema = :s');
		$qb->expects($this->once())->method('andWhere')->with('schema = :s')->willReturnSelf();

		(new ReadStateScope(register: '', schema: '7'))->applyTo($qb);
	}//end testOnlyTheGivenColumnIsConstrained()

	public function testEmptyScopeLeavesTheQueryAlone(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->expects($this->never())->method('andWhere');
		$qb->expects($this->never())->method('createNamedParameter');

		(new ReadStateScope())->applyTo($qb);
		(new ReadStateScope(register: '', schema: ''))->applyTo($qb);
	}//end testEmptyScopeLeavesTheQueryAlone()
}//end class
