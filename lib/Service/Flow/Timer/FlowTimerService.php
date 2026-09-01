<?php

/**
 * The business-timer lifecycle: arm, suspend, resume, extend, supersede,
 * cancel, fire — and the ONE recomputation of `fire_at` and `next_rung_at`.
 *
 * The store holds a budget and a suspension ledger, not a target instant
 * (design D-2): `suspend()` moves the running segment into `consumed_value`
 * and NULLs `running_since` and `fire_at`; `resume()` re-projects from the
 * resume instant across the calendar. Remaining time is answerable at any
 * moment, suspended included, as `budget - consumed - (running_since ?
 * measure(running_since, now) : 0)`. Every mutating operation ends in the
 * private {@see recompute()}, and nothing else writes the two derived columns.
 *
 * The task's `due_at`/`expires_at` are a PROJECTION maintained here inside the
 * same operation (design D-10): earliest open `due` timer, earliest open
 * `expiry` timer. Overdue is never written anywhere: {@see describe()} derives
 * it from the clock, and a suspended timer has no `fire_at` to be overdue by.
 *
 * The fence (design D-1): no branch in this class encodes what a specific
 * app's deadline MEANS. Every branch is time arithmetic, calendar resolution,
 * timer state or concurrency.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-suspended-deadline-holds-elapsed-time-not-a-moment
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerEvent;
use OCA\OpenRegister\Db\FlowTimerEventMapper;
use OCA\OpenRegister\Db\FlowTimerFire;
use OCA\OpenRegister\Db\FlowTimerFireMapper;
use OCA\OpenRegister\Db\FlowTimerMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCA\OpenRegister\Exception\FlowTimerStateException;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Service\Task\TaskService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Arm, suspend, resume, extend, supersede, cancel, fire.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One method per lifecycle
 * operation plus the derived reads; every operation is a distinct decision
 * with its own refusal rules, and splitting them across classes would
 * scatter the one recompute() they all end in.
 * @SuppressWarnings(PHPMD.TooManyMethods) The private helpers exist so every
 * operation shares ONE recompute, ONE projection and ONE event writer.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The service owns the three
 * timer mappers, the calendar, the calculator, the ladder, the task
 * projection and the task service: that is the lifecycle, not a design choice.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) Scales with the operation
 * count; the docblocks record the refusal rules the spec requires.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The sum of the operations'
 * refusal branches; each method is small.
 * @SuppressWarnings(PHPMD.StaticAccess) Uuid::v4() is the codebase's uuid
 * factory, as in TaskBuilder, and DateTime::createFromInterface is PHP's own.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Eleven constructor
 * dependencies: three timer mappers, the task table and service, the
 * calendar, the calculator, the ladder, the connection, the dispatcher and
 * the logger. Each is a distinct collaborator the lifecycle needs; bundling
 * them into a holder object would hide the coupling, not remove it.
 */
class FlowTimerService {

	/**
	 * The actor recorded for sweep-originated writes.
	 *
	 * @var string
	 */
	public const ACTOR_SWEEP = 'flow-timer-sweep';

	/**
	 * Constructor.
	 *
	 * @param FlowTimerMapper $timers The timer table.
	 * @param FlowTimerFireMapper $fires The rung dedup ledger.
	 * @param FlowTimerEventMapper $events The append-only history.
	 * @param TaskMapper $tasks The task table, for the projection and the subject read.
	 * @param TaskService $taskService Applies enforcing outcomes as named task actions.
	 * @param WorkingCalendarService $calendars Calendar resolution.
	 * @param SlaCalculator $calculator Business-time arithmetic.
	 * @param EscalationLadderService $ladder Rung resolution and validation.
	 * @param IDBConnection $db Holds the one transaction per operation.
	 * @param IEventDispatcher $dispatcher Raises the fired transitions.
	 * @param LoggerInterface $logger Diagnostics.
	 */
	public function __construct(
		private readonly FlowTimerMapper $timers,
		private readonly FlowTimerFireMapper $fires,
		private readonly FlowTimerEventMapper $events,
		private readonly TaskMapper $tasks,
		private readonly TaskService $taskService,
		private readonly WorkingCalendarService $calendars,
		private readonly SlaCalculator $calculator,
		private readonly EscalationLadderService $ladder,
		private readonly IDBConnection $db,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Arm a timer.
	 *
	 * Config keys: `subjectType`, `subjectUuid`, `organisation`, `runUuid`,
	 * `nodeId`, `appId`, `title`, `metadata`, `purpose` (due|expiry),
	 * `legalEffect` (none|servicenorm|wettelijk), `onExpiry`, `sla` {value,
	 * unit}, `calendar`, `ladder`, `escalationRules`, `extensionMax`, and the
	 * anchor: `anchorEvent`, `anchorEventAt` (the instant the named event
	 * happened; defaults to now), `anchorOffset`, `anchorOffsetUnit`.
	 *
	 * Refused, naming the constraint: an unknown calendar (never downgraded),
	 * an `onExpiry` on any timer whose `legalEffect` is not `wettelijk` or
	 * whose purpose is not `expiry`, an SLA outside the vocabulary, and an
	 * escalation rule whose preBreach offset resolves before the anchor.
	 *
	 * @param array<string, mixed> $config The timer configuration.
	 * @param string|null $actor The arming identity.
	 * @param DateTimeInterface|null $now The clock; null means the real clock.
	 *
	 * @return FlowTimer The armed timer.
	 *
	 * @throws FlowTimerValidationException On any refused value.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-deadline-declares-its-legal-effect-and-only-a-legal-one-enforces
	 */
	public function arm(array $config, ?string $actor, ?DateTimeInterface $now = null): FlowTimer {
		$now = $this->instant(now: $now);
		$timer = $this->build(config: $config, actor: $actor, now: $now);
		$calendar = $this->calendars->resolve(calendarSlug: $timer->getCalendarSlug(), organisation: $timer->getOrganisation());

		// Arm-time validation is AUTHORITATIVE: resolve the ladder and check
		// every preBreach rung on the timeline before anything is written.
		$this->recompute(timer: $timer, calendar: $calendar, firedKeys: []);
		$this->ladder->validateAgainstTimeline(
			rungs: $this->ladder->resolveLadder(timer: $timer)['rungs'],
			anchorAt: $timer->getAnchorAt(),
			fireAt: $timer->getFireAt(),
			calendar: $calendar
		);

		return $this->transactional(
			mutation: function () use ($timer, $actor, $now): FlowTimer {
				$persisted = $this->timers->insert($timer);
				$this->record(
					timer: $persisted,
					type: FlowTimerEvent::TYPE_ARMED,
					actor: $actor,
					reason: sprintf('Armed from anchor %s.', $persisted->getAnchorAt()->format('c')),
					priorFireAt: null,
					newFireAt: $persisted->getFireAt(),
					basis: (string)($persisted->getMetadata()['basis'] ?? ''),
					moment: $now
				);
				$this->project(timer: $persisted);

				return $persisted;
			}
		);
	}//end arm()

	/**
	 * Suspend a running timer (opschorting).
	 *
	 * `consumed_value += measure(running_since, now, budget_unit)`;
	 * `running_since`, `fire_at` and `next_rung_at` go NULL; `suspended_since`
	 * is set. Business days spent suspended over a weekend add nothing to
	 * consumption by construction. Evidenced with actor, moment, reason and basis.
	 *
	 * @param string $uuid The timer uuid.
	 * @param string $reason Why the term is suspended (required).
	 * @param DateTimeInterface|null $until The expected end, display-only.
	 * @param string|null $actor The acting identity.
	 * @param string|null $basis The legal ground, e.g. `Awb 4:15`.
	 * @param DateTimeInterface|null $now The clock.
	 *
	 * @return FlowTimer The suspended timer.
	 *
	 * @throws FlowTimerStateException When the timer is not armed.
	 * @throws FlowTimerValidationException When the reason is empty.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-suspended-deadline-holds-elapsed-time-not-a-moment
	 */
	public function suspend(
		string $uuid,
		string $reason,
		?DateTimeInterface $until,
		?string $actor,
		?string $basis = null,
		?DateTimeInterface $now = null,
	): FlowTimer {
		$now = $this->instant(now: $now);
		if (trim($reason) === '') {
			throw new FlowTimerValidationException(
				message: 'Suspending a term requires a non-empty reason: the suspension is itself a decision that has to be evidenced.'
			);
		}

		$timer = $this->timers->findByUuid(uuid: $uuid);
		if ($timer->getState() !== FlowTimer::STATE_ARMED) {
			throw new FlowTimerStateException(
				message: sprintf("Timer '%s' cannot be suspended: its state is '%s', not 'armed'.", $uuid, (string)$timer->getState())
			);
		}

		$calendar = $this->calendars->resolve(calendarSlug: $timer->getCalendarSlug(), organisation: $timer->getOrganisation());
		$priorFireAt = $timer->getFireAt();
		$consumed = $this->calculator->measure(
			from: $timer->getRunningSince(),
			to: $now,
			unit: (string)$timer->getBudgetUnit(),
			calendar: $calendar
		);

		return $this->transactional(
			mutation: function () use ($timer, $calendar, $consumed, $reason, $until, $actor, $basis, $now, $priorFireAt): FlowTimer {
				$timer->setConsumedValue((float)$timer->getConsumedValue() + max(0.0, $consumed));
				$timer->setRunningSince(null);
				$timer->setSuspendedSince($this->mutable(value: $now));
				$timer->setSuspendReason($reason);
				$timer->setState(FlowTimer::STATE_SUSPENDED);
				$this->recompute(timer: $timer, calendar: $calendar, firedKeys: $this->firedKeys(timerUuid: (string)$timer->getUuid()));
				$persisted = $this->timers->update($timer);

				$untilNote = '';
				if ($until !== null) {
					$untilNote = sprintf(' Expected until %s.', $until->format('c'));
				}

				$this->record(
					timer: $persisted,
					type: FlowTimerEvent::TYPE_SUSPENDED,
					actor: $actor,
					reason: $reason . $untilNote,
					priorFireAt: $priorFireAt,
					newFireAt: null,
					basis: (string)$basis,
					moment: $now
				);
				$this->project(timer: $persisted, suspendedUntil: $until);

				return $persisted;
			}
		);
	}//end suspend()

	/**
	 * Resume a suspended timer: `running_since = now` and the fire moment is
	 * re-projected from now across the calendar by the UNCONSUMED remainder.
	 *
	 * @param string $uuid The timer uuid.
	 * @param string|null $reason Why the term resumes.
	 * @param string|null $actor The acting identity.
	 * @param DateTimeInterface|null $now The clock.
	 *
	 * @return FlowTimer The running timer.
	 *
	 * @throws FlowTimerStateException When the timer is not suspended.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-suspended-deadline-holds-elapsed-time-not-a-moment
	 */
	public function resume(string $uuid, ?string $reason, ?string $actor, ?DateTimeInterface $now = null): FlowTimer {
		$now = $this->instant(now: $now);
		$timer = $this->timers->findByUuid(uuid: $uuid);
		if ($timer->getState() !== FlowTimer::STATE_SUSPENDED) {
			throw new FlowTimerStateException(
				message: sprintf("Timer '%s' cannot be resumed: its state is '%s', not 'suspended'.", $uuid, (string)$timer->getState())
			);
		}

		$calendar = $this->calendars->resolve(calendarSlug: $timer->getCalendarSlug(), organisation: $timer->getOrganisation());

		return $this->transactional(
			mutation: function () use ($timer, $calendar, $reason, $actor, $now): FlowTimer {
				$suspendedSince = $timer->getSuspendedSince();
				if ($suspendedSince !== null) {
					$timer->setSuspendedTotalSeconds(
						(int)$timer->getSuspendedTotalSeconds() + max(0, ($now->getTimestamp() - $suspendedSince->getTimestamp()))
					);
				}

				$timer->setRunningSince($this->mutable(value: $now));
				$timer->setSuspendedSince(null);
				$timer->setSuspendReason(null);
				$timer->setState(FlowTimer::STATE_ARMED);
				$this->recompute(timer: $timer, calendar: $calendar, firedKeys: $this->firedKeys(timerUuid: (string)$timer->getUuid()));
				$persisted = $this->timers->update($timer);
				$this->record(
					timer: $persisted,
					type: FlowTimerEvent::TYPE_RESUMED,
					actor: $actor,
					reason: (string)$reason,
					priorFireAt: null,
					newFireAt: $persisted->getFireAt(),
					basis: '',
					moment: $now
				);
				$this->project(timer: $persisted);

				return $persisted;
			}
		);
	}//end resume()

	/**
	 * Extend a timer (verdaging) through the STANDARD path, bounded by `extension_max`.
	 *
	 * @param string $uuid The timer uuid.
	 * @param int $amount The extension amount.
	 * @param string $unit Its unit; converted into the budget unit when they differ.
	 * @param string $rationale Why (required, non-empty).
	 * @param string|null $actor The acting identity.
	 * @param DateTimeInterface|null $now The clock.
	 *
	 * @return FlowTimer The extended timer.
	 *
	 * @throws FlowTimerStateException When fired/expired, or when the bound is reached (naming it).
	 * @throws FlowTimerValidationException When the rationale is empty or the amount is not positive.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-extension-is-bounded-and-may-only-be-granted-before-expiry
	 */
	public function extend(
		string $uuid,
		int $amount,
		string $unit,
		string $rationale,
		?string $actor,
		?DateTimeInterface $now = null,
	): FlowTimer {
		$now = $this->instant(now: $now);
		$timer = $this->timers->findByUuid(uuid: $uuid);
		$this->assertExtendable(timer: $timer, now: $now);

		if ((int)$timer->getExtensionCount() >= (int)$timer->getExtensionMax()) {
			throw new FlowTimerStateException(
				message: sprintf(
					"Timer '%s' has reached its extension bound of %d; a further extension requires the separately authorized override.",
					$uuid,
					(int)$timer->getExtensionMax()
				)
			);
		}

		return $this->applyExtension(timer: $timer, amount: $amount, unit: $unit, rationale: $rationale, actor: $actor, now: $now, basis: 'Awb 4:14');
	}//end extend()

	/**
	 * Extend BEYOND the bound: a distinct, separately authorized operation,
	 * recorded as an override. Not a flag on {@see extend()}, because the flag
	 * becomes the default caller within a release.
	 *
	 * @param string $uuid The timer uuid.
	 * @param int $amount The extension amount.
	 * @param string $unit Its unit.
	 * @param string $rationale Why (required, non-empty).
	 * @param string $actor The authorizing identity (required).
	 * @param DateTimeInterface|null $now The clock.
	 *
	 * @return FlowTimer The extended timer.
	 *
	 * @throws FlowTimerStateException When fired/expired.
	 * @throws FlowTimerValidationException When the rationale or actor is empty.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-extension-is-bounded-and-may-only-be-granted-before-expiry
	 */
	public function extendWithOverride(
		string $uuid,
		int $amount,
		string $unit,
		string $rationale,
		string $actor,
		?DateTimeInterface $now = null,
	): FlowTimer {
		$now = $this->instant(now: $now);
		if (trim($actor) === '') {
			throw new FlowTimerValidationException(message: 'An extension override requires an authorizing identity.');
		}

		$timer = $this->timers->findByUuid(uuid: $uuid);
		$this->assertExtendable(timer: $timer, now: $now);

		return $this->applyExtension(timer: $timer, amount: $amount, unit: $unit, rationale: $rationale, actor: $actor, now: $now, basis: 'override');
	}//end extendWithOverride()

	/**
	 * The anchoring event moved: mark this timer superseded and arm a
	 * successor from the new anchor, carrying `consumed_value` forward and
	 * inheriting a fire row for every rung still in the past under the NEW
	 * deadline (design D-4). The superseded row never fires.
	 *
	 * @param string $uuid The timer to supersede.
	 * @param DateTimeInterface $anchorEventAt The new instant of the anchoring event.
	 * @param string $reason Why the anchor moved.
	 * @param string|null $actor The acting identity.
	 * @param DateTimeInterface|null $now The clock.
	 *
	 * @return FlowTimer The successor.
	 *
	 * @throws FlowTimerStateException When the timer is not open.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-deadlines-anchor-is-stored-so-a-moved-anchor-re-arms-the-timer
	 */
	public function supersede(
		string $uuid,
		DateTimeInterface $anchorEventAt,
		string $reason,
		?string $actor,
		?DateTimeInterface $now = null,
	): FlowTimer {
		$now = $this->instant(now: $now);
		$prior = $this->timers->findByUuid(uuid: $uuid);
		if ($prior->isOpen() === false) {
			throw new FlowTimerStateException(
				message: sprintf("Timer '%s' cannot be superseded: its state is '%s'.", $uuid, (string)$prior->getState())
			);
		}

		$calendar = $this->calendars->resolve(calendarSlug: $prior->getCalendarSlug(), organisation: $prior->getOrganisation());
		$successor = $this->cloneForSuccession(prior: $prior, anchorEventAt: $anchorEventAt, calendar: $calendar, now: $now);
		$priorFired = $this->fires->findByTimer(timerUuid: $uuid);

		return $this->transactional(
			mutation: function () use ($prior, $successor, $priorFired, $calendar, $reason, $actor, $now): FlowTimer {
				$priorFireAt = $prior->getFireAt();
				$prior->setState(FlowTimer::STATE_SUPERSEDED);
				$this->recompute(timer: $prior, calendar: $calendar, firedKeys: []);
				$this->timers->update($prior);
				$this->record(
					timer: $prior,
					type: FlowTimerEvent::TYPE_SUPERSEDED,
					actor: $actor,
					reason: $reason,
					priorFireAt: $priorFireAt,
					newFireAt: $successor->getFireAt(),
					basis: '',
					moment: $now
				);

				// The successor must exist before its inherited fire rows.
				$persisted = $this->timers->insert($successor);
				$inherited = $this->inheritFires(successor: $persisted, priorFired: $priorFired, calendar: $calendar, now: $now);
				$this->recompute(timer: $persisted, calendar: $calendar, firedKeys: $inherited);
				$persisted = $this->timers->update($persisted);
				$this->record(
					timer: $persisted,
					type: FlowTimerEvent::TYPE_ARMED,
					actor: $actor,
					reason: sprintf("Re-armed from moved anchor; supersedes '%s'.", (string)$prior->getUuid()),
					priorFireAt: $priorFireAt,
					newFireAt: $persisted->getFireAt(),
					basis: '',
					moment: $now
				);
				$this->project(timer: $persisted);

				return $persisted;
			}
		);
	}//end supersede()

	/**
	 * Cancel every OPEN timer of a subject, with a reason. Idempotent; never
	 * deletes; a recorded breach stays recorded. Runs in the CALLER's
	 * transaction so a terminality listener lands it in the same operation.
	 *
	 * @param string $subjectType The subject type.
	 * @param string $subjectUuid The subject uuid.
	 * @param string $reason Why, recorded on each timer.
	 * @param string|null $actor The propagation source recorded as actor.
	 * @param DateTimeInterface|null $now The clock.
	 *
	 * @return int How many timers were cancelled.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function cancelForSubject(
		string $subjectType,
		string $subjectUuid,
		string $reason,
		?string $actor,
		?DateTimeInterface $now = null,
	): int {
		$open = $this->timers->findBySubject(
			subjectType: $subjectType,
			subjectUuid: $subjectUuid,
			states: [FlowTimer::STATE_ARMED, FlowTimer::STATE_SUSPENDED]
		);

		return $this->cancelAll(timers: $open, reason: $reason, actor: $actor, now: $this->instant(now: $now));
	}//end cancelForSubject()

	/**
	 * Cancel every OPEN timer a run terminality reaches: the run as subject,
	 * or the run as provenance.
	 *
	 * @param string $runUuid The run uuid.
	 * @param string $reason Why, recorded on each timer.
	 * @param string|null $actor The propagation source recorded as actor.
	 * @param DateTimeInterface|null $now The clock.
	 *
	 * @return int How many timers were cancelled.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function cancelForRun(string $runUuid, string $reason, ?string $actor, ?DateTimeInterface $now = null): int {
		if (trim($runUuid) === '') {
			return 0;
		}

		return $this->cancelAll(
			timers: $this->timers->findOpenByRun(runUuid: $runUuid),
			reason: $reason,
			actor: $actor,
			now: $this->instant(now: $now)
		);
	}//end cancelForRun()

	/**
	 * The derived read API: overdue, remaining and overdue-by, computed from
	 * the clock and the stored budget. Correct with the sweep disabled; a
	 * suspended timer is never overdue and still answers its remainder.
	 *
	 * @param FlowTimer $timer The timer.
	 * @param DateTimeInterface|null $now The clock.
	 *
	 * @return array{overdue: bool, remaining: float, unit: string, overdueBy: float|null, fireAt: string|null, state: string} The derivation.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-overdue-is-derived-from-the-clock-and-never-stored
	 */
	public function describe(FlowTimer $timer, ?DateTimeInterface $now = null): array {
		$now = $this->instant(now: $now);
		$calendar = $this->calendars->resolve(calendarSlug: $timer->getCalendarSlug(), organisation: $timer->getOrganisation());
		$unit = (string)$timer->getBudgetUnit();
		$remaining = $this->remaining(timer: $timer, calendar: $calendar, now: $now);
		$fireAt = $timer->getFireAt();
		$overdue = ($timer->getState() === FlowTimer::STATE_ARMED && $fireAt !== null && $fireAt < $now);

		$overdueBy = null;
		if ($overdue === true) {
			$overdueBy = $this->calculator->measure(from: $fireAt, to: $now, unit: $unit, calendar: $calendar);
		}

		$fireAtText = null;
		if ($fireAt !== null) {
			$fireAtText = $fireAt->format('c');
		}

		return [
			'overdue' => $overdue,
			'remaining' => $remaining,
			'unit' => $unit,
			'overdueBy' => $overdueBy,
			'fireAt' => $fireAtText,
			'state' => (string)$timer->getState(),
		];
	}//end describe()

	/**
	 * The history of a timer, oldest first.
	 *
	 * @param string $uuid The timer uuid.
	 *
	 * @return array<int, FlowTimerEvent> The events.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-suspended-deadline-holds-elapsed-time-not-a-moment
	 */
	public function history(string $uuid): array {
		return $this->events->findByTimer(timerUuid: $uuid);
	}//end history()

	/**
	 * Fire an EXPIRY timer whose moment has passed: claim it conditionally,
	 * record the breach, apply the enforcing outcome as a named task action,
	 * raise the transition. Zero affected rows on the claim means another
	 * pass owns it and nothing is applied. A task that completed in the
	 * meantime (a lost race, surfacing as {@see TaskConflictException}) is
	 * "nothing to do", not an error.
	 *
	 * @param FlowTimer $timer The due timer, as read by the sweep.
	 * @param DateTimeInterface $now The sweep instant.
	 *
	 * @return boolean True when THIS call fired the timer.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-advisory-due-date-notifies-an-enforcing-expiry-transitions
	 */
	public function fireExpiry(FlowTimer $timer, DateTimeInterface $now): bool {
		$uuid = (string)$timer->getUuid();
		if ($this->timers->claimFired(uuid: $uuid, firedAt: $now) === false) {
			return false;
		}

		$timer->setState(FlowTimer::STATE_FIRED);
		$timer->setFiredAt($this->mutable(value: $now));
		$timer->setBreached(true);
		$timer->setNextRungAt(null);
		$this->timers->update($timer);

		$type = FlowTimerEvent::TYPE_FIRED;
		if ($timer->getLegalEffect() === FlowTimer::LEGAL_WETTELIJK) {
			$type = FlowTimerEvent::TYPE_BREACHED;
		}

		$this->record(
			timer: $timer,
			type: $type,
			actor: self::ACTOR_SWEEP,
			reason: sprintf("Expiry reached; outcome '%s'.", (string)$timer->getOnExpiry()),
			priorFireAt: $timer->getFireAt(),
			newFireAt: null,
			basis: '',
			moment: $now
		);

		$this->applyOutcome(timer: $timer);
		$this->dispatcher->dispatchTyped(
			new FlowTimerFiredEvent(
				timer: $timer,
				kind: FlowTimerFiredEvent::KIND_EXPIRY,
				transition: 'expiry:' . (string)($timer->getOnExpiry() ?? 'none'),
				rungKey: null,
				recipients: [],
				priority: 'critical',
				message: null
			)
		);
		$this->project(timer: $timer);

		return true;
	}//end fireExpiry()

	/**
	 * Fire every due, unfired rung of a timer, in ladder order, each claimed
	 * by its ledger INSERT before the transition is raised (design D-7). A
	 * duplicate key means another pass owns the rung. Ends by recomputing
	 * `next_rung_at`.
	 *
	 * @param FlowTimer $timer The timer with a due rung, as read by the sweep.
	 * @param DateTimeInterface $now The sweep instant.
	 *
	 * @return int How many rungs THIS call fired.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function fireRungs(FlowTimer $timer, DateTimeInterface $now): int {
		if ($timer->getState() !== FlowTimer::STATE_ARMED || $timer->getFireAt() === null) {
			return 0;
		}

		$calendar = $this->calendars->resolve(calendarSlug: $timer->getCalendarSlug(), organisation: $timer->getOrganisation());
		$ladder = $this->ladder->resolveLadder(timer: $timer);
		$firedKeys = $this->firedKeys(timerUuid: (string)$timer->getUuid());
		$subject = $this->subjectTask(timer: $timer);
		$fired = 0;

		$due = $this->ladder->dueRungs(
			rungs: $ladder['rungs'],
			fireAt: $timer->getFireAt(),
			now: $now,
			firedKeys: $firedKeys,
			calendar: $calendar
		);
		foreach ($due as $entry) {
			$rung = $entry['rung'];
			$recipients = $this->ladder->resolveRecipients(rung: $rung, subject: $subject, roleBindings: $ladder['roleBindings']);
			$transition = 'escalation:' . (string)$rung['key'];

			$row = new FlowTimerFire();
			$row->setTimerUuid((string)$timer->getUuid());
			$row->setRungKey((string)$rung['key']);
			$row->setFiredAt($this->mutable(value: $now));
			$row->setTransitionAction($transition);
			$row->setRecipientRoles(array_values(array_unique(array_merge($rung['notifyRole'], $rung['escalateToRole']))));
			$row->setPriority((string)$rung['priority']);
			$row->setInherited(false);

			// The INSERT is the claim; null means another pass owns this rung.
			if ($this->fires->claim(fire: $row) === null) {
				$firedKeys[] = (string)$rung['key'];
				continue;
			}

			$firedKeys[] = (string)$rung['key'];
			$fired++;
			$this->dispatcher->dispatchTyped(
				new FlowTimerFiredEvent(
					timer: $timer,
					kind: FlowTimerFiredEvent::KIND_RUNG,
					transition: $transition,
					rungKey: (string)$rung['key'],
					recipients: $recipients,
					priority: (string)$rung['priority'],
					message: $rung['message']
				)
			);
		}//end foreach

		$this->recompute(timer: $timer, calendar: $calendar, firedKeys: $firedKeys);
		$this->timers->update($timer);

		return $fired;
	}//end fireRungs()

	/**
	 * Build and validate a timer from its configuration. Writes nothing.
	 *
	 * @param array<string, mixed> $config The configuration.
	 * @param string|null $actor The arming identity.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return FlowTimer The unsaved timer.
	 *
	 * @throws FlowTimerValidationException On any refused value.
	 */
	private function build(array $config, ?string $actor, DateTimeImmutable $now): FlowTimer {
		$timer = new FlowTimer();
		$timer->setUuid(Uuid::v4()->toRfc4122());

		$subjectType = (string)($config['subjectType'] ?? '');
		$subjectUuid = trim((string)($config['subjectUuid'] ?? ''));
		if (in_array($subjectType, FlowTimer::SUBJECT_TYPES, true) === false || $subjectUuid === '') {
			throw new FlowTimerValidationException(
				message: sprintf(
					"A timer requires a subjectType in [%s] and a subjectUuid; got '%s' / '%s'.",
					implode(', ', FlowTimer::SUBJECT_TYPES),
					$subjectType,
					$subjectUuid
				)
			);
		}

		$purpose = (string)($config['purpose'] ?? FlowTimer::PURPOSE_DUE);
		if (in_array($purpose, FlowTimer::PURPOSES, true) === false) {
			throw new FlowTimerValidationException(message: sprintf("Timer purpose '%s' is refused: use due or expiry.", $purpose));
		}

		$legalEffect = (string)($config['legalEffect'] ?? FlowTimer::LEGAL_NONE);
		if (in_array($legalEffect, FlowTimer::LEGAL_EFFECTS, true) === false) {
			throw new FlowTimerValidationException(
				message: sprintf("Timer legalEffect '%s' is refused: use none, servicenorm or wettelijk.", $legalEffect)
			);
		}

		$onExpiry = $this->validOnExpiry(config: $config, purpose: $purpose, legalEffect: $legalEffect);
		$sla = $this->calculator->validateSla(sla: ($config['sla'] ?? null));

		$timer->setSubjectType($subjectType);
		$timer->setSubjectUuid($subjectUuid);
		$timer->setOrganisation($this->stringOrNull(value: ($config['organisation'] ?? null)));
		$timer->setRunUuid($this->stringOrNull(value: ($config['runUuid'] ?? null)));
		$timer->setNodeId($this->stringOrNull(value: ($config['nodeId'] ?? null)));
		$timer->setAppId($this->stringOrNull(value: ($config['appId'] ?? null)));
		$timer->setTitle($this->stringOrNull(value: ($config['title'] ?? null)));
		$timer->setMetadata($this->arrayOrNull(value: ($config['metadata'] ?? null)));
		$timer->setPurpose($purpose);
		$timer->setLegalEffect($legalEffect);
		$timer->setOnExpiry($onExpiry);
		$timer->setBudgetValue((float)$sla['value']);
		$timer->setBudgetUnit($sla['unit']);
		$timer->setConsumedValue(0.0);
		$timer->setCalendarSlug($this->stringOrNull(value: ($config['calendar'] ?? null)));
		$timer->setLadderSlug($this->stringOrNull(value: ($config['ladder'] ?? null)));
		$timer->setEscalationRules($this->ladder->normaliseRules(rules: ($config['escalationRules'] ?? []), sla: $sla));
		$timer->setExtensionCount(0);
		$timer->setExtensionMax(max(0, (int)($config['extensionMax'] ?? 1)));
		$timer->setState(FlowTimer::STATE_ARMED);
		$timer->setBreached(false);
		$timer->setCreatedBy($actor);
		$timer->setSuspendedTotalSeconds(0);

		$this->applyAnchor(timer: $timer, config: $config, now: $now);
		$timer->setRunningSince($timer->getAnchorAt());

		return $timer;
	}//end build()

	/**
	 * The enforcing outcome, permitted ONLY on an expiry timer with legal effect wettelijk.
	 *
	 * @param array<string, mixed> $config The configuration.
	 * @param string $purpose The validated purpose.
	 * @param string $legalEffect The validated legal effect.
	 *
	 * @return string|null The outcome, or null for an advisory timer.
	 *
	 * @throws FlowTimerValidationException When an outcome is given where none may be.
	 */
	private function validOnExpiry(array $config, string $purpose, string $legalEffect): ?string {
		$onExpiry = $this->stringOrNull(value: ($config['onExpiry'] ?? null));
		if ($onExpiry === null) {
			return null;
		}

		if ($purpose !== FlowTimer::PURPOSE_EXPIRY) {
			throw new FlowTimerValidationException(
				message: sprintf("onExpiry '%s' is refused on a '%s' timer: only an expiry timer enforces.", $onExpiry, $purpose)
			);
		}

		if ($legalEffect !== FlowTimer::LEGAL_WETTELIJK) {
			throw new FlowTimerValidationException(
				message: sprintf(
					"onExpiry '%s' is refused: legal effect '%s' is advisory, and only a 'wettelijk' timer may carry an enforcing outcome.",
					$onExpiry,
					$legalEffect
				)
			);
		}

		if (in_array($onExpiry, FlowTimer::RESERVED_OUTCOMES, true) === false && str_starts_with($onExpiry, 'transition:') === false) {
			throw new FlowTimerValidationException(
				message: sprintf("onExpiry '%s' is refused: use skip, error, dead_letter or transition:<action>.", $onExpiry)
			);
		}

		return $onExpiry;
	}//end validOnExpiry()

	/**
	 * Store the anchor and resolve `anchor_at` = anchorEventAt + offset.
	 *
	 * @param FlowTimer $timer The timer being built.
	 * @param array<string, mixed> $config The configuration.
	 * @param DateTimeImmutable $now The clock, the default anchor.
	 *
	 * @return void
	 *
	 * @throws FlowTimerValidationException On a malformed offset.
	 */
	private function applyAnchor(FlowTimer $timer, array $config, DateTimeImmutable $now): void {
		$eventAt = $this->dateOrNull(value: ($config['anchorEventAt'] ?? null), field: 'anchorEventAt') ?? $now;
		$offset = ($config['anchorOffset'] ?? null);
		$offsetUnit = null;
		$anchorAt = $eventAt;

		if ($offset !== null) {
			if (is_int($offset) === false) {
				throw new FlowTimerValidationException(message: sprintf("anchorOffset '%s' must be an integer.", var_export($offset, true)));
			}

			$offsetUnit = $this->calculator->validateUnit(unit: ($config['anchorOffsetUnit'] ?? SlaCalculator::UNIT_CALENDAR_DAYS));
			$calendar = $this->calendars->resolve(
				calendarSlug: $this->stringOrNull(value: ($config['calendar'] ?? null)),
				organisation: $this->stringOrNull(value: ($config['organisation'] ?? null))
			);
			$anchorAt = $this->calculator->add(from: $eventAt, value: (float)$offset, unit: $offsetUnit, calendar: $calendar);
		}

		$timer->setAnchorEvent($this->stringOrNull(value: ($config['anchorEvent'] ?? null)));
		$timer->setAnchorOffset($offset);
		$timer->setAnchorOffsetUnit($offsetUnit);
		$timer->setAnchorAt($this->mutable(value: $anchorAt));
	}//end applyAnchor()

	/**
	 * THE one derivation of `fire_at` and `next_rung_at`.
	 *
	 * Armed: `fire_at = add(running_since, budget - consumed)` and
	 * `next_rung_at` = the earliest unfired rung. Any other state: both NULL.
	 * Every mutating operation ends here and nothing else writes these columns.
	 *
	 * @param FlowTimer $timer The timer, mutated in place.
	 * @param WorkingCalendar $calendar The resolved calendar.
	 * @param array<int, string> $firedKeys Rung keys already in the ledger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	private function recompute(FlowTimer $timer, WorkingCalendar $calendar, array $firedKeys): void {
		if ($timer->getState() !== FlowTimer::STATE_ARMED || $timer->getRunningSince() === null) {
			$timer->setFireAt(null);
			$timer->setNextRungAt(null);

			return;
		}

		$remaining = ((float)$timer->getBudgetValue() - (float)$timer->getConsumedValue());
		$fireAt = $this->calculator->add(
			from: $timer->getRunningSince(),
			value: max(0.0, $remaining),
			unit: (string)$timer->getBudgetUnit(),
			calendar: $calendar
		);
		$timer->setFireAt($this->mutable(value: $fireAt));

		$rungs = $this->ladder->resolveLadder(timer: $timer)['rungs'];
		$next = $this->ladder->nextRungAt(rungs: $rungs, fireAt: $fireAt, firedKeys: $firedKeys, calendar: $calendar);
		$timer->setNextRungAt($this->mutableOrNull(value: $next));
	}//end recompute()

	/**
	 * Remaining budget in the timer's own unit, answerable while suspended.
	 *
	 * @param FlowTimer $timer The timer.
	 * @param WorkingCalendar $calendar The resolved calendar.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return float The remainder; negative once overdue.
	 */
	private function remaining(FlowTimer $timer, WorkingCalendar $calendar, DateTimeImmutable $now): float {
		$running = 0.0;
		if ($timer->getRunningSince() !== null && $timer->getState() === FlowTimer::STATE_ARMED) {
			$running = $this->calculator->measure(
				from: $timer->getRunningSince(),
				to: $now,
				unit: (string)$timer->getBudgetUnit(),
				calendar: $calendar
			);
		}

		return ((float)$timer->getBudgetValue() - (float)$timer->getConsumedValue() - $running);
	}//end remaining()

	/**
	 * Refuse an extension on a timer that is not open or whose term has run out.
	 *
	 * @param FlowTimer $timer The timer.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return void
	 *
	 * @throws FlowTimerStateException When fired, cancelled, superseded or past its fire moment.
	 */
	private function assertExtendable(FlowTimer $timer, DateTimeImmutable $now): void {
		if ($timer->isOpen() === false) {
			throw new FlowTimerStateException(
				message: sprintf(
					"Timer '%s' cannot be extended: its state is '%s'. A term that has run out cannot be lengthened retroactively; the recorded breach stands.",
					(string)$timer->getUuid(),
					(string)$timer->getState()
				)
			);
		}

		if ($timer->getFireAt() !== null && $timer->getFireAt() <= $now) {
			throw new FlowTimerStateException(
				message: sprintf(
					"Timer '%s' cannot be extended: its fire moment %s has passed. A term that has run out cannot be lengthened retroactively.",
					(string)$timer->getUuid(),
					$timer->getFireAt()->format('c')
				)
			);
		}
	}//end assertExtendable()

	/**
	 * The shared body of extend() and extendWithOverride().
	 *
	 * @param FlowTimer $timer The open timer.
	 * @param int $amount The extension amount.
	 * @param string $unit Its unit.
	 * @param string $rationale Why.
	 * @param string|null $actor The acting identity.
	 * @param DateTimeImmutable $now The clock.
	 * @param string $basis The recorded basis (`Awb 4:14` or `override`).
	 *
	 * @return FlowTimer The extended timer.
	 *
	 * @throws FlowTimerValidationException When the rationale is empty or the amount is not positive.
	 */
	private function applyExtension(
		FlowTimer $timer,
		int $amount,
		string $unit,
		string $rationale,
		?string $actor,
		DateTimeImmutable $now,
		string $basis,
	): FlowTimer {
		if (trim($rationale) === '') {
			throw new FlowTimerValidationException(message: 'An extension requires a non-empty rationale.');
		}

		if ($amount <= 0) {
			throw new FlowTimerValidationException(message: sprintf('An extension amount must be positive; got %d.', $amount));
		}

		$calendar = $this->calendars->resolve(calendarSlug: $timer->getCalendarSlug(), organisation: $timer->getOrganisation());
		$added = $this->calculator->convert(
			value: (float)$amount,
			fromUnit: $this->calculator->validateUnit(unit: $unit),
			toUnit: (string)$timer->getBudgetUnit(),
			calendar: $calendar
		);

		return $this->transactional(
			mutation: function () use ($timer, $calendar, $added, $rationale, $actor, $now, $basis, $amount, $unit): FlowTimer {
				$priorFireAt = $timer->getFireAt();
				$timer->setBudgetValue((float)$timer->getBudgetValue() + $added);
				$timer->setExtensionCount((int)$timer->getExtensionCount() + 1);
				$this->recompute(timer: $timer, calendar: $calendar, firedKeys: $this->firedKeys(timerUuid: (string)$timer->getUuid()));
				$persisted = $this->timers->update($timer);
				$this->record(
					timer: $persisted,
					type: FlowTimerEvent::TYPE_EXTENDED,
					actor: $actor,
					reason: sprintf('%s (+%d %s)', $rationale, $amount, $unit),
					priorFireAt: $priorFireAt,
					newFireAt: $persisted->getFireAt(),
					basis: $basis,
					moment: $now,
					impact: $added
				);
				$this->project(timer: $persisted);

				return $persisted;
			}
		);
	}//end applyExtension()

	/**
	 * The successor row of a supersession, unsaved.
	 *
	 * @param FlowTimer $prior The timer being superseded.
	 * @param DateTimeInterface $anchorEventAt The new anchoring instant.
	 * @param WorkingCalendar $calendar The resolved calendar.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return FlowTimer The successor.
	 */
	private function cloneForSuccession(
		FlowTimer $prior,
		DateTimeInterface $anchorEventAt,
		WorkingCalendar $calendar,
		DateTimeImmutable $now,
	): FlowTimer {
		$successor = new FlowTimer();
		foreach ($prior->jsonSerialize() as $field => $value) {
			if (in_array($field, ['id', 'uuid', 'created', 'updated'], true) === true || str_ends_with($field, 'At') === true) {
				continue;
			}

			$setter = 'set' . ucfirst((string)$field);
			$successor->$setter($value);
		}

		$anchorAt = DateTimeImmutable::createFromInterface($anchorEventAt);
		if ($prior->getAnchorOffset() !== null) {
			$anchorAt = $this->calculator->add(
				from: $anchorAt,
				value: (float)$prior->getAnchorOffset(),
				unit: (string)$prior->getAnchorOffsetUnit(),
				calendar: $calendar
			);
		}

		$successor->setUuid(Uuid::v4()->toRfc4122());
		$successor->setSupersedesUuid((string)$prior->getUuid());
		$successor->setState(FlowTimer::STATE_ARMED);
		$successor->setAnchorAt($this->mutable(value: $anchorAt));
		$successor->setRunningSince($this->mutable(value: $anchorAt));
		$successor->setSuspendedSince(null);
		$successor->setSuspendReason(null);
		$successor->setFiredAt(null);
		$successor->setCancelledAt(null);
		$successor->setCancelReason(null);
		$successor->setBreached(false);
		$successor->setCreated($this->mutable(value: $now));
		// The predecessor's CONSUMED time (its completed segments) carries
		// forward; its running segment does not, because the term now runs
		// from the new anchor and that segment is re-measured from there.
		$successor->setConsumedValue((float)$prior->getConsumedValue());

		return $successor;
	}//end cloneForSuccession()

	/**
	 * Copy forward a fire row for every predecessor rung whose instant is
	 * still in the past under the successor's deadline; none for rungs pushed
	 * back into the future.
	 *
	 * @param FlowTimer $successor The persisted successor.
	 * @param array<int, FlowTimerFire> $priorFired The predecessor's fire rows.
	 * @param WorkingCalendar $calendar The resolved calendar.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return array<int, string> The inherited rung keys.
	 */
	private function inheritFires(FlowTimer $successor, array $priorFired, WorkingCalendar $calendar, DateTimeImmutable $now): array {
		if ($priorFired === []) {
			return [];
		}

		// The successor's fire moment, without the ladder (no ledger yet).
		$remaining = max(0.0, ((float)$successor->getBudgetValue() - (float)$successor->getConsumedValue()));
		$fireAt = $this->calculator->add(
			from: $successor->getRunningSince(),
			value: $remaining,
			unit: (string)$successor->getBudgetUnit(),
			calendar: $calendar
		);
		$byKey = [];
		foreach ($this->ladder->resolveLadder(timer: $successor)['rungs'] as $rung) {
			$byKey[(string)$rung['key']] = $rung;
		}

		$inherited = [];
		foreach ($priorFired as $fire) {
			$key = (string)$fire->getRungKey();
			if (array_key_exists($key, $byKey) === false) {
				continue;
			}

			if ($this->ladder->rungInstant(rung: $byKey[$key], fireAt: $fireAt, calendar: $calendar) > $now) {
				// Pushed back into the future: it will fire again, legitimately.
				continue;
			}

			$row = new FlowTimerFire();
			$row->setTimerUuid((string)$successor->getUuid());
			$row->setRungKey($key);
			$row->setFiredAt($fire->getFiredAt());
			$row->setTransitionAction($fire->getTransitionAction());
			$row->setRecipientRoles($fire->getRecipientRoles());
			$row->setPriority($fire->getPriority());
			$row->setInherited(true);
			if ($this->fires->claim(fire: $row) !== null) {
				$inherited[] = $key;
			}
		}

		return $inherited;
	}//end inheritFires()

	/**
	 * Cancel a set of open timers with a reason; never deletes.
	 *
	 * @param array<int, FlowTimer> $timers The open timers.
	 * @param string $reason Why.
	 * @param string|null $actor The propagation source.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return int How many were cancelled.
	 */
	private function cancelAll(array $timers, string $reason, ?string $actor, DateTimeImmutable $now): int {
		$cancelled = 0;
		foreach ($timers as $timer) {
			if ($timer->isOpen() === false) {
				continue;
			}

			$priorFireAt = $timer->getFireAt();
			$timer->setState(FlowTimer::STATE_CANCELLED);
			$timer->setCancelledAt($this->mutable(value: $now));
			$timer->setCancelReason($reason);
			$timer->setRunningSince(null);
			$timer->setFireAt(null);
			$timer->setNextRungAt(null);
			$this->timers->update($timer);
			$this->record(
				timer: $timer,
				type: FlowTimerEvent::TYPE_CANCELLED,
				actor: $actor,
				reason: $reason,
				priorFireAt: $priorFireAt,
				newFireAt: null,
				basis: '',
				moment: $now
			);
			$cancelled++;
		}

		if ($cancelled > 0 && isset($timer) === true) {
			$this->project(timer: $timer);
		}

		return $cancelled;
	}//end cancelAll()

	/**
	 * Apply an expiry timer's enforcing outcome to a task subject.
	 *
	 * @param FlowTimer $timer The fired timer.
	 *
	 * @return void
	 */
	private function applyOutcome(FlowTimer $timer): void {
		if ($timer->isEnforcing() === false || $timer->getSubjectType() !== 'task') {
			return;
		}

		try {
			$this->taskService->applyTimerOutcome(
				uuid: (string)$timer->getSubjectUuid(),
				outcome: (string)$timer->getOnExpiry(),
				source: 'flow-timer:' . (string)$timer->getUuid(),
				reason: sprintf("Expiry timer '%s' (%s) reached its deadline.", (string)$timer->getUuid(), (string)$timer->getLegalEffect())
			);
		} catch (TaskConflictException $race) {
			// The task closed concurrently: nothing to do, the timer is cancelled by that close.
			$this->logger->info('[FlowTimerService] Expiry outcome not applied, task closed concurrently: ' . $race->getMessage());
		} catch (DoesNotExistException) {
			$this->logger->warning(
				'[FlowTimerService] Expiry timer fired for an absent task subject.',
				['timer' => $timer->getUuid(), 'subject' => $timer->getSubjectUuid()]
			);
		}
	}//end applyOutcome()

	/**
	 * Maintain the task's `due_at`/`expires_at` projection (design D-10):
	 * the earliest OPEN due timer and the earliest OPEN expiry timer of the
	 * subject. Only task subjects have a projection.
	 *
	 * @param FlowTimer $timer A timer of the subject just mutated.
	 * @param DateTimeInterface|null $suspendedUntil Display-only expected resume, when suspending.
	 *
	 * @return void
	 */
	private function project(FlowTimer $timer, ?DateTimeInterface $suspendedUntil = null): void {
		if ($timer->getSubjectType() !== 'task') {
			return;
		}

		try {
			$task = $this->tasks->findByUuid(uuid: (string)$timer->getSubjectUuid());
		} catch (DoesNotExistException) {
			$this->logger->warning('[FlowTimerService] No task to project onto.', ['subject' => $timer->getSubjectUuid()]);

			return;
		}

		$earliest = $this->earliestOpenDeadlines(subjectUuid: (string)$timer->getSubjectUuid());
		$task->setDueAt($this->mutableOrNull(value: $earliest[FlowTimer::PURPOSE_DUE]));
		$task->setExpiresAt($this->mutableOrNull(value: $earliest[FlowTimer::PURPOSE_EXPIRY]));
		$task->setSuspendedUntil($this->mutableOrNull(value: $suspendedUntil));
		$this->tasks->update($task);
	}//end project()

	/**
	 * The earliest fire moment per purpose across a task's OPEN timers.
	 *
	 * @param string $subjectUuid The task uuid.
	 *
	 * @return array<string, DateTime|null> Keyed by purpose; null when no open timer of that purpose has a fire moment.
	 */
	private function earliestOpenDeadlines(string $subjectUuid): array {
		$earliest = [FlowTimer::PURPOSE_DUE => null, FlowTimer::PURPOSE_EXPIRY => null];
		$open = $this->timers->findBySubject(
			subjectType: 'task',
			subjectUuid: $subjectUuid,
			states: [FlowTimer::STATE_ARMED, FlowTimer::STATE_SUSPENDED]
		);
		foreach ($open as $candidate) {
			$fireAt = $candidate->getFireAt();
			$purpose = (string)$candidate->getPurpose();
			if ($fireAt === null || array_key_exists($purpose, $earliest) === false) {
				continue;
			}

			if ($earliest[$purpose] === null || $fireAt < $earliest[$purpose]) {
				$earliest[$purpose] = $fireAt;
			}
		}

		return $earliest;
	}//end earliestOpenDeadlines()

	/**
	 * The subject task, when the subject is a task that exists.
	 *
	 * @param FlowTimer $timer The timer.
	 *
	 * @return Task|null The task.
	 */
	private function subjectTask(FlowTimer $timer): ?Task {
		if ($timer->getSubjectType() !== 'task') {
			return null;
		}

		try {
			return $this->tasks->findByUuid(uuid: (string)$timer->getSubjectUuid());
		} catch (DoesNotExistException) {
			return null;
		}
	}//end subjectTask()

	/**
	 * Rung keys already in a timer's ledger.
	 *
	 * @param string $timerUuid The timer uuid.
	 *
	 * @return array<int, string> The keys.
	 */
	private function firedKeys(string $timerUuid): array {
		$keys = [];
		foreach ($this->fires->findByTimer(timerUuid: $timerUuid) as $fire) {
			$keys[] = (string)$fire->getRungKey();
		}

		return $keys;
	}//end firedKeys()

	/**
	 * Append one history row.
	 *
	 * @param FlowTimer $timer The timer.
	 * @param string $type The event type.
	 * @param string|null $actor The acting identity.
	 * @param string $reason The reason.
	 * @param DateTime|null $priorFireAt The fire moment before.
	 * @param DateTime|null $newFireAt The fire moment after.
	 * @param string $basis The legal ground.
	 * @param DateTimeInterface $moment The moment.
	 * @param float|null $impact The impact in the budget unit.
	 *
	 * @return void
	 */
	private function record(
		FlowTimer $timer,
		string $type,
		?string $actor,
		string $reason,
		?DateTime $priorFireAt,
		?DateTime $newFireAt,
		string $basis,
		DateTimeInterface $moment,
		?float $impact = null,
	): void {
		$event = new FlowTimerEvent();
		$event->setTimerUuid((string)$timer->getUuid());
		$event->setType($type);
		$event->setActor($actor);
		$event->setReason($reason);
		$event->setPriorFireAt($priorFireAt);
		$event->setNewFireAt($newFireAt);
		$event->setDaysImpact($impact);
		$event->setBasis($this->stringOrNull(value: $basis));
		$event->setCreated($this->mutable(value: $moment));
		$this->events->insert($event);
	}//end record()

	/**
	 * Run a mutation in one transaction.
	 *
	 * @param callable(): FlowTimer $mutation The mutation.
	 *
	 * @return FlowTimer The result.
	 *
	 * @throws Throwable The mutation's failure, after rollback.
	 */
	private function transactional(callable $mutation): FlowTimer {
		$this->db->beginTransaction();
		try {
			$result = $mutation();
			$this->db->commit();

			return $result;
		} catch (Throwable $failure) {
			$this->db->rollBack();
			throw $failure;
		}
	}//end transactional()

	/**
	 * The clock instant as an immutable.
	 *
	 * @param DateTimeInterface|null $now The injected clock, or null for the real one.
	 *
	 * @return DateTimeImmutable Now.
	 */
	private function instant(?DateTimeInterface $now): DateTimeImmutable {
		if ($now === null) {
			return new DateTimeImmutable();
		}

		return DateTimeImmutable::createFromInterface($now);
	}//end instant()

	/**
	 * A mutable copy, as the entities store.
	 *
	 * @param DateTimeInterface $value The instant.
	 *
	 * @return DateTime The copy.
	 */
	private function mutable(DateTimeInterface $value): DateTime {
		return DateTime::createFromInterface($value);
	}//end mutable()

	/**
	 * A mutable copy or null.
	 *
	 * @param DateTimeInterface|null $value The instant.
	 *
	 * @return DateTime|null The copy.
	 */
	private function mutableOrNull(?DateTimeInterface $value): ?DateTime {
		if ($value === null) {
			return null;
		}

		return $this->mutable(value: $value);
	}//end mutableOrNull()

	/**
	 * A parsed date or null.
	 *
	 * @param mixed $value An ISO string, a DateTimeInterface, or null.
	 * @param string $field The field name, for the message.
	 *
	 * @return DateTimeImmutable|null The instant.
	 *
	 * @throws FlowTimerValidationException On an unparseable value.
	 */
	private function dateOrNull(mixed $value, string $field): ?DateTimeImmutable {
		if ($value === null || $value === '') {
			return null;
		}

		if ($value instanceof DateTimeInterface) {
			return DateTimeImmutable::createFromInterface($value);
		}

		if (is_string($value) === false) {
			throw new FlowTimerValidationException(
				message: sprintf('%s must be a date string or a DateTime; got %s.', $field, get_debug_type($value))
			);
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable) {
			throw new FlowTimerValidationException(message: sprintf("%s '%s' is not a parseable date.", $field, $value));
		}
	}//end dateOrNull()

	/**
	 * A non-empty string or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The string.
	 */
	private function stringOrNull(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end stringOrNull()

	/**
	 * An array or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<string, mixed>|null The array.
	 */
	private function arrayOrNull(mixed $value): ?array {
		if (is_array($value) === false || $value === []) {
			return null;
		}

		return $value;
	}//end arrayOrNull()
}//end class
