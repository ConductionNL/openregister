<?php

/**
 * Version1Date20261126090000Test: the mismatch columns on the file digest records.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261126090000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\IColumn;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Version1Date20261126090000: nullable mismatch columns on openregister_file_digests.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261126090000
 *
 * @spec openspec/changes/published-file-digest/specs/file-actions/spec.md#requirement-a-published-file-is-checked-against-its-digest-when-it-is-read-req-pfd-002
 */
class Version1Date20261126090000Test extends TestCase {
	use SchemaTableMockTrait;

	public function testBothColumnsAreAddedNullableWhenAbsent(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(false);
		$added = [];
		$table->expects($this->exactly(2))->method('addColumn')->willReturnCallback(
			function (string $name, string $type, array $options) use (&$added) {
				$added[$name] = [$type, $options['notnull'], ($options['length'] ?? null)];
				return $this->createMock(IColumn::class);
			}
		);

		$schema = $this->schema(hasTable: true, table: $table);

		$this->assertSame($schema, $this->change(schema: $schema));
		$this->assertSame(
			[
				'mismatch_at' => [Types::DATETIME, false, null],
				'mismatch_sha256' => [Types::STRING, false, 64],
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
		return (new Version1Date20261126090000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}

	private function schema(bool $hasTable, object $table): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_file_digests')->willReturn($hasTable);
		$schema->expects($hasTable === true ? $this->atLeastOnce() : $this->never())->method('getTable')->with('openregister_file_digests')->willReturn($table);
		return $schema;
	}
}
