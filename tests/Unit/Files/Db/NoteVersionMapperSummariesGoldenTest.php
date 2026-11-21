<?php

/**
 * Characterisation of NoteVersionMapper::summariesFor(): the query it sends for the
 * deduplicated comment ids, and the per-comment edit summary it folds the rows into.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Db;

use OCA\OpenRegister\Files\Db\NoteVersionMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordsQueries;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Golden query and summaries of NoteVersionMapper::summariesFor().
 *
 * @covers \OCA\OpenRegister\Files\Db\NoteVersionMapper
 */
final class NoteVersionMapperSummariesGoldenTest extends TestCase {
	use RecordsQueries;

	/**
	 * The mapper under test.
	 *
	 * @var NoteVersionMapper
	 */
	private NoteVersionMapper $mapper;

	/**
	 * Build the mapper over a recording connection.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new NoteVersionMapper($this->recordingDb());
	}//end setUp()

	/**
	 * No comment ids is no summaries, without a query.
	 *
	 * @return void
	 */
	public function testNoIdsIsNoSummariesWithoutQuery(): void {
		$this->assertSame([], $this->mapper->summariesFor([]));
		$this->assertSame([], $this->queries);
	}//end testNoIdsIsNoSummariesWithoutQuery()

	/**
	 * The ids are cast, deduplicated and bound as an integer list; edits come oldest first.
	 *
	 * @return void
	 */
	public function testQueryBindsTheDistinctIds(): void {
		$this->assertSame([], $this->mapper->summariesFor(['3', 3, 5, '5', 9]));

		$this->assertSame(
			'select(comment_id, edited_by, edited_at) from(openregister_note_versions) where(in(comment_id, :p0)) orderBy(edited_at, ASC) addOrderBy(id, ASC) executeQuery()',
			$this->sql()
		);
		$this->assertSame([[3, 5, 9], IQueryBuilder::PARAM_INT_ARRAY], $this->params[':p0']);
	}//end testQueryBindsTheDistinctIds()

	/**
	 * Each comment counts its versions; the last row read names the latest editor and time.
	 *
	 * @return void
	 */
	public function testRowsFoldIntoOneSummaryPerComment(): void {
		$this->results[] = [
			['comment_id' => '3', 'edited_by' => 'alice', 'edited_at' => '2026-01-02 03:04:05'],
			['comment_id' => 5, 'edited_by' => 'bob', 'edited_at' => ''],
			['comment_id' => '3', 'edited_by' => 'carol', 'edited_at' => new \DateTime('2026-02-03T04:05:06+01:00')],
			['comment_id' => '3', 'edited_by' => null, 'edited_at' => 'not a date'],
			['comment_id' => '9', 'edited_by' => 'dave', 'edited_at' => '2026-03-04T05:06:07+02:00'],
		];

		$this->assertSame(
			[
				3 => ['editedAt' => null, 'editedBy' => null, 'versionCount' => 3],
				5 => ['editedAt' => null, 'editedBy' => 'bob', 'versionCount' => 1],
				9 => ['editedAt' => '2026-03-04T05:06:07+02:00', 'editedBy' => 'dave', 'versionCount' => 1],
			],
			$this->mapper->summariesFor([3, 5, 9])
		);
	}//end testRowsFoldIntoOneSummaryPerComment()

	/**
	 * A stored time string is served as ISO 8601.
	 *
	 * @return void
	 */
	public function testStoredTimeIsServedAsIso(): void {
		$this->results[] = [
			['comment_id' => '4', 'edited_by' => 'erin', 'edited_at' => new \DateTime('2026-04-05T06:07:08+00:00')],
		];

		$this->assertSame(
			[4 => ['editedAt' => '2026-04-05T06:07:08+00:00', 'editedBy' => 'erin', 'versionCount' => 1]],
			$this->mapper->summariesFor([4])
		);
	}//end testStoredTimeIsServedAsIso()
}//end class
