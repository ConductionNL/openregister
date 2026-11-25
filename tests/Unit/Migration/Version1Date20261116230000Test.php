<?php

/**
 * The request metrics columns on openregister_metrics.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261116230000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Both columns added once as nullable bigints; left alone when present or when the table is absent.
 *
 * @covers \OCA\OpenRegister\Migration\Version1Date20261116230000
 */
class Version1Date20261116230000Test extends TestCase {
	use SchemaTableMockTrait;

	public function testBothColumnsAreAddedAsNullableBigints(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(false);
		$added = [];
		$table->expects($this->exactly(2))->method('addColumn')->willReturnCallback(
			function (string $name, string $type, array $options) use (&$added) {
				$added[] = [$name, $type, $options];
				return $this->createColumnMock();
			}
		);
		$schema = $this->schema(table: $table);

		$this->assertSame($schema, (new Version1Date20261116230000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
		$this->assertSame(
			[
				['sample_count', Types::BIGINT, ['notnull' => false, 'default' => null]],
				['sample_sum_ms', Types::BIGINT, ['notnull' => false, 'default' => null]],
			],
			$added
		);
	}

	public function testAPresentColumnIsLeftAloneAndTheOtherAdded(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturnCallback(static fn (string $name): bool => $name === 'sample_count');
		$table->expects($this->once())->method('addColumn')->with('sample_sum_ms', Types::BIGINT, ['notnull' => false, 'default' => null]);

		(new Version1Date20261116230000())->changeSchema($this->createMock(IOutput::class), fn () => $this->schema(table: $table), []);
	}

	public function testWithoutTheTableNothingHappens(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$this->assertSame($schema, (new Version1Date20261116230000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []));
	}

	/**
	 * A schema holding the metrics table.
	 *
	 * @param MockObject $table The table double.
	 *
	 * @return ISchemaWrapper
	 */
	private function schema(MockObject $table): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_metrics')->willReturn(true);
		$schema->method('getTable')->with('openregister_metrics')->willReturn($table);

		return $schema;
	}
}
