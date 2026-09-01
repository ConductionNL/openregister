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

namespace OCA\OpenRegister\BackgroundJob;

use DateTime;
use OCA\OpenRegister\Db\ScheduledReportMapper;
use OCA\OpenRegister\Service\ScheduledReportService;
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
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param ScheduledReportMapper $mapper Scheduled report mapper.
	 * @param ScheduledReportService $service Due-check + execution logic.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ScheduledReportMapper $mapper,
		private readonly ScheduledReportService $service,
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
		$candidates = [];
		try {
			$candidates = $this->mapper->findEnabled();
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[ScheduledReportJob] Failed to enumerate enabled scheduled reports',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return;
		}

		$now = new DateTime();
		$ran = 0;
		$skip = 0;
		foreach ($candidates as $report) {
			if ($this->service->isDue(report: $report, now: $now) === false) {
				$skip++;
				continue;
			}

			try {
				// Per-report isolation: ScheduledReportService::runOne() already
				// catches everything internally and never throws, but this
				// try/catch is defence in depth so an unexpected error in a
				// future refactor still can't abort the batch (mirrors
				// ReportRenderJob::run()'s per-dashboard loop).
				$this->service->runOne(report: $report);
				$ran++;
			} catch (\Throwable $e) {
				$this->logger->warning(
					message: '[ScheduledReportJob] Run failed for scheduled report',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'reportId' => $report->getId(),
						'error' => $e->getMessage(),
					]
				);
			}
		}//end foreach

		$this->logger->info(
			message: '[ScheduledReportJob] Scheduled-report pass complete',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'candidates' => count($candidates),
				'ran' => $ran,
				'skipped' => $skip,
			]
		);
	}//end run()
}//end class
