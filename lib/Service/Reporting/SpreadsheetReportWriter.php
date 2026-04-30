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
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Reporting;

use DateTime;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Ods;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Render a resolved dashboard to CSV / XLSX / ODS via PhpSpreadsheet.
 */
class SpreadsheetReportWriter
{
    /**
     * Render the spreadsheet bytes.
     *
     * @param array<string, mixed>                               $dashboard       Dashboard payload.
     * @param array<int, array{widget: array, data: array|null}> $resolvedWidgets Widget+data tuples.
     * @param string                                             $format          csv|xlsx|ods.
     *
     * @return string Rendered bytes.
     */
    public function write(array $dashboard, array $resolvedWidgets, string $format): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle((string) ($dashboard['titel'] ?? 'Dashboard'))
            ->setDescription((string) ($dashboard['beschrijving'] ?? ''))
            ->setCreator('OpenRegister rapportage');

        $this->writeOverviewSheet(
            spreadsheet: $spreadsheet,
            dashboard: $dashboard,
            resolvedWidgets: $resolvedWidgets
        );

        foreach ($resolvedWidgets as $i => $entry) {
            $this->writeWidgetSheet(
                spreadsheet: $spreadsheet,
                index: $i,
                widget: $entry['widget'],
                data: $entry['data']
            );
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $this->writeFormat(spreadsheet: $spreadsheet, format: $format);

    }//end write()

    /**
     * Write the overview / cover sheet.
     *
     * @param Spreadsheet                                        $spreadsheet     Workbook.
     * @param array<string, mixed>                               $dashboard       Dashboard payload.
     * @param array<int, array{widget: array, data: array|null}> $resolvedWidgets Widget+data tuples.
     *
     * @return void
     */
    private function writeOverviewSheet(Spreadsheet $spreadsheet, array $dashboard, array $resolvedWidgets): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Overview');

        $sheet->setCellValue('A1', (string) ($dashboard['titel'] ?? 'Dashboard'));
        $sheet->getStyle('A1')->getFont()->setSize(16)->setBold(true);
        $sheet->mergeCells('A1:D1');

        if (isset($dashboard['beschrijving']) === true && $dashboard['beschrijving'] !== '') {
            $sheet->setCellValue('A2', (string) $dashboard['beschrijving']);
            $sheet->mergeCells('A2:D2');
        }

        $sheet->setCellValue('A4', 'Generated');
        $sheet->setCellValue('B4', (new DateTime())->format(DateTime::ATOM));
        $sheet->setCellValue('A5', 'Category');
        $sheet->setCellValue('B5', (string) ($dashboard['category'] ?? ''));
        $sheet->setCellValue('A6', 'Widgets');
        $sheet->setCellValue('B6', count($resolvedWidgets));

        // Widget summary table.
        $sheet->setCellValue('A8', '#');
        $sheet->setCellValue('B8', 'Widget');
        $sheet->setCellValue('C8', 'Type');
        $sheet->setCellValue('D8', 'Source');
        $sheet->setCellValue('E8', 'Headline');
        $headerRange = 'A8:E8';
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E5E5');

        $row = 9;
        foreach ($resolvedWidgets as $i => $entry) {
            $widget = $entry['widget'];
            $data   = $entry['data'];
            $sheet->setCellValue('A'.$row, ($i + 1));
            $sheet->setCellValue('B'.$row, (string) ($widget['title'] ?? ''));
            $sheet->setCellValue('C'.$row, (string) ($widget['type'] ?? ''));
            $sheet->setCellValue('D'.$row, $this->describeSource(widget: $widget));
            $sheet->setCellValue('E'.$row, $this->headlineFor(widget: $widget, data: $data));
            $row++;
        }

        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

    }//end writeOverviewSheet()

    /**
     * Write one sheet per widget with its data laid out as a table.
     *
     * @param Spreadsheet               $spreadsheet Workbook.
     * @param int                       $index       Widget index (drives sheet name + position).
     * @param array<string, mixed>      $widget      Widget descriptor.
     * @param array<string, mixed>|null $data        Resolved widget data.
     *
     * @return void
     */
    private function writeWidgetSheet(Spreadsheet $spreadsheet, int $index, array $widget, ?array $data): void
    {
        $title = (string) ($widget['title'] ?? ('Widget '.($index + 1)));
        $sheet = $spreadsheet->createSheet();
        // Excel sheet titles MUST be ≤ 31 chars and not contain : \ / ? * [ ].
        $cleanTitle = preg_replace('/[:\\\\\\/\\?\\*\\[\\]]/', '_', $title) ?? $title;
        $cleanTitle = substr(trim($cleanTitle), 0, 31);
        if ($cleanTitle === '') {
            $cleanTitle = 'Widget '.($index + 1);
        }

        $sheet->setTitle($cleanTitle);

        $sheet->setCellValue('A1', $title);
        $sheet->getStyle('A1')->getFont()->setSize(14)->setBold(true);
        $sheet->mergeCells('A1:D1');

        if (isset($widget['subtitle']) === true && $widget['subtitle'] !== '') {
            $sheet->setCellValue('A2', (string) $widget['subtitle']);
            $sheet->mergeCells('A2:D2');
        }

        if ($data === null) {
            $sheet->setCellValue('A4', 'No data available (data source did not resolve).');
            return;
        }

        // Group rows when the aggregation produced groups.
        $groups = ($data['groups'] ?? null);
        if (is_array($groups) === true && $groups !== []) {
            $sheet->setCellValue('A4', 'Key');
            $sheet->setCellValue('B4', 'Value');
            $sheet->getStyle('A4:B4')->getFont()->setBold(true);
            $row = 5;
            foreach ($groups as $group) {
                $sheet->setCellValue('A'.$row, (string) ($group['key'] ?? $group['label'] ?? ''));
                $cellValue = ($group['value'] ?? $group['count'] ?? null);
                $sheet->setCellValue('B'.$row, $this->numericOrString(value: $cellValue));
                $row++;
            }

            $sheet->getColumnDimension('A')->setAutoSize(true);
            $sheet->getColumnDimension('B')->setAutoSize(true);
            return;
        }

        // Flat scalar aggregation result — write the metric / value tuple.
        $sheet->setCellValue('A4', 'Metric');
        $sheet->setCellValue('B4', 'Value');
        $sheet->getStyle('A4:B4')->getFont()->setBold(true);
        $row = 5;
        foreach (['name', 'metric', 'value', 'count', 'sum', 'avg', 'min', 'max', 'count_distinct'] as $key) {
            if (array_key_exists($key, $data) === true && $data[$key] !== null) {
                $sheet->setCellValue('A'.$row, $key);
                $sheet->setCellValue('B'.$row, $this->numericOrString(value: $data[$key]));
                $row++;
            }
        }

        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setAutoSize(true);

    }//end writeWidgetSheet()

    /**
     * Write the workbook to the chosen format.
     *
     * @param Spreadsheet $spreadsheet Workbook.
     * @param string      $format      csv|xlsx|ods.
     *
     * @return string Rendered bytes.
     */
    private function writeFormat(Spreadsheet $spreadsheet, string $format): string
    {
        if ($format === 'csv') {
            // CSV is single-sheet — flatten to the active sheet.
            $writer = new Csv($spreadsheet);
            $writer->setUseBOM(true);
            // Loop over every sheet so the CSV captures the full content.
            $bytes      = '';
            $sheetCount = $spreadsheet->getSheetCount();
            for ($i = 0; $i < $sheetCount; $i++) {
                $writer->setSheetIndex($i);
                ob_start();
                $writer->save('php://output');
                $bytes .= "\r\n# Sheet: ".$spreadsheet->getSheet($i)->getTitle()."\r\n";
                $bytes .= (string) ob_get_clean();
            }

            return $bytes;
        }

        if ($format === 'ods') {
            $writer = new Ods($spreadsheet);
        } else {
            $writer = new Xlsx($spreadsheet);
        }

        ob_start();
        $writer->save('php://output');
        return (string) ob_get_clean();

    }//end writeFormat()

    /**
     * Best-effort headline for the overview row.
     *
     * @param array<string, mixed>      $widget Widget descriptor.
     * @param array<string, mixed>|null $data   Resolved data.
     *
     * @return string Headline value.
     */
    private function headlineFor(array $widget, ?array $data): string
    {
        if ($data === null) {
            return '—';
        }

        if (isset($data['groups']) === true && is_array($data['groups']) === true) {
            return sprintf('%d group(s)', count($data['groups']));
        }

        $valueField = $widget['options']['valueField'] ?? 'value';
        if (isset($data[$valueField]) === true && $data[$valueField] !== null) {
            return (string) $data[$valueField];
        }

        if (isset($data['count']) === true) {
            return (string) $data['count'];
        }

        return '—';

    }//end headlineFor()

    /**
     * Render the data-source as a single readable string.
     *
     * @param array<string, mixed> $widget Widget descriptor.
     *
     * @return string
     */
    private function describeSource(array $widget): string
    {
        $ds = ($widget['dataSource'] ?? null);
        if (is_array($ds) === false) {
            return '';
        }

        $mode = (string) ($ds['mode'] ?? '');
        if ($mode === 'aggregation') {
            return sprintf(
                '%s/%s/%s',
                $ds['register'] ?? '',
                $ds['schema'] ?? '',
                $ds['aggregation'] ?? ''
            );
        }

        if ($mode === 'graphql') {
            return 'graphql';
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
    private function numericOrString($value)
    {
        if ($value === null) {
            return '';
        }

        if (is_numeric($value) === true) {
            return ($value + 0);
        }

        return (string) $value;

    }//end numericOrString()
}//end class
