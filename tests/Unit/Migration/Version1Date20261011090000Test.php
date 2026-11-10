<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261011090000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The record template table is created once, with the sharing columns of a
 * saved view and index names Oracle accepts.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261011090000
 *
 * @spec openspec/changes/records-saved-templates/specs/record-templates/spec.md#requirement-a-user-can-save-and-share-a-named-record-template
 */
class Version1Date20261011090000Test extends TestCase {
	use SchemaTableMockTrait;

	/**
	 * A schema without the table gets it, with every column the entity maps.
	 *
	 * @return void
	 */
	public function testItCreatesTheTable(): void {
		$columns = [];
		$indexes = [];
		$table = $this->createTableMock();
		$table->method('addColumn')->willReturnCallback(
			function (string $name) use (&$columns) {
				$columns[] = $name;
				return $this->createColumnMock();
			}
		);
		$table->method('addIndex')->willReturnCallback(
			function (array $cols, string $name) use (&$indexes, $table) {
				$indexes[] = $name;
				return $table;
			}
		);
		$table->method('addUniqueIndex')->willReturnCallback(
			function (array $cols, string $name) use (&$indexes, $table) {
				$indexes[] = $name;
				return $table;
			}
		);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);
		$schema->expects($this->once())->method('createTable')->with('openregister_record_templates')->willReturn($table);

		$step = new Version1Date20261011090000();
		self::assertSame($schema, $step->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));

		self::assertSame(
			['id', 'uuid', 'name', 'description', 'register', 'schema', 'template_values', 'owner', 'is_public', 'shared_with', 'created', 'updated'],
			$columns
		);
		foreach ($indexes as $name) {
			self::assertLessThanOrEqual(30, strlen($name), $name . ' is too long for Oracle.');
		}
	}//end testItCreatesTheTable()

	/**
	 * A second run finds the table and changes nothing.
	 *
	 * @return void
	 */
	public function testASecondRunChangesNothing(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->expects($this->never())->method('createTable');

		$step = new Version1Date20261011090000();
		self::assertSame($schema, $step->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}//end testASecondRunChangesNothing()
}//end class
