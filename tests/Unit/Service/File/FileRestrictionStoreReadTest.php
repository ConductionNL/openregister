<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-in-an-objects-folder-can-carry-a-read-restriction-req-pfrr-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Files\Service\File\FileRestrictionStore;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Pins how stored restriction rows are read back, and how one is removed.
 *
 * @covers \OCA\OpenRegister\Files\Service\File\FileRestrictionStore
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-in-an-objects-folder-can-carry-a-read-restriction-req-pfrr-001
 */
class FileRestrictionStoreReadTest extends TestCase {

	/**
	 * The file-id lists each read builder was asked for, in order.
	 *
	 * @var list<mixed>
	 */
	private array $asked = [];

	/**
	 * A read builder whose query yields the given rows.
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 *
	 * @return IQueryBuilder The builder.
	 */
	private function reader(array $rows): IQueryBuilder {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
		$result->expects($this->once())->method('closeCursor');

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('createNamedParameter')->willReturnCallback(
			function ($value, $type) {
				$this->assertSame(IQueryBuilder::PARAM_INT_ARRAY, $type);
				$this->asked[] = $value;
				return 'ids';
			}
		);
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		foreach (['select', 'from', 'where'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}//end reader()

	/**
	 * The store on a connection handing out the given builders.
	 *
	 * @param list<IQueryBuilder> $builders The builders, in order.
	 *
	 * @return FileRestrictionStore The store.
	 */
	private function store(array $builders): FileRestrictionStore {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->exactly(count($builders)))->method('getQueryBuilder')->willReturnOnConsecutiveCalls(...$builders);
		return new FileRestrictionStore($db);
	}//end store()

	public function testAStoredRowIsReadBackWithItsListsAndAnIsoDate(): void {
		$row = $this->store(
			[
				$this->reader(
					[
						[
							'file_id' => '42',
							'user_ids' => '["pieter","",1001]',
							'group_ids' => '["woo-team"]',
							'reason' => 'Prices',
							'set_by' => 'pieter',
							'set_at' => '2026-01-02 03:04:05',
						],
					]
				),
			]
		)->find(42);

		$this->assertSame([[42]], $this->asked);
		$this->assertSame(
			[
				'fileId' => 42,
				'users' => ['pieter', '1001'],
				'groups' => ['woo-team'],
				'reason' => 'Prices',
				'setBy' => 'pieter',
				'setAt' => (new \DateTimeImmutable('2026-01-02 03:04:05'))->format(DATE_ATOM),
			],
			$row
		);
	}//end testAStoredRowIsReadBackWithItsListsAndAnIsoDate()

	public function testAFileWithoutARowHasNoRestriction(): void {
		$this->assertNull($this->store([$this->reader([])])->find(7));
	}//end testAFileWithoutARowHasNoRestriction()

	public function testMissingOrUnreadableColumnsReadAsEmpty(): void {
		$rows = $this->store(
			[
				$this->reader(
					[
						['file_id' => 5, 'user_ids' => null, 'group_ids' => 'not json', 'reason' => null, 'set_by' => null, 'set_at' => null],
						['file_id' => 6, 'user_ids' => '', 'group_ids' => '"text"', 'set_at' => 'yesterday'],
					]
				),
			]
		)->findMany([5, 6]);

		$this->assertSame(['fileId' => 5, 'users' => [], 'groups' => [], 'reason' => '', 'setBy' => '', 'setAt' => ''], $rows[5]);

		// A date the store cannot parse is passed through as written, not dropped.
		$this->assertSame(['fileId' => 6, 'users' => [], 'groups' => [], 'reason' => '', 'setBy' => '', 'setAt' => 'yesterday'], $rows[6]);
	}//end testMissingOrUnreadableColumnsReadAsEmpty()

	public function testIdsAreDeduplicatedFilteredAndReadInChunksOfFiveHundred(): void {
		$ids = array_merge(range(1, 600), [3, 0, -4, '17']);

		$rows = $this->store(
			[
				$this->reader([['file_id' => 1, 'set_at' => null]]),
				$this->reader([['file_id' => 600, 'set_at' => null]]),
			]
		)->findMany($ids);

		$this->assertCount(2, $this->asked);
		$this->assertSame(range(1, 500), $this->asked[0]);
		$this->assertSame(range(501, 600), $this->asked[1]);
		$this->assertSame([1, 600], array_keys($rows));
	}//end testIdsAreDeduplicatedFilteredAndReadInChunksOfFiveHundred()

	public function testNoValidIdsMeansNoQuery(): void {
		$this->assertSame([], $this->store([])->findMany([0, -1]));
	}//end testNoValidIdsMeansNoQuery()

	public function testDeleteRemovesTheRowOfThatFile(): void {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->expects($this->once())->method('delete')->with('openregister_file_restrictions')->willReturnSelf();
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->expects($this->once())->method('createNamedParameter')->with(42, IQueryBuilder::PARAM_INT)->willReturn('id');
		$qb->expects($this->once())->method('where')->willReturnSelf();
		$qb->expects($this->once())->method('executeStatement')->willReturn(1);

		$this->store([$qb])->delete(42);
	}//end testDeleteRemovesTheRowOfThatFile()
}//end class
