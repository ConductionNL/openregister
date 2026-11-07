<?php

/**
 * Golden test pinning the query TimeTrackerLinkMapper::findByObjectAndEntry builds.
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

use OCA\OpenRegister\Db\TimeTrackerLinkMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Pins each condition and parameter; a null id component is matched with IS NULL.
 *
 * @covers \OCA\OpenRegister\Db\TimeTrackerLinkMapper
 */
final class TimeTrackerLinkMapperFindByEntryGoldenTest extends TestCase {

	/**
	 * Every query-builder call, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * A time entry under a task under a client: every component compared.
	 *
	 * @return void
	 */
	public function testEveryComponentGiven(): void {
		$link = $this->mapper()->findByObjectAndEntry(
			objectUuid: 'obj-1',
			entryType: 'time',
			clientId: 'c-1',
			taskId: 't-1',
			timeId: 'e-1'
		);

		$this->assertNull($link);
		$this->assertSame(
			[
				'select(*)',
				'from(openregister_timetracker_links,)',
				'param(obj-1)',
				'expr.eq(object_uuid,:obj-1)',
				'where(eq:object_uuid)',
				'param(time)',
				'expr.eq(entry_type,:time)',
				'andWhere(eq:entry_type)',
				'param(c-1)',
				'expr.eq(client_id,:c-1)',
				'andWhere(eq:client_id)',
				'param(t-1)',
				'expr.eq(task_id,:t-1)',
				'andWhere(eq:task_id)',
				'param(e-1)',
				'expr.eq(time_id,:e-1)',
				'andWhere(eq:time_id)',
				'executeQuery',
			],
			$this->calls
		);
	}//end testEveryComponentGiven()

	/**
	 * A client link: the task and time components are IS NULL.
	 *
	 * @return void
	 */
	public function testNullComponentsAreIsNull(): void {
		$this->mapper()->findByObjectAndEntry(
			objectUuid: 'obj-2',
			entryType: 'client',
			clientId: 'c-2',
			taskId: null,
			timeId: null
		);

		$this->assertSame(
			[
				'param(c-2)',
				'expr.eq(client_id,:c-2)',
				'andWhere(eq:client_id)',
				'expr.isNull(task_id)',
				'andWhere(isNull:task_id)',
				'expr.isNull(time_id)',
				'andWhere(isNull:time_id)',
				'executeQuery',
			],
			array_slice($this->calls, 8)
		);
	}//end testNullComponentsAreIsNull()

	/**
	 * An empty string is compared, not treated as null.
	 *
	 * @return void
	 */
	public function testAnEmptyComponentIsCompared(): void {
		$this->mapper()->findByObjectAndEntry(
			objectUuid: 'obj-3',
			entryType: 'task',
			clientId: null,
			taskId: '',
			timeId: null
		);

		$this->assertSame(
			[
				'expr.isNull(client_id)',
				'andWhere(isNull:client_id)',
				'param()',
				'expr.eq(task_id,:)',
				'andWhere(eq:task_id)',
				'expr.isNull(time_id)',
				'andWhere(isNull:time_id)',
				'executeQuery',
			],
			array_slice($this->calls, 8)
		);
	}//end testAnEmptyComponentIsCompared()

	/**
	 * The mapper over a recording query builder whose query finds nothing.
	 *
	 * @return TimeTrackerLinkMapper The mapper.
	 */
	private function mapper(): TimeTrackerLinkMapper {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'andWhere'] as $name) {
			$qb->method($name)->willReturnCallback(
				function (mixed ...$args) use ($qb, $name): IQueryBuilder {
					$this->calls[] = $name.'('.implode(',', array_map(static fn (mixed $arg): string => (string)$arg, $args)).')';
					return $qb;
				}
			);
		}

		$qb->method('expr')->willReturn($this->expressions());
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value): string {
				$this->calls[] = 'param('.$value.')';
				return ':'.$value;
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function (): IResult {
				$this->calls[] = 'executeQuery';
				$result = $this->createMock(IResult::class);
				$result->method('fetch')->willReturn(false);
				return $result;
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new TimeTrackerLinkMapper(db: $db);
	}//end mapper()

	/**
	 * An expression builder that records eq and isNull.
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
		$expr->method('isNull')->willReturnCallback(
			function (string $column): string {
				$this->calls[] = 'expr.isNull('.$column.')';
				return 'isNull:'.$column;
			}
		);

		return $expr;
	}//end expressions()
}//end class
