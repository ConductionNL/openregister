<?php

/**
 * OpenRegister Spreadsheet Extractor
 *
 * Dedicated handler for extracting plain text from spreadsheet files
 * (xlsx/ods/csv via PhpSpreadsheet). Split out of the TextExtractionService
 * god-class (extract-god-class-services) so each format lives in its own
 * single-responsibility class, mirroring the existing EmlParser handler.
 * Behaviour is identical to the former TextExtractionService::extractSpreadsheet().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\TextExtraction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Files\Service\TextExtraction;

use Exception;
use OCP\Files\File;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Psr\Log\LoggerInterface;

/**
 * Extracts text content from spreadsheet files.
 */
class SpreadsheetExtractor {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Extract plain text from a spreadsheet file.
	 *
	 * @param File $file The spreadsheet file to extract.
	 *
	 * @return string|null The extracted text, or null when empty.
	 *
	 * @throws Exception When PhpSpreadsheet is unavailable or extraction fails.
	 */
	public function extract(File $file): ?string {
		$this->assertLibraryAvailable(file: $file);

		try {
			$this->logger->debug(
				message: '[SpreadsheetExtractor] Extracting spreadsheet',
				context: $this->context(file: $file, extra: ['name' => $file->getName()])
			);

			return $this->nonEmpty(file: $file, text: $this->readText(content: $file->getContent()));
		} catch (Exception $e) {
			throw $this->failure(file: $file, error: $e);
		}
	}//end extract()

	/**
	 * Log a failed extraction as an error and wrap the cause.
	 *
	 * @param File      $file  The file, for the log.
	 * @param Exception $error The cause.
	 *
	 * @return Exception The exception to throw.
	 */
	private function failure(File $file, Exception $error): Exception {
		$this->logger->error(
			message: '[SpreadsheetExtractor] Spreadsheet extraction failed',
			context: $this->context(file: $file, extra: ['error' => $error->getMessage()])
		);

		return new Exception('Spreadsheet extraction failed: ' . $error->getMessage());
	}//end failure()

	/**
	 * Refuse when PhpSpreadsheet is not installed (it should be, via composer.json).
	 *
	 * @param File $file The file being extracted, for the log.
	 *
	 * @return void
	 *
	 * @throws Exception When PhpSpreadsheet is unavailable.
	 */
	private function assertLibraryAvailable(File $file): void {
		if (class_exists('PhpOffice\PhpSpreadsheet\IOFactory') === true) {
			return;
		}

		$this->logger->warning(
			message: '[SpreadsheetExtractor] PhpSpreadsheet library not available',
			context: $this->context(file: $file)
		);
		$msg = 'PhpSpreadsheet library (phpoffice/phpspreadsheet) is not installed. ';
		$msg .= 'Run: composer require phpoffice/phpspreadsheet';
		throw new Exception($msg);
	}//end assertLibraryAvailable()

	/**
	 * The text of every sheet in spreadsheet bytes, or '' when no sheet holds a value.
	 *
	 * Each sheet is a header line, a tab-separated line per non-empty row, and a blank line.
	 * PhpSpreadsheet loads from a path, so the bytes go through a temporary file.
	 *
	 * @param string $content The file bytes.
	 *
	 * @return string
	 */
	private function readText(string $content): string {
		$tempFile = tmpfile();
		$tempPath = stream_get_meta_data($tempFile)['uri'];
		fwrite($tempFile, $content);
		$spreadsheet = SpreadsheetIOFactory::load($tempPath);

		$text = '';
		$rows = '';
		foreach ($spreadsheet->getAllSheets() as $sheet) {
			$sheetRows = $this->sheetRows(sheet: $sheet);
			$rows .= $sheetRows;
			$text .= 'Sheet: ' . $sheet->getTitle() . "\n" . $sheetRows . "\n";
		}

		fclose($tempFile);
		if ($rows === '') {
			return '';
		}

		return $text;
	}//end readText()

	/**
	 * One sheet's non-empty rows, each as a tab-separated line.
	 *
	 * @param Worksheet $sheet The sheet.
	 *
	 * @return string
	 */
	private function sheetRows(Worksheet $sheet): string {
		$text = '';
		$highestRow = $sheet->getHighestRow();
		$columns = $this->columnsUpTo(highestColumn: $sheet->getHighestColumn());
		for ($row = 1; $row <= $highestRow; $row++) {
			$coordinates = array_map(static fn (string $column): string => $column . $row, $columns);
			$text .= $this->rowLine(sheet: $sheet, coordinates: $coordinates);
		}

		return $text;
	}//end sheetRows()

	/**
	 * The column letters from A up to and including the highest column.
	 *
	 * @param string $highestColumn The highest column, e.g. `AB`.
	 *
	 * @return list<string>
	 */
	private function columnsUpTo(string $highestColumn): array {
		$columns = [];
		// @psalm-suppress StringIncrement - Excel column increment is intentional
		for ($col = 'A'; $col !== $highestColumn; $col++) {
			$columns[] = $col;
		}

		$columns[] = $highestColumn;
		return $columns;
	}//end columnsUpTo()

	/**
	 * The non-empty values of one row joined by tabs, or '' when the row is empty.
	 *
	 * @param Worksheet    $sheet       The sheet.
	 * @param list<string> $coordinates The row's cell coordinates, in column order.
	 *
	 * @return string
	 */
	private function rowLine(Worksheet $sheet, array $coordinates): string {
		$values = [];
		foreach ($coordinates as $coordinate) {
			$value = $sheet->getCell($coordinate)->getValue();
			if ($value !== null && $value !== '') {
				$values[] = $value;
			}
		}

		if ($values === []) {
			return '';
		}

		return implode("\t", $values) . "\n";
	}//end rowLine()

	/**
	 * Return the text, or null (with a warning) when it is only whitespace.
	 *
	 * @param File   $file The file, for the log.
	 * @param string $text The extracted text.
	 *
	 * @return string|null
	 */
	private function nonEmpty(File $file, string $text): ?string {
		if (trim($text) === '') {
			$this->logger->warning(
				message: '[SpreadsheetExtractor] Spreadsheet extraction returned empty text',
				context: $this->context(file: $file)
			);
			return null;
		}

		$this->logger->debug(
			message: '[SpreadsheetExtractor] Spreadsheet extracted successfully',
			context: $this->context(file: $file, extra: ['length' => strlen($text)])
		);
		return $text;
	}//end nonEmpty()

	/**
	 * The log context for a file: source location, file id, then any extra keys.
	 *
	 * @param File                 $file  The file.
	 * @param array<string, mixed> $extra Extra context keys.
	 *
	 * @return array<string, mixed>
	 */
	private function context(File $file, array $extra = []): array {
		return array_merge(['file' => __FILE__, 'line' => __LINE__, 'fileId' => $file->getId()], $extra);
	}//end context()
}//end class
