<?php

/**
 * The request metrics columns on a fresh install.
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

use OCA\OpenRegister\Migration\Version002006000Date20261126010000;
use OCA\OpenRegister\Tests\Support\SchemaTableMockTrait;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The step sorts after the step that creates the table, and adds the columns only when missing.
 *
 * @covers \OCA\OpenRegister\Migration\Version002006000Date20261126010000
 *
 * @spec openspec/changes/production-observability
 */
class Version002006000Date20261126010000Test extends TestCase {
	use SchemaTableMockTrait;

	/**
	 * In Nextcloud's own order the step runs after the table is created; the earlier step runs before it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function testItRunsAfterTheMetricsTableIsCreated(): void {
		$order = [
			'Version002005000Date20251013000000',
			'Version002006000Date20261126010000',
			'Version1Date20261116230000',
		];
		usort($order, [self::class, 'nextcloudOrder']);

		$this->assertSame(
			['Version1Date20261116230000', 'Version002005000Date20251013000000', 'Version002006000Date20261126010000'],
			$order
		);
	}

	/**
	 * Nextcloud's order (OC\DB\MigrationService::sortMigrations()): the number before `Date`, then the date.
	 *
	 * @param string $a A step's class name.
	 * @param string $b Another step's class name.
	 *
	 * @return int
	 */
	private static function nextcloudOrder(string $a, string $b): int {
		preg_match('/(\d+)Date(\d+)/', $a, $matchA);
		preg_match('/(\d+)Date(\d+)/', $b, $matchB);
		if ((int) $matchA[1] !== (int) $matchB[1]) {
			return ((int) $matchA[1] < (int) $matchB[1]) ? -1 : 1;
		}

		return strnatcmp($matchA[2], $matchB[2]);
	}

	/**
	 * A freshly created table without the columns gets both.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function testAFreshTableGetsBothColumns(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(false);
		$added = [];
		$table->expects($this->exactly(2))->method('addColumn')->willReturnCallback(
			function (string $name, string $type) use (&$added) {
				$added[] = [$name, $type];
				return $this->createColumnMock();
			}
		);
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_metrics')->willReturn(true);
		$schema->method('getTable')->with('openregister_metrics')->willReturn($table);

		(new Version002006000Date20261126010000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);

		$this->assertSame([['sample_count', Types::BIGINT], ['sample_sum_ms', Types::BIGINT]], $added);
	}

	/**
	 * An upgraded table that already has both columns is left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function testAnUpgradedTableIsLeftAlone(): void {
		$table = $this->createTableMock();
		$table->method('hasColumn')->willReturn(true);
		$table->expects($this->never())->method('addColumn');
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->method('getTable')->willReturn($table);

		(new Version002006000Date20261126010000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
	}
}
