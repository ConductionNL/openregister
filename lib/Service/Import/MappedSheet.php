<?php

/**
 * OpenRegister MappedSheet
 *
 * A worksheet with the column mapping its header row produced.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * A worksheet and its header mapping, read row by row (ADR-011 Rule 2).
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class MappedSheet {
	/**
	 * Build the mapped sheet.
	 *
	 * @param Worksheet             $sheet         The worksheet.
	 * @param array<string, string> $columnMapping Column letter to column name, from the header row.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly Worksheet $sheet,
		public readonly array $columnMapping,
	) {
	}//end __construct()
}//end class
