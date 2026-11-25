<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261124210000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\IColumn;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Version1Date20261124210000: the audit trail's rule_digest and rule_version columns.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261124210000
 *
 * @spec openspec/changes/read-audit-entries-name-the-rule/specs/audit-trail-immutable/spec.md#requirement-a-read-entry-names-the-access-rule-that-let-the-reader-see-the-object
 */
class Version1Date20261124210000Test extends TestCase {
	use SchemaTableMockTrait;

	public function testBothNullableColumnsAreAddedToAnExistingTable(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(false);
		$added = [];
		$table->expects($this->exactly(2))->method('addColumn')
			->willReturnCallback(function (string $name, string $type, array $options) use (&$added): IColumn {
				$added[] = [$name, $type, $options];
				return $this->createMock(IColumn::class);
			});

		$schema = $this->schema(hasTable: true, table: $table);

		$this->assertSame($schema, $this->change(schema: $schema));
		$this->assertSame(
			[
				['rule_digest', Types::STRING, ['notnull' => false, 'length' => 64]],
				['rule_version', Types::STRING, ['notnull' => false, 'length' => 64]],
			],
			$added
		);
	}

	public function testExistingColumnsAreLeftAlone(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(true);
		$table->expects($this->never())->method('addColumn');

		$schema = $this->schema(hasTable: true, table: $table);

		$this->assertSame($schema, $this->change(schema: $schema));
	}

	public function testAnInstallWithoutTheTableIsLeftAlone(): void {
		$table = $this->createTableMock();
		$table->expects($this->never())->method('addColumn');
		$schema = $this->schema(hasTable: false, table: $table);

		$this->assertSame($schema, $this->change(schema: $schema));
	}

	private function change(ISchemaWrapper $schema): ?ISchemaWrapper {
		return (new Version1Date20261124210000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}

	private function schema(bool $hasTable, object $table): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_audit_trails')->willReturn($hasTable);
		$schema->expects($hasTable === true ? $this->atLeastOnce() : $this->never())->method('getTable')->with('openregister_audit_trails')->willReturn($table);
		return $schema;
	}
}
