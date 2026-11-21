<?php

/**
 * The flow-attribution migration: three nullable columns and the run index,
 * each added on its own guard.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-object-attribution
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20260828120000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\IColumn;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The migration.
 */
class Version1Date20260828120000Test extends TestCase {
	use SchemaTableMockTrait;

	/**
	 * A schema holding the audit trail table.
	 *
	 * @param MockObject $table The audit trail table.
	 *
	 * @return ISchemaWrapper&MockObject
	 */
	private function schemaWith(MockObject $table): ISchemaWrapper&MockObject {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_audit_trails')->willReturn(true);
		$schema->method('getTable')->with('openregister_audit_trails')->willReturn($table);

		return $schema;
	}

	public function testItAddsThreeNullableColumnsAndTheRunIndex(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(false);
		$table->method('hasIndex')->willReturn(false);

		$added = [];
		$table->expects($this->exactly(3))->method('addColumn')->willReturnCallback(
			function (string $name, string $type, array $options) use (&$added) {
				$added[$name] = [$type, $options];
				return $this->createMock(IColumn::class);
			}
		);
		$table->expects($this->once())->method('addIndex')->with(['flow_run'], 'idx_audit_flow_run');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info');

		$schema = $this->schemaWith($table);
		$migration = new Version1Date20260828120000();
		$this->assertSame($schema, $migration->changeSchema($output, static fn (): ISchemaWrapper => $schema, []));

		$this->assertSame([Types::STRING, ['notnull' => false, 'length' => 64]], $added['flow_run']);
		$this->assertSame([Types::STRING, ['notnull' => false, 'length' => 255]], $added['flow_node']);
		$this->assertSame([Types::INTEGER, ['notnull' => false]], $added['flow_step']);
	}

	public function testAHalfMigratedTableGetsOnlyWhatIsMissing(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturnCallback(static fn (string $name): bool => $name === 'flow_run');
		$table->method('hasIndex')->willReturn(false);
		$table->expects($this->exactly(2))->method('addColumn')->with($this->logicalNot($this->equalTo('flow_run')));
		$table->expects($this->once())->method('addIndex');

		$schema = $this->schemaWith($table);
		(new Version1Date20260828120000())->changeSchema($this->createMock(IOutput::class), static fn (): ISchemaWrapper => $schema, []);
	}

	public function testARerunChangesNothing(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(true);
		$table->method('hasIndex')->willReturn(true);
		$table->expects($this->never())->method('addColumn');
		$table->expects($this->never())->method('addIndex');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');

		$schema = $this->schemaWith($table);
		$this->assertSame($schema, (new Version1Date20260828120000())->changeSchema($output, static fn (): ISchemaWrapper => $schema, []));
	}

	public function testWithoutTheAuditTableItDoesNothing(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$this->assertSame($schema, (new Version1Date20260828120000())->changeSchema($this->createMock(IOutput::class), static fn (): ISchemaWrapper => $schema, []));
	}
}
