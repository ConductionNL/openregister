<?php

/**
 * The per-note queries of NoteVersionMapper: a note's versions newest first,
 * and every version of a set of notes deleted in one statement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Db;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Files\Db\NoteVersion;
use OCA\OpenRegister\Files\Db\NoteVersionMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordsQueries;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * NoteVersionMapper::findByComment() and deleteByComments().
 *
 * @covers \OCA\OpenRegister\Files\Db\NoteVersionMapper
 * @uses \OCA\OpenRegister\Files\Db\NoteVersion
 */
final class NoteVersionMapperCommentQueriesTest extends TestCase {
	use RecordsQueries;

	private NoteVersionMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new NoteVersionMapper($this->recordingDb());
	}//end setUp()

	public function testANotesVersionsAreReadNewestFirst(): void {
		$this->results[] = [
			['id' => 9, 'comment_id' => 4, 'edited_by' => 'bob'],
			['id' => 7, 'comment_id' => 4, 'edited_by' => 'alice'],
		];

		$versions = $this->mapper->findByComment(4);

		$this->assertSame(
			'select(*) from(openregister_note_versions) where(eq(comment_id, :p0)) orderBy(edited_at, DESC) addOrderBy(id, DESC) executeQuery()',
			$this->sql()
		);
		$this->assertSame([4, IQueryBuilder::PARAM_INT], $this->params[':p0']);
		$this->assertContainsOnlyInstancesOf(NoteVersion::class, $versions);
		$this->assertSame([9, 7], array_map(static fn (NoteVersion $version): int => $version->getId(), $versions));
		$this->assertSame('bob', $versions[0]->getEditedBy());
	}//end testANotesVersionsAreReadNewestFirst()

	public function testANoteWithoutVersionsHasNone(): void {
		$this->assertSame([], $this->mapper->findByComment(12));
	}//end testANoteWithoutVersionsHasNone()

	public function testNoNotesDeletesNothingWithoutAQuery(): void {
		$this->assertSame(0, $this->mapper->deleteByComments([]));
		$this->assertSame([], $this->queries);
	}//end testNoNotesDeletesNothingWithoutAQuery()

	public function testTheVersionsOfTheDistinctNotesAreDeletedInOneStatement(): void {
		$this->affected = 5;

		$this->assertSame(5, $this->mapper->deleteByComments(['3', 3, 8, '11']));

		$this->assertSame(
			'delete(openregister_note_versions) where(in(comment_id, :p0)) executeStatement()',
			$this->sql()
		);
		$this->assertSame([[3, 8, 11], IQueryBuilder::PARAM_INT_ARRAY], $this->params[':p0']);
	}//end testTheVersionsOfTheDistinctNotesAreDeletedInOneStatement()
}//end class
