<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261011140000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The decision table is created once, and an entity relation gains the column
 * that links it to its current decision.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261011140000
 *
 * @spec openspec/changes/reviewer-owns-their-decisions/specs/decisions/spec.md#requirement-a-decision-is-its-own-object-req-dec-001
 */
class Version1Date20261011140000Test extends TestCase {
	use SchemaTableMockTrait;

	/**
	 * A schema without the table gets it, and the relation table gets the link.
	 *
	 * @return void
	 */
	public function testItCreatesTheTableAndTheLink(): void {
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

		$relationColumns = [];
		$relations = $this->createTableMock();
		$relations->method('hasColumn')->willReturn(false);
		$relations->method('addColumn')->willReturnCallback(
			function (string $name) use (&$relationColumns) {
				$relationColumns[] = $name;
				return $this->createColumnMock();
			}
		);
		$relations->method('addIndex')->willReturnCallback(
			function (array $cols, string $name) use (&$indexes, $relations) {
				$indexes[] = $name;
				return $relations;
			}
		);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnMap(
			[
				['openregister_decisions', false],
				['openregister_entity_relations', true],
			]
		);
		$schema->expects($this->once())->method('createTable')->with('openregister_decisions')->willReturn($table);
		$schema->method('getTable')->with('openregister_entity_relations')->willReturn($relations);

		$step = new Version1Date20261011140000();
		self::assertSame($schema, $step->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));

		self::assertSame(
			['id', 'uuid', 'subject_type', 'subject_id', 'verdict', 'decided_by', 'decided_at', 'note', 'supersedes'],
			$columns
		);
		self::assertSame(['decision_uuid'], $relationColumns);
		foreach ($indexes as $name) {
			self::assertLessThanOrEqual(30, strlen($name), $name . ' is too long for Oracle.');
		}
	}//end testItCreatesTheTableAndTheLink()

	/**
	 * A second run finds both and changes nothing.
	 *
	 * @return void
	 */
	public function testASecondRunChangesNothing(): void {
		$relations = $this->createTableMock();
		$relations->method('hasColumn')->willReturn(true);
		$relations->expects($this->never())->method('addColumn');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->expects($this->never())->method('createTable');
		$schema->method('getTable')->willReturn($relations);

		$step = new Version1Date20261011140000();
		self::assertSame($schema, $step->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}//end testASecondRunChangesNothing()
}//end class
