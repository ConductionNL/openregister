<?php

/**
 * Spreadsheet report writer (CSV / XLSX / ODS).
 *
 * Renders a resolved dashboard into a spreadsheet by giving each widget
 * its own sheet. The cover sheet ("Overview") summarises the dashboard
 * + lists each widget with its top-level value or row-count.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Reporting
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Reporting;

use DateTime;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\IWriter;
use PhpOffice\PhpSpreadsheet\Writer\Ods;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Render a resolved dashboard to CSV / XLSX / ODS via PhpSpreadsheet.
 */
class SpreadsheetReportWriter {

	/**
	 * Keys of a flat (ungrouped) aggregation result, in the order they are listed.
	 *
	 * @var array<int, string>
	 */
	private const SCALAR_KEYS = ['name', 'metric', 'value', 'count', 'sum', 'avg', 'min', 'max', 'count_distinct'];

	/**
	 * Render the spreadsheet bytes.
	 *
	 * @param array<string, mixed> $dashboard Dashboard payload.
	 * @param array<int, array{widget: array, data: array|null}> $resolvedWidgets Widget+data tuples.
	 * @param string $format csv|xlsx|ods.
	 *
	 * @return string Rendered bytes.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-5
	 */
	public function write(array $dashboard, array $resolvedWidgets, string $format): string {
		$spreadsheet = $this->newWorkbook(dashboard: $dashboard);
		$overview = $spreadsheet->getActiveSheet();
		$this->writeOverviewSheet(sheet: $overview, dashboard: $dashboard);
		$this->writeOverviewTable(sheet: $overview, resolvedWidgets: $resolvedWidgets);
		foreach ($resolvedWidgets as $i => $entry) {
			$widgetEntry = new ReportWidgetEntry(index: $i, widget: $entry['widget'], data: $entry['data']);
			$this->writeWidgetSheet(sheet: $spreadsheet->createSheet(), entry: $widgetEntry);
		}

		$spreadsheet->setActiveSheetIndex(0);
		return $this->writeFormat(spreadsheet: $spreadsheet, format: $format);
	}//end write()

	/**
	 * An empty workbook carrying the dashboard's title and description.
	 *
	 * @param array<string, mixed> $dashboard Dashboard payload.
	 *
	 * @return Spreadsheet
	 */
	private function newWorkbook(array $dashboard): Spreadsheet {
		$spreadsheet = new Spreadsheet();
		$spreadsheet->getProperties()
			->setTitle((string)($dashboard['titel'] ?? 'Dashboard'))
			->setDescription((string)($dashboard['beschrijving'] ?? ''))
			->setCreator('OpenRegister rapportage');
		return $spreadsheet;
	}//end newWorkbook()

	/**
	 * Write the overview / cover sheet: heading and dashboard facts.
	 *
	 * @param Worksheet            $sheet     The first sheet.
	 * @param array<string, mixed> $dashboard Dashboard payload.
	 *
	 * @return void
	 */
	private function writeOverviewSheet(Worksheet $sheet, array $dashboard): void {
		$sheet->setTitle('Overview');
		$title = (string)($dashboard['titel'] ?? 'Dashboard');
		$this->writeHeading(sheet: $sheet, heading: new SheetHeading(title: $title, subtitle: ($dashboard['beschrijving'] ?? null), fontSize: 16));
		$this->writeCells(
			sheet: $sheet,
			cells: [
				'A4' => 'Generated',
				'B4' => (new DateTime())->format(DateTime::ATOM),
				'A5' => 'Category',
				'B5' => (string)($dashboard['category'] ?? ''),
			]
		);
	}//end writeOverviewSheet()

	/**
	 * Write the widget count and the widget summary table on the overview sheet.
	 *
	 * @param Worksheet                                          $sheet           The overview sheet.
	 * @param array<int, array{widget: array, data: array|null}> $resolvedWidgets Widget+data tuples.
	 *
	 * @return void
	 */
	private function writeOverviewTable(Worksheet $sheet, array $resolvedWidgets): void {
		$this->writeCells(sheet: $sheet, cells: ['A6' => 'Widgets', 'B6' => count($resolvedWidgets)]);
		$this->writeCells(sheet: $sheet, cells: $this->rowCells(row: 8, values: ['#', 'Widget', 'Type', 'Source', 'Headline']));
		$sheet->getStyle('A8:E8')->getFont()->setBold(true);
		$sheet->getStyle('A8:E8')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E5E5');

		$row = 9;
		foreach ($resolvedWidgets as $i => $entry) {
			$this->writeCells(sheet: $sheet, cells: $this->rowCells(row: $row, values: $this->overviewValues(index: $i, entry: $entry)));
			$row++;
		}

		foreach (range('A', 'E') as $col) {
			$sheet->getColumnDimension($col)->setAutoSize(true);
		}
	}//end writeOverviewTable()

	/**
	 * The overview row for one widget: number, title, type, source, headline.
	 *
	 * @param mixed                                $index The widget's key in the resolved list.
	 * @param array{widget: array, data: array|null} $entry Widget+data tuple.
	 *
	 * @return array<int, mixed>
	 */
	private function overviewValues(mixed $index, array $entry): array {
		$widget = $entry['widget'];
		return [
			($index + 1),
			(string)($widget['title'] ?? ''),
			(string)($widget['type'] ?? ''),
			$this->describeSource(widget: $widget),
			$this->headlineFor(widget: $widget, data: $entry['data']),
		];
	}//end overviewValues()

	/**
	 * Write one sheet per widget with its data laid out as a table.
	 *
	 * @param Worksheet         $sheet The new sheet for this widget.
	 * @param ReportWidgetEntry $entry The widget, its index and its resolved data.
	 *
	 * @return void
	 */
	private function writeWidgetSheet(Worksheet $sheet, ReportWidgetEntry $entry): void {
		$title = (string)($entry->widget['title'] ?? ('Widget ' . ($entry->index + 1)));
		$sheet->setTitle($this->sheetTitle(title: $title, index: $entry->index));
		$this->writeHeading(sheet: $sheet, heading: new SheetHeading(title: $title, subtitle: ($entry->widget['subtitle'] ?? null), fontSize: 14));
		if ($entry->data === null) {
			$sheet->setCellValue('A4', 'No data available (data source did not resolve).');
			return;
		}

		$this->writeDataTable(sheet: $sheet, table: $this->dataTable(data: $entry->data));
	}//end writeWidgetSheet()

	/**
	 * A sheet title Excel accepts: at most 31 characters, none of
	 * `: \ / ? * [ ]`, and not blank.
	 *
	 * @param string $title The widget title.
	 * @param int    $index The widget index (for the fallback title).
	 *
	 * @return string
	 */
	private function sheetTitle(string $title, int $index): string {
		$cleanTitle = preg_replace('/[:\\\\\\/\\?\\*\\[\\]]/', '_', $title) ?? $title;
		$cleanTitle = substr(trim($cleanTitle), 0, 31);
		if ($cleanTitle === '') {
			return 'Widget ' . ($index + 1);
		}

		return $cleanTitle;
	}//end sheetTitle()

	/**
	 * Write a bold title in A1 (merged to D1) and, when given, a subtitle in
	 * A2 (merged to D2).
	 *
	 * @param Worksheet    $sheet   The sheet.
	 * @param SheetHeading $heading The title, subtitle and title font size.
	 *
	 * @return void
	 */
	private function writeHeading(Worksheet $sheet, SheetHeading $heading): void {
		$sheet->setCellValue('A1', $heading->title);
		$sheet->getStyle('A1')->getFont()->setSize($heading->fontSize)->setBold(true);
		$sheet->mergeCells('A1:D1');
		if ($heading->subtitle === null || $heading->subtitle === '') {
			return;
		}

		$sheet->setCellValue('A2', (string)$heading->subtitle);
		$sheet->mergeCells('A2:D2');
	}//end writeHeading()

	/**
	 * The widget's data as table rows, header first: Key/Value per group when
	 * the aggregation produced groups, otherwise Metric/Value per set scalar.
	 *
	 * @param array<string, mixed> $data Resolved widget data.
	 *
	 * @return array<int, array{0: mixed, 1: mixed}>
	 */
	private function dataTable(array $data): array {
		$groups = ($data['groups'] ?? null);
		if (is_array($groups) === true && $groups !== []) {
			return array_merge([['Key', 'Value']], array_map(fn (mixed $group): array => $this->groupRow(group: $group), array_values($groups)));
		}

		$keys = array_filter(self::SCALAR_KEYS, static fn (string $key): bool => array_key_exists($key, $data) === true && $data[$key] !== null);
		$rows = array_map(fn (string $key): array => [$key, $this->numericOrString(value: $data[$key])], array_values($keys));
		return array_merge([['Metric', 'Value']], $rows);
	}//end dataTable()

	/**
	 * One group as a table row: its key (or label) and its value (or count).
	 *
	 * @param mixed $group One aggregation group.
	 *
	 * @return array{0: string, 1: mixed}
	 */
	private function groupRow(mixed $group): array {
		return [
			(string)($group['key'] ?? $group['label'] ?? ''),
			$this->numericOrString(value: ($group['value'] ?? $group['count'] ?? null)),
		];
	}//end groupRow()

	/**
	 * Write a two-column table from A4 down, with a bold header row.
	 *
	 * @param Worksheet                             $sheet The sheet.
	 * @param array<int, array{0: mixed, 1: mixed}> $table Rows, header first.
	 *
	 * @return void
	 */
	private function writeDataTable(Worksheet $sheet, array $table): void {
		foreach ($table as $offset => $values) {
			$this->writeCells(sheet: $sheet, cells: $this->rowCells(row: (4 + $offset), values: $values));
		}

		$sheet->getStyle('A4:B4')->getFont()->setBold(true);
		$sheet->getColumnDimension('A')->setAutoSize(true);
		$sheet->getColumnDimension('B')->setAutoSize(true);
	}//end writeDataTable()

	/**
	 * Map a row of values onto cell coordinates, starting in column A.
	 *
	 * @param int               $row    The row number.
	 * @param array<int, mixed> $values The values, left to right.
	 *
	 * @return array<string, mixed> Coordinate => value.
	 */
	private function rowCells(int $row, array $values): array {
		$cells = [];
		foreach (array_values($values) as $column => $value) {
			$cells[chr(ord('A') + $column) . $row] = $value;
		}

		return $cells;
	}//end rowCells()

	/**
	 * Set each cell to its value.
	 *
	 * @param Worksheet            $sheet The sheet.
	 * @param array<string, mixed> $cells Coordinate => value.
	 *
	 * @return void
	 */
	private function writeCells(Worksheet $sheet, array $cells): void {
		foreach ($cells as $coordinate => $value) {
			$sheet->setCellValue($coordinate, $value);
		}
	}//end writeCells()

	/**
	 * Write the workbook to the chosen format; anything but csv and ods is xlsx.
	 *
	 * @param Spreadsheet $spreadsheet Workbook.
	 * @param string $format csv|xlsx|ods.
	 *
	 * @return string Rendered bytes.
	 */
	private function writeFormat(Spreadsheet $spreadsheet, string $format): string {
		if ($format === 'csv') {
			return $this->writeCsv(spreadsheet: $spreadsheet);
		}

		$writer = new Xlsx($spreadsheet);
		if ($format === 'ods') {
			$writer = new Ods($spreadsheet);
		}

		return $this->captured(writer: $writer);
	}//end writeFormat()

	/**
	 * CSV is single-sheet, so every sheet is written in turn, each after a
	 * `# Sheet: <title>` line, to capture the full content.
	 *
	 * @param Spreadsheet $spreadsheet Workbook.
	 *
	 * @return string Rendered bytes.
	 */
	private function writeCsv(Spreadsheet $spreadsheet): string {
		$writer = new Csv($spreadsheet);
		$writer->setUseBOM(true);
		$bytes = '';
		$sheetCount = $spreadsheet->getSheetCount();
		for ($i = 0; $i < $sheetCount; $i++) {
			$writer->setSheetIndex($i);
			$bytes .= "\r\n# Sheet: " . $spreadsheet->getSheet($i)->getTitle() . "\r\n" . $this->captured(writer: $writer);
		}

		return $bytes;
	}//end writeCsv()

	/**
	 * The bytes a writer saves to php://output.
	 *
	 * @param IWriter $writer The configured writer.
	 *
	 * @return string
	 */
	private function captured(IWriter $writer): string {
		ob_start();
		$writer->save('php://output');
		return (string)ob_get_clean();
	}//end captured()

	/**
	 * Best-effort headline for the overview row.
	 *
	 * @param array<string, mixed> $widget Widget descriptor.
	 * @param array<string, mixed>|null $data Resolved data.
	 *
	 * @return string Headline value.
	 */
	private function headlineFor(array $widget, ?array $data): string {
		if ($data === null) {
			return '—';
		}

		if (is_array($data['groups'] ?? null) === true) {
			return sprintf('%d group(s)', count($data['groups']));
		}

		$value = ($data[$widget['options']['valueField'] ?? 'value'] ?? $data['count'] ?? null);
		if ($value === null) {
			return '—';
		}

		return (string)$value;
	}//end headlineFor()

	/**
	 * Render the data-source as a single readable string: the aggregation
	 * path for an aggregation source, otherwise its mode.
	 *
	 * @param array<string, mixed> $widget Widget descriptor.
	 *
	 * @return string
	 */
	private function describeSource(array $widget): string {
		$source = ($widget['dataSource'] ?? null);
		if (is_array($source) === false) {
			return '';
		}

		$mode = (string)($source['mode'] ?? '');
		if ($mode === 'aggregation') {
			return sprintf('%s/%s/%s', ($source['register'] ?? ''), ($source['schema'] ?? ''), ($source['aggregation'] ?? ''));
		}

		return $mode;
	}//end describeSource()

	/**
	 * Coerce a scalar into a numeric-or-string suitable for spreadsheet cells.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return mixed
	 */
	private function numericOrString($value) {
		if ($value === null) {
			return '';
		}

		if (is_numeric($value) === true) {
			return ($value + 0);
		}

		return (string)$value;
	}//end numericOrString()
}//end class
