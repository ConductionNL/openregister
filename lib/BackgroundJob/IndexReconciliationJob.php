<?php

/**
 * IndexReconciliationJob: reconciles the search index against what it indexes, once a day.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Operations\IndexReconciliationReport;
use OCA\OpenRegister\Service\Operations\JobScheduleServiceInterface;

/**
 * Runs the five index probes daily and stores the report (REQ-SQF-006).
 *
 * The probes only read; repairing what they find is an administrator's act on
 * the operations console, never this job's.
 *
 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
 */
class IndexReconciliationJob extends RecordedTimedJob {

	/**
	 * Once a day.
	 */
	public const INTERVAL_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param RecordedJobRuntime          $runtime  The clock and the run recorder.
	 * @param JobScheduleServiceInterface $schedule Whether the job may run now.
	 * @param IndexReconciliationReport   $report   The probes' run and its store.
	 */
	public function __construct(
		RecordedJobRuntime $runtime,
		JobScheduleServiceInterface $schedule,
		private readonly IndexReconciliationReport $report,
	) {
		parent::__construct(runtime: $runtime, schedule: $schedule);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Run the probes and store the report with the moment it ran.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	protected function runRecorded(mixed $argument): void {
		$this->report->run(ranAt: $this->time->getDateTime());
	}//end runRecorded()
}//end class
