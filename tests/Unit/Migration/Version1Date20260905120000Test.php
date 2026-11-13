<?php

/**
 * The run-lock registry migration: creates the table the entity maps, once.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-every-lock-a-run-holds-is-released-when-the-run-ends
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Db\RunObjectLock;
use OCA\OpenRegister\Migration\Version1Date20260905120000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;

/**
 * The migration.
 */
#[CoversClass(Version1Date20260905120000::class)]
#[UsesClass(RunObjectLock::class)]
class Version1Date20260905120000Test extends TestCase {
	use SchemaTableMockTrait;

	/**
	 * The table is created with a column for every field the entity maps, the
	 * one-lock-per-run-and-object unique index and the two read indexes.
	 *
	 * @return void
	 */
	public function testTheTableIsCreatedWithEveryColumnTheEntityMaps(): void {
		$columns = [];
		$table = $this->createTableMock();
		$table->method('addColumn')->willReturnCallback(
			function (string $name, string $type, array $options) use (&$columns) {
				$columns[$name] = $options;
				return $this->createColumnMock();
			}
		);
		$table->expects($this->once())->method('setPrimaryKey')->with(['id']);
		$table->expects($this->once())->method('addUniqueIndex')->with(['run_uuid', 'object_uuid'], 'or_runlock_run_obj_uq');
		$indexes = [];
		$table->expects($this->exactly(2))->method('addIndex')->willReturnCallback(
			function (array $cols, string $name) use (&$indexes, $table) {
				$indexes[$name] = $cols;
				return $table;
			}
		);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_run_object_locks')->willReturn(false);
		$schema->expects($this->once())->method('createTable')->with('openregister_run_object_locks')->willReturn($table);

		$result = (new Version1Date20260905120000())->changeSchema(
			$this->createMock(IOutput::class),
			static fn (): ISchemaWrapper => $schema,
			[]
		);

		$this->assertSame($schema, $result);
		$this->assertSame(['or_runlock_run_idx' => ['run_uuid'], 'or_runlock_exp_idx' => ['expires_at']], $indexes);

		$mapped = ['id'];
		foreach (array_keys((new RunObjectLock())->getFieldTypes()) as $field) {
			$mapped[] = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $field));
		}

		sort($mapped);
		$created = array_keys($columns);
		sort($created);
		$this->assertSame(array_values(array_unique($mapped)), $created);

		// The two identities are required; a lock without an expiry is the TTL-less case.
		$this->assertTrue($columns['run_uuid']['notnull']);
		$this->assertTrue($columns['object_uuid']['notnull']);
		$this->assertTrue($columns['locked_at']['notnull']);
		$this->assertFalse($columns['expires_at']['notnull']);
	}//end testTheTableIsCreatedWithEveryColumnTheEntityMaps()

	/**
	 * Re-running the migration on an instance that already has the table changes nothing.
	 *
	 * @return void
	 */
	public function testAnExistingTableIsLeftAlone(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->expects($this->never())->method('createTable');

		$result = (new Version1Date20260905120000())->changeSchema(
			$this->createMock(IOutput::class),
			static fn (): ISchemaWrapper => $schema,
			[]
		);

		$this->assertSame($schema, $result);
	}//end testAnExistingTableIsLeftAlone()
}//end class
