<?php

/**
 * RecordFileReader: the rows of a tabular file of records, read lazily.
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

use Generator;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

/**
 * Reads CSV, TSV, XLSX and JSON-lines files as rows keyed by column name.
 *
 * Every format is read lazily: a CSV, TSV or JSON-lines file one line at a
 * time, an XLSX sheet in chunks of rows, so the streaming write path is not
 * defeated by a parser that buffers the whole file. Row numbers are the
 * file's own (a header line counts), so a reason can point at the line the
 * user sees.
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */
class RecordFileReader {

	public const CSV = 'csv';

	public const TSV = 'tsv';

	public const XLSX = 'xlsx';

	public const JSONL = 'jsonl';

	/**
	 * The XLSX rows read per pass.
	 *
	 * @var int
	 */
	private const XLSX_CHUNK = 500;

	/**
	 * The format a file name's extension names.
	 *
	 * @param string $fileName The file name.
	 *
	 * @return string One of the format constants.
	 *
	 * @throws InvalidArgumentException For an extension that is not a record file.
	 *
	 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
	 */
	public static function formatOf(string $fileName): string {
		$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
		$formats = ['csv' => self::CSV, 'tsv' => self::TSV, 'tab' => self::TSV, 'xlsx' => self::XLSX, 'jsonl' => self::JSONL, 'ndjson' => self::JSONL];
		$format = ($formats[$extension] ?? null);
		if ($format === null) {
			throw new InvalidArgumentException(sprintf('"%s" is not a record file: use CSV, TSV, XLSX or JSON lines.', $fileName));
		}

		return $format;
	}//end formatOf()

	/**
	 * The column names of a file: its header row, or the keys a JSON-lines file uses, in first-seen order.
	 *
	 * @param string $path   The file.
	 * @param string $format One of the format constants.
	 *
	 * @return list<string> The columns.
	 *
	 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
	 */
	public function columns(string $path, string $format): array {
		if ($format === self::JSONL) {
			$columns = [];
			foreach ($this->rows(path: $path, format: $format) as $row) {
				foreach (array_keys($row['values']) as $column) {
					$columns[$column] = true;
				}
			}

			return array_map('strval', array_keys($columns));
		}

		foreach ($this->tabularLines(path: $path, format: $format) as $line) {
			return self::header(cells: $line['cells']);
		}

		return [];
	}//end columns()

	/**
	 * The rows of a file, one at a time.
	 *
	 * @param string $path   The file.
	 * @param string $format One of the format constants.
	 *
	 * @return Generator<int, array{line: int, values: array<string, mixed>}> Each row with its line number.
	 *
	 * @throws InvalidArgumentException For an unknown format, or a JSON line that is not an object.
	 *
	 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
	 */
	public function rows(string $path, string $format): Generator {
		if ($format === self::JSONL) {
			yield from $this->jsonLines(path: $path);
			return;
		}

		$header = null;
		foreach ($this->tabularLines(path: $path, format: $format) as $line) {
			if ($header === null) {
				$header = self::header(cells: $line['cells']);
				continue;
			}

			if (self::isBlank(cells: $line['cells']) === true) {
				continue;
			}

			$values = [];
			foreach ($header as $index => $column) {
				$values[$column] = $line['cells'][$index] ?? null;
			}

			yield ['line' => $line['line'], 'values' => $values];
		}//end foreach
	}//end rows()

	/**
	 * The lines of a CSV, TSV or XLSX file as lists of cells.
	 *
	 * @param string $path   The file.
	 * @param string $format The format.
	 *
	 * @return Generator<int, array{line: int, cells: list<mixed>}>
	 *
	 * @throws InvalidArgumentException For an unknown format.
	 */
	private function tabularLines(string $path, string $format): Generator {
		if ($format === self::TSV) {
			yield from $this->delimitedLines(path: $path, delimiter: "\t");
			return;
		}

		if ($format === self::CSV) {
			yield from $this->delimitedLines(path: $path, delimiter: self::sniffDelimiter(path: $path));
			return;
		}

		if ($format === self::XLSX) {
			yield from $this->sheetLines(path: $path);
			return;
		}

		throw new InvalidArgumentException(sprintf('Unknown record file format "%s".', $format));
	}//end tabularLines()

	/**
	 * The lines of a delimited text file.
	 *
	 * @param string $path      The file.
	 * @param string $delimiter The delimiter.
	 *
	 * @return Generator<int, array{line: int, cells: list<mixed>}>
	 */
	private function delimitedLines(string $path, string $delimiter): Generator {
		$handle = self::open(path: $path);
		try {
			$number = 0;
			while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
				$number++;
				if ($number === 1 && isset($cells[0]) === true && is_string($cells[0]) === true) {
					// A UTF-8 byte order mark is not part of the first column's name.
					$cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]);
				}

				yield ['line' => $number, 'cells' => $cells];
			}
		} finally {
			fclose($handle);
		}
	}//end delimitedLines()

	/**
	 * The lines of the first sheet of an XLSX file, read in chunks of rows.
	 *
	 * @param string $path The file.
	 *
	 * @return Generator<int, array{line: int, cells: list<mixed>}>
	 */
	private function sheetLines(string $path): Generator {
		$reader = new Xlsx();
		$reader->setReadDataOnly(true);
		$info = $reader->listWorksheetInfo($path);
		$lastRow = (int)($info[0]['totalRows'] ?? 0);
		$filter = new RowRangeFilter();
		$reader->setReadFilter($filter);

		for ($first = 1; $first <= $lastRow; $first += self::XLSX_CHUNK) {
			$filter->first = $first;
			$filter->last = min($lastRow, $first + self::XLSX_CHUNK - 1);
			$sheet = $reader->load($path)->getSheet(0);
			$width = $sheet->getHighestDataColumn();
			for ($row = $filter->first; $row <= $filter->last; $row++) {
				$cells = $sheet->rangeToArray('A' . $row . ':' . $width . $row, null, true, false)[0] ?? [];
				yield ['line' => $row, 'cells' => array_values($cells)];
			}

			$sheet->getParent()?->disconnectWorksheets();
		}
	}//end sheetLines()

	/**
	 * The rows of a JSON-lines file: one object per non-blank line.
	 *
	 * @param string $path The file.
	 *
	 * @return Generator<int, array{line: int, values: array<string, mixed>}>
	 *
	 * @throws InvalidArgumentException For a line that is not a JSON object.
	 */
	private function jsonLines(string $path): Generator {
		$handle = self::open(path: $path);
		try {
			$number = 0;
			while (($text = fgets($handle)) !== false) {
				$number++;
				if (trim($text) === '') {
					continue;
				}

				$values = json_decode($text, true);
				if (is_array($values) === false || array_is_list($values) === true && $values !== []) {
					throw new InvalidArgumentException(sprintf('Line %d is not a JSON object.', $number));
				}

				yield ['line' => $number, 'values' => $values];
			}
		} finally {
			fclose($handle);
		}
	}//end jsonLines()

	/**
	 * Comma or semicolon, whichever the header line holds more of; spreadsheet exports in Dutch locales use semicolons.
	 *
	 * @param string $path The file.
	 *
	 * @return string The delimiter.
	 */
	private static function sniffDelimiter(string $path): string {
		$handle = self::open(path: $path);
		$first = (string)fgets($handle);
		fclose($handle);

		if (substr_count($first, ';') > substr_count($first, ',')) {
			return ';';
		}

		return ',';
	}//end sniffDelimiter()

	/**
	 * Open a file for reading.
	 *
	 * @param string $path The file.
	 *
	 * @return resource The handle.
	 *
	 * @throws InvalidArgumentException When the file cannot be read.
	 */
	private static function open(string $path) {
		$handle = false;
		if (is_file($path) === true && is_readable($path) === true) {
			$handle = fopen($path, 'rb');
		}

		if ($handle === false) {
			throw new InvalidArgumentException('The record file cannot be read.');
		}

		return $handle;
	}//end open()

	/**
	 * Column names from header cells, trimmed; an empty one is named by its position.
	 *
	 * @param list<mixed> $cells The header cells.
	 *
	 * @return list<string> The column names.
	 */
	private static function header(array $cells): array {
		$names = [];
		foreach (array_values($cells) as $index => $cell) {
			$name = trim((string)$cell);
			if ($name === '') {
				$name = 'column ' . ($index + 1);
			}

			$names[] = $name;
		}

		return $names;
	}//end header()

	/**
	 * Whether every cell of a line is empty.
	 *
	 * @param list<mixed> $cells The cells.
	 *
	 * @return bool True for a blank line.
	 */
	private static function isBlank(array $cells): bool {
		foreach ($cells as $cell) {
			if ($cell !== null && trim((string)$cell) !== '') {
				return false;
			}
		}

		return true;
	}//end isBlank()
}//end class
