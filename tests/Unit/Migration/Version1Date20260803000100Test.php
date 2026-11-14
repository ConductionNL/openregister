<?php

/**
 * Version1Date20260803000100 creates openregister_flow_steps, one row per node execution (flow-engine-unification task 1.2).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Migration\Version1Date20260803000100;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Migration\Version1Date20260803000100
 */
class Version1Date20260803000100Test extends TestCase {
	use SchemaTableMockTrait;

	public function testItCreatesTheStepTableWithItsColumnsAndIndexes(): void {
		$columns = [];
		$indexes = [];
		$unique  = [];
		$primary = null;
		$table = $this->createTableMock();
		$table->method('addColumn')->willReturnCallback(
			function (string $name, string $type, array $options = []) use (&$columns) {
				$columns[$name] = ['type' => $type, 'notnull' => ($options['notnull'] ?? null), 'default' => (array_key_exists('default', $options) ? $options['default'] : 'unset')];
				return $this->createColumnMock();
			}
		);
		$table->method('addIndex')->willReturnCallback(
			function (array $cols, string $name) use (&$indexes, $table) {
				$indexes[$name] = $cols;
				return $table;
			}
		);
		$table->method('addUniqueIndex')->willReturnCallback(
			function (array $cols, string $name) use (&$unique, $table) {
				$unique[$name] = $cols;
				return $table;
			}
		);
		$table->method('setPrimaryKey')->willReturnCallback(
			function (array $cols) use (&$primary, $table) {
				$primary = $cols;
				return $table;
			}
		);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_flow_steps')->willReturn(false);
		$schema->expects($this->once())->method('createTable')->with('openregister_flow_steps')->willReturn($table);

		$result = (new Version1Date20260803000100())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);

		$this->assertSame($schema, $result);
		foreach (['run_uuid', 'flow_id', 'node_id', 'node_type', 'sequence', 'status', 'started', 'finished', 'duration_ms', 'output', 'error'] as $column) {
			$this->assertArrayHasKey($column, $columns, $column . ' is missing');
		}

		$this->assertTrue($columns['run_uuid']['notnull']);
		$this->assertTrue($columns['status']['notnull']);
		$this->assertSame(['id'], $primary);
		$this->assertSame([], $unique);
		$this->assertSame(
			[
				'or_flowstep_run_idx' => ['run_uuid', 'sequence'],
				'or_flowstep_type_idx' => ['node_type', 'status'],
				'or_flowstep_flow_idx' => ['flow_id', 'id'],
				'or_flowstep_created_idx' => ['created'],
			],
			$indexes
		);
		foreach (array_keys($indexes) as $name) {
			$this->assertLessThanOrEqual(30, strlen($name));
		}
	}

	public function testAnExistingTableIsLeftAlone(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_flow_steps')->willReturn(true);
		$schema->expects($this->never())->method('createTable');

		$this->assertSame($schema, (new Version1Date20260803000100())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}
}
