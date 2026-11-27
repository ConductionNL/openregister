<?php

/**
 * Unit tests for Version1Date20261114180006: the upload sessions table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/large-file-handling/tasks.md#task-2-1
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261114180006;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Created once with its key and indexes; left alone when present.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261114180006
 * @uses   \OCA\OpenRegister\Migration\TargetedMigrationStep
 */
class Version1Date20261114180006Test extends TestCase {
	use SchemaTableMockTrait;

	public function testTheTableIsCreatedWithItsKeyAndIndexes(): void {
		$table = $this->createTableMock();
		$table->expects($this->exactly(11))->method('addColumn');
		$table->expects($this->once())->method('setPrimaryKey')->with(['id']);
		$table->expects($this->once())->method('addUniqueIndex')->with(['upload_id'], 'or_upload_sess_id_uidx');
		$table->expects($this->once())->method('addIndex')->with(['expires_at'], 'or_upload_sess_exp_idx');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with(Version1Date20261114180006::TABLE)->willReturn(false);
		$schema->expects($this->once())->method('createTable')->with(Version1Date20261114180006::TABLE)->willReturn($table);

		$this->assertSame($schema, (new Version1Date20261114180006())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}

	public function testAPresentTableIsLeftAlone(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->expects($this->never())->method('createTable');

		$this->assertSame($schema, (new Version1Date20261114180006())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}
}
