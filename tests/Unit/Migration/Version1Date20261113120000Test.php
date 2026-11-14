<?php

/**
 * Unit tests for the automation run records table migration.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/automation-run-records/design.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261113120000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The table is created once, with the key column out of the reserved word and the two read indexes.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261113120000
 */
class Version1Date20261113120000Test extends TestCase {
	use SchemaTableMockTrait;

	public function testTheTableIsCreatedWithItsColumnsAndIndexes(): void {
		$columns = [];
		$indexes = [];
		$table   = $this->createTableMock();
		$table->method('addColumn')->willReturnCallback(
			function (string $name) use (&$columns) {
				$columns[] = $name;
				return $this->createColumnMock();
			}
		);
		$table->method('addIndex')->willReturnCallback(
			static function (array $cols, string $name) use (&$indexes, &$table) {
				$indexes[$name] = $cols;
				return $table;
			}
		);
		$table->expects($this->once())->method('setPrimaryKey')->with(['id']);
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_automation_records')->willReturn(false);
		$schema->expects($this->once())->method('createTable')->with('openregister_automation_records')->willReturn($table);

		$this->assertSame($schema, $this->change(schema: $schema));
		$this->assertSame(
			['id', 'kind', 'automation_key', 'name', 'transition', 'schema_id', 'register_id', 'object_uuid', 'event_id', 'outcome', 'reason', 'channels', 'message', 'recorded_at'],
			$columns
		);
		$this->assertSame(['automation_key', 'recorded_at'], $indexes['idx_or_autrec_key_at']);
		$this->assertSame(['object_uuid'], $indexes['idx_or_autrec_object']);
	}

	public function testAnExistingTableIsLeftAlone(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_automation_records')->willReturn(true);
		$schema->expects($this->never())->method('createTable');

		$this->assertSame($schema, $this->change(schema: $schema));
	}

	private function change(ISchemaWrapper $schema): ?ISchemaWrapper {
		return (new Version1Date20261113120000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}
}
