<?php

/**
 * PDF report writer.
 *
 * Writes the `HtmlReportWriter` output as a portrait A4 ODT and has the
 * office server convert it to PDF.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Reporting
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Reporting;

use OCA\OpenRegister\Exception\OfficeServerRequiredException;
use OCA\OpenRegister\Service\Export\Odt\OdtWriter;
use OCA\OpenRegister\Service\File\Pdf\Fallback\NcOfficeConverterInterface;

/**
 * Render a resolved dashboard as a single PDF document.
 *
 * Only the text of the report HTML reaches the ODT; its CSS, links and any
 * other markup are not copied.
 */
class PdfReportWriter {
	/**
	 * Constructor.
	 *
	 * @param HtmlReportWriter           $htmlWriter      HTML writer used as the input layer.
	 * @param NcOfficeConverterInterface $officeConverter Office server conversion to PDF.
	 */
	public function __construct(
		private readonly HtmlReportWriter $htmlWriter,
		private readonly NcOfficeConverterInterface $officeConverter,
	) {

	}//end __construct()

	/**
	 * Render the PDF.
	 *
	 * @param array<string, mixed> $dashboard Dashboard payload.
	 * @param array<int, array{widget: array, data: array|null}> $resolvedWidgets Widget+data tuples.
	 *
	 * @return string Rendered PDF bytes.
	 *
	 * @throws OfficeServerRequiredException When no office server converts to PDF.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-4
	 */
	public function write(array $dashboard, array $resolvedWidgets): string {
		if ($this->officeConverter->isAvailable() === false) {
			throw new OfficeServerRequiredException();
		}

		$html = $this->htmlWriter->write(dashboard: $dashboard, resolvedWidgets: $resolvedWidgets);
		$odt = (new OdtWriter())->fromHtml(html: $html, landscape: false);

		return $this->officeConverter->odtToPdf(odtBytes: $odt);
	}//end write()
}//end class
