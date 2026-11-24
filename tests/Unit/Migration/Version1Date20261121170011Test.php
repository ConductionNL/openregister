<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261121170011;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Adding the `crosses` column to the federated shares table.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261121170011
 * @uses   \OCA\OpenRegister\Migration\TargetedMigrationStep
 *
 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-a-federated-share-declares-what-crosses-req-pcf-002
 */
class Version1Date20261121170011Test extends TestCase {
	use SchemaTableMockTrait;

	public function testTheColumnIsAddedWhenItIsMissing(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->with('crosses')->willReturn(false);
		$table->expects($this->once())->method('addColumn')->with('crosses', Types::TEXT, ['notnull' => false, 'default' => null]);
		$schema = $this->schema(hasTable: true, table: $table);

		$this->assertSame($schema, $this->change(schema: $schema));
	}

	public function testAnExistingColumnIsLeftAlone(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->with('crosses')->willReturn(true);
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
		return (new Version1Date20261121170011())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}

	private function schema(bool $hasTable, object $table): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_federated_shares')->willReturn($hasTable);
		$schema->expects($hasTable === true ? $this->atLeastOnce() : $this->never())->method('getTable')->with('openregister_federated_shares')->willReturn($table);
		return $schema;
	}
}
