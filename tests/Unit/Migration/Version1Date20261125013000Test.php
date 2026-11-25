<?php

/**
 * Version1Date20261125013000Test: the member path column on entity relations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261125013000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\IColumn;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Version1Date20261125013000: a nullable member path on entity relations.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261125013000
 *
 * @spec openspec/changes/archive-members-are-searchable/specs/text-extraction/spec.md#requirement-each-member-is-extracted-and-searchable-on-its-own-req-ams-002
 */
class Version1Date20261125013000Test extends TestCase {
	use SchemaTableMockTrait;

	public function testTheMemberColumnIsAddedNullableWhenAbsent(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->with('archive_member')->willReturn(false);
		$added = [];
		$table->expects($this->once())->method('addColumn')->willReturnCallback(
			function (string $name, string $type, array $options) use (&$added) {
				$added[$name] = [$type, $options['notnull'], $options['length']];
				return $this->createMock(IColumn::class);
			}
		);

		$schema = $this->schema(hasTable: true, table: $table);

		$this->assertSame($schema, $this->change(schema: $schema));
		$this->assertSame(['archive_member' => [Types::STRING, false, 1024]], $added);
	}

	public function testAnExistingColumnIsLeftAlone(): void {
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
		return (new Version1Date20261125013000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}

	private function schema(bool $hasTable, object $table): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_entity_relations')->willReturn($hasTable);
		$schema->expects($hasTable === true ? $this->atLeastOnce() : $this->never())->method('getTable')->with('openregister_entity_relations')->willReturn($table);
		return $schema;
	}
}
