<?php

/**
 * OpenRegister report writers
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Reporting;

/**
 * The format writers a report render picks from.
 *
 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-1
 */
final class ReportWriters {
	/**
	 * Bundle the services.
	 *
	 * @param SpreadsheetReportWriter $spreadsheetWriter CSV / XLSX / ODS writer.
	 * @param HtmlReportWriter        $htmlWriter        HTML writer.
	 * @param PdfReportWriter         $pdfWriter         PDF writer (office server conversion).
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-1
	 */
	public function __construct(
		public readonly SpreadsheetReportWriter $spreadsheetWriter,
		public readonly HtmlReportWriter $htmlWriter,
		public readonly PdfReportWriter $pdfWriter,
	) {
	}//end __construct()
}//end class
