<?php

/**
 * CsvCell: the CSV field rule every export follows, so a spreadsheet never reads a field as a formula.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * A CSV field that starts with a character a spreadsheet treats as a formula (`=`, `+`, `-`,
 * `@`, tab or carriage return) gets a leading apostrophe; numbers and other values pass unchanged.
 *
 * Kept in the Kernel so a writer in any component can apply it; Service\Export\SpreadsheetCell
 * delegates its CSV methods here.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class CsvCell {

	/**
	 * The characters a spreadsheet reads as the start of a formula.
	 *
	 * @var list<string>
	 */
	public const FORMULA_LEADS = ['=', '+', '-', '@', "\t", "\r"];

	/**
	 * The CSV-safe form of one value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed The value, with a leading apostrophe when it would start a formula.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public static function value(mixed $value): mixed {
		if (is_string($value) === false || $value === '' || is_numeric($value) === true) {
			return $value;
		}

		if (in_array($value[0], self::FORMULA_LEADS, true) === true) {
			return "'" . $value;
		}

		return $value;
	}//end value()

	/**
	 * The CSV-safe form of one row.
	 *
	 * @param array<array-key, mixed> $row The row.
	 *
	 * @return array<array-key, mixed> The row with every value made CSV-safe.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public static function row(array $row): array {
		return array_map(static fn (mixed $value): mixed => self::value(value: $value), $row);
	}//end row()
}//end class
