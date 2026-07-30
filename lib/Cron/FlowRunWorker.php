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
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowResolverRegistry;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use stdClass;
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
     * How long a run may sit in `running` before it counts as abandoned.
     *
     * A run executes synchronously inside ONE worker pass, so a pass that is
     * still going has touched its row far more recently than this. Fifteen
     * minutes is therefore generous rather than tight — it exists to be
     * unambiguous, not to be quick.
     *
     * @var int
     */
    private const DEFAULT_STALE_MINUTES = 15;

    /**
     * Constructor.
     *
     * @param ITimeFactory         $time      Job scheduling clock.
     * @param FlowRunMapper        $mapper    Reads and prunes runs.
     * @param FlowRunService       $runner    Executes a run.
     * @param FlowResolverRegistry $resolvers Loads a run's flow and subject.
     * @param IAppConfig           $appConfig Reads the retention setting.
     * @param LoggerInterface      $logger    The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly FlowRunMapper $mapper,
        private readonly FlowRunService $runner,
        private readonly FlowResolverRegistry $resolvers,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);
        // Every minute: a Wait step's resolution is bounded by this, and a
        // queued run should feel immediate to the person who triggered it.
        $this->setInterval(seconds: 60);

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

        $this->reapStale(now: $now);

        foreach ($this->mapper->findQueued(limit: self::BATCH) as $run) {
            $this->advance(run: $run);
        }

        foreach ($this->mapper->findDue(now: $now, limit: self::BATCH) as $run) {
            $this->advance(run: $run);
        }

        $this->prune(now: $now);

    }//end run()

    /**
     * Fail runs abandoned in `running` by a pass that never came back.
     *
     * FAILED, not requeued. A run that died mid-walk may already have written
     * an object, sent a mail, or called a webhook; restarting it would repeat
     * those silently. The existing retry endpoint turns it back into a run when
     * a person decides that is right — which is exactly the kind of decision
     * that should not be made by a cron job.
     *
     * Left alone, such a row is unreachable: the worker reads only `queued` and
     * due `suspended` runs, so nothing ever touches it again, and every surface
     * that shows live runs shows it as running forever.
     *
     * @param DateTime $now The current time.
     *
     * @return void
     *
     * @spec openspec/changes/or-flow-stale-runs/specs/flow-stale-runs/spec.md
     */
    private function reapStale(DateTime $now): void
    {
        $minutes = (int) $this->appConfig->getValueString(
            'openregister',
            'flow_run_stale_minutes',
            (string) self::DEFAULT_STALE_MINUTES
        );

        if ($minutes <= 0) {
            // An operator who runs very long single steps can switch the reaper
            // off rather than have it fail work that is still going.
            return;
        }

        $cutoff = (clone $now)->modify('-'.$minutes.' minutes');

        foreach ($this->mapper->findStale(before: $cutoff, limit: self::BATCH) as $run) {
            $run->setStatus(FlowRun::STATUS_FAILED);
            $run->setError(
                sprintf(
                    'Abandoned: no worker pass has touched this run for over %d minutes, '
                    .'so the pass that started it did not finish (a fatal, a timeout or a restart). '
                    .'Retry it to run it again from the start.',
                    $minutes
                )
            );
            $run->setUpdated($now);
            $this->mapper->update($run);

            $this->logger->warning(
                message: '[FlowRunWorker] Failed a run abandoned in `running`',
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'run'  => $run->getUuid(),
                    'flow' => $run->getFlowId(),
                ]
            );
        }//end foreach

    }//end reapStale()

    /**
     * Advance one run, never letting its failure stop the batch.
     *
     * @param FlowRun $run The run.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.StaticAccess) FlowItems::item is a pure value
     * constructor with no state to inject; a factory collaborator would add a
     * dependency to say the same thing.
     *
     * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
     */
    private function advance(FlowRun $run): void
    {
        try {
            $flow = $this->resolvers->resolveFlow((string) $run->getFlowId());
            if ($flow === null) {
                // No installed app owns this flow — it was deleted, or the app
                // that stored it was removed. The run cannot proceed; fail it
                // with a clear reason rather than leaving it queued forever.
                $run->setStatus(FlowRun::STATUS_FAILED);
                $run->setError(sprintf('No app provides flow "%s" (deleted, or its app removed?).', $run->getFlowId()));
                $this->mapper->update($run);
                return;
            }

            // A run may legitimately have no subject (a manual or webhook run
            // seeded from its payload). Only resolve one when the run names it.
            $subject = null;
            if (trim((string) $run->getSubjectUuid()) !== '') {
                $subject = $this->resolvers->resolveSubject(
                    (string) $run->getSubjectUuid(),
                    (string) $run->getSubjectRegister(),
                    (string) $run->getSubjectSchema()
                );

                if ($subject === null) {
                    $run->setStatus(FlowRun::STATUS_FAILED);
                    $run->setError(sprintf('Subject "%s" no longer exists.', $run->getSubjectUuid()));
                    $this->mapper->update($run);
                    return;
                }
            }

            // A subjectless run still needs an object to carry the marking; a
            // bare holder does, since the marking store keeps the marking on the
            // run itself ({@see FlowRunMarkingStore}). Such a run is seeded from
            // its payload instead of an object: a non-object trigger (a file, a
            // user) puts what it is about under `payload` on the run context, and
            // that becomes the first item, so the flow reads a file's path or a
            // user's id exactly as it would an object's fields.
            $seed = null;
            if ($subject === null) {
                $subject = new stdClass();
                $payload = (array) (($run->getContext() ?? [])['payload'] ?? []);
                if ($payload !== []) {
                    $seed = [FlowItems::item(json: $payload)];
                }
            }

            $this->runner->execute(run: $run, flow: $flow, subject: $subject, seedItems: $seed);
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
