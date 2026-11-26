<?php

/**
 * FlowMapper::findWithNodeType matches the node type as text on every platform.
 *
 * PostgreSQL stores `nodes` as json and has no LIKE for it: the bare
 * `nodes LIKE …` failed there, and because the calendar index refresh runs
 * inside an object save, the failed query aborted that save's transaction.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\FlowMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * A platform whose class name reads as PostgreSQL, as LikeOperator detects it.
 */
final class FakePostgreSQLPlatform {
}//end class

/**
 * A platform whose class name reads as MariaDB.
 */
final class FakeMariaDBPlatform {
}//end class

/**
 * A FlowMapper that returns no rows, so only the query is observed.
 */
final class NodeTypeRecordingFlowMapper extends FlowMapper {

	/**
	 * @param IQueryBuilder $query The query.
	 *
	 * @return array<int, never>
	 */
	protected function findEntities(IQueryBuilder $query): array {
		return [];
	}//end findEntities()
}//end class

/**
 * The node-type match per platform.
 */
final class FlowMapperNodeTypeMatchTest extends TestCase {

	/**
	 * The WHERE clause findWithNodeType builds on one platform.
	 *
	 * @param object $platform The database platform.
	 *
	 * @return array{0: string, 1: array<int, mixed>} The condition SQL and the bound parameters.
	 */
	private function whereOn(object $platform): array {
		$where  = '';
		$params = [];
		$qb     = $this->createMock(originalClassName: IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('getColumnName')->willReturnCallback(fn (string $column): string => '"' . $column . '"');
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value) use (&$params): string {
				$params[] = $value;
				return ':p' . count($params);
			}
		);
		$qb->method('createFunction')->willReturnCallback(
			function (string $sql) use (&$where): IQueryFunction {
				$where    = $sql;
				$function = $this->createMock(originalClassName: IQueryFunction::class);
				$function->method('__toString')->willReturn($sql);
				return $function;
			}
		);
		$qb->method('where')->willReturnSelf();

		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('getDatabasePlatform')->willReturn($platform);

		(new NodeTypeRecordingFlowMapper($db))->findWithNodeType(nodeType: 'openregister.trigger-calendar');

		return [$where, $params];
	}//end whereOn()

	/**
	 * On PostgreSQL the json column is cast to text before the match.
	 *
	 * @return void
	 */
	public function testPostgresCastsTheJsonColumnToText(): void {
		[$where, $params] = $this->whereOn(platform: new FakePostgreSQLPlatform());

		$this->assertSame(expected: 'CAST("nodes" AS TEXT) ILIKE :p1', actual: $where);
		$this->assertSame(expected: ['%openregister.trigger-calendar%'], actual: $params);
	}//end testPostgresCastsTheJsonColumnToText()

	/**
	 * On MariaDB and MySQL the column is matched as CHAR, as every other LIKE filter does.
	 *
	 * @return void
	 */
	public function testMariaDbMatchesAsChar(): void {
		[$where] = $this->whereOn(platform: new FakeMariaDBPlatform());

		$this->assertSame(expected: 'LOWER(CAST("nodes" AS CHAR)) LIKE LOWER(:p1)', actual: $where);
	}//end testMariaDbMatchesAsChar()

	/**
	 * LIKE wildcards in a node type are escaped, so they match literally.
	 *
	 * @return void
	 */
	public function testWildcardsInTheNodeTypeAreEscaped(): void {
		[, $params] = $this->whereOn(platform: new FakePostgreSQLPlatform());
		$this->assertSame(expected: ['%openregister.trigger-calendar%'], actual: $params);

		$qbParams = (new \OCA\OpenRegister\Db\LikeOperator(databasePlatform: new FakePostgreSQLPlatform()))->pattern(term: 'a_b%c');
		$this->assertSame(expected: '%a\\_b\\%c%', actual: $qbParams);
	}//end testWildcardsInTheNodeTypeAreEscaped()
}//end class
