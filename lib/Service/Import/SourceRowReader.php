<?php

/**
 * Reads a source file into numbered rows, and nothing else.
 *
 * Parsing only: no mapping, no validation, no decision, no write. The
 * decisive steps a previewed import takes are the ones the import path
 * already owns — {@see \OCA\OpenRegister\Service\MigrationPack\MappingEngine}
 * maps a row, {@see \OCA\OpenRegister\Service\ImportService::transformCsvRow()}
 * turns it into an object, {@see \OCA\OpenRegister\Service\ObjectService::saveObjects()}
 * writes it. This class only gets the bytes into the shape all three already
 * take, so CSV, Excel and JSON reach them by one road.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use Closure;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Row;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Turns an uploaded file into numbered, keyed rows.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
class SourceRowReader implements SourceRowReaderInterface {

	/**
	 * Read a file into rows.
	 *
	 * @param string $filePath Path to the file on disk.
	 * @param string $format One of the FORMAT_* constants.
	 *
	 * @return array<int, array{row: int, data: array<string, mixed>}> The rows, in file order.
	 *
	 * @throws InvalidArgumentException When the format is unknown or the file cannot be read as that format.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function read(string $filePath, string $format): array {
		if (in_array($format, self::FORMATS, true) === false) {
			throw new InvalidArgumentException(
				'Unknown source format "'.$format.'". Use one of: '.implode(', ', self::FORMATS).'.'
			);
		}

		if ($format === self::FORMAT_JSON) {
			return $this->readJson(filePath: $filePath);
		}

		return $this->readSpreadsheet(filePath: $filePath, format: $format);
	}//end read()

	/**
	 * The sha256 of a file, which is the identity a commit is checked against.
	 *
	 * @param string $filePath Path to the file on disk.
	 *
	 * @return string The hash.
	 *
	 * @throws InvalidArgumentException When the file cannot be hashed.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function hash(string $filePath): string {
		$hash = hash_file('sha256', $filePath);

		if ($hash === false) {
			throw new InvalidArgumentException('The source file could not be read: '.$filePath);
		}

		return $hash;
	}//end hash()

	/**
	 * The prefix every staged upload carries, so a staged copy is
	 * recognisable and a caller's own file is never deleted by mistake.
	 *
	 * @var string
	 */
	public const STAGE_PREFIX = 'openregister-import-';

	/**
	 * Copy an uploaded file somewhere it survives the request.
	 *
	 * PHP removes an upload's temp file when the request ends, so a preview
	 * that runs on cron rather than in the request has nothing left to read.
	 * The copy is host-local, which is the same assumption the rest of the
	 * import path makes about an upload, and it is removed as soon as the
	 * preview has decided every row.
	 *
	 * @param string $filePath The uploaded file's temp path.
	 * @param string $token A token making the staged name unique, usually the preview uuid.
	 *
	 * @return string The staged path.
	 *
	 * @throws InvalidArgumentException When the copy cannot be made.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function stage(string $filePath, string $token): string {
		$staged = rtrim(sys_get_temp_dir(), '/').'/'.self::STAGE_PREFIX.$token;

		if (copy($filePath, $staged) === false) {
			throw new InvalidArgumentException('The source file could not be staged for a background preview.');
		}

		return $staged;
	}//end stage()

	/**
	 * Whether a path is a staged copy this class made.
	 *
	 * @param string|null $filePath The path to test.
	 *
	 * @return bool True when the path is a staged copy.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function isStaged(?string $filePath): bool {
		if ($filePath === null || $filePath === '') {
			return false;
		}

		return str_starts_with(basename($filePath), self::STAGE_PREFIX);
	}//end isStaged()

	/**
	 * Guess a format from a file name, so a caller need not always say.
	 *
	 * @param string $fileName The uploaded file's name.
	 *
	 * @return string|null The format, or null when the name says nothing.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function formatFromName(string $fileName): ?string {
		$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

		return match ($extension) {
			'csv' => self::FORMAT_CSV,
			'xlsx', 'xls' => self::FORMAT_EXCEL,
			'json' => self::FORMAT_JSON,
			default => null,
		};
	}//end formatFromName()

	/**
	 * Read a CSV or Excel file.
	 *
	 * @param string $filePath Path to the file on disk.
	 * @param string $format The spreadsheet format.
	 *
	 * @return array<int, array{row: int, data: array<string, mixed>}> The rows.
	 */
	private function readSpreadsheet(string $filePath, string $format): array {
		$sheet = self::activeSheet(filePath: $filePath, format: $format);

		$headers = $this->headerRow(sheet: $sheet);

		if ($headers === []) {
			throw new InvalidArgumentException('No column headers were found in the source file.');
		}

		return self::dataRows(sheet: $sheet, headers: $headers);
	}//end readSpreadsheet()

	/**
	 * The active sheet of a spreadsheet file, read for its data only.
	 *
	 * @param string $filePath The file.
	 * @param string $format   Its format.
	 *
	 * @return Worksheet The sheet.
	 */
	private static function activeSheet(string $filePath, string $format): Worksheet {
		$reader = self::spreadsheetReader(format: $format);
		$reader->setReadDataOnly(true);

		return $reader->load($filePath)->getActiveSheet();
	}//end activeSheet()

	/**
	 * The non-empty rows below the header, each with its row number.
	 *
	 * @param Worksheet             $sheet   The sheet.
	 * @param array<string, string> $headers The headers by column letter.
	 *
	 * @return array<int, array{row: int, data: array<string, mixed>}> The rows.
	 */
	private static function dataRows(Worksheet $sheet, array $headers): array {
		$rows = [];
		$highestRow = $sheet->getHighestRow();

		for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
			$data = self::rowData(headers: $headers, cell: self::cellReader(sheet: $sheet, rowNumber: $rowNumber));
			if ($data === []) {
				continue;
			}

			$rows[] = [
				'row' => $rowNumber,
				'data' => $data,
			];
		}

		return $rows;
	}//end dataRows()

	/**
	 * Reads a cell of one row by its column letter.
	 *
	 * @param Worksheet $sheet     The sheet.
	 * @param int       $rowNumber The row.
	 *
	 * @return Closure The reader.
	 */
	private static function cellReader(Worksheet $sheet, int $rowNumber): Closure {
		return static fn (string $column): mixed => $sheet->getCell($column.$rowNumber)->getValue();
	}//end cellReader()

	/**
	 * The reader for a spreadsheet format: CSV, or else Excel.
	 *
	 * @param string $format The spreadsheet format.
	 *
	 * @return Csv|Xlsx The reader.
	 */
	private static function spreadsheetReader(string $format): Csv|Xlsx {
		if ($format !== self::FORMAT_CSV) {
			return new Xlsx();
		}

		$reader = new Csv();
		$reader->setDelimiter(',');
		$reader->setEnclosure('"');

		return $reader;
	}//end spreadsheetReader()

	/**
	 * One sheet row as header => value, leaving out blank cells.
	 *
	 * @param array<string, string> $headers The headers, column letter => name.
	 * @param Closure               $cell    The value of this row's cell in a column letter.
	 *
	 * @return array<string, mixed> The row's data, empty when every cell is blank.
	 */
	private static function rowData(array $headers, Closure $cell): array {
		$data = [];
		foreach ($headers as $column => $header) {
			$value = $cell((string)$column);
			if ($value === null || $value === '') {
				continue;
			}

			$data[$header] = $value;
		}

		return $data;
	}//end rowData()

	/**
	 * The header row as column letter => header name.
	 *
	 * @param Worksheet $sheet The sheet.
	 *
	 * @return array<string, string> The headers.
	 */
	private function headerRow(Worksheet $sheet): array {
		$headers = [];
		$highestColumn = $sheet->getHighestColumn();
		$columnIterator = $sheet->getRowIterator(1, 1);

		foreach ($columnIterator as $row) {
			$headers = array_merge($headers, self::rowHeaders(row: $row, highestColumn: $highestColumn));
		}

		return $headers;
	}//end headerRow()

	/**
	 * The non-blank, trimmed cell values of one row, keyed by column letter.
	 *
	 * @param Row    $row           The row.
	 * @param string $highestColumn The last column to read.
	 *
	 * @return array<string, string> The headers.
	 */
	private static function rowHeaders(Row $row, string $highestColumn): array {
		$cellIterator = $row->getCellIterator('A', $highestColumn);
		$cellIterator->setIterateOnlyExistingCells(false);

		$headers = [];
		foreach ($cellIterator as $cell) {
			$value = $cell->getValue();
			if ($value !== null && trim((string)$value) !== '') {
				$headers[$cell->getColumn()] = trim((string)$value);
			}
		}

		return $headers;
	}//end rowHeaders()

	/**
	 * Read a JSON file: a bare list, or a `{"results": [...]}` envelope.
	 *
	 * @param string $filePath Path to the file on disk.
	 *
	 * @return array<int, array{row: int, data: array<string, mixed>}> The rows.
	 */
	private function readJson(string $filePath): array {
		$decoded = self::jsonList(decoded: self::decodeJson(filePath: $filePath));

		$rows = [];
		foreach ($decoded as $index => $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$rows[] = [
				'row' => ($index + 1),
				'data' => $entry,
			];
		}

		return $rows;
	}//end readJson()

	/**
	 * Decode a JSON file into an array.
	 *
	 * @param string $filePath Path to the file on disk.
	 *
	 * @return array<mixed> The decoded document.
	 *
	 * @throws InvalidArgumentException When the file cannot be read or holds no JSON array or object.
	 */
	private static function decodeJson(string $filePath): array {
		$contents = file_get_contents($filePath);

		if ($contents === false) {
			throw new InvalidArgumentException('The source file could not be read: '.$filePath);
		}

		$decoded = json_decode($contents, true);

		if (is_array($decoded) === false) {
			throw new InvalidArgumentException('The source file is not a JSON document.');
		}

		return $decoded;
	}//end decodeJson()

	/**
	 * The list a decoded document holds: itself, or its `results` envelope.
	 *
	 * @param array<mixed> $decoded The decoded document.
	 *
	 * @return array<int, mixed> The list.
	 *
	 * @throws InvalidArgumentException When the document holds no list.
	 */
	private static function jsonList(array $decoded): array {
		if (array_is_list($decoded) === false) {
			$decoded = ($decoded['results'] ?? null);
		}

		if (is_array($decoded) === false || array_is_list($decoded) === false) {
			throw new InvalidArgumentException(
				'The source file holds no list of objects. Pass a JSON array, or an object with a "results" array.'
			);
		}

		return $decoded;
	}//end jsonList()
}//end class
