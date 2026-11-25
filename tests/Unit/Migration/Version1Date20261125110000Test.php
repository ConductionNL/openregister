<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261125110000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The closures table: one row per closed object, unique on the object (object-archive-state C29.4).
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
 */
class Version1Date20261125110000Test extends TestCase {
	use SchemaTableMockTrait;

	public function testTheClosureTableIsCreatedUniqueOnTheObject(): void {
		$columns = [];
		$table = $this->createTableMock();
		$table->method('addColumn')->willReturnCallback(
			function (string $name) use (&$columns) {
				$columns[] = $name;
				return $this->createColumnMock();
			}
		);
		$table->expects($this->once())->method('setPrimaryKey')->with(['id']);
		$table->expects($this->once())->method('addUniqueIndex')->with(['object_uuid'], 'uniq_or_entry_closure_obj')->willReturn($table);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_entry_closures')->willReturn(false);
		$schema->expects($this->once())->method('createTable')->with('openregister_entry_closures')->willReturn($table);

		$result = (new Version1Date20261125110000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);

		$this->assertSame($schema, $result);
		$this->assertSame(['id', 'object_uuid', 'closed_by', 'closed_at', 'reason'], $columns);
	}

	public function testAPresentTableIsLeftAlone(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_entry_closures')->willReturn(true);
		$schema->expects($this->never())->method('createTable');

		$result = (new Version1Date20261125110000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);

		$this->assertSame($schema, $result);
	}
}
