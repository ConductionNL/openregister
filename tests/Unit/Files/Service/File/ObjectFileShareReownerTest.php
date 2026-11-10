<?php

/**
 * Handing the public links of an object's files to their new owner.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\File;

use OCA\OpenRegister\Files\Service\File\ObjectFileShareReowner;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Only public-link shares (type 3) of the given files that someone else owns are re-owned,
 * in chunks of 500, and the re-owned count is summed.
 *
 * @covers \OCA\OpenRegister\Files\Service\File\ObjectFileShareReowner
 */
final class ObjectFileShareReownerTest extends TestCase {

	/**
	 * Recorded parameters and expressions per query.
	 *
	 * @var list<array{params: list<array{0: mixed, 1: mixed}>, where: list<string>, set: list<string>, table: string}>
	 */
	private array $queries = [];

	/**
	 * A connection whose query builders record what they are told and affect the given counts.
	 *
	 * @param list<int> $affected Rows affected per statement.
	 *
	 * @return IDBConnection The connection.
	 */
	private function connection(array $affected): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$affected): IQueryBuilder {
			$index = count($this->queries);
			$this->queries[$index] = ['params' => [], 'where' => [], 'set' => [], 'table' => ''];
			$expr = $this->createMock(IExpressionBuilder::class);
			$expr->method('in')->willReturnCallback(static fn ($column, $value): string => $column . ' IN ' . $value);
			$expr->method('eq')->willReturnCallback(static fn ($column, $value): string => $column . ' = ' . $value);
			$expr->method('neq')->willReturnCallback(static fn ($column, $value): string => $column . ' <> ' . $value);

			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturnCallback(function ($value, $type = IQueryBuilder::PARAM_STR) use ($index): string {
				$this->queries[$index]['params'][] = [$value, $type];
				return ':p' . (count($this->queries[$index]['params']) - 1);
			});
			$qb->method('update')->willReturnCallback(function (string $table) use ($qb, $index): IQueryBuilder {
				$this->queries[$index]['table'] = $table;
				return $qb;
			});
			$qb->method('set')->willReturnCallback(function (string $column, $value) use ($qb, $index): IQueryBuilder {
				$this->queries[$index]['set'][] = $column . ' = ' . $value;
				return $qb;
			});
			foreach (['where', 'andWhere'] as $method) {
				$qb->method($method)->willReturnCallback(function ($predicate) use ($qb, $index): IQueryBuilder {
					$this->queries[$index]['where'][] = (string)$predicate;
					return $qb;
				});
			}

			$qb->method('executeStatement')->willReturnCallback(static function () use (&$affected): int {
				return array_shift($affected);
			});
			return $qb;
		});

		return $db;
	}//end connection()

	/**
	 * One chunk: public-link shares of those files not already owned by the new owner are re-owned.
	 *
	 * @return void
	 */
	public function testPublicLinksOfTheFilesAreReowned(): void {
		$count = (new ObjectFileShareReowner(db: $this->connection(affected: [2])))->reown(fileIds: [11, 12], owner: 'bob');

		$this->assertSame(2, $count);
		$this->assertCount(1, $this->queries);
		$this->assertSame('share', $this->queries[0]['table']);
		$this->assertSame(['uid_owner = :p0'], $this->queries[0]['set']);
		$this->assertSame(['file_source IN :p1', 'share_type = :p2', 'uid_owner <> :p3'], $this->queries[0]['where']);
		$this->assertSame(
			[['bob', IQueryBuilder::PARAM_STR], [[11, 12], IQueryBuilder::PARAM_INT_ARRAY], [3, IQueryBuilder::PARAM_INT], ['bob', IQueryBuilder::PARAM_STR]],
			$this->queries[0]['params']
		);
	}//end testPublicLinksOfTheFilesAreReowned()

	/**
	 * More than 500 files are re-owned in chunks of 500, and the counts are summed.
	 *
	 * @return void
	 */
	public function testManyFilesAreReownedInChunksOf500(): void {
		$count = (new ObjectFileShareReowner(db: $this->connection(affected: [400, 1])))->reown(fileIds: range(1, 501), owner: 'bob');

		$this->assertSame(401, $count);
		$this->assertCount(2, $this->queries);
		$this->assertCount(500, $this->queries[0]['params'][1][0]);
		$this->assertSame([501], $this->queries[1]['params'][1][0]);
	}//end testManyFilesAreReownedInChunksOf500()

	/**
	 * No files means no query and nothing re-owned.
	 *
	 * @return void
	 */
	public function testNoFilesMeansNoQuery(): void {
		$this->assertSame(0, (new ObjectFileShareReowner(db: $this->connection(affected: [])))->reown(fileIds: [], owner: 'bob'));
		$this->assertSame([], $this->queries);
	}//end testNoFilesMeansNoQuery()
}//end class
