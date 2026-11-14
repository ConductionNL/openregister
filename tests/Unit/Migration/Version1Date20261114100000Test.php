<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261114100000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The `direction` column on the email links.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261114100000
 */
class Version1Date20261114100000Test extends TestCase {
	use SchemaTableMockTrait;

	private const TABLE = 'openregister_email_links';

	public function testTheColumnIsAddedNullableOnce(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->with('direction')->willReturn(false);
		$table->expects($this->once())->method('addColumn')
			->with('direction', 'string', ['notnull' => false, 'length' => 16, 'default' => null])
			->willReturn($this->createColumnMock());

		$this->migrate(schema: $this->schema(hasTable: true, table: $table));
	}

	public function testAnInstallWithTheColumnIsLeftAlone(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->with('direction')->willReturn(true);
		$table->expects($this->never())->method('addColumn');

		$this->migrate(schema: $this->schema(hasTable: true, table: $table));
	}

	public function testAnInstallWithoutTheTableIsLeftAlone(): void {
		$schema = $this->schema(hasTable: false, table: $this->createTableMock());

		$this->assertSame($schema, $this->migrate(schema: $schema));
	}

	private function migrate(ISchemaWrapper $schema): ?ISchemaWrapper {
		return (new Version1Date20261114100000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}

	private function schema(bool $hasTable, mixed $table): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with(self::TABLE)->willReturn($hasTable);
		$schema->expects($hasTable === true ? $this->once() : $this->never())->method('getTable')->with(self::TABLE)->willReturn($table);
		return $schema;
	}
}
