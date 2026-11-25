<?php

/**
 * OpenRegister audit report file
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Ods;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Writes an audit report as a spreadsheet (entries and summary sheets) or as CSV (entries only).
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-audit-report-generation
 */
final class AuditReportFile {
	/**
	 * The formats this writes, with their media type.
	 *
	 * @var array<string, string>
	 */
	public const FORMATS = [
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
		'csv'  => 'text/csv',
	];

	/**
	 * Write a built report.
	 *
	 * @param array<string, mixed> $report The report from AuditReport::build().
	 * @param string               $format One of FORMATS.
	 *
	 * @return array{mime: string, filename: string, bytes: string}
	 *
	 * @throws InvalidArgumentException When the format is not one of FORMATS.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-audit-report-generation
	 */
	public function write(array $report, string $format): array {
		if (isset(self::FORMATS[$format]) === false) {
			throw new InvalidArgumentException(
				sprintf('Unsupported audit report format "%s"; use %s.', $format, implode(', ', array_keys(self::FORMATS)))
			);
		}

		$bytes = $this->csv(entries: $report['entries']);
		if ($format !== 'csv') {
			$bytes = $this->spreadsheet(report: $report, format: $format);
		}

		return ['mime' => self::FORMATS[$format], 'filename' => 'audit-report.'.$format, 'bytes' => $bytes];
	}//end write()

	/**
	 * The entries as CSV with a heading row.
	 *
	 * @param array<int, array<string, string>> $entries The rows.
	 *
	 * @return string The CSV.
	 */
	private function csv(array $entries): string {
		$handle = fopen('php://temp', 'r+');
		fputcsv($handle, AuditReport::COLUMNS, ',', '"', '');
		foreach ($entries as $entry) {
			fputcsv($handle, $this->cells(entry: $entry), ',', '"', '');
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return $csv;
	}//end csv()

	/**
	 * The report as a two-sheet spreadsheet: the entries, then the summary.
	 *
	 * @param array<string, mixed> $report The report.
	 * @param string               $format xlsx or ods.
	 *
	 * @return string The file bytes.
	 */
	private function spreadsheet(array $report, string $format): string {
		$book = new Spreadsheet();
		$entries = $book->getActiveSheet();
		$entries->setTitle('Audit trail');
		$entries->fromArray([AuditReport::COLUMNS], null, 'A1');
		$line = 2;
		foreach ($report['entries'] as $entry) {
			$entries->fromArray([$this->cells(entry: $entry)], null, 'A'.$line, true);
			$line++;
		}

		$summary = $book->createSheet();
		$summary->setTitle('Summary');
		$summary->fromArray($this->summaryRows(report: $report), null, 'A1', true);

		$writer = new Xlsx($book);
		if ($format === 'ods') {
			$writer = new Ods($book);
		}

		$handle = fopen('php://temp', 'r+');
		$writer->save($handle);
		rewind($handle);
		$bytes = (string)stream_get_contents($handle);
		fclose($handle);
		$book->disconnectWorksheets();

		return $bytes;
	}//end spreadsheet()

	/**
	 * One entry's cells, in COLUMNS order. A value that starts like a formula is kept as text.
	 *
	 * @param array<string, string> $entry The row.
	 *
	 * @return array<int, string>
	 */
	private function cells(array $entry): array {
		$cells = [];
		foreach (AuditReport::COLUMNS as $column) {
			$value = (string)($entry[$column] ?? '');
			if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) === true) {
				$value = "'".$value;
			}

			$cells[] = $value;
		}

		return $cells;
	}//end cells()

	/**
	 * The summary sheet's rows: the total, the entries per action, the most active objects.
	 *
	 * @param array<string, mixed> $report The report.
	 *
	 * @return array<int, array<int, string|int>>
	 */
	private function summaryRows(array $report): array {
		$rows = [['Total entries', $report['summary']['total']]];
		if ($report['truncated'] === true) {
			$rows[] = ['Cut at', AuditReport::MAX_ENTRIES];
		}

		$rows[] = [''];
		$rows[] = ['Action', 'Entries'];
		foreach ($report['summary']['actions'] as $action => $count) {
			$rows[] = [(string)$action, $count];
		}

		$rows[] = [''];
		$rows[] = ['Object UUID', 'Object name', 'Entries'];
		foreach ($report['summary']['mostActiveObjects'] as $object) {
			$rows[] = [$object['objectUuid'], $object['objectName'], $object['count']];
		}

		return $rows;
	}//end summaryRows()
}//end class
