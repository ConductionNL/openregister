<?php

/**
 * FailedRowExport: the rows of an import that failed, in the input's columns plus a reason column.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import\RecordImport
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import\RecordImport;

use RuntimeException;

/**
 * Re-reads the imported file and keeps the rows whose line failed, as CSV.
 *
 * The columns are the file's own, in its order, followed by the reason column,
 * so the user corrects the rows in the shape they started from and drops the
 * file back into the importer. The reason column is then an extra column the
 * mapping step lists as unmatched, to be ignored. A failure without a line
 * (none is known for it) cannot be placed on a row and is counted instead.
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */
class FailedRowExport {

	/**
	 * The reason column's name, unless the file already has a column of that name.
	 */
	public const REASON_COLUMN = 'reason';

	/**
	 * The reason column's name when the file already has a `reason` column.
	 */
	public const REASON_COLUMN_FALLBACK = 'import_reason';

	/**
	 * Constructor.
	 *
	 * @param RecordFileReader $reader Reads the file again.
	 */
	public function __construct(
		private readonly RecordFileReader $reader,
	) {
	}//end __construct()

	/**
	 * The failed rows of an import of this file, as CSV.
	 *
	 * @param string                                      $path     The imported file.
	 * @param string                                      $format   One of the RecordFileReader format constants.
	 * @param list<array{line: int|null, reason: string}> $failures The import result's failures.
	 *
	 * @return array{csv: string, rows: int, unplaced: int} The CSV, its row count, and the failures that name no line.
	 *
	 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
	 */
	public function export(string $path, string $format, array $failures): array {
		$reasons = [];
		$unplaced = 0;
		foreach ($failures as $failure) {
			if (($failure['line'] ?? null) === null) {
				$unplaced++;
				continue;
			}

			$line = (int)$failure['line'];
			$reasons[$line] = (isset($reasons[$line]) === true) ? $reasons[$line] . '; ' . $failure['reason'] : $failure['reason'];
		}

		$columns = $this->reader->columns(path: $path, format: $format);
		$handle  = self::buffer();
		fputcsv($handle, [...$columns, self::reasonColumn(columns: $columns)], ',', '"', '');

		$rows = 0;
		if ($reasons !== []) {
			foreach ($this->reader->rows(path: $path, format: $format) as $row) {
				if (isset($reasons[$row['line']]) === false) {
					continue;
				}

				fputcsv($handle, [...self::cells(columns: $columns, values: $row['values']), $reasons[$row['line']]], ',', '"', '');
				$rows++;
			}
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return ['csv' => $csv, 'rows' => $rows, 'unplaced' => $unplaced];
	}//end export()

	/**
	 * The reason column's name for a file with these columns.
	 *
	 * @param list<string> $columns The file's columns.
	 *
	 * @return string
	 */
	private static function reasonColumn(array $columns): string {
		if (in_array(self::REASON_COLUMN, $columns, true) === true) {
			return self::REASON_COLUMN_FALLBACK;
		}

		return self::REASON_COLUMN;
	}//end reasonColumn()

	/**
	 * A row's cells in column order: a missing value is empty, a structured one JSON.
	 *
	 * @param list<string>         $columns The file's columns.
	 * @param array<string, mixed> $values  The row, by column.
	 *
	 * @return list<string>
	 */
	private static function cells(array $columns, array $values): array {
		$cells = [];
		foreach ($columns as $column) {
			$value = ($values[$column] ?? null);
			if (is_array($value) === true || is_object($value) === true) {
				$cells[] = (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				continue;
			}

			if (is_bool($value) === true) {
				$cells[] = ($value === true) ? 'true' : 'false';
				continue;
			}

			$cells[] = (string)($value ?? '');
		}

		return $cells;
	}//end cells()

	/**
	 * An in-memory stream to write the CSV into.
	 *
	 * @return resource
	 *
	 * @throws RuntimeException When no stream can be opened.
	 */
	private static function buffer() {
		$handle = fopen('php://temp', 'w+');
		if ($handle === false) {
			throw new RuntimeException('Cannot open a buffer for the failed-row export.');
		}

		return $handle;
	}//end buffer()
}//end class
