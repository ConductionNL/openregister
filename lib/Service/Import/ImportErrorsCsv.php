<?php

/**
 * Writes the errors of an import summary as a downloadable CSV.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

/**
 * The error file of an import.
 *
 * @spec openspec/specs/data-import-export/spec.md#import-must-provide-detailed-error-reporting-with-downloadable-error-files
 */
class ImportErrorsCsv {

	/**
	 * The columns of the error file.
	 */
	private const HEADERS = ['sheet', 'row', 'field', 'error_message', 'original_value'];

	/**
	 * The CSV of every error in a summary (UTF-8 BOM prefixed); '' when there are none.
	 *
	 * @param array<string, mixed> $summary The sheet-based import summary
	 *
	 * @return string The CSV
	 *
	 * @spec openspec/specs/data-import-export/spec.md#import-must-provide-detailed-error-reporting-with-downloadable-error-files
	 */
	public function render(array $summary): string {
		$rows = [];
		foreach ($summary as $sheetTitle => $sheetData) {
			foreach ($this->sheetErrors(sheetData: $sheetData) as $error) {
				$rows[] = $this->row(error: $error, sheetTitle: $sheetTitle);
			}
		}

		if ($rows === []) {
			return '';
		}

		return $this->written(rows: $rows);
	}//end render()

	/**
	 * The errors of one summary entry; entries that are no sheet hold none.
	 *
	 * @param mixed $sheetData The summary entry
	 *
	 * @return array<mixed> The errors
	 */
	private function sheetErrors(mixed $sheetData): array {
		$errors = ($sheetData['errors'] ?? []);
		if (is_array($errors) === false) {
			return [];
		}

		return $errors;
	}//end sheetErrors()

	/**
	 * The CSV cells of one error.
	 *
	 * @param mixed      $error      The error: an envelope, or bare text
	 * @param int|string $sheetTitle The sheet the summary files it under
	 *
	 * @return list<string> The cells, in column order
	 */
	private function row(mixed $error, int|string $sheetTitle): array {
		if (is_array($error) === false) {
			$error = ['error' => (string)$error];
		}

		return [
			(string)($error['sheet'] ?? $sheetTitle),
			(string)($error['row'] ?? ''),
			(string)($error['field'] ?? ($error['type'] ?? '')),
			(string)($error['error'] ?? ''),
			$this->cellOf(value: ($error['object'] ?? ($error['original_value'] ?? ''))),
		];
	}//end row()

	/**
	 * Write the rows under the header.
	 *
	 * @param list<list<string>> $rows The rows
	 *
	 * @return string The CSV; '' when no stream could be opened
	 */
	private function written(array $rows): string {
		$stream = fopen('php://temp', 'r+');
		if ($stream === false) {
			return '';
		}

		// Excel needs the UTF-8 BOM to detect encoding.
		fwrite($stream, "\xEF\xBB\xBF");
		fputcsv($stream, self::HEADERS);
		foreach ($rows as $row) {
			fputcsv($stream, $row);
		}

		rewind($stream);
		$csv = stream_get_contents($stream);
		fclose($stream);

		return (string)$csv;
	}//end written()

	/**
	 * One CSV cell for the value an error carries: scalars as text, structures as JSON.
	 *
	 * @param mixed $value The value
	 *
	 * @return string The cell
	 */
	private function cellOf(mixed $value): string {
		if (is_scalar($value) === true) {
			return (string)$value;
		}

		if (is_array($value) === true || is_object($value) === true) {
			return (string)json_encode($value, (JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		}

		return '';
	}//end cellOf()
}//end class
