<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261114100011;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Adding the `verification` column to the anonymisation log.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261114100011
 * @uses   \OCA\OpenRegister\Migration\TargetedMigrationStep
 *
 * @spec openspec/changes/redaction-release-safeguards/tasks.md#task-1.2
 */
class Version1Date20261114100011Test extends TestCase {
	use SchemaTableMockTrait;

	public function testTheColumnIsAddedWhenItIsMissing(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->with('verification')->willReturn(false);
		$table->expects($this->once())->method('addColumn')->with('verification', Types::TEXT, ['notnull' => false]);
		$schema = $this->schema(hasTable: true, table: $table);

		$this->assertSame($schema, $this->change(schema: $schema));
	}

	public function testAnExistingColumnIsLeftAlone(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->with('verification')->willReturn(true);
		$table->expects($this->never())->method('addColumn');
		$schema = $this->schema(hasTable: true, table: $table);

		$this->assertSame($schema, $this->change(schema: $schema));
	}

	public function testAnInstallWithoutTheLogIsLeftAlone(): void {
		$table = $this->createTableMock();
		$table->expects($this->never())->method('addColumn');
		$schema = $this->schema(hasTable: false, table: $table);

		$this->assertSame($schema, $this->change(schema: $schema));
	}

	private function change(ISchemaWrapper $schema): ?ISchemaWrapper {
		return (new Version1Date20261114100011())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}

	private function schema(bool $hasTable, object $table): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_anonymisation_log')->willReturn($hasTable);
		$schema->expects($hasTable === true ? $this->atLeastOnce() : $this->never())->method('getTable')->with('openregister_anonymisation_log')->willReturn($table);
		return $schema;
	}
}
