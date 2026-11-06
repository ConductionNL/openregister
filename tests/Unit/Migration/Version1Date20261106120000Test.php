<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The import-preview row column `row_number` is renamed to `row_no` once, with
 * quoted identifiers, and only when the table still has the old name (#4909).
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261106120000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Migration\Version1Date20261106120000
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
class Version1Date20261106120000Test extends TestCase {
	use SchemaTableMockTrait;

	/**
	 * Run the step over a rows table with the given columns.
	 *
	 * @param bool     $hasTable      Whether the rows table exists.
	 * @param string[] $columns       The columns the table has.
	 * @param int      $expectRename  How often the rename statement is expected.
	 * @param int      $expectWarning How often a warning is expected.
	 *
	 * @return void
	 */
	private function migrate(bool $hasTable, array $columns, int $expectRename, int $expectWarning): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturnCallback(static fn (string $name): bool => in_array($name, $columns, true));

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with(Version1Date20261106120000::TABLE)->willReturn($hasTable);
		$schema->method('getTable')->willReturn($table);

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->exactly($expectRename))
			->method('executeStatement')
			->with('ALTER TABLE `*PREFIX*openregister_import_preview_rows` RENAME COLUMN `row_number` TO `row_no`');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->exactly($expectWarning))->method('warning');

		$step    = new Version1Date20261106120000(db: $db);
		$closure = static fn () => $schema;
		$step->preSchemaChange($output, $closure, []);
		$this->assertSame($schema, $step->changeSchema($output, $closure, []));
	}//end migrate()

	/**
	 * An install that has the old name (PostgreSQL, SQLite, MySQL 8, the MariaDB workaround) is renamed.
	 *
	 * @return void
	 */
	public function testTheOldNameIsRenamed(): void {
		$this->migrate(hasTable: true, columns: ['id', 'preview_id', 'row_number'], expectRename: 1, expectWarning: 0);
	}//end testTheOldNameIsRenamed()

	/**
	 * An install whose table was created with the new name is left alone.
	 *
	 * @return void
	 */
	public function testTheNewNameIsLeftAlone(): void {
		$this->migrate(hasTable: true, columns: ['id', 'preview_id', 'row_no'], expectRename: 0, expectWarning: 0);
	}//end testTheNewNameIsLeftAlone()

	/**
	 * Without the table there is nothing to rename; Version1Date20260915050000 creates it with the new name.
	 *
	 * @return void
	 */
	public function testAMissingTableIsLeftAlone(): void {
		$this->migrate(hasTable: false, columns: [], expectRename: 0, expectWarning: 0);
	}//end testAMissingTableIsLeftAlone()

	/**
	 * A table with both names is not touched, and the upgrade says so.
	 *
	 * @return void
	 */
	public function testBothNamesWarnAndRenameNothing(): void {
		$this->migrate(hasTable: true, columns: ['row_number', 'row_no'], expectRename: 0, expectWarning: 1);
	}//end testBothNamesWarnAndRenameNothing()
}//end class
