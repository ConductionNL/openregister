<?php

/**
 * Version1Date20260803000000 creates openregister_flows, the native flow store (flow-engine-unification task 1.1).
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

use OCA\OpenRegister\Migration\Version1Date20260803000000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Migration\Version1Date20260803000000
 */
class Version1Date20260803000000Test extends TestCase {
	use SchemaTableMockTrait;

	public function testItCreatesTheFlowTableWithItsColumnsAndIndexes(): void {
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
		$schema->method('hasTable')->with('openregister_flows')->willReturn(false);
		$schema->expects($this->once())->method('createTable')->with('openregister_flows')->willReturn($table);

		$result = (new Version1Date20260803000000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);

		$this->assertSame($schema, $result);
		foreach (['uuid', 'name', 'description', 'app', 'enabled', 'trigger', 'trigger_register', 'trigger_schema', 'cron', 'execution_mode', 'nodes', 'edges', 'limits', 'retention_days', 'owner', 'organisation', 'notes', 'created', 'updated'] as $column) {
			$this->assertArrayHasKey($column, $columns, $column . ' is missing');
		}

		// Unowned by default: a flow with no owner must not dispatch, so nothing fills one in.
		$this->assertFalse($columns['owner']['notnull']);
		$this->assertNull($columns['owner']['default']);
		// NULL retention follows the admin setting rather than freezing today's default.
		$this->assertNull($columns['retention_days']['default']);
		$this->assertSame('openregister', $columns['app']['default']);

		$this->assertSame(['id'], $primary);
		$this->assertSame(['or_flow_uuid_idx' => ['uuid']], $unique);
		$this->assertSame(
			['or_flow_app_idx' => ['app', 'id'], 'or_flow_trigger_idx' => ['enabled', 'trigger'], 'or_flow_org_idx' => ['organisation']],
			$indexes
		);
		foreach (array_merge(array_keys($indexes), array_keys($unique)) as $name) {
			$this->assertLessThanOrEqual(30, strlen($name));
		}
	}

	public function testAnExistingTableIsLeftAlone(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_flows')->willReturn(true);
		$schema->expects($this->never())->method('createTable');

		$this->assertSame($schema, (new Version1Date20260803000000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}
}
