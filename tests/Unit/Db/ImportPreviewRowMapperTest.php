<?php

/**
 * ImportPreviewRowMapperTest: the decided rows of an import preview.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Db\ImportPreviewRow;
use OCA\OpenRegister\Db\ImportPreviewRowMapper;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Tests\Unit\Db\Support\RecordsQueries;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\ImportPreviewRowMapper
 * @uses   \OCA\OpenRegister\Db\ImportPreviewRow
 * @uses   \OCA\OpenRegister\Db\QueryPage
 */
class ImportPreviewRowMapperTest extends TestCase {
	use RecordsQueries;

	/**
	 * The mapper on a recording connection.
	 *
	 * @return ImportPreviewRowMapper
	 */
	private function mapper(): ImportPreviewRowMapper {
		return new ImportPreviewRowMapper($this->recordingConnection());
	}//end mapper()

	public function testFindByPreviewListsAllRowsInSourceOrder(): void {
		$this->rowSets = [[['id' => 1, 'preview_id' => 3, 'source_row' => 1, 'decision' => 'create'], ['id' => 2, 'preview_id' => 3, 'source_row' => 2, 'decision' => 'skip']]];

		$rows = $this->mapper()->findByPreview(3, ListQuery::unbounded());

		$this->assertSame(['create', 'skip'], array_map(static fn (ImportPreviewRow $row): ?string => $row->getDecision(), $rows));
		$this->assertSame(['select(*)', 'from(openregister_import_preview_rows)', 'where(eq(preview_id, 3))', 'orderBy(source_row, ASC)', 'executeQuery'], $this->queries[0]);
	}//end testFindByPreviewListsAllRowsInSourceOrder()

	public function testFindByPreviewNarrowsByDecisionAndPages(): void {
		$this->mapper()->findByPreview(3, new ListQuery(25, 50, ['decision' => 'refuse']));

		$this->assertSame(["andWhere(eq(decision, 'refuse'))", 'setMaxResults(25)', 'setFirstResult(50)', 'executeQuery'], array_slice($this->queries[0], 4));
	}//end testFindByPreviewNarrowsByDecisionAndPages()

	public function testFindPendingWritesAsksForUnappliedCreatesAndUpdates(): void {
		$this->rowSets = [[['id' => 7, 'preview_id' => 3, 'decision' => 'update']]];

		$rows = $this->mapper()->findPendingWrites(3);

		$this->assertSame([7], array_map(static fn (ImportPreviewRow $row): int => $row->getId(), $rows));
		$this->assertSame(
			[
				'select(*)',
				'from(openregister_import_preview_rows)',
				'where(eq(preview_id, 3))',
				'andWhere(isNull(applied_at))',
				'andWhere(in(decision, ["create","update"]))',
				'orderBy(source_row, ASC)',
				'executeQuery',
			],
			$this->queries[0]
		);
	}//end testFindPendingWritesAsksForUnappliedCreatesAndUpdates()

	public function testFindPendingWritesHonoursALimit(): void {
		$this->mapper()->findPendingWrites(3, 100);

		$this->assertSame(['orderBy(source_row, ASC)', 'setMaxResults(100)', 'executeQuery'], array_slice($this->queries[0], 5));
	}//end testFindPendingWritesHonoursALimit()

	public function testPersistInsertsANewRowWithACreationTime(): void {
		$this->lastInsertId = 44;
		$row = new ImportPreviewRow();
		$row->setPreviewId(3);
		$row->setDecision('create');

		$saved = $this->mapper()->persist($row);

		$this->assertSame(44, $saved->getId());
		$this->assertInstanceOf(DateTime::class, $saved->getCreated());
		$this->assertContains('insert(openregister_import_preview_rows)', $this->queries[0]);
		$this->assertContains('setValue(preview_id, 3)', $this->queries[0]);
	}//end testPersistInsertsANewRowWithACreationTime()

	public function testPersistUpdatesAnExistingRowWithoutTouchingItsCreationTime(): void {
		$row = new ImportPreviewRow();
		$row->setId(8);
		$row->resetUpdatedFields();
		$row->setDecision('skip');

		$saved = $this->mapper()->persist($row);

		$this->assertNull($saved->getCreated());
		$this->assertSame(['update(openregister_import_preview_rows)', "set(decision, 'skip')", 'where(eq(id, 8))', 'executeStatement'], $this->queries[0]);
	}//end testPersistUpdatesAnExistingRowWithoutTouchingItsCreationTime()

	public function testDeleteByPreviewRemovesEveryRowOfThePreview(): void {
		$this->mapper()->deleteByPreview(3);

		$this->assertSame(['delete(openregister_import_preview_rows)', 'where(eq(preview_id, 3))', 'executeStatement'], $this->queries[0]);
	}//end testDeleteByPreviewRemovesEveryRowOfThePreview()
}//end class
