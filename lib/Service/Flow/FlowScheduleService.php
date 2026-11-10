<?php

/**
 * Fires flows that are due on a schedule — n8n's Schedule / Cron trigger.
 *
 * The event triggers (object, file, user, share, tag) run a flow when something
 * happens. A schedule runs it when a time arrives. There is no event to listen
 * for, so this is driven by a worker ({@see FlowScheduleWorker}) that ticks
 * periodically and asks this service which scheduled flows are now due.
 *
 * A scheduled flow is any flow whose `trigger` is `schedule` and which carries a
 * `cron` expression. Which flows exist is not this service's business: it asks
 * the resolver registry, the same way the event triggers do. It used to read one
 * hard-coded store instead — the `flow_register`/`flow_schema` pair — so a flow
 * owned by a leaf app (hermiq's agentflows) was invisible to the scheduler and
 * could never fire, no matter how correct its cron was. An event-triggered flow
 * in that same store fired fine, which made the gap look like a flow-authoring
 * problem rather than a missing enumeration.
 *
 * "Due" is decided per flow: the last time it fired is remembered (in app
 * config, keyed by the flow's id — so the flow object itself is never
 * rewritten), and a flow is due when a cron occurrence has passed since then. A
 * flow that has never fired is measured from when it was last saved, so it
 * fires on its first occurrence after that save and never earlier.
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
 * @spec openspec/changes/or-flow-scheduled-trigger/specs/flow-scheduled-trigger/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Service\Delegation\DelegationRefused;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues runs for the scheduled flows that are due.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
class FlowScheduleService implements FlowScheduleServiceInterface {

	/**
	 * The app id its config lives under.
	 */
	private const APP_ID = 'openregister';

	/**
	 * Config-key prefix for a flow's last-fire timestamp (per flow uuid).
	 */
	private const LAST_FIRE_KEY = 'flow_sched_last_';

	/**
	 * Constructor.
	 *
	 * @param FlowLocator $resolvers Lists the scheduled flows every app owns.
	 * @param FlowRunServiceInterface $runs Queues a run for a due flow.
	 * @param IAppConfig $appConfig Remembers last-fire.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly FlowLocator $resolvers,
		private readonly FlowRunServiceInterface $runs,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Queue a run for every scheduled flow that is due at $now.
	 *
	 * @param DateTimeInterface $now The moment to evaluate against.
	 *
	 * @return array<int, string> The uuids of the flows that fired.
	 *
	 * @spec openspec/changes/or-flow-scheduled-trigger/specs/flow-scheduled-trigger/spec.md
	 */
	public function fireDueFlows(DateTimeInterface $now): array {
		try {
			$candidates = $this->resolvers->scheduledFlows();
		} catch (Throwable $e) {
			// No resolver could be asked — nothing scheduled.
			return [];
		}

		$fired = [];
		foreach ($candidates as $candidate) {
			if ($this->fireIfDue(candidate: $candidate, now: $now) === true) {
				$fired[] = $candidate['id'];
			}
		}

		return $fired;
	}//end fireDueFlows()

	/**
	 * Fire one candidate when it is a valid schedule, due, and not still running.
	 *
	 * @param array<string, mixed> $candidate The scheduled-flow candidate.
	 * @param DateTimeInterface    $now       The tick.
	 *
	 * @return bool True when the flow fired.
	 *
	 * @spec openspec/changes/or-flow-scheduled-trigger/specs/flow-scheduled-trigger/spec.md
	 */
	private function fireIfDue(array $candidate, DateTimeInterface $now): bool {
		$cron = $this->scheduleOf(candidate: $candidate);
		if ($cron === null) {
			return false;
		}

		// The registry guarantees a non-empty id — a candidate without one
		// is dropped there, so every id here is one a resolver can load.
		$uuid = $candidate['id'];
		$baseline = $this->baselineOf(candidate: $candidate, now: $now);
		if ($baseline === null) {
			return false;
		}

		$next = $this->nextRunAfter(cron: $cron, baseline: $baseline);
		if ($next === null || $next > $now) {
			return false;
		}

		// SINGLETON: never overlap a flow with itself.
		//
		// A scheduled flow can be slower than its own interval — a pipeline
		// poll on a five-minute cron easily is — and without this guard
		// tick N+1 starts while tick N is still going. Two runs of one flow
		// then race on whatever that flow is bookkeeping, which is exactly
		// the failure openregister#2212 documented at the object layer.
		//
		// This is the property that makes the shell orchestrator being
		// replaced safe today: `hydra-supervisor.sh` holds an exclusive
		// flock, so exactly one supervisor exists and its check-then-write
		// slot bookkeeping never races because nothing runs beside it. A
		// scheduled flow gets the same guarantee here, and with it most
		// flow state needs no locking at all.
		//
		// The last-fire marker is deliberately NOT advanced when we skip:
		// the flow stays due, so it starts on the first tick after the
		// previous run finishes rather than waiting a whole extra interval.
		if ($this->runs->hasActiveRun(flowId: $uuid) === true) {
			$this->logger->info(
				message: '[FlowSchedule] Skipping due flow — previous run has not finished',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'flow' => $uuid,
				]
			);
			return false;
		}

		// The candidate's `owner` is deliberately NOT passed on as the run's
		// identity any more. It is the DEFINITION's owner — who may edit this
		// flow — and using it here made a scheduled run execute as whoever
		// authored the flow, which nobody consented to (ADR-099). The
		// identity now comes from the schedule trigger node's `runAs`,
		// resolved by FlowRunAttribution, and its absence is a refusal.
		//
		// Per flow, not around the loop. A refused flow must not stop the
		// ones after it from firing — one broken definition silently
		// disabling every later schedule is a far bigger outage than the
		// one being reported, and it would present as "cron stopped
		// working" rather than as a fault in a specific flow.
		return $this->tryFire(uuid: $uuid, now: $now);
	}//end fireIfDue()

	/**
	 * Fire one due flow; a flow that refuses is reported and skipped, never fatal to the tick.
	 *
	 * @param string            $uuid The flow id.
	 * @param DateTimeInterface $now  The tick.
	 *
	 * @return bool True when it fired, false when it was refused.
	 *
	 * @spec openspec/changes/or-flow-scheduled-trigger/specs/flow-scheduled-trigger/spec.md
	 */
	private function tryFire(string $uuid, DateTimeInterface $now): bool {
		try {
			$this->fire(uuid: $uuid, now: $now);
		} catch (FlowUnattributed $e) {
			$this->reportUnattributed(uuid: $uuid, refusal: $e);
			return false;
		} catch (DelegationRefused $e) {
			// A SEPARATE catch, not folded into the one above. Both stop the
			// flow, and they mean opposite things: "nobody was named" needs
			// the definition edited, "the delegation is no longer live" needs
			// a grant re-issued and will start working again on its own when
			// one is. Reporting them in the same words would send the reader
			// to the wrong one every second time.
			$this->reportRefusedDelegation(uuid: $uuid, refusal: $e);
			return false;
		} catch (FlowDeadEnd $e) {
			// The refusal already recorded status/status_message on the
			// flow itself, so the author can see why without reading logs.
			$this->logger->warning(
				message: '[FlowSchedule] Skipping due flow — it is not runnable: ' . $e->getMessage(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'flow' => $uuid,
					'nodes' => $e->getNodeIds(),
				]
			);
			return false;
		}

		return true;
	}//end tryFire()

	/**
	 * Report a due flow that named no acting identity.
	 *
	 * The refusal has already recorded status/status_message on the flow and
	 * switched the schedule off, so this only writes the operator-facing log
	 * line. Extracted from the sweep so `fireDueFlows()` stays inside its length
	 * budget rather than growing a third inline catch body.
	 *
	 * @param string           $uuid    The flow that was refused.
	 * @param FlowUnattributed $refusal The refusal.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegated-identity/spec.md
	 */
	private function reportUnattributed(string $uuid, FlowUnattributed $refusal): void {
		$this->logger->warning(
			message: '[FlowSchedule] Disabled a due flow — it names no acting identity: ' . $refusal->getMessage(),
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'flow' => $uuid,
				'trigger' => $refusal->getTrigger(),
			]
		);

	}//end reportUnattributed()

	/**
	 * Report a due flow whose delegation is no longer live.
	 *
	 * Extracted for the same reason `reportUnattributed()` was: `fireDueFlows()`
	 * has a length budget, and a third inline catch body was what pushed it over.
	 *
	 * The wording is deliberately NOT the unattributed wording. This flow named
	 * somebody and the naming is what stopped being valid, so the fix is a grant
	 * — and unlike the unattributed case it will start firing again on its own
	 * once one exists, with nobody editing anything.
	 *
	 * @param string            $uuid    The flow that was refused.
	 * @param DelegationRefused $refusal The refusal.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	private function reportRefusedDelegation(string $uuid, DelegationRefused $refusal): void {
		$this->logger->warning(
			message: '[FlowSchedule] Skipping due flow — its delegation is no longer live: ' . $refusal->getMessage(),
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'flow' => $uuid,
				'principal' => $refusal->getPrincipal(),
				'actingAs' => $refusal->getActingAs(),
				'reason' => $refusal->getVerdict()->reason,
			]
		);

	}//end reportRefusedDelegation()

	/**
	 * The cron expression of a candidate that is an enabled schedule, or null.
	 *
	 * Every gate a scheduled flow has to pass is here, in the scheduler, and
	 * NOT in the app that contributed the candidate. A source is trusted to say
	 * which flows it owns; it is not trusted to decide which of them may run.
	 * That matters most for `enabled`: a leaf app that got its own filtering
	 * wrong would otherwise be able to run a flow an admin had switched off.
	 *
	 * @param array $candidate The candidate flow reported by a source, shaped
	 *                         `{id, enabled, trigger, cron, owner}`.
	 *
	 * @return string|null The cron expression, or null when the candidate is not
	 *                     a valid enabled schedule.
	 */
	private function scheduleOf(array $candidate): ?string {
		if ($this->isScheduleCandidate(candidate: $candidate) === false) {
			return null;
		}

		$cron = trim((string)($candidate['cron'] ?? ''));
		if ($cron === '' || CronExpression::isValidExpression($cron) === false) {
			return null;
		}

		return $cron;
	}//end scheduleOf()

	/**
	 * Whether a candidate is an enabled flow on the schedule trigger.
	 *
	 * @param array<string, mixed> $candidate The candidate.
	 *
	 * @return bool True for an enabled schedule.
	 *
	 * @spec openspec/changes/or-flow-scheduled-trigger/specs/flow-scheduled-trigger/spec.md
	 */
	private function isScheduleCandidate(array $candidate): bool {
		return ($candidate['enabled'] ?? false) === true && (string)($candidate['trigger'] ?? '') === 'schedule';
	}//end isScheduleCandidate()

	/**
	 * The first cron occurrence after a flow's baseline, or null when the cron or the baseline cannot be read.
	 *
	 * @param string $cron     The cron expression.
	 * @param string $baseline The last fire, or the moment the flow was scheduled from.
	 *
	 * @return DateTimeInterface|null The next run date.
	 */
	private function nextRunAfter(string $cron, string $baseline): ?DateTimeInterface {
		try {
			return (new CronExpression($cron))->getNextRunDate(new DateTimeImmutable($baseline));
		} catch (Throwable $e) {
			return null;
		}
	}//end nextRunAfter()

	/**
	 * The moment a flow's next occurrence is measured from.
	 *
	 * The last fire when there is one, otherwise the flow's last save. A flow
	 * with neither starts its clock at `$now`: the baseline is recorded and the
	 * flow is not due on this tick. Never the epoch: measured from there, every
	 * new schedule is due on the first tick that sees it, whatever its cron says.
	 *
	 * @param array $candidate The candidate flow; `since` is when it was last saved.
	 * @param DateTimeInterface $now The moment to evaluate against.
	 *
	 * @return string|null The baseline, or null when the clock starts now.
	 */
	private function baselineOf(array $candidate, DateTimeInterface $now): ?string {
		$uuid = (string)$candidate['id'];
		$since = (string)($candidate['since'] ?? '');
		$lastFire = $this->appConfig->getValueString(self::APP_ID, self::LAST_FIRE_KEY . $uuid, '');
		if ($lastFire !== '') {
			return $lastFire;
		}

		if ($since !== '') {
			return $since;
		}

		$this->appConfig->setValueString(self::APP_ID, self::LAST_FIRE_KEY . $uuid, $now->format(DATE_ATOM));

		return null;
	}//end baselineOf()

	/**
	 * Queue a run for a due flow and record that it fired.
	 *
	 * @param string $uuid The flow uuid.
	 * @param DateTimeInterface $now The moment it fired.
	 * @return void
	 *
	 * @throws FlowUnattributed   When the schedule trigger names no acting identity.
	 * @throws FlowDeadEnd       When the flow has a node that cannot pass its token on.
	 * @throws DelegationRefused When the trigger asserts a delegation that is no
	 *                           longer live. Declared SEPARATELY from
	 *                           FlowUnattributed on purpose — the sweep catches
	 *                           them apart because they mean opposite things, and
	 *                           an undeclared throw here makes that catch look
	 *                           dead to static analysis and to the next reader.
	 */
	private function fire(string $uuid, DateTimeInterface $now): void {
		// No `user` is passed, and that is the fix rather than an omission.
		//
		// A scheduled run has no session, so this used to hand `flow.owner` down
		// as the identity — "the person who created and enabled it". That solved
		// the ownerless-run problem (or#2158: ObjectWriteNode answering "this
		// flow run has no owner", which made every natively-scheduled flow
		// silently incapable of writing anything) by creating a quieter one:
		// authoring a flow became standing consent to unattended execution as
		// its author, under whatever triggers anyone later added.
		//
		// Passing nothing lets FlowRunAttribution read the identity off the
		// SCHEDULE TRIGGER NODE, which is where a run begins and the only place
		// an author states it deliberately. When the node names nobody the queue
		// refuses, and the refusal switches the schedule off with a reason
		// attached instead of retrying every tick forever (ADR-099).
		$this->runs->queueRun(
			flowId: $uuid,
			options: new FlowRunQueueOptions(
				subject: [],
				trigger: 'schedule',
				context: ['payload' => ['flowId' => $uuid, 'scheduledAt' => $now->format(DATE_ATOM)]]
			)
		);

		// Remember the fire time so the next occurrence is measured from here,
		// not from the epoch — otherwise the flow would fire every tick.
		$this->appConfig->setValueString(self::APP_ID, self::LAST_FIRE_KEY . $uuid, $now->format(DATE_ATOM));

	}//end fire()
}//end class
