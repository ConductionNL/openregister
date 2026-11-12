<?php

/**
 * OpenRegister RowsTable
 *
 * A titled table of rows a caller already read, for rendering as a PDF section.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

/**
 * A titled table of rows a caller already read, for rendering as a PDF section.
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */
final class RowsTable {
	/**
	 * Hold one table.
	 *
	 * @param string                           $title   The heading above the table.
	 * @param array<int|string, string>        $columns Column keys to labels, or a list of keys that are their own labels.
	 * @param array<int, array<string, mixed>> $rows    The rows, each keyed by column key.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function __construct(
		public readonly string $title,
		public readonly array $columns,
		public readonly array $rows,
	) {
	}//end __construct()

	/**
	 * The column labels keyed by column key; a list of keys labels itself.
	 *
	 * @return array<int|string, string>
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function labels(): array {
		if (array_is_list($this->columns) === true) {
			return array_combine(array_map('strval', $this->columns), array_map('strval', $this->columns));
		}

		return $this->columns;
	}//end labels()
}//end class
