<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261106130000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Removing the flow oversight column and its stored setting.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261106130000
 */
class Version1Date20261106130000Test extends TestCase {
	use SchemaTableMockTrait;

	public function testTheColumnIsDroppedWhileItExists(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->with('oversight_enabled')->willReturn(true);
		$table->expects($this->once())->method('dropColumn')->with('oversight_enabled');

		$this->change(schema: $this->schema(hasTable: true, table: $table));
	}

	public function testAnInstallWithoutTheColumnIsLeftAlone(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(false);
		$table->expects($this->never())->method('dropColumn');

		$this->change(schema: $this->schema(hasTable: true, table: $table));
	}

	public function testAnInstallWithoutTheTableIsLeftAlone(): void {
		$table = $this->createTableMock();
		$table->expects($this->never())->method('dropColumn');
		$schema = $this->schema(hasTable: false, table: $table);

		$this->assertSame($schema, $this->change(schema: $schema));
	}

	public function testTheStoredSettingIsRemoved(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->expects($this->once())->method('deleteKey')->with('openregister', 'flow_oversight_enabled');

		(new Version1Date20261106130000($config))->postSchemaChange($this->createMock(IOutput::class), static fn () => null, []);
	}

	private function change(ISchemaWrapper $schema): ?ISchemaWrapper {
		$migration = new Version1Date20261106130000($this->createMock(IAppConfig::class));
		return $migration->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}

	private function schema(bool $hasTable, object $table): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_flows')->willReturn($hasTable);
		$schema->expects($hasTable === true ? $this->atLeastOnce() : $this->never())->method('getTable')->with('openregister_flows')->willReturn($table);
		return $schema;
	}
}
