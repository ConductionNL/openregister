<?php

/**
 * MagicSlugTableTest: the slug reads and the guarded slug write of one magic table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicSlugTable;
use OCA\OpenRegister\Tests\Unit\Db\Support\RecordsQueries;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSlugTable
 */
class MagicSlugTableTest extends TestCase {
	use RecordsQueries;

	private const TABLE = 'openregister_table_1_2';

	/**
	 * The table under test on a recording connection.
	 *
	 * @return MagicSlugTable
	 */
	private function table(): MagicSlugTable {
		return new MagicSlugTable($this->recordingConnection(), self::TABLE);
	}//end table()

	public function testNameIsTheTableItWasBuiltFor(): void {
		$this->assertSame(self::TABLE, $this->table()->name());
		$this->assertSame('other', (new MagicSlugTable($this->recordingConnection(), 'other'))->name());
	}//end testNameIsTheTableItWasBuiltFor()

	public function testByPrefixEscapesTheLikeWildcardsAndCastsRowsToStrings(): void {
		$this->rowSets = [[['_slug' => 'a%b-1', '_uuid' => 'u1'], ['_slug' => 7, '_uuid' => 9]]];

		$matches = $this->table()->byPrefix('a%b_', 5);

		$this->assertSame([['slug' => 'a%b-1', 'uuid' => 'u1'], ['slug' => '7', 'uuid' => '9']], $matches);
		$this->assertSame(
			[
				'select(_slug, _uuid)',
				'from(' . self::TABLE . ')',
				"where(like(_slug, 'a\\%b\\_%'))",
				'andWhere(isNull(_deleted))',
				'orderBy(_id, ASC)',
				'setMaxResults(5)',
				'executeQuery',
			],
			$this->queries[0]
		);
	}//end testByPrefixEscapesTheLikeWildcardsAndCastsRowsToStrings()

	public function testByPrefixWithoutMatchesIsEmpty(): void {
		$this->assertSame([], $this->table()->byPrefix('x', 1));
	}//end testByPrefixWithoutMatchesIsEmpty()

	public function testHoldersWithoutExclusionAsksForAnyLiveHolder(): void {
		$this->rowSets = [[['_uuid' => 'u1']]];

		$this->assertSame(['u1'], $this->table()->holders('zaak', null));
		$this->assertSame(
			['select(_uuid)', 'from(' . self::TABLE . ')', "where(eq(_slug, 'zaak'))", 'andWhere(isNull(_deleted))', 'setMaxResults(1)', 'executeQuery'],
			$this->queries[0]
		);
	}//end testHoldersWithoutExclusionAsksForAnyLiveHolder()

	public function testHoldersExcludeTheObjectBeingSaved(): void {
		$this->rowSets = [[]];

		$this->assertSame([], $this->table()->holders('zaak', 'u9'));
		$this->assertContains("andWhere(neq(_uuid, 'u9'))", $this->queries[0]);
	}//end testHoldersExcludeTheObjectBeingSaved()

	public function testStateMapsHoldersAndStoredSlugsAndDropsEmptyCandidates(): void {
		$this->rowSets = [
			[['_uuid' => 'u1', '_slug' => 'a'], ['_uuid' => 'u7', '_slug' => 'a']],
			[['_uuid' => 'u1', '_slug' => 'a'], ['_uuid' => 'u2', '_slug' => ''], ['_uuid' => 'u3', '_slug' => null]],
		];

		$state = $this->table()->state(['a', 'a', '', null, 'b'], ['u1', '', null, 'u2', 'u3']);

		$this->assertSame(['a' => ['u1', 'u7']], $state['holders']);
		$this->assertSame(['u1' => 'a', 'u2' => null, 'u3' => null], $state['stored']);
		$this->assertContains('where(in(_slug, ["a","b"]))', $this->queries[0]);
		$this->assertContains('where(in(_uuid, ["u1","u2","u3"]))', $this->queries[1]);
	}//end testStateMapsHoldersAndStoredSlugsAndDropsEmptyCandidates()

	public function testStateOfNothingRunsNoQuery(): void {
		$this->assertSame(['holders' => [], 'stored' => []], $this->table()->state([], ['', null]));
		$this->assertSame([], $this->queries);
	}//end testStateOfNothingRunsNoQuery()

	public function testStateSplitsLongListsIntoChunksOfAThousand(): void {
		$many = array_map(static fn (int $i): string => "s{$i}", range(1, 1001));
		$this->rowSets = [[['_uuid' => 'u1', '_slug' => 's1']], [['_uuid' => 'u2', '_slug' => 's1001']]];

		$state = $this->table()->state($many, []);

		$this->assertCount(2, $this->queries);
		$this->assertSame(['s1' => ['u1'], 's1001' => ['u2']], $state['holders']);
		$this->assertContains('where(in(_slug, ["s1001"]))', $this->queries[1]);
	}//end testStateSplitsLongListsIntoChunksOfAThousand()

	public function testDuplicatesGroupsHoldersByCaseFoldedSlugOldestFirst(): void {
		$this->rowSets = [
			[['slug_key' => 'hello'], ['slug_key' => 'twee']],
			[['_uuid' => 'u1', '_slug' => 'Hello'], ['_uuid' => 'u2', '_slug' => 'hello'], ['_uuid' => 'u3', '_slug' => 'TWEE']],
		];

		$duplicates = $this->table()->duplicates();

		$this->assertSame(['hello' => ['u1', 'u2'], 'twee' => ['u3']], $duplicates);
		$this->assertContains('selectAlias(lower(_slug), slug_key)', $this->queries[0]);
		$this->assertContains('having(COUNT(*) > 1)', $this->queries[0]);
		$this->assertContains('groupBy(lower(_slug))', $this->queries[0]);
		$this->assertContains('where(in(lower(_slug), ["hello","twee"]))', $this->queries[1]);
		$this->assertSame(['orderBy(_created, ASC)', 'addOrderBy(_id, ASC)'], array_values(array_filter($this->queries[1], static fn (string $c): bool => str_contains($c, 'OrderBy') || str_starts_with($c, 'orderBy'))));
	}//end testDuplicatesGroupsHoldersByCaseFoldedSlugOldestFirst()

	public function testNoDuplicateKeysMeansNoHolderQuery(): void {
		$this->rowSets = [[]];

		$this->assertSame([], $this->table()->duplicates());
		$this->assertCount(1, $this->queries);
	}//end testNoDuplicateKeysMeansNoHolderQuery()

	public function testAllListsEveryNonEmptyLiveSlugWithItsHolders(): void {
		$this->rowSets = [[['_uuid' => 'u1', '_slug' => 'a'], ['_uuid' => 'u2', '_slug' => 'a'], ['_uuid' => 'u3', '_slug' => 'b']]];

		$this->assertSame(['a' => ['u1', 'u2'], 'b' => ['u3']], $this->table()->all());
		$this->assertSame(
			['select(_uuid, _slug)', 'from(' . self::TABLE . ')', 'where(isNotNull(_slug))', "andWhere(neq(_slug, ''))", 'andWhere(isNull(_deleted))', 'executeQuery'],
			$this->queries[0]
		);
	}//end testAllListsEveryNonEmptyLiveSlugWithItsHolders()

	public function testBackfillRowsPagesLiveRowsWithoutASlugAfterTheLastId(): void {
		$rows = [['_id' => 4, '_uuid' => 'u4', '_slug' => null], ['_id' => 11, '_uuid' => 'u11', '_slug' => '']];
		$this->rowSets = [$rows];

		$this->assertSame($rows, iterator_to_array($this->table()->backfillRows(3, 50), false));
		$this->assertSame(
			[
				'select(*)',
				'from(' . self::TABLE . ', t)',
				'where(gt(t._id, 3))',
				'andWhere(isNull(t._deleted))',
				"andWhere(orX(isNull(t._slug), eq(t._slug, '')))",
				'orderBy(t._id, ASC)',
				'setMaxResults(50)',
				'executeQuery',
			],
			$this->queries[0]
		);
	}//end testBackfillRowsPagesLiveRowsWithoutASlugAfterTheLastId()

	public function testFillEmptyOnlyWritesARowThatStillHasNoSlug(): void {
		$this->statementResults = [0];

		$this->assertSame(0, $this->table()->fillEmpty('u1', 'nieuw'));
		$this->assertSame(
			[
				'update(' . self::TABLE . ')',
				"set(_slug, 'nieuw')",
				"where(eq(_uuid, 'u1'))",
				"andWhere(orX(isNull(_slug), eq(_slug, '')))",
				'executeStatement',
			],
			$this->queries[0]
		);
	}//end testFillEmptyOnlyWritesARowThatStillHasNoSlug()

	public function testFillEmptyReportsTheRowsItUpdated(): void {
		$this->statementResults = [1];

		$this->assertSame(1, $this->table()->fillEmpty('u2', 'x'));
	}//end testFillEmptyReportsTheRowsItUpdated()
}//end class
