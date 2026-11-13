<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261113190000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The caller record gains `client`, and the unique index covers it.
 *
 * @spec openspec/changes/api-client-libraries/tasks.md#task-1.1
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261113190000
 */
class Version1Date20261113190000Test extends TestCase {
	use SchemaTableMockTrait;

	private const TABLE = 'openregister_api_calls';

	public function testTheClientColumnIsAddedAndTheUniqueIndexRebuiltOverFiveKeys(): void {
		$events = [];
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(false);
		$table->method('hasIndex')->willReturnCallback(static fn (string $name): bool => $name === 'idx_or_apicall_unique');
		$table->method('addColumn')->willReturnCallback(function (string $name, string $type, array $options) use (&$events) {
			$events[] = ['addColumn', $name, $type, $options];
			return $this->createColumnMock();
		});
		$table->method('dropIndex')->willReturnCallback(function (string $name) use (&$events, $table) {
			$events[] = ['dropIndex', $name];
			return $table;
		});
		$table->method('addUniqueIndex')->willReturnCallback(function (array $columns, ?string $name = null) use (&$events, $table) {
			$events[] = ['addUniqueIndex', $columns, $name];
			return $table;
		});

		$result = $this->runMigration(schema: $this->schema(hasTable: true, table: $table));

		$this->assertNotNull($result);
		$this->assertSame(
			[
				['addColumn', 'client', 'string', ['notnull' => true, 'length' => 48, 'default' => '']],
				['dropIndex', 'idx_or_apicall_unique'],
				['addUniqueIndex', ['principal', 'route', 'method', 'api_version', 'client'], 'idx_or_apicall_unique'],
			],
			$events
		);
	}

	public function testATableThatAlreadyHasTheColumnIsLeftAlone(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->with('client')->willReturn(true);
		$table->expects($this->never())->method('addColumn');
		$table->expects($this->never())->method('dropIndex');
		$table->expects($this->never())->method('addUniqueIndex');

		$this->assertNull($this->runMigration(schema: $this->schema(hasTable: true, table: $table)));
	}

	public function testAnInstallWithoutTheTableIsLeftAlone(): void {
		$schema = $this->schema(hasTable: false, table: $this->createTableMock());
		$schema->expects($this->never())->method('getTable');

		$this->assertNull($this->runMigration(schema: $schema));
	}

	private function runMigration(ISchemaWrapper $schema): ?ISchemaWrapper {
		return (new Version1Date20261113190000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}

	private function schema(bool $hasTable, $table): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with(self::TABLE)->willReturn($hasTable);
		$schema->method('getTable')->with(self::TABLE)->willReturn($table);

		return $schema;
	}
}
