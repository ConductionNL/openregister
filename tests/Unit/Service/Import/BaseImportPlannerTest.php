<?php

/**
 * A whole base export is read into a plan a person confirms (REQ-IPC-006).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md#REQ-IPC-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Import;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Import\BaseColumn;
use OCA\OpenRegister\Service\Import\BaseImportPlanner;
use OCA\OpenRegister\Service\Import\BaserowBaseReader;
use OCA\OpenRegister\Service\Import\BaseSchemaProposer;
use OCA\OpenRegister\Service\Import\BaseSet;
use OCA\OpenRegister\Service\Import\BaseSetReader;
use OCA\OpenRegister\Service\Import\BaseTable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see BaseImportPlanner}.
 */
#[CoversClass(BaseImportPlanner::class)]
class BaseImportPlannerTest extends TestCase {

	private const FIXTURE = __DIR__.'/../../../Fixtures/import/baserow-projecten-taken.json';

	/**
	 * The two-table fixture plans two schemas, one relation, both row counts, and Projecten before Taken.
	 *
	 * @return void
	 */
	public function testTheTwoTableFixtureIsPlanned(): void {
		$plan = $this->planner()->plan(format: ' Baserow ', filePath: self::FIXTURE);

		self::assertSame('baserow', $plan['format']);
		self::assertSame(['Projecten', 'Taken'], array_keys($plan['proposal']['schemas']));
		self::assertCount(1, $plan['proposal']['relations']);
		self::assertSame(['Projecten', 'Taken'], $plan['order']);
		self::assertSame([], $plan['deferredLinks']);
		self::assertSame(array_keys($plan['proposal']['schemas']), array_keys($plan['tables']));
		self::assertGreaterThan(0, $plan['tables']['Taken']);
	}//end testTheTwoTableFixtureIsPlanned()

	/**
	 * A table comes after what it links to, whatever the export order.
	 *
	 * @return void
	 */
	public function testATableLoadsAfterTheTablesItLinksTo(): void {
		$set = new BaseSet(
			name: 'b',
			source: 'baserow',
			tables: [
				new BaseTable(name: 'Taken', columns: [new BaseColumn(name: 'project', type: BaseColumn::TYPE_LINK, linkTable: 'Projecten')], rows: []),
				new BaseTable(name: 'Projecten', columns: [new BaseColumn(name: 'klant', type: BaseColumn::TYPE_LINK, linkTable: 'Klanten')], rows: []),
				new BaseTable(name: 'Klanten', columns: [new BaseColumn(name: 'naam', type: BaseColumn::TYPE_STRING)], rows: []),
			]
		);

		self::assertSame(['order' => ['Klanten', 'Projecten', 'Taken'], 'deferred' => []], $this->planner()->loadOrder(set: $set));
	}//end testATableLoadsAfterTheTablesItLinksTo()

	/**
	 * Tables whose links form a cycle keep export order and are named; a self link is no cycle.
	 *
	 * @return void
	 */
	public function testACycleIsDeferredAndASelfLinkIsNot(): void {
		$set = new BaseSet(
			name: 'b',
			source: 'baserow',
			tables: [
				new BaseTable(name: 'A', columns: [new BaseColumn(name: 'b', type: BaseColumn::TYPE_LINK, linkTable: 'B')], rows: []),
				new BaseTable(name: 'B', columns: [new BaseColumn(name: 'a', type: BaseColumn::TYPE_LINK, linkTable: 'A')], rows: []),
				new BaseTable(name: 'Boom', columns: [new BaseColumn(name: 'ouder', type: BaseColumn::TYPE_LINK, linkTable: 'Boom')], rows: []),
			]
		);

		self::assertSame(['order' => ['Boom', 'A', 'B'], 'deferred' => ['A', 'B']], $this->planner()->loadOrder(set: $set));
	}//end testACycleIsDeferredAndASelfLinkIsNot()

	/**
	 * An unknown format is refused, naming the formats that can be read.
	 *
	 * @return void
	 */
	public function testAnUnknownFormatIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('readable formats: baserow');

		$this->planner()->plan(format: 'airtable', filePath: self::FIXTURE);
	}//end testAnUnknownFormatIsRefused()

	/**
	 * The planner over the real reader and proposer.
	 *
	 * @return BaseImportPlanner The planner.
	 */
	private function planner(): BaseImportPlanner {
		return new BaseImportPlanner(reader: new BaseSetReader(new BaserowBaseReader()), proposer: new BaseSchemaProposer());
	}//end planner()
}//end class
