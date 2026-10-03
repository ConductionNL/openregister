<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261003090000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;

/**
 * The audit trail gets an index that leads with the object uuid.
 *
 * Every per-object audit read (the history tab, revert, a leaf app's flow
 * report through AuditTrailMapper::findChangesForObject) filters on
 * `object_uuid` and orders by `created`. Without this index each of those
 * reads is a sequential scan over the whole audit table: 788 ms for one
 * object on a 599k-row table (planninq live pass, 3 Oct 2026).
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-history-of-one-object-is-read-through-an-index
 */
class Version1Date20261003090000Test extends TestCase {
	use SchemaTableMockTrait;


	/**
	 * A table without the index gets it, on (object_uuid, created) in that order.
	 *
	 * @return void
	 */
	public function testItAddsTheObjectUuidIndex(): void {
		$added = [];

		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(true);
		$table->method('hasIndex')->willReturn(false);
		$table->method('addIndex')->willReturnCallback(
			function (array $columns, string $name) use (&$added, $table) {
				$added[$name] = $columns;
				return $table;
			}
		);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_audit_trails')->willReturn(true);
		$schema->method('getTable')->with('openregister_audit_trails')->willReturn($table);

		$step   = new Version1Date20261003090000();
		$result = $step->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);

		$this->assertSame($schema, $result);
		$this->assertSame(['or_audit_obj_uuid_created' => ['object_uuid', 'created']], $added);
		// Nextcloud refuses index names over 30 characters on Oracle.
		$this->assertLessThanOrEqual(30, strlen((string)array_key_first($added)));
	}//end testItAddsTheObjectUuidIndex()

	/**
	 * Run twice: the second run finds the index and adds nothing.
	 *
	 * @return void
	 */
	public function testASecondRunChangesNothing(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(true);
		$table->method('hasIndex')->willReturnCallback(
			static fn (string $name): bool => $name === 'or_audit_obj_uuid_created'
		);
		$table->expects($this->never())->method('addIndex');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturn($table);

		$step = new Version1Date20261003090000();
		$this->assertSame($schema, $step->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}//end testASecondRunChangesNothing()

	/**
	 * A table that lacks one of the two columns is left alone rather than failing the upgrade.
	 *
	 * @return void
	 */
	public function testATableWithoutTheColumnsIsSkipped(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturnCallback(static fn (string $name): bool => $name === 'created');
		$table->method('hasIndex')->willReturn(false);
		$table->expects($this->never())->method('addIndex');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturn($table);

		$step = new Version1Date20261003090000();
		$this->assertSame($schema, $step->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}//end testATableWithoutTheColumnsIsSkipped()

	/**
	 * No audit table (a fresh install creates it in an earlier step): nothing to do.
	 *
	 * @return void
	 */
	public function testAMissingTableIsSkipped(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$step = new Version1Date20261003090000();
		$this->assertSame($schema, $step->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}//end testAMissingTableIsSkipped()
}//end class
