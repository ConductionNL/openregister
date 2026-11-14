<?php

/**
 * Unit tests for Version1Date20261113220000: the (flow_id, created) index on flow runs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/tasks-progress-report/tasks.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261113220000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Added once, on the window's columns; left alone when present or when the table is absent.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261113220000
 */
class Version1Date20261113220000Test extends TestCase {
	use SchemaTableMockTrait;

	public function testTheIndexIsAddedOnFlowAndCreated(): void {
		$table = $this->createTableMock();
		$table->method('hasIndex')->with('or_flowrun_flow_created_idx')->willReturn(false);
		$table->expects($this->once())->method('addIndex')->with(['flow_id', 'created'], 'or_flowrun_flow_created_idx');
		$schema = $this->schema(table: $table);

		$this->assertSame($schema, (new Version1Date20261113220000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}

	public function testAPresentIndexIsLeftAlone(): void {
		$table = $this->createTableMock();
		$table->method('hasIndex')->willReturn(true);
		$table->expects($this->never())->method('addIndex');

		(new Version1Date20261113220000())->changeSchema($this->createMock(IOutput::class), fn () => $this->schema(table: $table), []);
	}

	public function testWithoutTheTableNothingHappens(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$this->assertSame($schema, (new Version1Date20261113220000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}

	/**
	 * A schema holding the flow runs table.
	 *
	 * @param MockObject $table The table double.
	 *
	 * @return ISchemaWrapper
	 */
	private function schema(MockObject $table): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_flow_runs')->willReturn(true);
		$schema->method('getTable')->with('openregister_flow_runs')->willReturn($table);

		return $schema;
	}
}
