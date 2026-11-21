<?php

/**
 * Unit tests for the reminder state columns on the notification dedupe table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/service-hours-and-repeating-reminders/tasks.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261114100003;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The two reminder columns are added once to an existing dedupe table; a missing table or present columns change nothing.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261114100003
 */
class Version1Date20261114100003Test extends TestCase {
	use SchemaTableMockTrait;

	public function testBothReminderColumnsAreAdded(): void {
		$columns = [];
		$table   = $this->createTableMock();
		$table->method('hasColumn')->willReturn(false);
		$table->method('addColumn')->willReturnCallback(
			function (string $name) use (&$columns) {
				$columns[] = $name;
				return $this->createColumnMock();
			}
		);
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with(Version1Date20261114100003::TABLE)->willReturn(true);
		$schema->method('getTable')->with(Version1Date20261114100003::TABLE)->willReturn($table);

		$this->assertSame($schema, $this->change(schema: $schema));
		$this->assertSame(['reminder_sent', 'reminder_stopped'], $columns);
	}

	public function testPresentColumnsAreLeftAlone(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(true);
		$table->expects($this->never())->method('addColumn');
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturn($table);

		$this->assertSame($schema, $this->change(schema: $schema));
	}

	public function testAMissingTableIsLeftAlone(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with(Version1Date20261114100003::TABLE)->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$this->assertSame($schema, $this->change(schema: $schema));
	}

	private function change(ISchemaWrapper $schema): ?ISchemaWrapper {
		return (new Version1Date20261114100003())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}
}
