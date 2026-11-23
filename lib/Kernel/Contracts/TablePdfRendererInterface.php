<?php

/**
 * TablePdfRendererInterface: rows a caller fetched itself, rendered as a PDF.
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
 * Renders a titled table through the PDF export format.
 *
 * The port another component uses instead of the Configuration ExportService and its
 * RowsTable value. Bound to Service\ExportService, so the same office server, house
 * style, row cap and cell escaping apply.
 *
 * @spec openspec/changes/notes-leaf-rich-text-lock-export/specs/object-interactions/spec.md#requirement-an-objects-notes-export-as-a-journal-sheet
 */
interface TablePdfRendererInterface {
	/**
	 * Whether an office server is configured to convert to PDF.
	 *
	 * @return bool True when PDF rendering can be attempted.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function isPdfAvailable(): bool;

	/**
	 * Render a table as a PDF.
	 *
	 * @param string                           $title      The heading above the table.
	 * @param array<int|string, string>        $columns    Column keys to labels, or a list of keys that are their own labels.
	 * @param array<int, array<string, mixed>> $rows       The rows, each keyed by column key.
	 * @param bool                             $wholeCells True to print every cell whole instead of cut to the object export's length.
	 *
	 * @return string The PDF bytes.
	 *
	 * @throws \OCA\OpenRegister\Exception\ExportTooLargeException When there are more rows than the PDF export allows.
	 * @throws \OCA\OpenRegister\Exception\OfficeServerRequiredException When no office server converts to PDF.
	 *
	 * @spec openspec/changes/notes-leaf-rich-text-lock-export/specs/object-interactions/spec.md#requirement-an-objects-notes-export-as-a-journal-sheet
	 */
	public function renderRowsToPdf(string $title, array $columns, array $rows, bool $wholeCells=false): string;
}//end interface
