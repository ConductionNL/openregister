<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20260915050000;
use OCA\OpenRegister\Migration\Version1Date20261106120000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The rename of the reserved row_number column, and the fresh schema that no longer holds it.
 *
 * The rename itself ran on real MariaDB and PostgreSQL; these cases pin when it fires and what it sends.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261106120000
 * @covers \OCA\OpenRegister\Migration\Version1Date20260915050000
 */
class Version1Date20261106120000Test extends TestCase {
	use SchemaTableMockTrait;

	private const TABLE = 'openregister_import_preview_rows';

	public function testAnInstallHoldingTheOldColumnIsRenamedWithQuotedNames(): void {
		$query = $this->createMock(IQueryBuilder::class);
		$query->method('getTableName')->willReturnCallback(static fn (string $name): string => '`oc_' . $name . '`');
		$query->method('getColumnName')->willReturnCallback(static fn (string $name): string => '`' . $name . '`');
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getQueryBuilder')->willReturn($query);
		$connection->expects($this->once())->method('executeStatement')
			->with('ALTER TABLE `oc_openregister_import_preview_rows` RENAME COLUMN `row_number` TO `source_row`');

		$this->runRename(connection: $connection, schema: $this->schema(hasTable: true, hasOldColumn: true));
	}

	public function testAnInstallAlreadyOnTheNewColumnIsLeftAlone(): void {
		$connection = $this->createMock(IDBConnection::class);
		$connection->expects($this->never())->method('executeStatement');

		$this->runRename(connection: $connection, schema: $this->schema(hasTable: true, hasOldColumn: false));
	}

	public function testAnInstallWithoutTheTableIsLeftAlone(): void {
		$connection = $this->createMock(IDBConnection::class);
		$connection->expects($this->never())->method('executeStatement');

		$this->runRename(connection: $connection, schema: $this->schema(hasTable: false, hasOldColumn: false));
	}

	public function testAFreshSchemaHoldsNoReservedRowNumberColumn(): void {
		$columns = [];
		$table = $this->createTableMock();
		$table->method('addColumn')->willReturnCallback(function (string $name) use (&$columns) {
			$columns[] = $name;
			return $this->createColumnMock();
		});
		$indexes = [];
		$table->method('addUniqueIndex')->willReturnCallback(function (array $names) use (&$indexes, $table) {
			$indexes[] = $names;
			return $table;
		});
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(static fn (string $name): bool => $name !== self::TABLE);
		$schema->method('createTable')->with(self::TABLE)->willReturn($table);

		(new Version1Date20260915050000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);

		$this->assertContains('source_row', $columns);
		$this->assertNotContains('row_number', $columns);
		$this->assertContains(['preview_id', 'source_row'], $indexes);
	}

	private function runRename(IDBConnection $connection, ISchemaWrapper $schema): void {
		(new Version1Date20261106120000($connection))->preSchemaChange($this->createMock(IOutput::class), static fn () => $schema, []);
	}

	private function schema(bool $hasTable, bool $hasOldColumn): ISchemaWrapper {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturnCallback(static fn (string $name): bool => $name === 'row_number' && $hasOldColumn);
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with(self::TABLE)->willReturn($hasTable);
		$schema->expects($hasTable === true ? $this->once() : $this->never())->method('getTable')->with(self::TABLE)->willReturn($table);
		return $schema;
	}
}
