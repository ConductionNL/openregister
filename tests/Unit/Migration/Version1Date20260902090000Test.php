<?php

/**
 * The declared-behaviour migration: additive, guarded on existence, and a
 * no-op re-run returns null instead of a schema.
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
 * @spec openspec/changes/task-expiry-and-outcomes/specs/task-expiry-and-outcomes/spec.md#requirement-a-task-declares-its-timeout-and-reject-behaviour-in-one-vocabulary
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\OpenRegister\Migration\Version1Date20260902090000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The migration.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20260902090000
 */
class Version1Date20260902090000Test extends TestCase {

	/**
	 * A schema wrapper answering for the tasks table.
	 *
	 * @param Table&MockObject $table The table the wrapper serves.
	 *
	 * @return ISchemaWrapper&MockObject The wrapper.
	 */
	private function schemaWith(Table&MockObject $table): ISchemaWrapper&MockObject {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(
			static fn (string $name): bool => $name === 'openregister_tasks'
		);
		$schema->method('getTable')->willReturn($table);

		return $schema;
	}//end schemaWith()

	/**
	 * Run the step against a schema.
	 *
	 * @param ISchemaWrapper $schema The schema the closure yields.
	 *
	 * @return ISchemaWrapper|null What changeSchema() returned.
	 */
	private function apply(ISchemaWrapper $schema): ?ISchemaWrapper {
		$step = new Version1Date20260902090000();

		return $step->changeSchema(
			output: $this->createMock(IOutput::class),
			schemaClosure: static fn (): ISchemaWrapper => $schema,
			options: []
		);
	}//end apply()

	public function testAddsBothColumnsAndTheExpiryIndexWhenMissing(): void {
		$table = $this->createMock(Table::class);
		$table->method('hasColumn')->willReturn(false);
		$table->method('hasIndex')->willReturn(false);

		$added = [];
		$table->expects($this->exactly(2))->method('addColumn')->willReturnCallback(
			function (string $name, string $type, array $options) use (&$added): Table {
				$added[] = [$name, $type, $options['notnull']];

				return $this->createMock(Table::class);
			}
		);
		$table->expects($this->once())->method('addIndex')->with(['is_terminal', 'expires_at'], 'or_tasks_open_expiry');

		$schema = $this->schemaWith(table: $table);
		self::assertSame($schema, $this->apply(schema: $schema));
		self::assertSame([['on_timeout', 'string', false], ['on_reject', 'string', false]], $added);
	}//end testAddsBothColumnsAndTheExpiryIndexWhenMissing()

	public function testARerunAgainstAMigratedTableChangesNothingAndReturnsNull(): void {
		$table = $this->createMock(Table::class);
		$table->method('hasColumn')->willReturn(true);
		$table->method('hasIndex')->willReturn(true);
		$table->expects($this->never())->method('addColumn');
		$table->expects($this->never())->method('addIndex');

		self::assertNull($this->apply(schema: $this->schemaWith(table: $table)));
	}//end testARerunAgainstAMigratedTableChangesNothingAndReturnsNull()

	public function testAnAbsentTasksTableIsLeftAlone(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		self::assertNull($this->apply(schema: $schema));
	}//end testAnAbsentTasksTableIsLeftAlone()
}//end class
