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
 * flow seen for the first time has no last-fire, so it fires once on the next
 * tick and then follows its cron.
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
class FlowScheduleService {

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
	 * @param FlowRunService $runs Queues a run for a due flow.
	 * @param IAppConfig $appConfig Remembers last-fire.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly FlowLocator $resolvers,
		private readonly FlowRunService $runs,
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
			$cron = $this->scheduleOf(candidate: $candidate);
			if ($cron === null) {
				continue;
			}

			// The registry guarantees a non-empty id — a candidate without one
			// is dropped there, so every id here is one a resolver can load.
			$uuid = $candidate['id'];
			if ($this->isDue(cron: $cron, uuid: $uuid, now: $now) === false) {
				continue;
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
				continue;
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
			try {
				$this->fire(uuid: $uuid, now: $now);
			} catch (FlowUnattributed $e) {
				$this->reportUnattributed(uuid: $uuid, refusal: $e);
				continue;
			} catch (DelegationRefused $e) {
				// A SEPARATE catch, not folded into the one above. Both stop the
				// flow, and they mean opposite things: "nobody was named" needs
				// the definition edited, "the delegation is no longer live" needs
				// a grant re-issued and will start working again on its own when
				// one is. Reporting them in the same words would send the reader
				// to the wrong one every second time.
				$this->reportRefusedDelegation(uuid: $uuid, refusal: $e);
				continue;
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
				continue;
			}

			$fired[] = $uuid;
		}//end foreach

		return $fired;
	}//end fireDueFlows()

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
		if (($candidate['enabled'] ?? false) !== true) {
			return null;
		}

		if ((string)($candidate['trigger'] ?? '') !== 'schedule') {
			return null;
		}

		$cron = trim((string)($candidate['cron'] ?? ''));
		if ($cron === '' || CronExpression::isValidExpression($cron) === false) {
			return null;
		}

		return $cron;
	}//end scheduleOf()

	/**
	 * Whether a cron occurrence has passed since the flow last fired.
	 *
	 * A flow with no recorded last-fire is treated as never run, so its next
	 * occurrence after the epoch is in the past and it fires once on this tick.
	 *
	 * @param string $cron The cron expression.
	 * @param string $uuid The flow uuid.
	 * @param DateTimeInterface $now The moment to evaluate against.
	 *
	 * @return boolean Whether the flow is due.
	 */
	private function isDue(string $cron, string $uuid, DateTimeInterface $now): bool {
		$lastRaw = $this->appConfig->getValueString(self::APP_ID, self::LAST_FIRE_KEY . $uuid, '');

		// A flow never fired has an epoch baseline, so its next occurrence is in
		// the past and it fires once on this tick.
		$lastStamp = '@0';
		if ($lastRaw !== '') {
			$lastStamp = $lastRaw;
		}

		try {
			$next = (new CronExpression($cron))->getNextRunDate(new DateTimeImmutable($lastStamp));
		} catch (Throwable $e) {
			return false;
		}

		return $next <= $now;
	}//end isDue()

	/**
	 * Queue a run for a due flow and record that it fired.
	 *
	 * @param string $uuid The flow uuid.
	 * @param DateTimeInterface $now The moment it fired.
	 * @return void
	 *
	 * @throws FlowUnattributed When the schedule trigger names no acting identity.
	 * @throws FlowDeadEnd     When the flow has a node that cannot pass its token on.
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
		$this->runs->queue(
			flowId: $uuid,
			subject: [],
			trigger: 'schedule',
			context: ['payload' => ['flowId' => $uuid, 'scheduledAt' => $now->format(DATE_ATOM)]]
		);

		// Remember the fire time so the next occurrence is measured from here,
		// not from the epoch — otherwise the flow would fire every tick.
		$this->appConfig->setValueString(self::APP_ID, self::LAST_FIRE_KEY . $uuid, $now->format(DATE_ATOM));

	}//end fire()
}//end class
