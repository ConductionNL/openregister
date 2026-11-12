<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md#requirement-a-share-never-grants-more-than-its-creator-holds-req-fse-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Migration;

use OCA\OpenRegister\Migration\Version1Date20261103220000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\IColumn;
use OCP\DB\Schema\ITable;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Migration\Version1Date20261103220000
 */
class Version1Date20261103220000Test extends TestCase {

	/**
	 * Runs the step against a table double and returns the columns it added.
	 *
	 * @param bool $hasTable  Whether the table exists.
	 * @param bool $hasColumn Whether the column exists.
	 *
	 * @return list<string> The added columns.
	 */
	private function applyStep(bool $hasTable, bool $hasColumn): array {
		if (interface_exists(ITable::class) === false) {
			// OCP\DB\Schema\ITable ships with Nextcloud 35. The unit suite also runs
			// against the older servers info.xml declares (NC 32-34), whose OCP
			// shadows the nextcloud/ocp dev dependency and has no ITable to mock.
			$this->markTestSkipped(message: 'OCP\DB\Schema\ITable needs Nextcloud 35; the step is covered on the stable35 run.');
		}

		$added = [];
		$table = $this->createMock(ITable::class);
		$table->method('hasColumn')->with('owner')->willReturn($hasColumn);
		$table->method('addColumn')->willReturnCallback(
			function (string $name, string $type, array $options) use (&$added, $table) {
				$added[] = $name . ':' . json_encode($options['notnull']);
				return $this->createMock(IColumn::class);
			}
		);
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('openregister_federated_shares')->willReturn($hasTable);
		$schema->method('getTable')->willReturn($table);

		$result = (new Version1Date20261103220000())->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);
		$this->assertSame($schema, $result);

		return $added;
	}

	public function testTheColumnIsAddedNullable(): void {
		$this->assertSame(['owner:false'], $this->applyStep(hasTable: true, hasColumn: false));
	}

	public function testAnExistingColumnIsLeftAlone(): void {
		$this->assertSame([], $this->applyStep(hasTable: true, hasColumn: true));
	}

	public function testAMissingTableIsLeftAlone(): void {
		$this->assertSame([], $this->applyStep(hasTable: false, hasColumn: false));
	}
}
