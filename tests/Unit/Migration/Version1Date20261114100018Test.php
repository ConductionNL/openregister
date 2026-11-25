<?php

/**
 * Unit tests for Version1Date20261114100018: the document store move table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261114100018;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Created once, with its key columns and indexes; left alone when present.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261114100018
 */
class Version1Date20261114100018Test extends TestCase {
	use SchemaTableMockTrait;

	public function testTheMoveTableIsCreatedWithItsIndexes(): void {
		$columns = [];
		$table   = $this->createTableMock();
		$table->method('addColumn')->willReturnCallback(
			function (string $name) use (&$columns) {
				$columns[] = $name;
				return $this->createColumnMock();
			}
		);
		$table->expects($this->once())->method('setPrimaryKey')->with(['id']);
		$indexes = [];
		$table->method('addIndex')->willReturnCallback(
			function (array $cols, string $name) use (&$indexes, $table) {
				$indexes[$name] = $cols;
				return $table;
			}
		);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_document_moves')->willReturn(false);
		$schema->expects($this->once())->method('createTable')->with('openregister_document_moves')->willReturn($table);

		$result = (new Version1Date20261114100018())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);

		$this->assertSame($schema, $result);
		$this->assertSame(
			['id', 'register_id', 'old_id', 'new_id', 'kind', 'version_time', 'sha256', 'state', 'reason', 'moved_at'],
			$columns
		);
		$this->assertSame(
			['idx_or_docmove_reg_state' => ['register_id', 'state'], 'idx_or_docmove_old' => ['old_id']],
			$indexes
		);
	}

	public function testAPresentTableIsLeftAlone(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_document_moves')->willReturn(true);
		$schema->expects($this->never())->method('createTable');

		$this->assertSame($schema, (new Version1Date20261114100018())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}
}
