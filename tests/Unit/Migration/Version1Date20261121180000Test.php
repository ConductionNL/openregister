<?php

/**
 * Version1Date20261121180000Test: the former_slugs column on the schemas table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/modelling-rename-without-loss/specs/schema-migration/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCP\DB\Schema\ITable;
use Doctrine\DBAL\Types\Types;
use OCA\OpenRegister\Migration\TargetedMigrationStep;
use OCA\OpenRegister\Migration\Version1Date20261121180000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Version1Date20261121180000::class)]
#[UsesClass(TargetedMigrationStep::class)]
final class Version1Date20261121180000Test extends TestCase {

	public function testAddsTheNullableTextColumnWhenAbsent(): void {
		$table = $this->createMock(ITable::class);
		$table->method('hasColumn')->with('former_slugs')->willReturn(false);
		$table->expects($this->once())->method('addColumn')->with(
			'former_slugs',
			Types::TEXT,
			$this->callback(static fn (array $o): bool => $o['notnull'] === false && $o['default'] === null)
		);
		$schema = $this->schema(hasTable: true, table: $table);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains('former_slugs'));

		$this->assertSame($schema, (new Version1Date20261121180000())->changeSchema($output, fn () => $schema, []));
	}//end testAddsTheNullableTextColumnWhenAbsent()

	public function testLeavesAnExistingColumnAlone(): void {
		$table = $this->createMock(ITable::class);
		$table->method('hasColumn')->with('former_slugs')->willReturn(true);
		$table->expects($this->never())->method('addColumn');
		$schema = $this->schema(hasTable: true, table: $table);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');

		$this->assertSame($schema, (new Version1Date20261121180000())->changeSchema($output, fn () => $schema, []));
	}//end testLeavesAnExistingColumnAlone()

	public function testDoesNothingWithoutTheSchemasTable(): void {
		$schema = $this->schema(hasTable: false, table: null);
		$schema->expects($this->never())->method('getTable');

		$this->assertSame($schema, (new Version1Date20261121180000())->changeSchema($this->createMock(IOutput::class), fn () => $schema, []));
	}//end testDoesNothingWithoutTheSchemasTable()

	/**
	 * A schema wrapper that answers for openregister_schemas.
	 *
	 * @param bool       $hasTable Whether the table exists.
	 * @param ITable|null $table    The table it returns.
	 *
	 * @return ISchemaWrapper&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function schema(bool $hasTable, ?ITable $table): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_schemas')->willReturn($hasTable);
		if ($table !== null) {
			$schema->method('getTable')->with('openregister_schemas')->willReturn($table);
		}

		return $schema;
	}//end schema()
}//end class
