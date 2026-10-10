<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCP\DB\Schema\ITable;
use OCA\OpenRegister\Migration\Version1Date20261010120000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The imported flag lands once, nullable with a false default.
 */
class Version1Date20261010120000Test extends TestCase {

	/**
	 * A table without the column gains it.
	 *
	 * @return void
	 */
	public function testItAddsTheImportedColumn(): void {
		$table = $this->createMock(ITable::class);
		$table->method('hasColumn')->with('imported')->willReturn(false);
		$table->expects($this->once())->method('addColumn')->with('imported', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_case_item_audit')->willReturn(true);
		$schema->method('getTable')->willReturn($table);

		$this->assertSame($schema, (new Version1Date20261010120000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}//end testItAddsTheImportedColumn()

	/**
	 * A second run, or a missing table, changes nothing.
	 *
	 * @return void
	 */
	public function testItIsIdempotentAndSkipsAMissingTable(): void {
		$table = $this->createMock(ITable::class);
		$table->method('hasColumn')->willReturn(true);
		$table->expects($this->never())->method('addColumn');
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturn($table);
		(new Version1Date20261010120000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);

		$absent = $this->createMock(ISchemaWrapper::class);
		$absent->method('hasTable')->willReturn(false);
		$absent->expects($this->never())->method('getTable');
		$this->assertSame($absent, (new Version1Date20261010120000())->changeSchema($this->createMock(IOutput::class), static fn () => $absent, []));
	}//end testItIsIdempotentAndSkipsAMissingTable()
}//end class
