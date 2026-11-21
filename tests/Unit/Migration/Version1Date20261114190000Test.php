<?php

/**
 * The webhook log duration column migration.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261114190000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Added once as a nullable integer; left alone when present or when the table is absent.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261114190000
 */
class Version1Date20261114190000Test extends TestCase {
	use SchemaTableMockTrait;

	public function testTheColumnIsAddedAsANullableInteger(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->with('duration_ms')->willReturn(false);
		$table->expects($this->once())->method('addColumn')->with('duration_ms', Types::INTEGER, ['notnull' => false, 'default' => null]);
		$schema = $this->schema(table: $table);

		$this->assertSame($schema, (new Version1Date20261114190000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}

	public function testAPresentColumnIsLeftAlone(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(true);
		$table->expects($this->never())->method('addColumn');

		(new Version1Date20261114190000())->changeSchema($this->createMock(IOutput::class), fn () => $this->schema(table: $table), []);
	}

	public function testWithoutTheTableNothingHappens(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$this->assertSame($schema, (new Version1Date20261114190000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}

	/**
	 * A schema holding the webhook logs table.
	 *
	 * @param MockObject $table The table double.
	 *
	 * @return ISchemaWrapper
	 */
	private function schema(MockObject $table): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_webhook_logs')->willReturn(true);
		$schema->method('getTable')->with('openregister_webhook_logs')->willReturn($table);

		return $schema;
	}
}
