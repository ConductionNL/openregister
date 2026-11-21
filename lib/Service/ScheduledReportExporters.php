<?php

/**
 * OpenRegister Scheduled report exporters
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Service\Connection\ConnectionReportKind;
use OCA\OpenRegister\Service\Export\ExportProfileService;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;

/**
 * What produces a scheduled report's file: the export engine, the named
 * export profile runner, and the recorder that accounts for each run.
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */
final class ScheduledReportExporters {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ExportService         $exportService  The export engine this feature composes.
	 * @param ?ExportProfileService $profileService Runs a named export profile, when the schedule names one.
	 * @param ?ExportRunRecorder    $exportRuns     Records what each run produced.
	 * @param ?ConnectionReportKind  $connectionReports Runs the connection-health kind.
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function __construct(
		public readonly ExportService $exportService,
		public readonly ?ExportProfileService $profileService = null,
		public readonly ?ExportRunRecorder $exportRuns = null,
		public readonly ?ConnectionReportKind $connectionReports = null,
	) {
	}//end __construct()
}//end class
