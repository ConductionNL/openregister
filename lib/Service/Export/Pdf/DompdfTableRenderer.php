<?php

/**
 * OpenRegister DompdfTableRenderer
 *
 * The default export table renderer, through dompdf (LGPL-2.1).
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

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/**
 * Renders export table sections through dompdf's HTML and CSS engine.
 */
class DompdfTableRenderer implements PdfTableRenderer {

	/**
	 * Render the sections into one A4-landscape PDF.
	 *
	 * No remote fetches and no PHP execution, the same sandbox as
	 * `PdfReportWriter`. Page numbers use `Canvas::page_text()`'s safe
	 * `{PAGE_NUM}`/`{PAGE_COUNT}` placeholders, not PHP in the HTML.
	 *
	 * @param string[] $sections Escaped HTML fragments, one per section.
	 *
	 * @return string Raw PDF bytes.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function render(array $sections): string {
		$style = '@page { margin: 12mm; }'
			. "body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #1a1a1a; }"
			. 'h1 { font-size: 14pt; margin: 0 0 2mm 0; }'
			. 'p.meta { font-size: 8pt; color: #555555; margin: 0 0 4mm 0; }'
			. 'table { width: 100%; border-collapse: collapse; }'
			. 'th, td { border: 0.25pt solid #cccccc; padding: 1.5mm 2mm; text-align: left; word-wrap: break-word; }'
			. 'th { background-color: #2c3e50; color: #ffffff; font-weight: bold; }'
			. 'tbody tr:nth-child(even) { background-color: #f2f2f2; }'
			. '.pdf-section { page-break-after: always; }'
			. '.pdf-section:last-child { page-break-after: auto; }';

		$body = implode('', $sections);
		$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>' . $style . '</style></head><body>' . $body . '</body></html>';

		$options = new Options();
		$options->set('isRemoteEnabled', false);
		$options->set('isPhpEnabled', false);
		$options->set('defaultFont', 'DejaVu Sans');
		$options->set('defaultPaperSize', 'A4');
		$options->set('defaultPaperOrientation', 'landscape');

		// SECURITY: dompdf has a history of SSRF and file-disclosure CVEs
		// (CVE-2022-41343, CVE-2023-23924); these flags are the primary
		// mitigation and must stay false.
		if ($options->getIsRemoteEnabled() !== false || $options->getIsPhpEnabled() !== false) {
			throw new RuntimeException(
				'ExportService PDF sandbox configuration drifted; remote-fetch / PHP execution must remain disabled.'
			);
		}

		$dompdf = new Dompdf($options);
		$dompdf->loadHtml($html, 'UTF-8');
		$dompdf->setPaper('A4', 'landscape');
		$dompdf->render();

		$canvas = $dompdf->getCanvas();
		$font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
		$canvas->page_text(
			$canvas->get_width() - 70,
			$canvas->get_height() - 20,
			'Page {PAGE_NUM} / {PAGE_COUNT}',
			$font,
			8,
			[0.3, 0.3, 0.3]
		);

		$output = $dompdf->output();
		return $output ?? '';
	}//end render()
}//end class
