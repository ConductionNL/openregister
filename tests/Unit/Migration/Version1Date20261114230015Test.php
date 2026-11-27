<?php

/**
 * Unit tests for Version1Date20261114230015 (audit category column).
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
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\TargetedMigrationStep;
use OCA\OpenRegister\Migration\Version1Date20261114230015;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The step is targeted, adds the nullable category column with its index, and
 * leaves a table that already has both alone.
 */
#[CoversClass(Version1Date20261114230015::class)]
#[UsesClass(TargetedMigrationStep::class)]
class Version1Date20261114230015Test extends TestCase {
	use SchemaTableMockTrait;

	public function testTheStepIsTargeted(): void {
		$this->assertInstanceOf(TargetedMigrationStep::class, $this->step());
	}

	public function testTheColumnAndIndexAreAdded(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->with('category')->willReturn(false);
		$table->method('hasIndex')->with('or_audit_category_idx')->willReturn(false);
		$table->expects($this->once())->method('addColumn')
			->with('category', Types::STRING, ['notnull' => false, 'length' => 32])
			->willReturn($this->createColumnMock());
		$table->expects($this->once())->method('addIndex')->with(['category'], 'or_audit_category_idx');

		$schema = $this->schemaWith(table: $table);

		$this->assertSame($schema, $this->change(schema: $schema));
	}

	public function testAnExistingColumnAndIndexAreLeftAlone(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->with('category')->willReturn(true);
		$table->method('hasIndex')->with('or_audit_category_idx')->willReturn(true);
		$table->expects($this->never())->method('addColumn');
		$table->expects($this->never())->method('addIndex');

		$schema = $this->schemaWith(table: $table);

		$this->assertSame($schema, $this->change(schema: $schema));
	}

	public function testAMissingAuditTableReturnsTheSchema(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_audit_trails')->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$this->assertSame($schema, $this->change(schema: $schema));
	}

	private function schemaWith(object $table): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_audit_trails')->willReturn(true);
		$schema->method('getTable')->with('openregister_audit_trails')->willReturn($table);
		return $schema;
	}

	private function step(): Version1Date20261114230015 {
		return new Version1Date20261114230015(connection: $this->createMock(IDBConnection::class));
	}

	private function change(ISchemaWrapper $schema): ?ISchemaWrapper {
		return $this->step()->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}
}//end class
