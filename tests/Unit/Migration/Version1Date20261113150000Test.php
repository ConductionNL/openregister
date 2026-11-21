<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261113150000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\IColumn;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Version1Date20261113150000: nullable page and box on entity relations.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261113150000
 */
class Version1Date20261113150000Test extends TestCase {
	use SchemaTableMockTrait;

	public function testBothColumnsAreAddedNullableWhenAbsent(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(false);
		$added = [];
		$table->expects($this->exactly(2))->method('addColumn')->willReturnCallback(
			function (string $name, string $type, array $options) use (&$added) {
				$added[$name] = [$type, $options['notnull']];
				return $this->createMock(IColumn::class);
			}
		);

		$this->change(schema: $this->schema(hasTable: true, table: $table));

		$this->assertSame(['page' => [Types::INTEGER, false], 'box' => [Types::JSON, false]], $added);
	}

	public function testExistingColumnsAreLeftAlone(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(true);
		$table->expects($this->never())->method('addColumn');

		$this->change(schema: $this->schema(hasTable: true, table: $table));
	}

	public function testAnInstallWithoutTheTableIsLeftAlone(): void {
		$table = $this->createTableMock();
		$table->expects($this->never())->method('addColumn');
		$schema = $this->schema(hasTable: false, table: $table);

		$this->assertSame($schema, $this->change(schema: $schema));
	}

	private function change(ISchemaWrapper $schema): ?ISchemaWrapper {
		return (new Version1Date20261113150000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}

	private function schema(bool $hasTable, object $table): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_entity_relations')->willReturn($hasTable);
		$schema->expects($hasTable === true ? $this->atLeastOnce() : $this->never())->method('getTable')->with('openregister_entity_relations')->willReturn($table);
		return $schema;
	}
}
