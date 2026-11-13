<?php

/**
 * RowRangeFilter: the read filter that loads one chunk of an XLSX sheet.
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

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Admits only the cells of rows `first` to `last`, so a sheet is loaded a chunk at a time.
 *
 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
 */
final class RowRangeFilter implements IReadFilter {

	/**
	 * The first row admitted.
	 *
	 * @var int
	 */
	public int $first = 1;

	/**
	 * The last row admitted.
	 *
	 * @var int
	 */
	public int $last = 1;

	/**
	 * Whether a cell is in the admitted rows.
	 *
	 * @param string $columnAddress The column.
	 * @param int    $row           The row.
	 * @param string $worksheetName The sheet.
	 *
	 * @return bool True when the row is in range.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface names all three.
	 *
	 * @spec openspec/changes/widget-record-import/specs/widget-record-import/spec.md
	 */
	public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool {
		return $row >= $this->first && $row <= $this->last;
	}//end readCell()
}//end class
