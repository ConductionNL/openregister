<?php
/**
 * HtmlPdfConverterInterface: an HTML report document converted to PDF by the office server.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Search;

/**
 * Converts an HTML document to a PDF through the office server.
 *
 * The dashboard report writer (Search) asks; the document export (Configuration),
 * which writes the HTML as an ODT and has the office server convert it, answers
 * and is bound to this contract.
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */
interface HtmlPdfConverterInterface {

	/**
	 * Whether an office server can convert documents right now.
	 *
	 * @return bool True when htmlToPdf() can be called.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function isAvailable(): bool;

	/**
	 * The HTML document's text as an A4 PDF.
	 *
	 * @param string $html      The document.
	 * @param bool   $landscape Landscape pages instead of portrait.
	 *
	 * @return string The PDF bytes.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function htmlToPdf(string $html, bool $landscape): string;
}//end interface
