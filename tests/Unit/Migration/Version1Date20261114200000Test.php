<?php

/**
 * The access link journey column migration (or-form-and-journey-registry, task 6.1).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-form-link-may-open-an-existing-record-for-change-req-fjr-008
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261114200000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Version1Date20261114200000: a nullable `journey` on the access links.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261114200000
 */
class Version1Date20261114200000Test extends TestCase {
	use SchemaTableMockTrait;

	public function testTheJourneyColumnIsAddedNullable(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->with('journey')->willReturn(false);
		$table->expects($this->once())->method('addColumn')
			->with('journey', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null])
			->willReturn($this->createColumnMock());

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_access_links')->willReturn(true);
		$schema->method('getTable')->with('openregister_access_links')->willReturn($table);

		$this->assertSame($schema, $this->change(schema: $schema));
	}

	public function testAnExistingColumnOrAMissingTableChangesNothing(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(true);
		$table->expects($this->never())->method('addColumn');
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturn($table);
		$this->assertSame($schema, $this->change(schema: $schema));

		$missing = $this->createMock(ISchemaWrapper::class);
		$missing->method('hasTable')->willReturn(false);
		$missing->expects($this->never())->method('getTable');
		$this->assertSame($missing, $this->change(schema: $missing));
	}

	private function change(ISchemaWrapper $schema): ?ISchemaWrapper {
		return (new Version1Date20261114200000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}
}//end class
