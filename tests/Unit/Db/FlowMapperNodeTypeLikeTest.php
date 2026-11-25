<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Db\FlowMapper;
use OCP\AppFramework\Db\Entity;
use OCP\DB\QueryBuilder\IQueryBuilder;
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
 * The node-type lookup on the json `nodes` column, per database platform.
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */
class FlowMapperNodeTypeLikeTest extends TestCase {

	/**
	 * The where() condition and bound parameter of one lookup.
	 *
	 * @param object $platform The database platform the connection reports.
	 *
	 * @return array{0: string, 1: string} The condition and the pattern.
	 */
	private function lookup(object $platform): array {
		$where = '';
		$param = '';
		$qb    = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('getColumnName')->willReturnCallback(static fn (string $column): string => $column);
		$qb->method('createNamedParameter')->willReturnCallback(
			static function ($value) use (&$param): string {
				$param = (string) $value;
				return ':p1';
			}
		);
		$qb->method('where')->willReturnCallback(
			static function ($condition) use (&$where, $qb) {
				$where = (string) $condition;
				return $qb;
			}
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('getDatabasePlatform')->willReturn($platform);

		$mapper = new FlowMapperNodeTypeLikeProbe(db: $db);
		$mapper->findWithNodeType(nodeType: 'openregister.trigger-calendar');

		return [$where, $param];
	}//end lookup()

	/**
	 * On PostgreSQL the json column is cast to text, so the lookup never
	 * raises "operator does not exist: json ~~ unknown".
	 *
	 * @return void
	 */
	public function testPostgresCastsTheJsonColumnToText(): void {
		[$where, $param] = $this->lookup(platform: new PostgreSQLPlatform());

		$this->assertSame(expected: 'CAST(nodes AS TEXT) ILIKE :p1', actual: $where);
		$this->assertSame(expected: '%openregister.trigger-calendar%', actual: $param);
	}//end testPostgresCastsTheJsonColumnToText()

	/**
	 * On MySQL / MariaDB the lookup stays a substring match on the text.
	 *
	 * @return void
	 */
	public function testMysqlMatchesTheSubstring(): void {
		[$where, $param] = $this->lookup(platform: new MySQLPlatform());

		$this->assertSame(expected: 'LOWER(CAST(nodes AS CHAR)) LIKE LOWER(:p1)', actual: $where);
		$this->assertSame(expected: '%openregister.trigger-calendar%', actual: $param);
	}//end testMysqlMatchesTheSubstring()
}//end class
