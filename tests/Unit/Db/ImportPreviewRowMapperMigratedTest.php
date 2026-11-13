<?php

/**
 * The import-preview row mapper against the table the app's migrations create.
 *
 * A mocked query builder accepts any column name, so the mapper could order by
 * a column the table does not have. This test runs the mapper's queries as SQL
 * against the migrated table: the source row number lives in `source_row`, not in
 * `row_number`, which is reserved on MariaDB (#4909), and both readers return
 * rows in source order.
 *
 * It needs a Nextcloud server's classes for the migrated table, like the
 * other MigratedSqliteDatabase tests, so it runs in CI and not in a bare clone.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\ImportPreviewRow;
use OCA\OpenRegister\Db\ImportPreviewRowMapper;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Tests\Support\MigratedSqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\ImportPreviewRowMapper
 * @uses \OCA\OpenRegister\Db\ImportPreviewRow
 */
class ImportPreviewRowMapperMigratedTest extends TestCase {

	private MigratedSqliteDatabase $database;

	private ImportPreviewRowMapper $mapper;

	protected function setUp(): void {
		$this->database = new MigratedSqliteDatabase($this, ['openregister_import_preview_rows']);
		$this->mapper   = new ImportPreviewRowMapper(db: $this->database->idbConnection());

		// Stored out of source order, with one row of another preview.
		$rows = [
			['preview_id' => 7, 'source_row' => 3, 'decision' => ImportPreviewRow::DECISION_UPDATE],
			['preview_id' => 7, 'source_row' => 1, 'decision' => ImportPreviewRow::DECISION_CREATE],
			['preview_id' => 8, 'source_row' => 1, 'decision' => ImportPreviewRow::DECISION_CREATE],
			['preview_id' => 7, 'source_row' => 2, 'decision' => ImportPreviewRow::DECISION_CREATE, 'applied_at' => '2026-11-06 10:00:00'],
		];
		foreach ($rows as $row) {
			$this->database->insert('openregister_import_preview_rows', $row);
		}
	}//end setUp()

	/**
	 * The row numbers of the given rows, in the order they came back.
	 *
	 * @param ImportPreviewRow[] $rows The rows.
	 *
	 * @return int[]
	 */
	private static function rowNumbers(array $rows): array {
		return array_map(static fn (ImportPreviewRow $row): int => $row->getSourceRow(), $rows);
	}//end rowNumbers()

	/**
	 * The migrated table has the new column and not the reserved one.
	 *
	 * @return void
	 */
	public function testTheTableHasSourceRowAndNotRowNumber(): void {
		$columns = array_map(
			static fn ($column): string => trim($column->getName(), '"`'),
			$this->database->connection()->createSchemaManager()->listTableColumns('openregister_import_preview_rows')
		);

		$this->assertContains('source_row', $columns);
		$this->assertNotContains('row_number', $columns);
	}//end testTheTableHasSourceRowAndNotRowNumber()

	/**
	 * A preview's rows come back in source order, and only that preview's.
	 *
	 * @return void
	 */
	public function testFindByPreviewReturnsTheRowsInSourceOrder(): void {
		$this->assertSame([1, 2, 3], self::rowNumbers($this->mapper->findByPreview(previewId: 7, query: ListQuery::unbounded())));
	}//end testFindByPreviewReturnsTheRowsInSourceOrder()

	/**
	 * The rows a commit still has to write come back in source order, without the written one.
	 *
	 * @return void
	 */
	public function testFindPendingWritesReturnsTheUnwrittenRowsInSourceOrder(): void {
		$this->assertSame([1, 3], self::rowNumbers($this->mapper->findPendingWrites(previewId: 7)));
	}//end testFindPendingWritesReturnsTheUnwrittenRowsInSourceOrder()

	/**
	 * The JSON the frontend reads keeps the `row` key.
	 *
	 * @return void
	 */
	public function testTheJsonKeepsTheRowKey(): void {
		$first = $this->mapper->findByPreview(previewId: 7, query: ListQuery::unbounded())[0];

		$this->assertSame(1, $first->jsonSerialize()['row']);
	}//end testTheJsonKeepsTheRowKey()
}//end class
