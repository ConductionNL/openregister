<?php

declare(strict_types=1);

/*
 * Tests for Version1Date20250321061615, which adds the organisation, ownership,
 * authorization, deletion and processing columns to the objects, schemas,
 * registers and audit trail tables, and drops the old object audit log table.
 *
 * Pins the exact column definitions and their order, the per-column
 * idempotency, and the table drop.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20250321061615;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Mock-driven test of the 2025-03-21 column additions.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20250321061615
 */
class Version1Date20250321061615Test extends TestCase {
	use SchemaTableMockTrait;

	private const STRING_255 = ['notnull' => false, 'length' => 255];

	private const NULLABLE = ['notnull' => false];

	/**
	 * Every column the migration adds, per table, in the order it adds them.
	 *
	 * @return array<string, array<int, array{0: string, 1: string, 2: array<string, mixed>}>>
	 */
	private function expectedColumns(): array {
		return [
			'openregister_objects'      => [
				['organisation', Types::STRING, self::STRING_255],
				['application', Types::STRING, self::STRING_255],
				['validation', Types::JSON, self::NULLABLE],
				['deleted', Types::JSON, self::NULLABLE],
				['geo', Types::JSON, self::NULLABLE],
				['retention', Types::JSON, self::NULLABLE],
			],
			'openregister_schemas'      => [
				['slug', Types::STRING, self::STRING_255],
				['owner', Types::STRING, self::STRING_255],
				['application', Types::STRING, self::STRING_255],
				['organisation', Types::STRING, self::STRING_255],
				['authorization', Types::JSON, self::NULLABLE],
				['deleted', Types::DATETIME, self::NULLABLE],
			],
			'openregister_registers'    => [
				['slug', Types::STRING, self::STRING_255],
				['owner', Types::STRING, self::STRING_255],
				['application', Types::STRING, self::STRING_255],
				['organisation', Types::STRING, self::STRING_255],
				['authorization', Types::JSON, self::NULLABLE],
				['deleted', Types::DATETIME, self::NULLABLE],
			],
			'openregister_audit_trails' => [
				['object_uuid', Types::STRING, self::STRING_255],
				['register_uuid', Types::STRING, self::STRING_255],
				['schema_uuid', Types::STRING, self::STRING_255],
				['organisation_id', Types::STRING, self::STRING_255],
				['organisation_id_type', Types::STRING, ['notnull' => false, 'length' => 50]],
				['processing_activity_id', Types::STRING, self::STRING_255],
				['processing_activity_url', Types::STRING, self::STRING_255],
				['processing_id', Types::STRING, self::STRING_255],
				['confidentiality', Types::STRING, self::STRING_255],
				['retention_period', Types::STRING, self::STRING_255],
			],
		];
	}//end expectedColumns()

	/**
	 * Run the migration against tables that report the given existing columns.
	 *
	 * @param array<string, array<int, string>> $existing      Existing columns per table.
	 * @param bool                              $oldLogsExists Whether the old audit log table exists.
	 *
	 * @return array<int, array<int, mixed>> The recorded schema calls, in order.
	 */
	private function runMigration(array $existing, bool $oldLogsExists): array {
		$calls  = [];
		$tables = [];
		foreach (array_keys($this->expectedColumns()) as $name) {
			$table = $this->createTableMock();
			$table->method('hasColumn')->willReturnCallback(
				fn (string $column): bool => in_array($column, $existing[$name] ?? [], true)
			);
			$table->method('addColumn')->willReturnCallback(
				function (string $column, string $type, array $options) use (&$calls, $name) {
					$calls[] = ['add', $name, $column, $type, $options];
					return $this->createColumnMock();
				}
			);
			$tables[$name] = $table;
		}

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('getTable')->willReturnCallback(
			function (string $name) use (&$calls, $tables) {
				$calls[] = ['getTable', $name];
				return $tables[$name];
			}
		);
		$schema->method('hasTable')->willReturnCallback(
			function (string $name) use (&$calls, $oldLogsExists): bool {
				$calls[] = ['hasTable', $name];
				return $oldLogsExists;
			}
		);
		$schema->method('dropTable')->willReturnCallback(
			function (string $name) use (&$calls, $schema) {
				$calls[] = ['dropTable', $name];
				return $schema;
			}
		);

		$result = (new Version1Date20250321061615())->changeSchema($this->createMock(IOutput::class), fn () => $schema, []);
		$this->assertSame($schema, $result);

		return $calls;
	}//end runMigration()

	/**
	 * On an empty schema every column is added in order and the old table is dropped.
	 *
	 * @return void
	 */
	public function testAddsEveryColumnInOrderAndDropsTheOldTable(): void {
		$expected = [];
		foreach ($this->expectedColumns() as $table => $columns) {
			$expected[] = ['getTable', $table];
			foreach ($columns as [$column, $type, $options]) {
				$expected[] = ['add', $table, $column, $type, $options];
			}
		}

		$expected[] = ['hasTable', 'openregister_object_audit_logs'];
		$expected[] = ['dropTable', 'openregister_object_audit_logs'];

		$this->assertSame($expected, $this->runMigration(existing: [], oldLogsExists: true));
	}//end testAddsEveryColumnInOrderAndDropsTheOldTable()

	/**
	 * When every column exists nothing is added, and an absent old table is not dropped.
	 *
	 * @return void
	 */
	public function testIsIdempotentWhenEverythingExists(): void {
		$existing = [];
		foreach ($this->expectedColumns() as $table => $columns) {
			$existing[$table] = array_column($columns, 0);
		}

		$expected = [
			['getTable', 'openregister_objects'],
			['getTable', 'openregister_schemas'],
			['getTable', 'openregister_registers'],
			['getTable', 'openregister_audit_trails'],
			['hasTable', 'openregister_object_audit_logs'],
		];

		$this->assertSame($expected, $this->runMigration(existing: $existing, oldLogsExists: false));
	}//end testIsIdempotentWhenEverythingExists()

	/**
	 * Only the missing columns are added; a column a table already has is skipped per table.
	 *
	 * @return void
	 */
	public function testAddsOnlyMissingColumns(): void {
		$existing = [
			'openregister_objects'      => ['organisation', 'geo'],
			'openregister_schemas'      => ['deleted'],
			'openregister_audit_trails' => ['organisation_id_type', 'object_uuid'],
		];

		$added = [];
		foreach ($this->runMigration(existing: $existing, oldLogsExists: false) as $call) {
			if ($call[0] === 'add') {
				$added[] = $call[1] . '.' . $call[2];
			}
		}

		$this->assertNotContains('openregister_objects.organisation', $added);
		$this->assertNotContains('openregister_objects.geo', $added);
		$this->assertContains('openregister_objects.application', $added);
		$this->assertNotContains('openregister_schemas.deleted', $added);
		$this->assertContains('openregister_registers.deleted', $added);
		$this->assertNotContains('openregister_audit_trails.organisation_id_type', $added);
		$this->assertContains('openregister_audit_trails.register_uuid', $added);
		$this->assertCount(28 - 5, $added);
	}//end testAddsOnlyMissingColumns()
}//end class
