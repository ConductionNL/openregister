<?php

/**
 * The withdrawal migration: additive, guarded on existence, and a re-run adds nothing.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261113210000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The migration.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261113210000
 */
class Version1Date20261113210000Test extends TestCase {
	use SchemaTableMockTrait;

	/**
	 * Run the step against a schema.
	 *
	 * @param ISchemaWrapper $schema The schema the closure yields.
	 *
	 * @return ISchemaWrapper|null What changeSchema() returned.
	 */
	private function apply(ISchemaWrapper $schema): ?ISchemaWrapper {
		return (new Version1Date20261113210000())->changeSchema(
			output: $this->createMock(IOutput::class),
			schemaClosure: static fn (): ISchemaWrapper => $schema,
			options: []
		);
	}//end apply()

	/**
	 * A schema wrapper that has the timeline entries table, or not.
	 *
	 * @param MockObject|null $table The table, or null when the table is absent.
	 *
	 * @return ISchemaWrapper&MockObject The wrapper.
	 */
	private function schemaWith(?MockObject $table): ISchemaWrapper&MockObject {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(
			static fn (string $name): bool => $table !== null && $name === 'openregister_timeline_entries'
		);
		if ($table !== null) {
			$schema->method('getTable')->with('openregister_timeline_entries')->willReturn($table);
		}

		return $schema;
	}//end schemaWith()

	public function testAddsTheThreeWithdrawalColumnsWhenMissing(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(false);
		$added = [];
		$column = $this->createColumnMock();
		$table->method('addColumn')->willReturnCallback(
			function (string $name, string $type, array $options) use (&$added, $column) {
				$added[$name] = [$type, $options];
				return $column;
			}
		);
		$schema = $this->schemaWith(table: $table);

		$this->assertSame($schema, $this->apply(schema: $schema));
		$this->assertSame(
			[
				'withdrawn_by' => [Types::STRING, ['notnull' => false, 'length' => 64]],
				'withdrawn_at' => [Types::DATETIME, ['notnull' => false]],
				'withdrawn_reason' => [Types::TEXT, ['notnull' => false]],
			],
			$added
		);
	}//end testAddsTheThreeWithdrawalColumnsWhenMissing()

	public function testARerunChangesNothing(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(true);
		$table->expects($this->never())->method('addColumn');
		$schema = $this->schemaWith(table: $table);

		$this->assertSame($schema, $this->apply(schema: $schema));
	}//end testARerunChangesNothing()

	public function testWithoutTheTableNothingHappens(): void {
		$schema = $this->schemaWith(table: null);
		$schema->expects($this->never())->method('getTable');

		$this->assertSame($schema, $this->apply(schema: $schema));
	}//end testWithoutTheTableNothingHappens()
}//end class
