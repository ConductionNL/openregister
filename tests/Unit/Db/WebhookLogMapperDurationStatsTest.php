<?php

/**
 * The webhook delivery duration aggregate: cumulative buckets over timed rows.
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

use OCA\OpenRegister\Db\WebhookLogMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\WebhookLogMapper::deliveryDurationStats
 */
final class WebhookLogMapperDurationStatsTest extends TestCase {

	/**
	 * The query builder calls, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * One aggregate query: timed count, sum and a bucket sum per bound; the row maps to integers.
	 *
	 * @return void
	 */
	public function testAggregatesTimedRowsIntoCumulativeBuckets(): void {
		$result = $this->createMock(IResult::class);
		$result->expects($this->once())->method('fetch')->willReturn(['timed' => '5', 'sum_ms' => '3200', 'le_100' => '1', 'le_1000' => '4']);
		$result->expects($this->once())->method('closeCursor');

		$stats = (new WebhookLogMapper($this->connection(result: $result)))->deliveryDurationStats([100, 1000]);

		$this->assertSame(['timed' => 5, 'sumMs' => 3200.0, 'buckets' => [100 => 1, 1000 => 4]], $stats);
		$this->assertSame(
			[
				'selectAlias(COUNT(duration_ms), timed)',
				'selectAlias(SUM(duration_ms), sum_ms)',
				'selectAlias(SUM(CASE WHEN duration_ms <= 100 THEN 1 ELSE 0 END), le_100)',
				'selectAlias(SUM(CASE WHEN duration_ms <= 1000 THEN 1 ELSE 0 END), le_1000)',
				'from(openregister_webhook_logs)',
				'executeQuery()',
			],
			$this->calls
		);
	}//end testAggregatesTimedRowsIntoCumulativeBuckets()

	/**
	 * No row reads as zeros.
	 *
	 * @return void
	 */
	public function testNoRowIsZero(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);

		$this->assertSame(
			['timed' => 0, 'sumMs' => 0.0, 'buckets' => [250 => 0]],
			(new WebhookLogMapper($this->connection(result: $result)))->deliveryDurationStats([250])
		);
	}//end testNoRowIsZero()

	/**
	 * A connection whose query builder records its calls and answers the result.
	 *
	 * @param IResult $result The query result.
	 *
	 * @return IDBConnection
	 */
	private function connection(IResult $result): IDBConnection {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('createFunction')->willReturnCallback(
			function (string $sql): IQueryFunction {
				$function = $this->createMock(IQueryFunction::class);
				$function->method('__toString')->willReturn($sql);
				return $function;
			}
		);
		$qb->method('selectAlias')->willReturnCallback(
			function (mixed $select, string $alias) use ($qb): IQueryBuilder {
				$this->calls[] = 'selectAlias('.(string) $select.', '.$alias.')';
				return $qb;
			}
		);
		$qb->method('from')->willReturnCallback(
			function (mixed $table) use ($qb): IQueryBuilder {
				$this->calls[] = 'from('.(string) $table.')';
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
}//end class
