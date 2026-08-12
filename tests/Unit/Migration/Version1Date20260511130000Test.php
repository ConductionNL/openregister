<?php

declare(strict_types=1);

/*
 * Tests for Version1Date20260511130000 — adds Message.context column.
 *
 * Verifies: (a) changeSchema() adds the column when missing,
 * (b) is idempotent when already present, (c) is a no-op when the
 * messages table itself is missing, and (d) down() removes the column.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/ai-chat-companion-orchestrator/tasks.md#7
 */

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20260511130000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version1Date20260511130000Test extends TestCase {

	private Version1Date20260511130000 $migration;

	protected function setUp(): void {
		parent::setUp();
		$this->migration = new Version1Date20260511130000();
	}//end setUp()

	public function testChangeSchemaAddsContextColumnWhenMissing(): void {
		$output = $this->createMock(IOutput::class);

		$table = $this->createMock(\Doctrine\DBAL\Schema\Table::class);
		$table->method('hasColumn')->with('context')->willReturn(false);
		$table->expects($this->once())
			->method('addColumn')
			->with(
				'context',
				Types::TEXT,
				$this->callback(
					function ($opts) {
						return $opts['notnull'] === false
						&& $opts['default'] === '{}'
						&& is_string($opts['comment']);
					}
				)
			);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_messages')->willReturn(true);
		$schema->method('getTable')->with('openregister_messages')->willReturn($table);

		$result = $this->migration->changeSchema($output, fn () => $schema, []);
		$this->assertSame($schema, $result);
	}//end testChangeSchemaAddsContextColumnWhenMissing()

	public function testChangeSchemaIsIdempotentWhenColumnAlreadyExists(): void {
		$output = $this->createMock(IOutput::class);

		$table = $this->createMock(\Doctrine\DBAL\Schema\Table::class);
		$table->method('hasColumn')->with('context')->willReturn(true);
		$table->expects($this->never())->method('addColumn');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_messages')->willReturn(true);
		$schema->method('getTable')->with('openregister_messages')->willReturn($table);

		$result = $this->migration->changeSchema($output, fn () => $schema, []);
		$this->assertNull($result);
	}//end testChangeSchemaIsIdempotentWhenColumnAlreadyExists()

	public function testChangeSchemaIsNoOpWhenMessagesTableMissing(): void {
		$output = $this->createMock(IOutput::class);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_messages')->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$result = $this->migration->changeSchema($output, fn () => $schema, []);
		$this->assertNull($result);
	}//end testChangeSchemaIsNoOpWhenMessagesTableMissing()

	public function testDownRemovesContextColumnWhenPresent(): void {
		$output = $this->createMock(IOutput::class);

		$table = $this->createMock(\Doctrine\DBAL\Schema\Table::class);
		$table->method('hasColumn')->with('context')->willReturn(true);
		$table->expects($this->once())
			->method('dropColumn')
			->with('context');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_messages')->willReturn(true);
		$schema->method('getTable')->with('openregister_messages')->willReturn($table);

		$result = $this->migration->down($output, fn () => $schema, []);
		$this->assertSame($schema, $result);
	}//end testDownRemovesContextColumnWhenPresent()

	public function testDownIsIdempotentWhenColumnAlreadyAbsent(): void {
		$output = $this->createMock(IOutput::class);

		$table = $this->createMock(\Doctrine\DBAL\Schema\Table::class);
		$table->method('hasColumn')->with('context')->willReturn(false);
		$table->expects($this->never())->method('dropColumn');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_messages')->willReturn(true);
		$schema->method('getTable')->with('openregister_messages')->willReturn($table);

		$result = $this->migration->down($output, fn () => $schema, []);
		$this->assertNull($result);
	}//end testDownIsIdempotentWhenColumnAlreadyAbsent()

	public function testDownIsNoOpWhenMessagesTableMissing(): void {
		$output = $this->createMock(IOutput::class);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_messages')->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$result = $this->migration->down($output, fn () => $schema, []);
		$this->assertNull($result);
	}//end testDownIsNoOpWhenMessagesTableMissing()
}//end class
