<?php

/**
 * SpreadsheetCell — writes exported values so a spreadsheet never reads them as formulas.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One rule for every spreadsheet and CSV writer.
 *
 * Workbook cells holding text are written with the explicit string type, so
 * no stored value becomes a formula cell. CSV fields that start with a
 * character a spreadsheet treats as a formula (`=`, `+`, `-`, `@`, tab or
 * carriage return) get a leading apostrophe; plain numbers pass unchanged.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class SpreadsheetCell {

	private const FORMULA_LEADS = ['=', '+', '-', '@', "\t", "\r"];

	/**
	 * The CSV-safe form of one value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The value, with a leading apostrophe when it would start a formula.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public static function csvValue(mixed $value): mixed {
		if (is_string($value) === false || $value === '' || is_numeric($value) === true) {
			return $value;
		}

		if (in_array($value[0], self::FORMULA_LEADS, true) === true) {
			return "'" . $value;
		}

		return $value;
	}//end csvValue()

	/**
	 * The CSV-safe form of one row.
	 *
	 * @param array<array-key, mixed> $row The row.
	 *
	 * @return array<array-key, mixed> The row with every value made CSV-safe.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public static function csvRow(array $row): array {
		return array_map(static fn (mixed $value): mixed => self::csvValue(value: $value), $row);
	}//end csvRow()

	/**
	 * Set one cell; text that is not a number is stored as a string, never as a formula.
	 *
	 * @param Worksheet $sheet      The sheet.
	 * @param string    $coordinate The cell, e.g. `B7`.
	 * @param mixed     $value      The value.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public static function set(Worksheet $sheet, string $coordinate, mixed $value): void {
		if (is_string($value) === true && is_numeric($value) === false) {
			$sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
			return;
		}

		$sheet->setCellValue($coordinate, $value);
	}//end set()

	/**
	 * Make every text cell of a workbook CSV-safe before it is written as CSV.
	 *
	 * @param Spreadsheet $spreadsheet The workbook.
	 *
	 * @return Spreadsheet The same workbook.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public static function forCsv(Spreadsheet $spreadsheet): Spreadsheet {
		foreach ($spreadsheet->getAllSheets() as $sheet) {
			foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
				$cell  = $sheet->getCell($coordinate);
				$value = $cell->getValue();
				if (is_string($value) === true) {
					$cell->setValueExplicit(self::csvValue(value: $value), DataType::TYPE_STRING);
				}
			}
		}

		return $spreadsheet;
	}//end forCsv()
}//end class
