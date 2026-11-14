<?php

/**
 * The webhook retry queue count: failed rows whose retry is still ahead.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\WebhookLogMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\WebhookLogMapper::countPendingRetries
 */
final class WebhookLogMapperPendingRetriesTest extends TestCase {

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
	 * The count selects failed rows whose retry time is after now, and reads one integer.
	 *
	 * @return void
	 */
	public function testCountsFailedRowsWhoseRetryIsStillAhead(): void {
		$now    = new DateTime('2026-11-13 12:00:00');
		$result = $this->createMock(IResult::class);
		$result->expects($this->once())->method('fetchOne')->willReturn('3');
		$result->expects($this->once())->method('closeCursor');

		$count = (new WebhookLogMapper($this->connection(result: $result)))->countPendingRetries($now);

		$this->assertSame(3, $count);
		$this->assertSame(
			[
				'select(COUNT(*) AS cnt)',
				'from(openregister_webhook_logs)',
				'where(eq(success, :p0))',
				'andWhere(isNotNull(next_retry_at))',
				'andWhere(gt(next_retry_at, :p1))',
				'executeQuery()',
			],
			$this->calls
		);
		$this->assertSame([[false, IQueryBuilder::PARAM_BOOL], [$now, IQueryBuilder::PARAM_DATETIME_MUTABLE]], $this->bound);
	}//end testCountsFailedRowsWhoseRetryIsStillAhead()

	/**
	 * No row reads as zero.
	 *
	 * @return void
	 */
	public function testNoRowIsZero(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn(false);

		$this->assertSame(0, (new WebhookLogMapper($this->connection(result: $result)))->countPendingRetries(new DateTime()));
	}//end testNoRowIsZero()

	/**
	 * A connection whose query builder records its calls and answers the result.
	 *
	 * @param IResult $result The query result.
	 *
	 * @return IDBConnection
	 */
	private function connection(IResult $result): IDBConnection {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn (string $c, mixed $v): string => 'eq('.$c.', '.$v.')');
		$expr->method('gt')->willReturnCallback(static fn (string $c, mixed $v): string => 'gt('.$c.', '.$v.')');
		$expr->method('isNotNull')->willReturnCallback(static fn (string $c): string => 'isNotNull('.$c.')');

		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturnCallback(
			function (string $column, string $alias): IQueryFunction {
				$function = $this->createMock(IQueryFunction::class);
				$function->method('__toString')->willReturn('COUNT('.$column.') AS '.$alias);
				return $function;
			}
		);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value, mixed $type): string {
				$this->bound[] = [$value, $type];
				return ':p'.(count($this->bound) - 1);
			}
		);
		foreach (['select', 'from', 'where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(
				function (mixed $arg) use ($qb, $method): IQueryBuilder {
					$this->calls[] = $method.'('.(string) $arg.')';
					return $qb;
				}
			);
		}

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
}//end class
