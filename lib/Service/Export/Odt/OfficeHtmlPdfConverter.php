<?php

/**
 * OpenRegister OfficeHtmlPdfConverter
 *
 * Converts HTML to PDF by writing it as an ODT and having the office server convert that.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export\Odt
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export\Odt;

use OCA\OpenRegister\Files\Service\File\Pdf\Fallback\NcOfficeConverterInterface;
use OCA\OpenRegister\Kernel\Contracts\Search\HtmlPdfConverterInterface;

/**
 * HTML to PDF through an ODT the office server converts.
 *
 * Only the text of the HTML reaches the ODT; its CSS, links and any other
 * markup are not copied.
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */
class OfficeHtmlPdfConverter implements HtmlPdfConverterInterface {

	/**
	 * Constructor.
	 *
	 * @param NcOfficeConverterInterface $officeConverter Office server conversion to PDF.
	 * @param OdtWriterInterface         $odtWriter       Writes the HTML as an ODT.
	 */
	public function __construct(
		private readonly NcOfficeConverterInterface $officeConverter,
		private readonly OdtWriterInterface $odtWriter,
	) {
	}//end __construct()

	/**
	 * Whether an office server can convert documents right now.
	 *
	 * @return bool True when htmlToPdf() can be called.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function isAvailable(): bool {
		return $this->officeConverter->isAvailable();
	}//end isAvailable()

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
	public function htmlToPdf(string $html, bool $landscape): string {
		$odt = $this->odtWriter->fromHtml(html: $html, landscape: $landscape);

		return $this->officeConverter->odtToPdf(odtBytes: $odt);
	}//end htmlToPdf()
}//end class
