<?php

/**
 * Keeps a running flow run alive, and stops it when it has run long enough.
 *
 * Two jobs, because they are asked at the same moments and answering only one
 * of them is what produced the bug this replaces.
 *
 * LIVENESS. The stale reaper fails runs left `running` past a threshold, to
 * clear rows whose executor died mid-walk. It decides on the run's `updated`
 * column — and nothing wrote that column during the walk, so it could not tell a
 * dead executor from a busy one. It was measuring elapsed time and calling it
 * liveness. Measured on the dev instance at low load: a run started 09:00:56 was
 * marked abandoned 09:20:03 and then went on to import every record it was asked
 * for. The heartbeat is what makes `updated` mean what the reaper reads it as.
 *
 * BUDGET. Liveness alone would let a run that is alive and useless run forever,
 * so the run also carries a deadline. When it passes, the walk STOPS — the run
 * is failed by the executor that owns it, at the next checkpoint, rather than
 * being marked failed by a bystander while it carries on writing. That
 * distinction is the whole point: previously the reaper's verdict and the run's
 * behaviour disagreed for twenty minutes.
 *
 * WHAT "STOPS" CAN AND CANNOT MEAN. PHP cannot preempt a call that is already
 * running, so a deadline is enforced at CHECKPOINTS: between steps, and wherever
 * a node calls `checkpoint()` mid-work. A node that runs long without ever
 * checking cannot be interrupted — it is stopped when it returns, and the run
 * fails then. This is why the deadline is exposed to nodes rather than kept
 * private to the dispatcher.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-stale-runs/specs/flow-stale-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Exception\FlowRunExpired;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One run's liveness signal and its runtime budget.
 */
class FlowRunGuard
{

    /**
     * The context key nodes read this handle from.
     *
     * Beating and checking between steps covers a run made of many steps, and
     * says nothing at all while ONE step runs long — which is the shape that
     * exposed the bug: a single synchronization step ran past the threshold and
     * was reaped mid-import. A node that works in pages, batches or records calls
     * `checkpoint()` between them; a node that returns quickly never needs to.
     *
     * @var string
     */
    public const CONTEXT_KEY = '_runGuard';

    /**
     * Unix time of the last beat that reached the database.
     *
     * @var integer
     */
    private int $lastBeat = 0;

    /**
     * Constructor.
     *
     * @param FlowRunMapper   $mapper      Writes the beat.
     * @param LoggerInterface $logger      Records a beat that could not be written.
     * @param string          $runUuid     The run being guarded.
     * @param float           $startedAt   microtime(true) when the walk began.
     * @param integer         $budget      Seconds the whole run may take; 0 disables.
     * @param integer         $minInterval Seconds between liveness writes.
     */
    public function __construct(
        private readonly FlowRunMapper $mapper,
        private readonly LoggerInterface $logger,
        private readonly string $runUuid,
        private readonly float $startedAt,
        private readonly int $budget,
        private readonly int $minInterval=30
    ) {

    }//end __construct()

    /**
     * Seconds this run has been walking.
     *
     * @return float The elapsed time.
     */
    public function elapsed(): float
    {
        return (microtime(true) - $this->startedAt);

    }//end elapsed()

    /**
     * Whether the run has used its whole budget.
     *
     * @return boolean True when the deadline has passed.
     */
    public function expired(): bool
    {
        if ($this->budget <= 0) {
            // An operator who runs deliberately long flows can lift the ceiling
            // rather than have real work killed. Nothing else switches it off.
            return false;
        }

        return ($this->elapsed() >= (float) $this->budget);

    }//end expired()

    /**
     * Record liveness, and stop the run if its budget is spent.
     *
     * Called between steps by the dispatcher, and by any node that does enough
     * work to be worth interrupting. Order matters: the deadline is tested
     * BEFORE the beat, so a run that is over budget is not first refreshed into
     * looking healthy and then killed.
     *
     * @param string $where What is being entered, for the failure message.
     *
     * @return void
     *
     * @throws FlowRunExpired When the run has exhausted its runtime budget.
     *
     * @spec openspec/changes/or-flow-stale-runs/specs/flow-stale-runs/spec.md
     */
    public function checkpoint(string $where=''): void
    {
        if ($this->expired() === true) {
            $location = '';
            if ($where !== '') {
                $location = ' at '.$where;
            }

            throw new FlowRunExpired(
                message: sprintf(
                    'The run exceeded its maximum runtime of %d seconds (%s elapsed)%s. '
                    .'Raise `maxRuntimeMinutes` on the flow, or the `flow_max_runtime_minutes` '
                    .'instance setting, if this flow is meant to run this long.',
                    $this->budget,
                    number_format($this->elapsed(), 1),
                    $location
                )
            );
        }

        $this->beat();

    }//end checkpoint()

    /**
     * Record that the run is still executing.
     *
     * Throttled, because the caller is a per-step (or per-batch) hook and a flow
     * with thousands of steps would otherwise issue thousands of writes to say
     * one thing. The interval only has to sit comfortably below the reaper's
     * threshold — which is minutes — so seconds of granularity is ample.
     *
     * Never throws. A missed beat costs the run its liveness signal for one
     * interval; a beat that propagated an exception would fail a run that is
     * working, which is the exact outcome this class exists to prevent.
     *
     * @return void
     *
     * @spec openspec/changes/or-flow-stale-runs/specs/flow-stale-runs/spec.md
     */
    public function beat(): void
    {
        $now = time();
        if (($now - $this->lastBeat) < $this->minInterval) {
            return;
        }

        // Stamped BEFORE the write, not after. On an instance where the write is
        // itself slow, stamping afterwards would let the throttle measure the
        // write's duration as idle time and beat again immediately.
        $this->lastBeat = $now;

        try {
            $this->mapper->touch(uuid: $this->runUuid, when: new DateTime());
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[FlowRunGuard] Could not record a beat: '.$e->getMessage(),
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                    'run'  => $this->runUuid,
                ]
            );
        }

    }//end beat()
}//end class
