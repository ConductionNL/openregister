<?php

/**
 * The classification and catalogue migration on openregister_schemas.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/modelling-type-catalogue-metadata/design.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261113140100;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Both columns are added, the classification is indexed, and an instance that already has them is left alone.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261113140100
 */
class Version1Date20261113140100Test extends TestCase {
	use SchemaTableMockTrait;

	private function change(ISchemaWrapper $schema): ?ISchemaWrapper {
		return (new Version1Date20261113140100())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}

	public function testBothColumnsAreAddedWithTheClassificationIndex(): void {
		$columns = [];
		$indexes = [];
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(false);
		$table->method('addColumn')->willReturnCallback(
			function (string $name) use (&$columns) {
				$columns[] = $name;
				return $this->createColumnMock();
			}
		);
		$table->method('addIndex')->willReturnCallback(
			function (array $cols, string $name) use (&$indexes, $table) {
				$indexes[$name] = $cols;
				return $table;
			}
		);
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_schemas')->willReturn(true);
		$schema->method('getTable')->with('openregister_schemas')->willReturn($table);

		$this->assertSame($schema, $this->change($schema));
		$this->assertSame(['classification', 'catalogue'], $columns);
		$this->assertSame(['classification'], $indexes['idx_or_schemas_classification']);
	}

	public function testAnInstanceThatAlreadyHasBothIsLeftAlone(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(true);
		$table->expects($this->never())->method('addColumn');
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_schemas')->willReturn(true);
		$schema->method('getTable')->willReturn($table);

		$this->assertNull($this->change($schema));
	}

	public function testWithoutTheTableNothingHappens(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_schemas')->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$this->assertNull($this->change($schema));
	}
}
