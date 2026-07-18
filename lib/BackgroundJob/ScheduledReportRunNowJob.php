<?php

/**
 * Scheduled report immediate-run job.
 *
 * Fire-once QueuedJob dispatched by `ScheduledReportsController::runNow()`
 * via `IJobList::add()` — mirrors `WebhookDeliveryJob`'s
 * dispatch-not-inline pattern so the REST request never blocks on
 * `ExportService` execution. Runs the same
 * `ScheduledReportService::runOne()` logic the hourly `ScheduledReportJob`
 * uses, skipping the due-check (an explicit run-now is due by definition).
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
 * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Db\ScheduledReportMapper;
use OCA\OpenRegister\Service\ScheduledReportService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * One-shot immediate execution of a single scheduled report.
 *
 * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
 */
class ScheduledReportRunNowJob extends QueuedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory           $time    Time factory.
     * @param ScheduledReportMapper  $mapper  Scheduled report mapper.
     * @param ScheduledReportService $service Execution logic.
     * @param LoggerInterface        $logger  Logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly ScheduledReportMapper $mapper,
        private readonly ScheduledReportService $service,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);
    }//end __construct()

    /**
     * Run the identified scheduled report immediately.
     *
     * @param mixed $argument Job argument: `['scheduledReportId' => int]`.
     *
     * @return void
     *
     * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
     */
    protected function run($argument): void
    {
        $rawReportId = 0;
        if (is_array($argument) === true) {
            $rawReportId = ($argument['scheduledReportId'] ?? 0);
        }

        $reportId = (int) $rawReportId;
        if ($reportId <= 0) {
            $this->logger->warning(
                message: '[ScheduledReportRunNowJob] Missing/invalid scheduledReportId argument',
                context: ['file' => __FILE__, 'line' => __LINE__, 'argument' => $argument]
            );
            return;
        }

        try {
            $report = $this->mapper->find(id: $reportId);
        } catch (DoesNotExistException $e) {
            $this->logger->warning(
                message: '[ScheduledReportRunNowJob] Scheduled report no longer exists',
                context: ['file' => __FILE__, 'line' => __LINE__, 'reportId' => $reportId]
            );
            return;
        }

        $this->service->runOne(report: $report);
    }//end run()
}//end class
