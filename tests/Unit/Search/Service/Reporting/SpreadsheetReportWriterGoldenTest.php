<?php

/**
 * Golden test for SpreadsheetReportWriter::write().
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Reporting;

use DateTime;
use OCA\OpenRegister\Search\Service\Reporting\ReportContent;
use OCA\OpenRegister\Search\Service\Reporting\SpreadsheetReportWriter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;

/**
 * Renders fixed dashboards to xlsx, ods and csv and pins what comes out:
 * for the workbook formats the read-back sheets (titles, every cell value,
 * merges and the styles the writer sets), for csv the text. The "Generated"
 * timestamp is checked to be an ATOM date and then masked, so the golden
 * never depends on the clock. Compared with
 * fixtures/spreadsheet-report-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Search\Service\Reporting\SpreadsheetReportWriter
 */
class SpreadsheetReportWriterGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/spreadsheet-report-golden.json';

	private const MASK = '<generated>';

	/**
	 * Case name => [dashboard, resolved widgets].
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<int, array<string, mixed>>}>
	 */
	private static function cases(): array {
		$agg = ['mode' => 'aggregation', 'register' => 'zaken', 'schema' => 'zaak', 'aggregation' => 'perStatus'];
		return [
			'empty dashboard' => [[], []],
			'full dashboard' => [
				['titel' => 'Zaken overzicht', 'beschrijving' => 'Alle zaken', 'category' => 'ops'],
				[
					['widget' => ['title' => 'Per status', 'type' => 'bar', 'subtitle' => 'open en dicht', 'dataSource' => $agg], 'data' => ['groups' => [['key' => 'open', 'value' => 3], ['label' => 'dicht', 'count' => '7'], ['key' => 'x', 'value' => 'n/a'], ['value' => 1.5], []]]],
					['widget' => ['title' => 'Totaal', 'type' => 'kpi', 'dataSource' => ['mode' => 'graphql', 'query' => '{x}']], 'data' => ['name' => 'totaal', 'metric' => 'count', 'value' => 10, 'count' => 10, 'sum' => null, 'avg' => '2.50', 'min' => 0, 'extra' => 1]],
					['widget' => ['type' => 'kpi', 'dataSource' => ['mode' => 'static']], 'data' => null],
					['widget' => ['title' => 'Lang: a/b\\c?d*e[f]g:h en dan nog veel meer tekst', 'options' => ['valueField' => 'sum'], 'dataSource' => 'oops'], 'data' => ['sum' => 42, 'count' => 1]],
					['widget' => ['title' => '   ', 'subtitle' => '', 'options' => ['valueField' => 'avg']], 'data' => ['count' => 5]],
					['widget' => ['title' => 'Leeg', 'dataSource' => ['register' => 'r']], 'data' => ['groups' => []]],
					['widget' => ['title' => 'Geen waarde'], 'data' => ['metric' => 'sum']],
					['widget' => ['title' => 'Aggregatie zonder velden', 'dataSource' => ['mode' => 'aggregation']], 'data' => ['groups' => 'not-a-list', 'value' => true]],
				],
			],
			'description empty, numeric title' => [
				['titel' => 12, 'beschrijving' => '', 'category' => 3],
				[['widget' => ['title' => 'Een'], 'data' => ['value' => 0]]],
			],
		];
	}//end cases()

	/**
	 * @param Worksheet $sheet One read-back sheet.
	 *
	 * @return array<string, mixed>
	 */
	private static function describeSheet(Worksheet $sheet): array {
		$cells = [];
		foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
			$cells[$coordinate] = $sheet->getCell($coordinate)->getValue();
		}

		ksort($cells);
		$styles = [];
		foreach (['A1', 'A2', 'A4', 'B4', 'A8', 'E8', 'A9'] as $coordinate) {
			$style = $sheet->getStyle($coordinate);
			$styles[$coordinate] = [
				'bold' => $style->getFont()->getBold(),
				'size' => $style->getFont()->getSize(),
				'fill' => $style->getFill()->getFillType(),
				'rgb' => $style->getFill()->getStartColor()->getRGB(),
			];
		}

		return ['title' => $sheet->getTitle(), 'cells' => $cells, 'merged' => array_values($sheet->getMergeCells()), 'styles' => $styles];
	}//end describeSheet()

	/**
	 * @param Spreadsheet $book The read-back workbook.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function describeBook(Spreadsheet $book): array {
		$generated = (string) $book->getSheet(0)->getCell('B4')->getValue();
		$this->assertNotFalse(DateTime::createFromFormat(DateTime::ATOM, $generated), $generated);
		$book->getSheet(0)->setCellValue('B4', self::MASK);

		$sheets = [];
		foreach ($book->getAllSheets() as $sheet) {
			$sheets[] = self::describeSheet($sheet);
		}

		return $sheets;
	}//end describeBook()

	/**
	 * @param string $format xlsx|ods.
	 * @param string $bytes  Rendered bytes.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function readBack(string $format, string $bytes): array {
		$path = tempnam(sys_get_temp_dir(), 'or-report-') . '.' . $format;
		file_put_contents($path, $bytes);
		try {
			return $this->describeBook(IOFactory::createReader(ucfirst($format))->load($path));
		} finally {
			unlink($path);
		}
	}//end readBack()

	public function testWriteMatchesGolden(): void {
		$writer = new SpreadsheetReportWriter();
		$actual = [];
		foreach (self::cases() as $name => [$dashboard, $widgets]) {
			$actual[$name . ' xlsx'] = $this->readBack('xlsx', $writer->write(new ReportContent($dashboard, $widgets), 'xlsx'));
			$actual[$name . ' ods']  = $this->readBack('ods', $writer->write(new ReportContent($dashboard, $widgets), 'ods'));
			$actual[$name . ' unknown format'] = $this->readBack('xlsx', $writer->write(new ReportContent($dashboard, $widgets), 'pdf'));

			$csv = $writer->write(new ReportContent($dashboard, $widgets), 'csv');
			$this->assertSame(1, preg_match('/"Generated","([^"]+)"/', $csv, $match));
			$this->assertNotFalse(DateTime::createFromFormat(DateTime::ATOM, $match[1]), $match[1]);
			$actual[$name . ' csv'] = explode("\n", str_replace($match[1], self::MASK, $csv));
		}

		$actual = json_decode((string) json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name], $name);
		}
	}//end testWriteMatchesGolden()
}//end class
