<?php

/**
 * Unit tests for Version1Date20261114103700: the mandate columns on tasks and the task audit.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-human-tasks/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\TargetedMigrationStep;
use OCA\OpenRegister\Migration\Version1Date20261114103700;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Nullable columns added once; present columns and absent tables are left alone.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261114103700
 * @uses   \OCA\OpenRegister\Migration\TargetedMigrationStep
 */
class Version1Date20261114103700Test extends TestCase {
	use SchemaTableMockTrait;

	public function testTheStepIsTargeted(): void {
		$this->assertInstanceOf(TargetedMigrationStep::class, new Version1Date20261114103700());
	}

	public function testTheMandateColumnsAreAddedAsNullable(): void {
		$tasks = $this->createTableMock();
		$tasks->method('hasColumn')->willReturn(false);
		$added = [];
		$tasks->expects($this->exactly(2))->method('addColumn')->willReturnCallback(
			function (string $name, string $type, array $options) use (&$added) {
				$added[] = [$name, $type, $options];
				return $this->createColumnMock();
			}
		);
		$audit = $this->createTableMock();
		$audit->method('hasColumn')->willReturn(false);
		$audit->expects($this->once())->method('addColumn')
			->with('mandate_evidence', 'json', ['notnull' => false])
			->willReturn($this->createColumnMock());
		$schema = $this->schema(tasks: $tasks, audit: $audit);

		$this->assertSame($schema, (new Version1Date20261114103700())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
		$this->assertSame(
			[
				['required_mandate', 'string', ['notnull' => false, 'length' => 128]],
				['mandate_evidence', 'json', ['notnull' => false]],
			],
			$added
		);
	}

	public function testPresentColumnsAreLeftAlone(): void {
		$tasks = $this->createTableMock();
		$tasks->method('hasColumn')->willReturn(true);
		$tasks->expects($this->never())->method('addColumn');
		$audit = $this->createTableMock();
		$audit->method('hasColumn')->willReturn(true);
		$audit->expects($this->never())->method('addColumn');
		$schema = $this->schema(tasks: $tasks, audit: $audit);

		$this->assertSame($schema, (new Version1Date20261114103700())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}

	public function testWithoutTheTablesNothingHappens(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$this->assertSame($schema, (new Version1Date20261114103700())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}

	/**
	 * A schema holding both tables.
	 *
	 * @param MockObject $tasks The tasks table double.
	 * @param MockObject $audit The task audit table double.
	 *
	 * @return ISchemaWrapper
	 */
	private function schema(MockObject $tasks, MockObject $audit): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturnMap(
			[
				[Version1Date20261114103700::TASKS, $tasks],
				[Version1Date20261114103700::AUDIT, $audit],
			]
		);

		return $schema;
	}
}
