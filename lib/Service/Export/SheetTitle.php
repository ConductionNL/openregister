<?php

/**
 * SheetTitle — names a worksheet within what a workbook accepts.
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

use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * A worksheet title a workbook accepts, derived from a schema slug.
 *
 * A workbook refuses a sheet title longer than 31 characters, one holding any
 * of `: \ / ? * [ ]`, one that starts or ends with an apostrophe, and a title
 * another sheet of the same workbook already has. A schema slug may be any of
 * those, and a refused title aborted the whole export (CSV included, which is
 * written through a workbook) with a server error. The slug is kept as far as
 * it fits; a title nothing is left of falls back to `data`, and a title taken
 * by an earlier sheet gets a ` (2)`, ` (3)`, ... suffix inside the limit.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md#requirement-the-system-must-support-structured-export-to-csv-excel-xlsx-json-xml-and-ods-formats
 */
final class SheetTitle {
	/**
	 * The longest title a workbook accepts.
	 */
	public const MAX_LENGTH = 31;

	/**
	 * The title of a sheet whose slug leaves nothing usable.
	 */
	public const FALLBACK = 'data';

	/**
	 * A title for a new sheet of the workbook, derived from the slug.
	 *
	 * @param Spreadsheet $spreadsheet The workbook the sheet joins (its existing titles are avoided).
	 * @param string|null $slug        The schema slug, or null for a sheet without a schema.
	 *
	 * @return string A title the workbook accepts and no other sheet of it carries.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-the-system-must-support-structured-export-to-csv-excel-xlsx-json-xml-and-ods-formats
	 */
	public static function for(Spreadsheet $spreadsheet, ?string $slug): string {
		$base = self::clean(slug: $slug);

		$title  = $base;
		$number = 1;
		while ($spreadsheet->sheetNameExists($title) === true) {
			$number++;
			$suffix = ' ('.$number.')';
			$title  = rtrim(mb_substr($base, 0, self::MAX_LENGTH - mb_strlen($suffix)), "' ").$suffix;
		}

		return $title;
	}//end for()

	/**
	 * The slug without the characters a title may not hold, cut to the limit.
	 *
	 * @param string|null $slug The schema slug.
	 *
	 * @return string The cleaned title, or the fallback when nothing is left.
	 */
	private static function clean(?string $slug): string {
		$title = str_replace([':', '\\', '/', '?', '*', '[', ']'], '-', (string)$slug);
		$title = trim(mb_substr(trim($title, "' "), 0, self::MAX_LENGTH), "' ");

		if ($title === '') {
			return self::FALLBACK;
		}

		return $title;
	}//end clean()
}//end class
