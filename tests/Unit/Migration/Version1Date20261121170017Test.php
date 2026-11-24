<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261121170017;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\IColumn;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Version1Date20261121170017: the audit trail's trigger_object column and its index.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261121170017
 */
class Version1Date20261121170017Test extends TestCase {
	use SchemaTableMockTrait;

	public function testTheColumnAndIndexAreAddedWhenAbsent(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturnMap([['trigger_object', false], ['action', true]]);
		$table->method('hasIndex')->with('or_audit_trigger_action')->willReturn(false);
		$table->expects($this->once())->method('addColumn')
			->with('trigger_object', Types::STRING, ['notnull' => false, 'length' => 36])
			->willReturn($this->createMock(IColumn::class));
		$table->expects($this->once())->method('addIndex')->with(['trigger_object', 'action'], 'or_audit_trigger_action');

		$schema = $this->schema(hasTable: true, table: $table);

		$this->assertSame($schema, $this->change(schema: $schema));
	}

	public function testAnExistingColumnAndIndexAreLeftAlone(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(true);
		$table->method('hasIndex')->willReturn(true);
		$table->expects($this->never())->method('addColumn');
		$table->expects($this->never())->method('addIndex');

		$this->change(schema: $this->schema(hasTable: true, table: $table));
	}

	public function testAnInstallWithoutTheTableIsLeftAlone(): void {
		$table = $this->createTableMock();
		$table->expects($this->never())->method('addColumn');
		$schema = $this->schema(hasTable: false, table: $table);

		$this->assertSame($schema, $this->change(schema: $schema));
	}

	private function change(ISchemaWrapper $schema): ?ISchemaWrapper {
		return (new Version1Date20261121170017())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}

	private function schema(bool $hasTable, object $table): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_audit_trails')->willReturn($hasTable);
		$schema->expects($hasTable === true ? $this->atLeastOnce() : $this->never())->method('getTable')->with('openregister_audit_trails')->willReturn($table);
		return $schema;
	}
}
