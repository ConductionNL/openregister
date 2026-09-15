<?php

/**
 * Reads a source file into numbered rows, and nothing else.
 *
 * Parsing only: no mapping, no validation, no decision, no write. The
 * decisive steps a previewed import takes are the ones the import path
 * already owns — {@see \OCA\OpenRegister\Service\MigrationPack\MappingEngine}
 * maps a row, {@see \OCA\OpenRegister\Service\ImportService::transformCsvRowToObject()}
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

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Turns an uploaded file into numbered, keyed rows.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
class SourceRowReader {

	/**
	 * Comma-separated values.
	 *
	 * @var string
	 */
	public const FORMAT_CSV = 'csv';

	/**
	 * A spreadsheet.
	 *
	 * @var string
	 */
	public const FORMAT_EXCEL = 'excel';

	/**
	 * A JSON list, or a `{"results": [...]}` envelope.
	 *
	 * @var string
	 */
	public const FORMAT_JSON = 'json';

	/**
	 * Every format a preview reads.
	 *
	 * @var array<int, string>
	 */
	public const FORMATS = [
		self::FORMAT_CSV,
		self::FORMAT_EXCEL,
		self::FORMAT_JSON,
	];

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
		$reader = new Xlsx();

		if ($format === self::FORMAT_CSV) {
			$reader = new Csv();
			$reader->setDelimiter(',');
			$reader->setEnclosure('"');
		}

		$reader->setReadDataOnly(true);
		$sheet = $reader->load($filePath)->getActiveSheet();

		$headers = $this->headerRow(sheet: $sheet);

		if ($headers === []) {
			throw new InvalidArgumentException('No column headers were found in the source file.');
		}

		$rows = [];
		$highestRow = $sheet->getHighestRow();

		for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
			$data = [];
			foreach ($headers as $column => $header) {
				$value = $sheet->getCell($column.$rowNumber)->getValue();
				if ($value === null || $value === '') {
					continue;
				}

				$data[$header] = $value;
			}

			if ($data === []) {
				continue;
			}

			$rows[] = [
				'row' => $rowNumber,
				'data' => $data,
			];
		}//end for

		return $rows;
	}//end readSpreadsheet()

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
			$cellIterator = $row->getCellIterator('A', $highestColumn);
			$cellIterator->setIterateOnlyExistingCells(false);

			foreach ($cellIterator as $cell) {
				$value = $cell->getValue();
				if ($value === null || trim((string)$value) === '') {
					continue;
				}

				$headers[$cell->getColumn()] = trim((string)$value);
			}
		}

		return $headers;
	}//end headerRow()

	/**
	 * Read a JSON file: a bare list, or a `{"results": [...]}` envelope.
	 *
	 * @param string $filePath Path to the file on disk.
	 *
	 * @return array<int, array{row: int, data: array<string, mixed>}> The rows.
	 */
	private function readJson(string $filePath): array {
		$contents = file_get_contents($filePath);

		if ($contents === false) {
			throw new InvalidArgumentException('The source file could not be read: '.$filePath);
		}

		$decoded = json_decode($contents, true);

		if (is_array($decoded) === false) {
			throw new InvalidArgumentException('The source file is not a JSON document.');
		}

		if (array_is_list($decoded) === false) {
			$decoded = ($decoded['results'] ?? null);
		}

		if (is_array($decoded) === false || array_is_list($decoded) === false) {
			throw new InvalidArgumentException(
				'The source file holds no list of objects. Pass a JSON array, or an object with a "results" array.'
			);
		}

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
}//end class
