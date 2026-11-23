<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\BackgroundJob\BackfillEmailLinkMessageIdJob;
use OCA\OpenRegister\Migration\Version1Date20261121190000;
use OCP\BackgroundJob\IJobList;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The `direction` column on the email links.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261121190000
 */
class Version1Date20261121190000Test extends TestCase {
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

	public function testTheTier1UniqueIndexIsDroppedSoASecondSentMailFits(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->with('direction')->willReturn(true);
		$table->method('hasIndex')->willReturnMap([
			['email_links_unique_idx', true],
			['idx_email_object_message', true],
		]);
		$table->expects($this->once())->method('dropIndex')->with('email_links_unique_idx');
		$table->expects($this->never())->method('addUniqueIndex');

		$this->migrate(schema: $this->schema(hasTable: true, table: $table));
	}

	public function testTheUidBearingUniqueIndexIsAddedWhenMissing(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->with('direction')->willReturn(true);
		$table->method('hasIndex')->willReturnMap([
			['email_links_unique_idx', true],
			['idx_email_object_message', false],
		]);
		$table->expects($this->once())->method('dropIndex')->with('email_links_unique_idx');
		$table->expects($this->once())->method('addUniqueIndex')
			->with(['object_uuid', 'mail_account_id', 'mail_message_id', 'mail_message_uid'], 'idx_email_object_message');

		$this->migrate(schema: $this->schema(hasTable: true, table: $table));
	}

	public function testATier2TableKeepsItsIndexes(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->with('direction')->willReturn(true);
		$table->method('hasIndex')->willReturnMap([
			['email_links_unique_idx', false],
			['idx_email_object_message', true],
		]);
		$table->expects($this->never())->method('dropIndex');
		$table->expects($this->never())->method('addUniqueIndex');

		$this->migrate(schema: $this->schema(hasTable: true, table: $table));
	}

	public function testAnInstallWithoutTheTableIsLeftAlone(): void {
		$schema = $this->schema(hasTable: false, table: $this->createTableMock());

		$this->assertSame($schema, $this->migrate(schema: $schema));
	}

	public function testTheBackfillIsQueuedOnceAfterTheSchemaChange(): void {
		$jobs = $this->createMock(IJobList::class);
		$jobs->expects($this->once())->method('add')->with(BackfillEmailLinkMessageIdJob::class);

		(new Version1Date20261121190000($jobs))->postSchemaChange($this->createMock(IOutput::class), static fn () => $this->createMock(ISchemaWrapper::class), []);
	}

	private function migrate(ISchemaWrapper $schema): ?ISchemaWrapper {
		return (new Version1Date20261121190000($this->createMock(IJobList::class)))->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}

	private function schema(bool $hasTable, mixed $table): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with(self::TABLE)->willReturn($hasTable);
		$schema->expects($hasTable === true ? $this->once() : $this->never())->method('getTable')->with(self::TABLE)->willReturn($table);
		return $schema;
	}
}
