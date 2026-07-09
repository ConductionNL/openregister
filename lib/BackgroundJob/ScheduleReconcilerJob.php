<?php

/**
 * OpenRegister AppHost — Schedule Reconciler Job
 *
 * A 60-second `TimedJob` that drives {@see ScheduleReconciler}: each tick it
 * enumerates on-disk and virtual manifest `schedules[]` and reconciles them into
 * OpenConnector `job` objects. Mirrors `ScheduledWorkflowJob` (cadence + shape).
 * Execution of the reconciled jobs is performed separately by OpenConnector's
 * own `JobTask`/`JobService` — this job only curates the `job` objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
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

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\AppHost\Scheduling\ScheduleReconciler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * TimedJob that reconciles manifest schedules into OpenConnector jobs.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ScheduleReconcilerJob extends TimedJob
{
    /**
     * Constructor for ScheduleReconcilerJob.
     *
     * @param ITimeFactory       $time       Time factory.
     * @param ScheduleReconciler $reconciler The AppHost scheduling engine.
     * @param LoggerInterface    $logger     Logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly ScheduleReconciler $reconciler,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);
        // Run every 60 seconds; the reconcile is a cheap set-diff (execution is
        // OpenConnector's separate JobTask).
        $this->setInterval(seconds: 60);
    }//end __construct()

    /**
     * Execute one reconciliation sweep.
     *
     * @param mixed $argument Job argument (unused for TimedJob).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md
     */
    protected function run($argument): void
    {
        try {
            $this->reconciler->reconcile();
        } catch (Throwable $e) {
            // Defence in depth — reconcile() already never throws.
            $this->logger->error(
                message: '[ScheduleReconcilerJob] Reconcile sweep failed: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
        }
    }//end run()
}//end class
