<?php

/**
 * Drains the flow-run queue and wakes runs whose wait is over.
 *
 * This is what makes a trigger cheap. A Nextcloud Flow rule, an object event
 * or a file write only records the intent to run a flow; the graph itself
 * executes here, off the request that caused it. Without this job a trigger
 * would either block a user action for the length of an arbitrary graph, or
 * quietly do nothing.
 *
 * Runs are claimed in small batches so one pass cannot monopolise the cron
 * slot, and retention is applied in the same pass because runs are operational
 * data that grows without bound — this instance has already been taken down
 * once by a file that nobody was pruning.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Cron
 * @package  OCA\OpenRegister\Cron
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Cron;

use DateTime;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Executes queued runs, resumes due ones, and prunes old ones.
 */
class FlowRunWorker extends TimedJob
{

    /**
     * How many runs one pass may claim, per category.
     *
     * A ceiling rather than "everything waiting": a cron slot is shared, and a
     * backlog that cannot be cleared in one pass is cleared over several.
     *
     * @var int
     */
    private const BATCH = 25;

    /**
     * Default retention for terminal runs, in days.
     *
     * @var int
     */
    private const DEFAULT_RETENTION_DAYS = 30;

    /**
     * Constructor.
     *
     * @param ITimeFactory    $time      Job scheduling clock.
     * @param FlowRunMapper   $mapper    Reads and prunes runs.
     * @param FlowRunService  $runner    Executes a run.
     * @param IAppConfig      $appConfig Reads the retention setting.
     * @param LoggerInterface $logger    The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly FlowRunMapper $mapper,
        private readonly FlowRunService $runner,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);
        // Every minute: a Wait step's resolution is bounded by this, and a
        // queued run should feel immediate to the person who triggered it.
        $this->setInterval(interval: 60);

    }//end __construct()

    /**
     * One pass: start what is queued, resume what is due, prune what is old.
     *
     * @param mixed $argument Job argument (unused).
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
     */
    protected function run($argument): void
    {
        $now = new DateTime();

        foreach ($this->mapper->findQueued(limit: self::BATCH) as $run) {
            $this->advance(run: $run);
        }

        foreach ($this->mapper->findDue(now: $now, limit: self::BATCH) as $run) {
            $this->advance(run: $run);
        }

        $this->prune(now: $now);

    }//end run()

    /**
     * Advance one run, never letting its failure stop the batch.
     *
     * @param FlowRun $run The run.
     *
     * @return void
     *
     * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
     */
    private function advance(FlowRun $run): void
    {
        try {
            // TODO(#2067): resolve the flow document and the subject object.
            // Both arrive with the flow-document store; until then a claimed
            // run is left for the next pass rather than being failed, so no
            // run is lost between these two changes landing.
            $this->logger->debug(
                message: '[FlowRunWorker] Run awaiting the flow-document store',
                context: ['file' => __FILE__, 'line' => __LINE__, 'run' => $run->getUuid()]
            );
        } catch (Throwable $e) {
            $this->logger->error(
                message: '[FlowRunWorker] Failed to advance a run',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'run'   => $run->getUuid(),
                    'error' => $e->getMessage(),
                ]
            );
        }//end try

    }//end advance()

    /**
     * Delete terminal runs past their retention window.
     *
     * A retention of 0 disables pruning, for an operator who ships runs
     * somewhere else and wants them kept.
     *
     * @param DateTime $now The current time.
     *
     * @return void
     *
     * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
     */
    private function prune(DateTime $now): void
    {
        $days = (int) $this->appConfig->getValueString(
            'openregister',
            'flow_run_retention_days',
            (string) self::DEFAULT_RETENTION_DAYS
        );

        if ($days <= 0) {
            return;
        }

        try {
            $before  = (clone $now)->modify(sprintf('-%d days', $days));
            $deleted = $this->mapper->pruneBefore(before: $before);
            if ($deleted > 0) {
                $this->logger->info(
                    message: '[FlowRunWorker] Pruned old flow runs',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'deleted' => $deleted, 'olderThanDays' => $days]
                );
            }
        } catch (Throwable $e) {
            $this->logger->error(
                message: '[FlowRunWorker] Retention pass failed',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
        }//end try

    }//end prune()
}//end class
