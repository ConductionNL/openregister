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
use OCA\OpenRegister\Service\Flow\FlowRunAdvancer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * Executes queued runs, resumes due ones, and prunes old ones.
 *
 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
 * @spec openspec/changes/or-flow-queue-fairness/specs/flow-queue-fairness/spec.md
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
     * How long a run may sit in `queued` before it is too late to run it.
     *
     * A queued run says "do this now". Twenty-four hours later that is no
     * longer what it says, and a schedule tick, a poll or a reminder that
     * fires a day late is usually worse than one that never fired at all.
     *
     * Set to 0 to keep queued runs forever, for an instance whose cron is
     * deliberately intermittent and which wants every tick eventually run.
     *
     * @var int
     */
    private const DEFAULT_QUEUED_TTL_HOURS = 24;

    /**
     * How many stale queued runs one pass may expire.
     *
     * Higher than BATCH because expiry is a status change and nothing else —
     * no flow is resolved, no node runs, nothing outside the database is
     * touched — so it costs a fraction of advancing a run. It is still capped:
     * a backlog of tens of thousands should not become one enormous
     * transaction on the cron slot it happens to land in.
     *
     * @var int
     */
    private const EXPIRE_BATCH = 500;

    /**
     * Constructor.
     *
     * @param ITimeFactory    $time      Job scheduling clock.
     * @param FlowRunMapper   $mapper    Reads and prunes runs.
     * @param FlowRunAdvancer $advancer  Advances one run (shared with sync runs).
     * @param IAppConfig      $appConfig Reads the retention setting.
     * @param LoggerInterface $logger    The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly FlowRunMapper $mapper,
        private readonly FlowRunAdvancer $advancer,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct(time: $time);
        // A FLOOR, not a schedule. `setInterval` says "not more often than
        // this"; how often the job actually runs is how often Nextcloud's cron
        // is invoked, and the stock system cron is every FIVE minutes.
        //
        // Worth being explicit about, because reading this line as "every
        // minute" overstates the queue's throughput by 5x: measured on the dev
        // instance 2026-08-02, the drain was a flat 25 runs per five minutes —
        // 300/hour — not 25 per minute. Capacity planning that starts from
        // BATCH alone will be wrong by whatever the cron period is.
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
     * @spec openspec/changes/or-flow-queue-fairness/specs/flow-queue-fairness/spec.md
     */
    protected function run($argument): void
    {
        $now = new DateTime();

        $this->reapStale(now: $now);
        $this->expireStaleQueued(now: $now);

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
     * Abandon queued runs that waited so long that running them is wrong.
     *
     * Two things go wrong without this, and only one of them is the obvious
     * one.
     *
     * The obvious one: a queue that only ever drains is a queue that will
     * eventually execute a week-old intention. A schedule tick from last
     * Tuesday does not catch anything up; it replays a decision against a
     * world that has moved on.
     *
     * The one that actually bites: `hasActiveRun()` counts `queued`, so the
     * scheduler's singleton guard ({@see FlowScheduleService}) refuses to fire
     * a flow that still has a queued run. One starved run therefore stops that
     * flow's ENTIRE schedule, silently and for as long as the run sits there.
     * Expiry is what breaks that deadlock — fairness alone would not, because
     * a flow whose only run is stuck never gets a second one to be fair to.
     *
     * @param DateTime $now The current time.
     *
     * @return void
     *
     * @spec openspec/changes/or-flow-queue-fairness/specs/flow-queue-fairness/spec.md
     */
    private function expireStaleQueued(DateTime $now): void
    {
        $hours = (int) $this->appConfig->getValueString(
            'openregister',
            'flow_run_queued_ttl_hours',
            (string) self::DEFAULT_QUEUED_TTL_HOURS
        );

        if ($hours <= 0) {
            return;
        }

        $reason = sprintf(
            'Expired: this run waited in the queue for more than %d hours, so whatever it was '
            .'meant to do now is no longer what it would do. Retry it to run it again from the start.',
            $hours
        );

        try {
            $cutoff  = (clone $now)->modify('-'.$hours.' hours');
            $expired = $this->mapper->expireQueuedBefore(
                before: $cutoff,
                reason: $reason,
                limit: self::EXPIRE_BATCH
            );

            if ($expired !== []) {
                $this->logger->warning(
                    message: '[FlowRunWorker] Expired queued runs that waited past their TTL',
                    context: [
                        'file'     => __FILE__,
                        'line'     => __LINE__,
                        'expired'  => count($expired),
                        'ttlHours' => $hours,
                    ]
                );
            }
        } catch (Throwable $e) {
            // Expiry is housekeeping; it must never cost the pass its ability
            // to actually run the queue.
            $this->logger->error(
                message: '[FlowRunWorker] Queued-run expiry pass failed',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
        }//end try

    }//end expireStaleQueued()

    /**
     * Advance one run, never letting its failure stop the batch.
     *
     * The body lives in {@see FlowRunAdvancer} so a synchronous run executes
     * through exactly this path rather than a parallel implementation that
     * could drift from it.
     *
     * @param FlowRun $run The run.
     *
     * @return void
     *
     * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
     */
    private function advance(FlowRun $run): void
    {
        // Swallowing is deliberate here and NOT in the synchronous path: one
        // poisoned run must not stop the queue draining for every other flow.
        $this->advancer->advance(run: $run, rethrow: false);

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
