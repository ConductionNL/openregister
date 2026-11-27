<?php

/**
 * Unit tests for Version1Date20261114170013: the calendar flow trigger moments table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\TargetedMigrationStep;
use OCA\OpenRegister\Migration\Version1Date20261114170013;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Created once with its unique trigger index; a present table is left alone.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261114170013
 * @uses   \OCA\OpenRegister\Migration\TargetedMigrationStep
 */
class Version1Date20261114170013Test extends TestCase {
	use SchemaTableMockTrait;

	public function testTheStepIsTargeted(): void {
		$this->assertInstanceOf(TargetedMigrationStep::class, new Version1Date20261114170013());
	}

	public function testTheTableIsCreatedWithItsIndexes(): void {
		$table = $this->createTableMock();
		$table->expects($this->exactly(9))->method('addColumn');
		$table->expects($this->once())->method('setPrimaryKey')->with(['id']);
		$table->expects($this->once())->method('addUniqueIndex')->with(['flow_uuid', 'node_id', 'object_uuid'], 'or_flowmoment_trigger_obj_uq');
		$table->expects($this->exactly(2))->method('addIndex');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_flow_moments')->willReturn(false);
		$schema->expects($this->once())->method('createTable')->with('openregister_flow_moments')->willReturn($table);

		$this->assertSame($schema, (new Version1Date20261114170013())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}

	public function testAPresentTableIsLeftAloneAndTheSchemaHandedBack(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_flow_moments')->willReturn(true);
		$schema->expects($this->never())->method('createTable');

		$this->assertSame($schema, (new Version1Date20261114170013())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}
}
