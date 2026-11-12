<?php

/**
 * SchemaApplicationMigrator against a query builder: the count, slug and move
 * queries it issues, and migrate() end to end without partial mocks.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\ApplicationMove;
use OCA\OpenRegister\Service\SchemaApplicationMigrator;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\SchemaApplicationMigrator
 */
class SchemaApplicationMigratorQueryTest extends TestCase {

	/**
	 * Every query builder handed out, with the calls made on it.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * A connection whose query builders record their table, application filter and update.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsByApplication Rows a select returns per application.
	 * @param array<string, int> $affectedByTable Rows an update affects per table.
	 *
	 * @return IDBConnection
	 */
	private function connection(array $rowsByApplication, array $affectedByTable = []): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->builder($rowsByApplication, $affectedByTable));
		return $db;
	}

	private function builder(array $rowsByApplication, array $affectedByTable): IQueryBuilder {
		$index = count($this->queries);
		$this->queries[$index] = ['params' => []];
		$q = &$this->queries[$index];

		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn ($col, $param) => "{$col} = {$param}");
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturnCallback(function ($what, $alias) use (&$q) {
			$q['count'] = [$what, $alias];
			return $this->createMock(IQueryFunction::class);
		});
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturnCallback(static function ($value) use (&$q) {
			$q['params'][] = $value;
			return ':p' . (count($q['params']) - 1);
		});
		$qb->method('select')->willReturnCallback(static function ($what) use (&$q, $qb) {
			$q['select'] = is_string($what) ? $what : 'count';
			return $qb;
		});
		$qb->method('from')->willReturnCallback(static function ($table) use (&$q, $qb) {
			$q['table'] = $table;
			return $qb;
		});
		$qb->method('update')->willReturnCallback(static function ($table) use (&$q, $qb) {
			$q['update'] = $table;
			return $qb;
		});
		$qb->method('set')->willReturnCallback(static function ($col, $param) use (&$q, $qb) {
			$q['set'] = [$col, $param];
			return $qb;
		});
		$qb->method('where')->willReturnCallback(static function ($cond) use (&$q, $qb) {
			$q['where'] = $cond;
			return $qb;
		});
		$qb->method('executeStatement')->willReturnCallback(static function () use (&$q, $affectedByTable) {
			return $affectedByTable[$q['update']] ?? 0;
		});
		$qb->method('executeQuery')->willReturnCallback(function () use (&$q, $rowsByApplication) {
			$rows = $rowsByApplication[$q['params'][0]] ?? [];
			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
			$result->expects($this->once())->method('closeCursor');
			return $result;
		});

		return $qb;
	}

	public function testCountForCountsRowsOfTheApplicationInTheTable(): void {
		$migrator = new SchemaApplicationMigrator($this->connection(['decidesk' => [['c' => '4']]]));

		$this->assertSame(4, $migrator->countFor('openregister_registers', 'decidesk'));
		$this->assertSame('openregister_registers', $this->queries[0]['table']);
		$this->assertSame(['*', 'c'], $this->queries[0]['count']);
		$this->assertSame('application = :p0', $this->queries[0]['where']);
		$this->assertSame(['decidesk'], $this->queries[0]['params']);
	}

	public function testCountForIsZeroWhenNoRowComesBack(): void {
		$this->assertSame(0, (new SchemaApplicationMigrator($this->connection([])))->countFor('openregister_schemas', 'none'));
	}

	public function testSlugsForListsEverySchemaSlugOfTheApplication(): void {
		$migrator = new SchemaApplicationMigrator($this->connection(['decidesk' => [['slug' => 'meeting'], ['slug' => 'motion']]]));

		$this->assertSame(['meeting', 'motion'], $migrator->slugsFor('decidesk'));
		$this->assertSame('openregister_schemas', $this->queries[0]['table']);
		$this->assertSame('slug', $this->queries[0]['select']);
	}

	public function testCollidingSlugsComparesBothApplicationsCaseInsensitively(): void {
		$migrator = new SchemaApplicationMigrator($this->connection([
			'old' => [['slug' => 'Meeting'], ['slug' => 'agenda']],
			'new' => [['slug' => 'meeting']],
		]));

		$this->assertSame(['meeting'], $migrator->collidingSlugs('old', 'new'));
		$this->assertSame(['old'], $this->queries[0]['params']);
		$this->assertSame(['new'], $this->queries[1]['params']);
	}

	public function testMoveApplicationRewritesTheApplicationColumn(): void {
		$migrator = new SchemaApplicationMigrator($this->connection([], ['openregister_schemas' => 3]));

		$this->assertSame(3, $migrator->moveTable('openregister_schemas', new ApplicationMove('old', 'new')));
		$this->assertSame('openregister_schemas', $this->queries[0]['update']);
		$this->assertSame(['application', ':p0'], $this->queries[0]['set']);
		$this->assertSame('application = :p1', $this->queries[0]['where']);
		$this->assertSame(['new', 'old'], $this->queries[0]['params']);
	}

	public function testMigrateMovesSchemasAndRegistersWhenNothingCollides(): void {
		$migrator = new SchemaApplicationMigrator($this->connection(
			['old' => [['slug' => 'a']], 'new' => [['slug' => 'b']]],
			['openregister_schemas' => 2, 'openregister_registers' => 1]
		));

		$this->assertSame(
			['ok' => true, 'reason' => 'migrated', 'collisions' => [], 'schemas' => 2, 'registers' => 1],
			$migrator->migrate('old', 'new')
		);
		$this->assertSame(['openregister_schemas', 'openregister_registers'], [$this->queries[2]['update'], $this->queries[3]['update']]);
	}

	public function testMigrateRefusesOnACollisionAndUpdatesNothing(): void {
		$migrator = new SchemaApplicationMigrator($this->connection(['old' => [['slug' => 'a']], 'new' => [['slug' => 'A']]]));

		$result = $migrator->migrate('old', 'new');

		$this->assertSame(['ok' => false, 'reason' => 'collisions', 'collisions' => ['a'], 'schemas' => 0, 'registers' => 0], $result);
		$this->assertCount(2, $this->queries);
	}
}
