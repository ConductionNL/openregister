<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\FlowMapper;
use OCP\AppFramework\Db\Entity;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * A FlowMapper that hands back no rows; the query is what is checked.
 */
class FlowMapperNodeTypeLikeProbe extends FlowMapper {

	/**
	 * Return no rows.
	 *
	 * @param IQueryBuilder $query The built query.
	 *
	 * @return Entity[] Always empty.
	 */
	protected function findEntities(IQueryBuilder $query): array {
		return [];
	}//end findEntities()
}//end class

/**
 * The node-type lookup on the json `nodes` column: the column is cast to
 * text before LIKE, because PostgreSQL has no LIKE operator on json.
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */
class FlowMapperNodeTypeLikeTest extends TestCase {

	/**
	 * Run one lookup and capture what reached castColumn() and like().
	 *
	 * @param string $nodeType The node type to look up.
	 *
	 * @return array{cast: array<int, mixed>, like: array<int, mixed>, cast_result: IQueryFunction, param: string}
	 */
	private function lookup(string $nodeType): array {
		$seen = ['cast' => [], 'like' => [], 'param' => ''];

		$castResult = $this->createMock(originalClassName: IQueryFunction::class);

		$expr = $this->createMock(originalClassName: IExpressionBuilder::class);
		$expr->method('castColumn')->willReturnCallback(
			static function ($column, $type) use (&$seen, $castResult) {
				$seen['cast'] = [$column, $type];
				return $castResult;
			}
		);
		$expr->method('like')->willReturnCallback(
			static function ($x, $y) use (&$seen): string {
				$seen['like'] = [$x, $y];
				return 'LIKE-CONDITION';
			}
		);

		$qb = $this->createMock(originalClassName: IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			static function ($value) use (&$seen): string {
				$seen['param'] = (string) $value;
				return ':p1';
			}
		);

		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('escapeLikeParameter')->willReturnCallback(
			static fn (string $value): string => addcslashes($value, '\\_%')
		);

		$mapper = new FlowMapperNodeTypeLikeProbe(db: $db);
		$mapper->findWithNodeType(nodeType: $nodeType);

		$seen['cast_result'] = $castResult;
		return $seen;
	}//end lookup()

	/**
	 * The json column is cast to a string before the LIKE, so PostgreSQL never
	 * raises "operator does not exist: json ~~ unknown".
	 *
	 * @return void
	 */
	public function testTheJsonColumnIsCastToTextBeforeTheLike(): void {
		$seen = $this->lookup(nodeType: 'openregister.trigger-calendar');

		$this->assertSame(expected: ['nodes', IQueryBuilder::PARAM_STR], actual: $seen['cast']);
		$this->assertSame(expected: $seen['cast_result'], actual: $seen['like'][0]);
		$this->assertSame(expected: ':p1', actual: $seen['like'][1]);
		$this->assertSame(expected: '%openregister.trigger-calendar%', actual: $seen['param']);
	}//end testTheJsonColumnIsCastToTextBeforeTheLike()

	/**
	 * LIKE wildcards in the node type match literally.
	 *
	 * @return void
	 */
	public function testWildcardsInTheNodeTypeAreEscaped(): void {
		$seen = $this->lookup(nodeType: 'a_b%c');

		$this->assertSame(expected: '%a\\_b\\%c%', actual: $seen['param']);
	}//end testWildcardsInTheNodeTypeAreEscaped()
}//end class
