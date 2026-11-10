<?php

/**
 * One update of a scheduled report.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

/**
 * One update of a scheduled report: which report, the fields to change and who asks.
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */
final class ScheduledReportUpdate {
	/**
	 * Hold the values.
	 *
	 * @param int                 $id     The scheduled report id.
	 * @param array<string,mixed> $data   Fields to update (same shape as create()).
	 * @param ReportCaller        $caller Who asks.
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	public function __construct(
		public readonly int $id,
		public readonly array $data,
		public readonly ReportCaller $caller,
	) {
	}//end __construct()
}//end class
