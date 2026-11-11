<?php

/**
 * The exported feed holds the rows the reader was looking at.
 *
 * 🔴 AN EXPORT THAT RE-QUERIES CAN DISAGREE WITH THE SCREEN, and the reader
 * has no way to tell which was wrong. This exporter is handed the rendered
 * page, so the test hands it a filtered page and asserts the file holds
 * exactly those rows and no others.
 *
 * 🔴 A CELL BEGINNING `=` IS EXECUTED BY A SPREADSHEET when the file opens. A
 * summary is text a person typed into a note, so every cell that could start
 * a formula is prefixed — asserted here because the failure only shows on
 * somebody else's machine, after the export has left ours.
 *
 * 🔴 AN UNDATED ROW MUST NOT EXPORT AS 1970, which reads as a real date and
 * sorts as one.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Integration\ActivityFeedExport;
use OCA\OpenRegister\Service\Integration\ActivityFeedMerge;
use PHPUnit\Framework\TestCase;

/**
 * The CSV export of a merged page.
 *
 * @spec openspec/changes/activity-leaf/specs/integration-activity/spec.md
 */
class ActivityFeedExportTest extends TestCase {

	private ActivityFeedExport $export;

	protected function setUp(): void {
		parent::setUp();
		$this->export = new ActivityFeedExport();
	}//end setUp()

	public function testTheFileHoldsTheFilteredRowsAndNoOthers(): void {
		// The page a reader who filtered on notes is looking at.
		$page = (new ActivityFeedMerge())->page(
			[
				'note' => [
					['id' => 'n1', 'timestamp' => 300, 'actor' => 'carol', 'summary' => 'gebeld'],
					['id' => 'n2', 'timestamp' => 200, 'actor' => 'carol', 'summary' => 'teruggebeld'],
					['id' => 'n3', 'timestamp' => 100, 'actor' => 'dave', 'summary' => 'brief'],
				],
				'file' => [['id' => 'f1', 'timestamp' => 250, 'summary' => 'gevel.jpg']],
			],
			['kinds' => ['note']]
		);

		$csv = $this->export->toCsv($page['rows']);
		$lines = array_values(array_filter(explode("\n", trim($csv))));

		$this->assertCount(4, $lines, 'a header and three note rows');
		$this->assertStringContainsString('when,kind,actor', $lines[0]);
		$this->assertStringNotContainsString('gevel.jpg', $csv);
		$this->assertStringContainsString('gebeld', $csv);
	}//end testTheFileHoldsTheFilteredRowsAndNoOthers()

	public function testTheColumnsAreTheOnesAReaderReads(): void {
		$csv = $this->export->toCsv([]);

		$this->assertSame('when,kind,actor,action,summary,url', trim($csv));
		$this->assertSame(['when', 'kind', 'actor', 'action', 'summary', 'url'], ActivityFeedExport::COLUMNS);
	}//end testTheColumnsAreTheOnesAReaderReads()

	public function testACellThatWouldRunIsWrittenAsText(): void {
		$csv = $this->export->toCsv([
			['timestamp' => 100, 'kind' => 'note', 'summary' => '=cmd|/c calc'],
		]);

		// Prefixed, not stripped: removing the character would change what the
		// note says, and the note is evidence.
		$this->assertStringContainsString("'=cmd|/c calc", $csv);
	}//end testACellThatWouldRunIsWrittenAsText()

	public function testEveryFormulaStarterIsCovered(): void {
		foreach (['=', '+', '-', '@'] as $start) {
			$csv = $this->export->toCsv([['timestamp' => 100, 'kind' => 'note', 'summary' => $start . 'HYPERLINK("x")']]);
			$this->assertStringContainsString("'" . $start, $csv, sprintf('a cell starting %s still runs', $start));
		}
	}//end testEveryFormulaStarterIsCovered()

	public function testTheMomentIsReadableAndCarriesTheTime(): void {
		$csv = $this->export->toCsv([['timestamp' => 1789000000, 'kind' => 'audit']], 'UTC');

		$this->assertStringContainsString(gmdate('Y-m-d H:i', 1789000000), $csv);
	}//end testTheMomentIsReadableAndCarriesTheTime()

	public function testAnUndatedRowExportsAnEmptyCellRatherThan1970(): void {
		$csv = $this->export->toCsv([['kind' => 'note', 'summary' => 'geen datum']]);

		$this->assertStringNotContainsString('1970', $csv);
		$this->assertStringContainsString('geen datum', $csv);
	}//end testAnUndatedRowExportsAnEmptyCellRatherThan1970()

	public function testAnUnknownZoneFallsBackRatherThanThrowing(): void {
		$csv = $this->export->toCsv([['timestamp' => 1789000000, 'kind' => 'audit']], 'Mars/Olympus');

		$this->assertStringContainsString(gmdate('Y-m-d H:i', 1789000000), $csv);
	}//end testAnUnknownZoneFallsBackRatherThanThrowing()
}//end class
