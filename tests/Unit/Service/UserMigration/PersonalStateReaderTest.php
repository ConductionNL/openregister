<?php

/**
 * The reader takes what one user holds, by owner, from the views and watchers tables.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\UserMigration
 *
 * @spec openspec/changes/platform-user-migrator/specs/platform-user-migration/spec.md#requirement-a-users-personal-state-is-exported-and-imported-req-pum-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\UserMigration;

use OCA\OpenRegister\Service\UserMigration\PersonalStateReader;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\UserMigration\PersonalStateReader
 * @uses \OCA\OpenRegister\Search\Db\View
 * @uses \OCA\OpenRegister\Notifications\Db\Watcher
 */
final class PersonalStateReaderTest extends TestCase {

	/**
	 * What the query asked: table, owner column, owner value, order.
	 *
	 * @var array<string, mixed>
	 */
	private array $asked = [];

	/**
	 * A reader over a database answering the given rows.
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 */
	private function reader(array $rows): PersonalStateReader {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
		$result->expects($this->once())->method('closeCursor');

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			function (string $column, mixed $value): string {
				$this->asked['column'] = $column;
				return $column . ' = ' . $value;
			}
		);

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnCallback(
			function (string $table) use ($qb): IQueryBuilder {
				$this->asked['table'] = $table;
				return $qb;
			}
		);
		$qb->method('where')->willReturnSelf();
		$qb->method('orderBy')->willReturnCallback(
			function (string $sort, string $order) use ($qb): IQueryBuilder {
				$this->asked['order'] = $sort . ' ' . $order;
				return $qb;
			}
		);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			function (mixed $value): string {
				$this->asked['value'] = $value;
				return ':owner';
			}
		);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new PersonalStateReader(db: $db);
	}//end reader()

	/**
	 * The views the user owns, as entities, read by the owner column.
	 */
	public function testOwnedViewsAreReadByOwner(): void {
		$views = $this->reader(rows: [['id' => 1, 'name' => 'Mijn zaken', 'owner' => 'alice', 'query' => '{"registers":[3]}']])->ownedViews(userId: 'alice');

		$this->assertSame(['table' => 'openregister_views', 'column' => 'owner', 'value' => 'alice', 'order' => 'id ASC'], $this->sorted());
		$this->assertCount(1, $views);
		$this->assertSame('Mijn zaken', $views[0]->getName());
		$this->assertSame(['registers' => [3]], $views[0]->getQuery());
	}//end testOwnedViewsAreReadByOwner()

	/**
	 * The watches of the user, read by the user column.
	 */
	public function testWatchesAreReadByUser(): void {
		$watches = $this->reader(rows: [['id' => 4, 'user_id' => 'alice', 'object_uuid' => 'obj-1', 'register' => '3', 'schema' => '9']])->watches(userId: 'alice');

		$this->assertSame(['table' => 'openregister_watchers', 'column' => 'user_id', 'value' => 'alice', 'order' => 'id ASC'], $this->sorted());
		$this->assertSame('obj-1', $watches[0]->getObjectUuid());
	}//end testWatchesAreReadByUser()

	/**
	 * Without a user nothing is read: an empty owner must never match every row.
	 */
	public function testNoUserReadsNothing(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');

		$this->assertSame([], (new PersonalStateReader(db: $db))->ownedViews(userId: ''));
	}//end testNoUserReadsNothing()

	/**
	 * What was asked, in a fixed key order.
	 *
	 * @return array<string, mixed>
	 */
	private function sorted(): array {
		return ['table' => $this->asked['table'], 'column' => $this->asked['column'], 'value' => $this->asked['value'], 'order' => $this->asked['order']];
	}//end sorted()
}//end class
