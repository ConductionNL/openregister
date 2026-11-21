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
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\Flow\FlowErrorPolicy;
use DateTime;
use OCA\OpenRegister\BackgroundJob\Support\AbandonedFlowClaim;
use OCA\OpenRegister\BackgroundJob\Support\FlowReapRun;
use OCA\OpenRegister\Db\FlowClaimMapperInterface;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\FlowRunQueueExpiry;
use OCA\OpenRegister\Db\FlowStreamMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowConsentSweepInterface;
use OCA\OpenRegister\Service\Flow\FlowLocatorInterface;
use OCA\OpenRegister\Service\Object\RunLockReleaseInterface;
use OCA\OpenRegister\Service\Flow\FlowRunAdvancerInterface;
use OCA\OpenRegister\Service\Flow\FlowTokenRouter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Executes queued runs, resumes due ones, and prunes old ones.
 *
 * @spec openspec/specs/flow-runs/spec.md
 * @spec openspec/specs/flow-queue-fairness/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The pass owns recovery for runs, claims and
 * streams alike; each mapper is one table the reaper has to read.
 */
class FlowRunWorker extends TimedJob {

	/**
	 * How many runs one pass may claim, per category.
	 *
	 * A ceiling rather than "everything waiting": a cron slot is shared, and a
	 * backlog that cannot be cleared in one pass is cleared over several.
	 *
	 * @var int
	 */
	public const BATCH = 25;

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
	 * How long a run may wait for an external signal before it counts as abandoned.
	 *
	 * DAYS, not minutes, and deliberately so: the runs this reaps are waiting on
	 * a person, and a fortnight is the point past which "still deciding" and
	 * "nobody is coming" stop being distinguishable. Set it too short and the
	 * reaper fails live approvals; the failure mode of setting it too long is
	 * only that a dead run lingers, so the generous side is the safe one.
	 *
	 * @var int
	 */
	private const DEFAULT_SIGNAL_WAIT_DAYS = 14;

	/**
	 * The instance-wide runtime ceiling this reaper must not undercut.
	 *
	 * Kept in step with FlowRunService::DEFAULT_MAX_RUNTIME_MINUTES: the reaper
	 * reads the same setting so its patience is derived from what runs are
	 * actually allowed, rather than being a second, contradictory number.
	 *
	 * @var int
	 */
	private const DEFAULT_MAX_RUNTIME_MINUTES = 60;

	/**
	 * Slack between a run's deadline and the reaper's.
	 *
	 * A run that hits its ceiling fails itself at the next checkpoint, which may
	 * be a little after the deadline. Reaping the instant the budget expires
	 * would race that, and produce the abandonment message for a run that was
	 * about to record a perfectly accurate one of its own.
	 *
	 * @var int
	 */
	private const REAP_GRACE_MINUTES = 5;

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
	 * Run persistence.
	 *
	 * @var FlowRunMapperInterface
	 */
	private readonly FlowRunMapperInterface $mapper;

	/**
	 * Advances one run (shared with sync runs).
	 *
	 * @var FlowRunAdvancerInterface
	 */
	private readonly FlowRunAdvancerInterface $advancer;

	/**
	 * Reads the pass tuning settings.
	 *
	 * @var IAppConfig
	 */
	private readonly IAppConfig $appConfig;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Place claims, for the abandoned-claim reaper.
	 *
	 * @var FlowClaimMapperInterface|null
	 */
	private readonly ?FlowClaimMapperInterface $claims;

	/**
	 * Run streams, for failing an abandoned branch.
	 *
	 * @var FlowStreamMapperInterface|null
	 */
	private readonly ?FlowStreamMapperInterface $streams;

	/**
	 * Resolves the run's flow, for the abandoned step's `onError` policy.
	 *
	 * @var FlowLocatorInterface|null
	 */
	private readonly ?FlowLocatorInterface $flows;

	/**
	 * Run-held object locks, for the orphaned-lock sweep.
	 *
	 * @var RunLockReleaseInterface|null
	 */
	private readonly ?RunLockReleaseInterface $locks;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory                    $time     Job scheduling clock.
	 * @param FlowRunWorkerServices           $services The runs, their advancer, the config and the logger.
	 * @param FlowRunRecoveryServices         $recovery Claims, streams, flows and locks for recovering a dead pass.
	 * @param FlowConsentSweepInterface|null  $consent  Settles runs parked awaiting consent. Its absence is
	 *                                                  REPORTED rather than assumed harmless: see
	 *                                                  settleConsent(), which warns when runs are actually
	 *                                                  parked, because a sweep that skips looks exactly like
	 *                                                  one that found nothing.
	 */
	public function __construct(
		ITimeFactory $time,
		FlowRunWorkerServices $services,
		FlowRunRecoveryServices $recovery,
		private readonly ?FlowConsentSweepInterface $consent = null,
	) {
		$this->mapper = $services->mapper;
		$this->advancer = $services->advancer;
		$this->appConfig = $services->appConfig;
		$this->logger = $services->logger;
		$this->claims = $recovery->claims;
		$this->streams = $recovery->streams;
		$this->flows = $recovery->flows;
		$this->locks = $recovery->locks;
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
	 * @spec openspec/specs/flow-runs/spec.md
	 * @spec openspec/specs/flow-queue-fairness/spec.md
	 */
	protected function run($argument): void {
		$now = new DateTime();

		$this->reapStale(now: $now);
		$this->expireStaleQueued(now: $now);
		$this->expireAbandonedSignals(now: $now);
		$this->settleConsent(now: $now);

		foreach ($this->mapper->findQueued(limit: self::BATCH) as $run) {
			$this->advance(run: $run);
		}

		foreach ($this->mapper->findDue(now: $now, limit: self::BATCH) as $run) {
			$this->advance(run: $run);
		}

		$this->sweepOrphanedLocks(now: $now);
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
	 * @spec openspec/specs/flow-stale-runs/spec.md
	 */
	private function reapStale(DateTime $now): void {
		$minutes = $this->intSetting(key: 'flow_run_stale_minutes', default: self::DEFAULT_STALE_MINUTES);

		if ($minutes <= 0) {
			// An operator who runs very long single steps can switch the reaper
			// off rather than have it fail work that is still going.
			return;
		}

		$reap = new FlowReapRun(now: $now, minutes: $this->staleWindow(minutes: $minutes));

		$this->reapAbandonedClaims(reap: $reap);
		$this->failStaleRuns(reap: $reap);

	}//end reapStale()

	/**
	 * The stale window, never shorter than the runtime a run is granted.
	 *
	 * The reaper must never call a run abandoned while that run is still
	 * inside the time it was GRANTED. Those two numbers used to be set
	 * independently and contradicted each other by default: runs were allowed
	 * an hour and declared dead at fifteen minutes, so any walk between the
	 * two was failed while working perfectly — measured on the dev instance,
	 * a run reaped at 09:20 that went on to import everything it was asked
	 * for.
	 *
	 * The executor beats at every checkpoint, so a run that has gone quiet for
	 * longer than its whole budget really is gone. Waiting that long is the
	 * price of not being able to distinguish "dead" from "inside one long
	 * step" any faster.
	 *
	 * @param int $minutes The configured stale window.
	 *
	 * @return int The window the reapers use.
	 */
	private function staleWindow(int $minutes): int {
		$maxRuntime = $this->intSetting(key: 'flow_max_runtime_minutes', default: self::DEFAULT_MAX_RUNTIME_MINUTES);

		if ($maxRuntime > 0) {
			$minutes = max($minutes, ($maxRuntime + self::REAP_GRACE_MINUTES));
		}

		return $minutes;
	}//end staleWindow()

	/**
	 * Fail every run left in `running` since before the reap's cutoff.
	 *
	 * @param FlowReapRun $reap The reap.
	 *
	 * @return void
	 */
	private function failStaleRuns(FlowReapRun $reap): void {
		foreach ($this->mapper->findStale(before: $reap->cutoff(), limit: self::BATCH) as $run) {
			$run->setStatus(FlowRun::STATUS_FAILED);
			$run->setError($this->staleRunError(minutes: $reap->minutes));
			$run->setUpdated($reap->now);
			$this->mapper->update($run);

			$this->logFailedRun(message: '[FlowRunWorker] Failed a run abandoned in `running`', run: $run);
		}
	}//end failStaleRuns()

	/**
	 * The error a run reaped from `running` is failed with.
	 *
	 * @param int $minutes The stale window in minutes.
	 *
	 * @return string
	 */
	private function staleRunError(int $minutes): string {
		return sprintf(
			'Abandoned: no worker pass has touched this run for over %d minutes, '
			. 'so the pass that started it did not finish (a fatal, a timeout or a restart). '
			. 'Retry it to run it again from the start.',
			$minutes
		);
	}//end staleRunError()

	/**
	 * Release claims a dead worker left behind, and FAIL the branch they held.
	 *
	 * Uses the SAME cutoff as the run reaper — one expression, not a second
	 * constant — so the two can never contradict each other. A reaped branch
	 * is failed, never re-dispatched: it may already have written an object,
	 * sent a message or called a remote system, and re-running it would repeat
	 * those without saying so. The run's error policy for the abandoned step
	 * decides the siblings' fate: `continue` keeps them (the run is re-armed
	 * as queued), anything else stops the run.
	 *
	 * @param FlowReapRun $reap The reap; claims taken before its cutoff are abandoned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-branch-abandoned-by-a-crashed-worker-must-be-recovered-and-must-not-be-silently-re-run
	 */
	private function reapAbandonedClaims(FlowReapRun $reap): void {
		if ($this->claims === null || $this->streams === null) {
			return;
		}

		foreach ($this->claims->findOlderThan(before: $reap->cutoff(), limit: self::BATCH) as $claim) {
			$this->reapClaim(abandoned: new AbandonedFlowClaim(claim: $claim, reap: $reap));
		}
	}//end reapAbandonedClaims()

	/**
	 * Release one abandoned claim and fail the branch it held, logging (never
	 * throwing) a failure.
	 *
	 * @param AbandonedFlowClaim $abandoned The abandoned claim.
	 *
	 * @return void
	 */
	private function reapClaim(AbandonedFlowClaim $abandoned): void {
		try {
			$this->claims->releaseByOwner(runUuid: $abandoned->runUuid, owner: (string)$abandoned->claim->getOwner());
			$this->failAbandonedBranch(abandoned: $abandoned);
			$this->failOrRearmClaimedRun(abandoned: $abandoned);
			$this->logReapedClaim(abandoned: $abandoned);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[FlowRunWorker] Could not reap an abandoned claim',
				context: ['file' => __FILE__, 'line' => __LINE__, 'run' => $abandoned->runUuid, 'error' => $e->getMessage()]
			);
		}
	}//end reapClaim()

	/**
	 * Fail the stream the abandoned claim held, when it still exists.
	 *
	 * @param AbandonedFlowClaim $abandoned The abandoned claim.
	 *
	 * @return void
	 */
	private function failAbandonedBranch(AbandonedFlowClaim $abandoned): void {
		$stream = $this->streams->findByRunAndStream(runUuid: $abandoned->runUuid, streamId: $abandoned->branch);
		if ($stream !== null) {
			$stream->setStatus(FlowRun::STATUS_FAILED);
			$stream->setError($abandoned->error);
			$stream->setUpdated($abandoned->now);
			$this->streams->update($stream);
		}
	}//end failAbandonedBranch()

	/**
	 * Fail the claimed run, or re-arm it when the step's policy lets a live
	 * sibling continue.
	 *
	 * @param AbandonedFlowClaim $abandoned The abandoned claim.
	 *
	 * @return void
	 */
	private function failOrRearmClaimedRun(AbandonedFlowClaim $abandoned): void {
		$run = $this->mapper->findByUuid(uuid: $abandoned->runUuid);
		// The abandoned step's policy decides the siblings' fate: the
		// default fails the run; `continue` with a live sibling re-arms
		// the run for the next pass instead.
		$run->setStatus(FlowRun::STATUS_FAILED);
		$run->setError($abandoned->error);
		$run->setResumeAt(null);
		$continues = $this->abandonedStepContinues(run: $run, transition: $abandoned->transition);
		if ($continues === true && $this->hasLiveSibling(runUuid: $abandoned->runUuid, failed: $abandoned->branch) === true) {
			$run->setStatus(FlowRun::STATUS_QUEUED);
			$run->setError(null);
		}

		$run->setUpdated($abandoned->now);
		$this->mapper->update($run);
	}//end failOrRearmClaimedRun()

	/**
	 * Log a reaped claim.
	 *
	 * @param AbandonedFlowClaim $abandoned The abandoned claim.
	 *
	 * @return void
	 */
	private function logReapedClaim(AbandonedFlowClaim $abandoned): void {
		$this->logger->warning(
			message: '[FlowRunWorker] Released an abandoned place claim and failed its branch',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'run' => $abandoned->runUuid,
				'stream' => $abandoned->branch,
				'transition' => $abandoned->transition,
				'owner' => $abandoned->claim->getOwner(),
			]
		);
	}//end logReapedClaim()

	/**
	 * Whether the abandoned step's `onError` policy lets the run's siblings
	 * continue. Unknown flow or step reads as the default policy, `stop`.
	 *
	 * @param FlowRun $run The run.
	 * @param string $transition The abandoned transition.
	 *
	 * @return bool True when the policy is `continue`.
	 */
	private function abandonedStepContinues(FlowRun $run, string $transition): bool {
		if ($this->flows === null || $transition === '') {
			return false;
		}

		try {
			$flow = $this->flows->resolveFlow((string)$run->getFlowId());
		} catch (Throwable $e) {
			return false;
		}

		if ($flow === null) {
			return false;
		}

		$step = (new FlowTokenRouter())->stepFor(flow: $flow, transitionName: $transition);

		return FlowErrorPolicy::continues(step: $step);
	}//end abandonedStepContinues()

	/**
	 * Whether any stream of the run other than the failed one is still live.
	 *
	 * @param string $runUuid The run.
	 * @param string $failed The failed stream id.
	 *
	 * @return bool True when a sibling can still advance.
	 */
	private function hasLiveSibling(string $runUuid, string $failed): bool {
		foreach ($this->streams->findByRun(runUuid: $runUuid) as $stream) {
			if ((string)$stream->getStreamId() !== $failed && $stream->isTerminal() === false) {
				return true;
			}
		}

		return false;
	}//end hasLiveSibling()

	/**
	 * Fail runs suspended on a signal that never arrived.
	 *
	 * The same deadlock as {@see self::expireStaleQueued()} describes, reached
	 * from the other direction. `hasActiveRun()` counts `suspended`, and
	 * `findDue()` deliberately never returns a run whose `resume_at` is null —
	 * correctly, because waking it on a clock would run it before its answer
	 * arrived. The two together mean a signal that is never delivered leaves a
	 * run nothing will ever touch, holding its flow's schedule shut behind it.
	 *
	 * An approval nobody actions is not exotic; it is the normal end of a
	 * request that stopped mattering. So the default window is generous — days,
	 * not minutes — and this is FAILED rather than resumed, because resuming
	 * would run the awaiting node as though it had been answered when the fact
	 * on the ground is that nobody answered.
	 *
	 * @param DateTime $now The current time.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-suspended-on-an-external-signal-must-be-reachable
	 */
	private function expireAbandonedSignals(DateTime $now): void {
		$days = $this->intSetting(key: 'flow_signal_wait_days', default: self::DEFAULT_SIGNAL_WAIT_DAYS);

		if ($days <= 0) {
			// An operator whose approvals legitimately take months can switch
			// the expiry off rather than have it fail live requests.
			return;
		}

		$this->failSignalRuns(now: $now, days: $days);

	}//end expireAbandonedSignals()

	/**
	 * Fail every run suspended on a signal for longer than the wait window.
	 *
	 * @param DateTime $now  The current time.
	 * @param int      $days The signal wait window in days.
	 *
	 * @return void
	 */
	private function failSignalRuns(DateTime $now, int $days): void {
		$cutoff = (clone $now)->modify('-' . $days . ' days');

		foreach ($this->mapper->findAbandonedSignals(before: $cutoff, limit: self::BATCH) as $run) {
			$run->setStatus(FlowRun::STATUS_FAILED);
			$run->setError($this->signalRunError(days: $days));
			$run->setUpdated($now);
			$this->mapper->update($run);

			$this->logFailedRun(message: '[FlowRunWorker] Failed a run whose signal never arrived', run: $run);
		}
	}//end failSignalRuns()

	/**
	 * The error a run whose signal never arrived is failed with.
	 *
	 * @param int $days The signal wait window in days.
	 *
	 * @return string
	 */
	private function signalRunError(int $days): string {
		return sprintf(
			'Abandoned: this run suspended waiting for an external signal '
			. '(an approval, a webhook, a child run) and none arrived within %d days. '
			. 'Retry it to run it again from the start.',
			$days
		);
	}//end signalRunError()

	/**
	 * Log a run the worker failed.
	 *
	 * @param string  $message The log message.
	 * @param FlowRun $run     The failed run.
	 *
	 * @return void
	 */
	private function logFailedRun(string $message, FlowRun $run): void {
		$this->logger->warning(
			message: $message,
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'run' => $run->getUuid(),
				'flow' => $run->getFlowId(),
			]
		);
	}//end logFailedRun()

	/**
	 * Release or fail runs parked waiting for somebody to allow a delegation.
	 *
	 * Runs BEFORE the queue drain, so a run released this pass starts in the same
	 * pass rather than waiting another cron period for no reason.
	 *
	 * 🔴 A MISSING COLLABORATOR IS REPORTED, NOT SKIPPED SILENTLY. If the
	 * consent sweep is absent, parked runs simply never move — and a sweep
	 * that skipped produces exactly the same output as one that found nothing to
	 * do. So the absence is only mentioned when runs are ACTUALLY parked, which
	 * is the only time it is a fault rather than noise.
	 *
	 * @param DateTime $now The current time.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	private function settleConsent(DateTime $now): void {
		if ($this->consent === null) {
			$this->reportParkedWithoutSweep();
			return;
		}

		$outcome = $this->sweepConsent(now: $now, hours: $this->consentWaitHours());
		if ($outcome === null || ($outcome['released'] === 0 && $outcome['failed'] === 0)) {
			return;
		}

		$this->logSettled(outcome: $outcome);

	}//end settleConsent()

	/**
	 * Log how many parked runs a consent sweep released and failed.
	 *
	 * @param array{released: int, failed: int} $outcome The sweep outcome.
	 *
	 * @return void
	 */
	private function logSettled(array $outcome): void {
		$this->logger->info(
			message: '[FlowRunWorker] Settled parked runs',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'released' => $outcome['released'],
				'failed' => $outcome['failed'],
			]
		);
	}//end logSettled()

	/**
	 * Report runs parked awaiting consent while no sweep can settle them.
	 *
	 * @return void
	 */
	private function reportParkedWithoutSweep(): void {
		$parked = $this->mapper->findAwaitingConsent(limit: 1);
		if ($parked !== []) {
			$this->logger->error(
				message: '[FlowRunWorker] Runs are parked awaiting consent but no delegation service is '
					. 'available to settle them; they will not move.',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end reportParkedWithoutSweep()

	/**
	 * How long a run may wait for consent; the default when unset or not positive.
	 *
	 * @return int Hours.
	 */
	private function consentWaitHours(): int {
		$hours = $this->intSetting(key: 'flow_consent_wait_hours', default: FlowConsentSweepInterface::DEFAULT_WAIT_HOURS);

		if ($hours <= 0) {
			$hours = FlowConsentSweepInterface::DEFAULT_WAIT_HOURS;
		}

		return $hours;
	}//end consentWaitHours()

	/**
	 * Run the consent sweep, logging (never throwing) a failure.
	 *
	 * @param DateTime $now   The current time.
	 * @param int      $hours How long a run may wait for consent.
	 *
	 * @return array{released: int, failed: int}|null The outcome, null when the sweep failed.
	 */
	private function sweepConsent(DateTime $now, int $hours): ?array {
		try {
			return $this->consent->sweep(now: $now, waitHours: $hours);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[FlowRunWorker] The consent sweep failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return null;
		}
	}//end sweepConsent()

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
	 * @spec openspec/specs/flow-queue-fairness/spec.md
	 */
	private function expireStaleQueued(DateTime $now): void {
		$hours = $this->intSetting(key: 'flow_run_queued_ttl_hours', default: self::DEFAULT_QUEUED_TTL_HOURS);

		if ($hours <= 0) {
			return;
		}

		try {
			$expired = $this->mapper->expireQueuedBefore(expiry: $this->queuedExpiry(now: $now, hours: $hours));
			$this->logExpiredQueued(expired: $expired, hours: $hours);
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
	 * The expiry of runs queued for longer than the TTL, with the reason they get.
	 *
	 * @param DateTime $now   The current time.
	 * @param int      $hours The queued TTL in hours.
	 *
	 * @return FlowRunQueueExpiry
	 */
	private function queuedExpiry(DateTime $now, int $hours): FlowRunQueueExpiry {
		$reason = sprintf(
			'Expired: this run waited in the queue for more than %d hours, so whatever it was '
			. 'meant to do now is no longer what it would do. Retry it to run it again from the start.',
			$hours
		);

		$cutoff = (clone $now)->modify('-' . $hours . ' hours');
		return new FlowRunQueueExpiry(before: $cutoff, reason: $reason, limit: self::EXPIRE_BATCH);
	}//end queuedExpiry()

	/**
	 * Log the queued runs an expiry pass expired, if any.
	 *
	 * @param array<int, mixed> $expired The expired runs.
	 * @param int               $hours   The queued TTL in hours.
	 *
	 * @return void
	 */
	private function logExpiredQueued(array $expired, int $hours): void {
		if ($expired !== []) {
			$this->logger->warning(
				message: '[FlowRunWorker] Expired queued runs that waited past their TTL',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'expired' => count($expired),
					'ttlHours' => $hours,
				]
			);
		}
	}//end logExpiredQueued()

	/**
	 * Advance one run, never letting its failure stop the batch.
	 *
	 * The body lives in {@see FlowRunAdvancerInterface} so a synchronous run executes
	 * through exactly this path rather than a parallel implementation that
	 * could drift from it.
	 *
	 * @param FlowRun $run The run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-runs/spec.md
	 */
	private function advance(FlowRun $run): void {
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
	 * @spec openspec/specs/flow-runs/spec.md
	 */
	private function prune(DateTime $now): void {
		$days = $this->intSetting(key: 'flow_run_retention_days', default: self::DEFAULT_RETENTION_DAYS);

		if ($days <= 0) {
			return;
		}

		try {
			$this->pruneRunsOlderThan(now: $now, days: $days);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[FlowRunWorker] Retention pass failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}//end try

	}//end prune()

	/**
	 * Delete terminal runs older than the retention window, with their stream
	 * and claim rows.
	 *
	 * @param DateTime $now  The current time.
	 * @param int      $days The retention window in days.
	 *
	 * @return void
	 */
	private function pruneRunsOlderThan(DateTime $now, int $days): void {
		$before = (clone $now)->modify(sprintf('-%d days', $days));
		$deleted = $this->mapper->pruneBefore(before: $before);
		// Stream and claim rows go with their runs.
		$this->streams?->deleteOrphans();
		$this->claims?->deleteOrphans();
		if ($deleted > 0) {
			$this->logger->info(
				message: '[FlowRunWorker] Pruned old flow runs',
				context: ['file' => __FILE__, 'line' => __LINE__, 'deleted' => $deleted, 'olderThanDays' => $days]
			);
		}
	}//end pruneRunsOlderThan()

	/**
	 * Read an integer tuning setting of the worker.
	 *
	 * @param string $key     The app-config key.
	 * @param int    $default The value when the key is unset.
	 *
	 * @return int
	 */
	private function intSetting(string $key, int $default): int {
		return (int)$this->appConfig->getValueString(
			'openregister',
			$key,
			(string)$default
		);
	}//end intSetting()

	/**
	 * Release object locks whose holding run is terminal, gone or expired.
	 *
	 * Release layer 2. Layer 1, the `FlowRunTerminalEvent` listener, covers
	 * every run that reaches a terminal status, and the reapers above do
	 * eventually terminate a run whose worker was killed. What neither covers
	 * is a release that itself failed, or a run row deleted out from under an
	 * outstanding lock. This collects those.
	 *
	 * It reads the run-lock registry, not the objects: locks live in the
	 * `_locked` column of magic tables, and an instance-wide scan over those
	 * costs seconds just to PLAN. Never throws: a sweep that can fail the
	 * whole worker pass would take the queue down with it, and the lock TTL is
	 * still the backstop underneath.
	 *
	 * @param DateTime $now The pass clock.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-every-lock-a-run-holds-is-released-when-the-run-ends
	 */
	private function sweepOrphanedLocks(DateTime $now): void {
		if ($this->locks === null) {
			return;
		}

		try {
			$released = $this->locks->sweepOrphaned(now: $now, limit: self::BATCH);
			if ($released > 0) {
				$this->logger->info(
					sprintf('[FlowRunWorker] Swept %d orphaned object lock(s).', $released)
				);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[FlowRunWorker] Orphaned-lock sweep failed: ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try
	}//end sweepOrphanedLocks()
}//end class
