<?php

/**
 * Scheduled report delivery job.
 *
 * Hourly TimedJob that walks every enabled `ScheduledReport`, computes
 * whether it's due (catch-up-safe elapsed-period check, see
 * `ScheduledReportService::isDue()`), and executes due reports via
 * `ScheduledReportService::runOne()` — export as the owner, deliver to the
 * owner's Nextcloud Files, notify the owner. One report's failure never
 * aborts the rest (per-report isolation).
 *
 * This is the reserved `ScheduledReportJob` class named in
 * `openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md`'s
 * "scheduled report generation" requirement — see this app's
 * `openspec/changes/scheduled-report-jobs/design.md` for the full
 * reconciliation with that proposal and with `ReportRenderJob` (the
 * separate, already-shipped BI-dashboard-template renderer).
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\BackgroundJob;

use DateTime;
use OCA\OpenRegister\Notifications\Db\ScheduledReportMapper;
use OCA\OpenRegister\Kernel\Contracts\ScheduledReportInterface;
use OCA\OpenRegister\Kernel\Contracts\ScheduledReportsInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Hourly scheduled-report deliverer.
 *
 * @spec openspec/specs/scheduled-report-jobs/spec.md
 */
class ScheduledReportJob extends TimedJob {

	/**
	 * Hourly cadence — runs once every hour, so due reports are caught
	 * within an hour of their period elapsing (and any downtime is caught
	 * up on the next tick — see ScheduledReportService::isDue()).
	 *
	 * @var int
	 */
	private const RUN_INTERVAL_SECONDS = 3600;

	/**
	 * The outcome of a report that ran.
	 *
	 * @var string
	 */
	private const RAN = 'ran';

	/**
	 * The outcome of a report that was not due.
	 *
	 * @var string
	 */
	private const SKIPPED = 'skipped';

	/**
	 * The outcome of a report whose run threw.
	 *
	 * @var string
	 */
	private const FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param ScheduledReportMapper $mapper Scheduled report mapper.
	 * @param ScheduledReportsInterface $service Due-check + execution logic.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ScheduledReportMapper $mapper,
		private readonly ScheduledReportsInterface $service,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::RUN_INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Drive the scheduled report deliveries.
	 *
	 * @param mixed $argument Job arguments (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 *
	 * @spec openspec/specs/scheduled-report-jobs/spec.md
	 */
	protected function run($argument): void {
		$candidates = $this->enabledReports();
		if ($candidates === null) {
			return;
		}

		$now = new DateTime();
		$outcomes = [];
		foreach ($candidates as $report) {
			$outcomes[] = $this->runIfDue(report: $report, now: $now);
		}

		$this->logPass(candidates: $candidates, outcomes: $outcomes);
	}//end run()

	/**
	 * The enabled scheduled reports; null (logged) when they cannot be listed.
	 *
	 * @return array|null
	 */
	private function enabledReports(): ?array {
		try {
			return $this->mapper->findEnabled();
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ScheduledReportJob] Failed to enumerate enabled scheduled reports',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end enabledReports()

	/**
	 * Run one report when it is due.
	 *
	 * @param ScheduledReportInterface $report The report.
	 * @param DateTime                 $now    The moment the whole pass checks against.
	 *
	 * @return string `skipped`, `ran` or `failed`.
	 */
	private function runIfDue(ScheduledReportInterface $report, DateTime $now): string {
		if ($this->service->isDue(report: $report, now: $now) === false) {
			return self::SKIPPED;
		}

		try {
			// Per-report isolation: ScheduledReportService::runOne() already
			// catches everything internally and never throws, but this
			// try/catch is defence in depth so an unexpected error in a
			// future refactor still can't abort the batch (mirrors
			// ReportRenderJob::run()'s per-dashboard loop).
			$this->service->runOne(report: $report);
			return self::RAN;
		} catch (\Throwable $e) {
			$this->logRunFailure(report: $report, error: $e);
			return self::FAILED;
		}
	}//end runIfDue()

	/**
	 * Log a report whose run threw.
	 *
	 * @param ScheduledReportInterface $report The report.
	 * @param \Throwable               $error  The failure.
	 *
	 * @return void
	 */
	private function logRunFailure(ScheduledReportInterface $report, \Throwable $error): void {
		$this->logger->warning(
			message: '[ScheduledReportJob] Run failed for scheduled report',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'reportId' => $report->getId(),
				'error' => $error->getMessage(),
			]
		);
	}//end logRunFailure()

	/**
	 * Log the pass summary: candidates, reports run and reports skipped.
	 *
	 * @param array        $candidates The enabled reports.
	 * @param list<string> $outcomes   The outcome of each report.
	 *
	 * @return void
	 */
	private function logPass(array $candidates, array $outcomes): void {
		$counts = array_count_values($outcomes);
		$this->logger->info(
			message: '[ScheduledReportJob] Scheduled-report pass complete',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'candidates' => count($candidates),
				'ran' => ($counts[self::RAN] ?? 0),
				'skipped' => ($counts[self::SKIPPED] ?? 0),
			]
		);
	}//end logPass()
}//end class
