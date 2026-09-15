<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use OCA\OpenRegister\Migration\Version1Date20260913180000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The people-on-objects migration widens the contact link table.
 *
 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-link-on-an-object-is-a-user-or-a-contact-in-a-role-for-a-period
 */
class Version1Date20260913180000Test extends TestCase {

	/**
	 * A fresh table: four columns added, two made nullable, the unique key moved, the user index added.
	 *
	 * @return void
	 */
	public function testItWidensTheTable(): void {
		$added = [];
		$relaxed = [];
		$indexes = ['dropped' => [], 'unique' => [], 'plain' => []];

		$table = $this->createMock(Table::class);
		$table->method('hasColumn')->willReturnCallback(
			static fn (string $name): bool => in_array($name, ['addressbook_id', 'contact_uri'], true)
		);
		$table->method('addColumn')->willReturnCallback(
			function (string $name, string $type, array $options) use (&$added): Column {
				$added[$name] = ['type' => $type, 'options' => $options];
				return $this->createMock(Column::class);
			}
		);
		$table->method('getColumn')->willReturnCallback(
			function (string $name) use (&$relaxed): Column {
				$column = $this->createMock(Column::class);
				$column->method('getNotnull')->willReturn(true);
				$column->method('setNotnull')->willReturnCallback(
					static function (bool $notnull) use (&$relaxed, $name, $column): Column {
						$relaxed[$name] = $notnull;
						return $column;
					}
				);
				return $column;
			}
		);
		$table->method('hasIndex')->willReturnCallback(
			static fn (string $name): bool => $name === 'idx_contact_object_uid_uniq'
		);
		$table->method('dropIndex')->willReturnCallback(
			static function (string $name) use (&$indexes): void {
				$indexes['dropped'][] = $name;
			}
		);
		$table->method('addUniqueIndex')->willReturnCallback(
			function (array $columns, string $name) use (&$indexes, $table): Table {
				$indexes['unique'][$name] = $columns;
				return $table;
			}
		);
		$table->method('addIndex')->willReturnCallback(
			function (array $columns, string $name) use (&$indexes, $table): Table {
				$indexes['plain'][$name] = $columns;
				return $table;
			}
		);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_contact_links')->willReturn(true);
		$schema->method('getTable')->with('openregister_contact_links')->willReturn($table);

		$step = new Version1Date20260913180000();
		$result = $step->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);

		$this->assertSame($schema, $result);
		$this->assertSame(['user_id', 'valid_from', 'valid_until', 'note'], array_keys($added));
		$this->assertSame(Types::STRING, $added['user_id']['type']);
		$this->assertSame(64, $added['user_id']['options']['length']);
		$this->assertSame(Types::DATE_MUTABLE, $added['valid_from']['type']);
		$this->assertSame(Types::DATE_MUTABLE, $added['valid_until']['type']);
		$this->assertSame(Types::TEXT, $added['note']['type']);
		foreach ($added as $column) {
			$this->assertFalse($column['options']['notnull']);
		}

		$this->assertSame(['addressbook_id' => false, 'contact_uri' => false], $relaxed);
		$this->assertSame(['idx_contact_object_uid_uniq'], $indexes['dropped']);
		$this->assertSame(
			['idx_contact_obj_uid_role_uniq' => ['object_uuid', 'contact_uid', 'role']],
			$indexes['unique']
		);
		$this->assertSame(['idx_contact_user_id' => ['user_id']], $indexes['plain']);
	}//end testItWidensTheTable()

	/**
	 * Run twice: the second run finds everything in place and changes nothing.
	 *
	 * @return void
	 */
	public function testASecondRunChangesNothing(): void {
		$table = $this->createMock(Table::class);
		$table->method('hasColumn')->willReturn(true);
		$column = $this->createMock(Column::class);
		$column->method('getNotnull')->willReturn(false);
		$table->method('getColumn')->willReturn($column);
		$table->method('hasIndex')->willReturnCallback(
			static fn (string $name): bool => $name !== 'idx_contact_object_uid_uniq'
		);
		$table->expects($this->never())->method('addColumn');
		$table->expects($this->never())->method('dropIndex');
		$table->expects($this->never())->method('addUniqueIndex');
		$table->expects($this->never())->method('addIndex');
		$column->expects($this->never())->method('setNotnull');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturn($table);

		$step = new Version1Date20260913180000();
		$this->assertSame($schema, $step->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}//end testASecondRunChangesNothing()

	/**
	 * No table (a fresh install runs the creating migration later): nothing to do.
	 *
	 * @return void
	 */
	public function testAMissingTableIsSkipped(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$step = new Version1Date20260913180000();
		$this->assertSame($schema, $step->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}//end testAMissingTableIsSkipped()
}//end class
