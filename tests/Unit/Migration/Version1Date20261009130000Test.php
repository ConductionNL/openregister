<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261009130000;
use OCA\OpenRegister\Migration\Version1Date20261009130100;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\IResult;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Favourites become follows with notifications off, and the table goes.
 *
 * The copy is asserted on what it WRITES: which (user, object) pairs are
 * inserted and with which notify value, against a fixture where one pair is
 * already a follow (the union rule) and one is not (the control).
 *
 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-favourites-became-follows-with-notifications-off
 */
class Version1Date20261009130000Test extends TestCase {
	use SchemaTableMockTrait;

	/**
	 * The `notify` column is added, nullable with default on.
	 *
	 * @return void
	 */
	public function testItAddsTheNotifyColumn(): void {
		$added = [];
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(false);
		$table->method('addColumn')->willReturnCallback(
			function (string $name, string $type, array $options) use (&$added) {
				$added[$name] = $options;
				return $this->createColumnMock();
			}
		);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->with('openregister_watchers')->willReturn($table);

		$step = new Version1Date20261009130000($this->createMock(IDBConnection::class));
		$this->assertSame($schema, $step->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
		$this->assertSame(['notify' => ['notnull' => false, 'default' => true]], $added);
	}//end testItAddsTheNotifyColumn()

	/**
	 * A second run finds the column and changes nothing.
	 *
	 * @return void
	 */
	public function testASecondRunChangesNothing(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(true);
		$table->expects($this->never())->method('addColumn');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturn($table);

		$step = new Version1Date20261009130000($this->createMock(IDBConnection::class));
		$this->assertNull($step->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}//end testASecondRunChangesNothing()

	/**
	 * A starred case becomes a quiet follow; a starred and followed case is left as it was.
	 *
	 * @return void
	 */
	public function testFavouritesBecomeQuietFollowsAndExistingFollowsWin(): void {
		$favourites = [
			['id' => 1, 'user_id' => 'alice', 'object_uuid' => 'uuid-a', 'register' => '3', 'schema' => '12', 'created' => '2026-10-01 10:00:00'],
			['id' => 2, 'user_id' => 'alice', 'object_uuid' => 'uuid-b', 'register' => '3', 'schema' => '12', 'created' => '2026-10-02 10:00:00'],
		];
		$alreadyFollowed = ['alice|uuid-b'];
		$inserted = [];

		$connection = $this->createMock(IDBConnection::class);
		$connection->method('tableExists')->willReturn(true);
		$connection->method('getQueryBuilder')->willReturnCallback(
			function () use ($favourites, $alreadyFollowed, &$inserted) {
				return $this->recordingBuilder(favourites: $favourites, followed: $alreadyFollowed, inserted: $inserted);
			}
		);

		$step = new Version1Date20261009130000($connection);
		$step->postSchemaChange($this->createMock(IOutput::class), static fn () => null, []);

		$this->assertSame([['alice', 'uuid-a', false]], $inserted);
	}//end testFavouritesBecomeQuietFollowsAndExistingFollowsWin()

	/**
	 * No favourites table (a fresh install past the drop): nothing to copy.
	 *
	 * @return void
	 */
	public function testNoFavouritesTableCopiesNothing(): void {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('tableExists')->willReturn(false);
		$connection->expects($this->never())->method('getQueryBuilder');

		$step = new Version1Date20261009130000($connection);
		$step->postSchemaChange($this->createMock(IOutput::class), static fn () => null, []);
	}//end testNoFavouritesTableCopiesNothing()

	/**
	 * The drop step removes the favourites table, once.
	 *
	 * @return void
	 */
	public function testTheDropStepRemovesTheFavouritesTable(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_favourites')->willReturn(true);
		$schema->expects($this->once())->method('dropTable')->with('openregister_favourites');

		$step = new Version1Date20261009130100();
		$this->assertSame($schema, $step->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));

		$gone = $this->createMock(ISchemaWrapper::class);
		$gone->method('hasTable')->willReturn(false);
		$gone->expects($this->never())->method('dropTable');
		$this->assertNull($step->changeSchema($this->createMock(IOutput::class), static fn () => $gone, []));
	}//end testTheDropStepRemovesTheFavouritesTable()

	/**
	 * A query builder double that answers the three queries the copy makes.
	 *
	 * It tells them apart by the table named in `from()` / `insert()`: a page
	 * of favourites, a follow lookup, or an insert, which it records.
	 *
	 * @param array<int, array<string, mixed>> $favourites The favourites table.
	 * @param array<int, string> $followed "user|uuid" pairs already followed.
	 * @param array<int, array<int, mixed>> $inserted Receives [user, uuid, notify] per insert.
	 *
	 * @return IQueryBuilder
	 */
	private function recordingBuilder(array $favourites, array $followed, array &$inserted): IQueryBuilder {
		$state = ['table' => null, 'params' => [], 'values' => []];
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('gt')->willReturn('gt');

		$qb->method('expr')->willReturn($expr);
		$qb->method('select')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('orderBy')->willReturnSelf();
		$qb->method('setMaxResults')->willReturnSelf();
		$qb->method('from')->willReturnCallback(
			function (string $table) use (&$state, $qb) {
				$state['table'] = $table;
				return $qb;
			}
		);
		$qb->method('insert')->willReturnCallback(
			function (string $table) use (&$state, $qb) {
				$state['table'] = 'insert:'.$table;
				return $qb;
			}
		);
		$qb->method('values')->willReturnCallback(
			function (array $values) use (&$state, $qb) {
				$state['values'] = $values;
				return $qb;
			}
		);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value) use (&$state) {
				$state['params'][] = $value;
				return $value;
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			function () use (&$state, $favourites, $followed) {
				$result = $this->createMock(IResult::class);
				if ($state['table'] === 'openregister_favourites') {
					$result->method('fetchAll')->willReturn($state['params'][0] === 0 ? $favourites : []);
					return $result;
				}

				$key = $state['params'][0].'|'.$state['params'][1];
				$result->method('fetch')->willReturn(in_array($key, $followed, true) ? ['id' => 9] : false);
				return $result;
			}
		);
		$qb->method('executeStatement')->willReturnCallback(
			function () use (&$state, &$inserted) {
				$values = $state['values'];
				$inserted[] = [$values['user_id'], $values['object_uuid'], $values['notify']];
				return 1;
			}
		);

		return $qb;
	}//end recordingBuilder()
}//end class
