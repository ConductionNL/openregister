<?php

/**
 * OpenRegister PdfTableRenderer
 *
 * Turns export table sections into one A4-landscape PDF.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export\Pdf
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

namespace OCA\OpenRegister\Service\Export\Pdf;

/**
 * Renders the table sections built by ExportService and RowsPdfSection.
 *
 * Each section is an escaped HTML fragment of a fixed shape: a `div.pdf-section`
 * holding an `h1`, a `p.meta` and a `table` with one header row.
 */
interface PdfTableRenderer {

	/**
	 * Render the sections into one PDF, each section starting on a new page.
	 *
	 * @param string[] $sections Escaped HTML fragments, one per section.
	 *
	 * @return string Raw PDF bytes.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function render(array $sections): string;
}//end interface
