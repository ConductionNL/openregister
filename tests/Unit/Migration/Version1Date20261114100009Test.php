<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261114100009;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\IColumn;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Version1Date20261114100009: nullable capacity columns on entity relations.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261114100009
 */
class Version1Date20261114100009Test extends TestCase {
	use SchemaTableMockTrait;

	public function testEveryCapacityColumnIsAddedNullableWhenAbsent(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(false);
		$added = [];
		$table->expects($this->exactly(5))->method('addColumn')->willReturnCallback(
			function (string $name, string $type, array $options) use (&$added) {
				$added[$name] = [$type, $options['notnull'], $options['length'] ?? null];
				return $this->createMock(IColumn::class);
			}
		);

		$schema = $this->schema(hasTable: true, table: $table);
		$this->assertSame($schema, $this->change(schema: $schema));

		$this->assertSame(
			[
				'capacity' => [Types::STRING, false, 32],
				'capacity_source' => [Types::STRING, false, 32],
				'capacity_applied' => [Types::STRING, false, 32],
				'capacity_confirmed_by' => [Types::STRING, false, 64],
				'capacity_confirmed_at' => [Types::DATETIME, false, null],
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
		return (new Version1Date20261114100009())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}

	private function schema(bool $hasTable, object $table): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_entity_relations')->willReturn($hasTable);
		$schema->expects($hasTable === true ? $this->atLeastOnce() : $this->never())->method('getTable')->with('openregister_entity_relations')->willReturn($table);
		return $schema;
	}
}
