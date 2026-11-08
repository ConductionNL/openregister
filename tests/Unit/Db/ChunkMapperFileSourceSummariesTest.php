<?php

declare(strict_types=1);

/**
 * ChunkMapper file-source summary characterisation tests.
 *
 * Pins the sort map, the sort direction, the file-name search clause and the
 * row shape of getFileSourceSummaries(), and the search clause of
 * countFileSourceSummaries().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Files\Db\ChunkMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChunkMapperFileSourceSummariesTest extends TestCase {

	/** @var array<int, array{0: string, 1: string}> */
	private array $orderBy = [];

	/** @var array<int, mixed> */
	private array $andWhere = [];

	/** @var array<int, mixed> */
	private array $namedParameters = [];

	/** @var array<int, array<string, mixed>|false> */
	private array $rows = [];

	/**
	 * A mapper over a recording query builder.
	 *
	 * @return ChunkMapper
	 */
	private function mapper(): ChunkMapper {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$expr->method('iLike')->willReturnCallback(static fn ($column, $value) => 'ilike:' . $column . ':' . $value);

		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));

		$result = $this->createMock(IResult::class);
		$rows = $this->rows;
		$rows[] = false;
		$result->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
		$result->method('fetchOne')->willReturn('3');

		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'selectAlias', 'from', 'innerJoin', 'leftJoin', 'where', 'groupBy', 'setMaxResults', 'setFirstResult'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$qb->method('orderBy')->willReturnCallback(
			function ($sort, $order) use ($qb) {
				$this->orderBy[] = [$sort, $order];
				return $qb;
			}
		);
		$qb->method('andWhere')->willReturnCallback(
			function ($clause) use ($qb) {
				$this->andWhere[] = $clause;
				return $qb;
			}
		);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value) {
				$this->namedParameters[] = $value;
				return 'p' . count($this->namedParameters);
			}
		);
		$qb->method('expr')->willReturn($expr);
		$qb->method('func')->willReturn($func);
		$qb->method('createFunction')->willReturn($this->createMock(IQueryFunction::class));
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		$db->method('escapeLikeParameter')->willReturnCallback(static fn (string $value) => 'esc(' . $value . ')');

		return new ChunkMapper($db);
	}//end mapper()

	/**
	 * Sort field and direction cases.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function sorts(): array {
		return [
			'file name asc'      => ['fileName', 'ASC', 'fc.name', 'ASC'],
			'file size lower'    => ['fileSize', 'asc', 'fc.size', 'ASC'],
			'extracted desc'     => ['extractedAt', 'DESC', 'last_extracted', 'DESC'],
			'chunk count'        => ['chunkCount', 'desc', 'chunk_count', 'DESC'],
			'unknown field'      => ['riskLevel', 'ASC', 'last_extracted', 'ASC'],
			'unknown direction'  => ['fileName', 'sideways', 'fc.name', 'DESC'],
		];
	}//end sorts()

	/**
	 * The sort field maps to a column and anything but ASC sorts descending.
	 *
	 * @param string $sort          Requested sort.
	 * @param string $order         Requested order.
	 * @param string $expectedSort  Column ordered by.
	 * @param string $expectedOrder Direction ordered by.
	 *
	 * @return void
	 */
	#[DataProvider('sorts')]
	public function testSortMapsToColumnAndDirection(string $sort, string $order, string $expectedSort, string $expectedOrder): void {
		$this->mapper()->getFileSourceSummaries(null, null, null, $sort, $order);

		$this->assertSame([[$expectedSort, $expectedOrder]], $this->orderBy);
		$this->assertSame([], $this->andWhere);
	}//end testSortMapsToColumnAndDirection()

	/**
	 * A search term adds one escaped iLike clause on the file name; an empty one adds none.
	 *
	 * @return void
	 */
	public function testSearchAddsEscapedFileNameClause(): void {
		$this->mapper()->getFileSourceSummaries(null, null, 'rep%');
		$this->assertSame(['ilike:fc.name:p2'], $this->andWhere);
		$this->assertSame(['file', '%esc(rep%)%'], $this->namedParameters);

		$this->andWhere = [];
		$this->mapper()->getFileSourceSummaries(null, null, '');
		$this->assertSame([], $this->andWhere);
	}//end testSearchAddsEscapedFileNameClause()

	/**
	 * Rows are mapped to the summary shape with integer casts.
	 *
	 * @return void
	 */
	public function testRowsAreMappedToSummaries(): void {
		$this->rows = [
			[
				'source_id' => '12',
				'chunk_count' => '4',
				'first_extracted' => '2026-01-01 10:00:00',
				'last_extracted' => '2026-01-02 10:00:00',
				'file_name' => 'a.pdf',
				'mime_type' => 'application/pdf',
				'file_size' => '2048',
			],
			[
				'source_id' => 13,
				'chunk_count' => 1,
				'first_extracted' => null,
				'last_extracted' => null,
				'file_name' => null,
				'mime_type' => null,
				'file_size' => null,
			],
		];

		$summaries = $this->mapper()->getFileSourceSummaries();

		$this->assertSame(
			[
				[
					'sourceId' => 12,
					'chunkCount' => 4,
					'firstExtracted' => '2026-01-01 10:00:00',
					'lastExtracted' => '2026-01-02 10:00:00',
					'fileName' => 'a.pdf',
					'mimeType' => 'application/pdf',
					'fileSize' => 2048,
				],
				[
					'sourceId' => 13,
					'chunkCount' => 1,
					'firstExtracted' => null,
					'lastExtracted' => null,
					'fileName' => null,
					'mimeType' => null,
					'fileSize' => 0,
				],
			],
			$summaries
		);
		$this->assertSame([['last_extracted', 'DESC']], $this->orderBy);
	}//end testRowsAreMappedToSummaries()

	/**
	 * The count applies the same file-name clause and casts the count.
	 *
	 * @return void
	 */
	public function testCountAppliesTheSameSearchClause(): void {
		$this->assertSame(3, $this->mapper()->countFileSourceSummaries('x'));
		$this->assertSame(['ilike:fc.name:p2'], $this->andWhere);
		$this->assertSame(['file', '%esc(x)%'], $this->namedParameters);

		$this->andWhere = [];
		$this->assertSame(3, $this->mapper()->countFileSourceSummaries(null));
		$this->assertSame([], $this->andWhere);
	}//end testCountAppliesTheSameSearchClause()
}//end class
