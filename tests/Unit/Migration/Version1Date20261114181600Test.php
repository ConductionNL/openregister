<?php

/**
 * The scheme run table is created with one running run per sequence.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261114181600;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The migration over a mocked schema.
 */
#[CoversClass(Version1Date20261114181600::class)]
class Version1Date20261114181600Test extends TestCase {
	use SchemaTableMockTrait;

	/**
	 * The table is created with a unique index on the running sequence.
	 *
	 * @return void
	 */
	public function testTheTableIsCreatedUniqueOnTheRunningSequence(): void {
		$columns = [];
		$column  = $this->createColumnMock();
		$table   = $this->createTableMock();
		$table->method('addColumn')->willReturnCallback(
			function (string $name, string $type, array $options) use (&$columns, $column) {
				$columns[$name] = [$type, ($options['notnull'] ?? null), ($options['default'] ?? null)];
				return $column;
			}
		);
		$table->expects($this->once())->method('setPrimaryKey')->with(['id']);
		$table->expects($this->once())->method('addUniqueIndex')->with(['active_sequence'], 'idx_or_idsr_active');
		$table->expects($this->once())->method('addIndex')->with(['sequence'], 'idx_or_idsr_sequence');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_id_scheme_runs')->willReturn(false);
		$schema->expects($this->once())->method('createTable')->with('openregister_id_scheme_runs')->willReturn($table);

		$this->assertSame($schema, (new Version1Date20261114181600())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
		$this->assertFalse($columns['active_sequence'][1], 'A finished run holds no sequence, so the column is nullable.');
		$this->assertTrue($columns['sequence'][1]);
		$this->assertSame(0, $columns['processed'][2]);
		$this->assertArrayHasKey('previous_format', $columns);
		$this->assertArrayHasKey('last_error', $columns);
	}//end testTheTableIsCreatedUniqueOnTheRunningSequence()

	/**
	 * A present table is left alone.
	 *
	 * @return void
	 */
	public function testAPresentTableIsLeftAlone(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_id_scheme_runs')->willReturn(true);
		$schema->expects($this->never())->method('createTable');

		$this->assertSame($schema, (new Version1Date20261114181600())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}//end testAPresentTableIsLeftAlone()
}//end class
