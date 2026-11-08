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
use OCA\OpenRegister\Kernel\Contracts\FlowTimerControlInterface;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerEvent;
use OCA\OpenRegister\Db\FlowTimerEventMapperInterface;
use OCA\OpenRegister\Db\FlowTimerFire;
use OCA\OpenRegister\Db\FlowTimerFireMapperInterface;
use OCA\OpenRegister\Db\FlowTimerMapperInterface;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCA\OpenRegister\Exception\FlowTimerStateException;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Service\Task\TaskServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
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
 */
class FlowTimerService implements FlowTimerControlInterface {

	/**
	 * The actor recorded for sweep-originated writes.
	 *
	 * @var string
	 */
	public const ACTOR_SWEEP = 'flow-timer-sweep';

	/**
	 * The timer table.
	 *
	 * @var FlowTimerMapperInterface
	 */
	private readonly FlowTimerMapperInterface $timers;

	/**
	 * The rung dedup ledger.
	 *
	 * @var FlowTimerFireMapperInterface
	 */
	private readonly FlowTimerFireMapperInterface $fires;

	/**
	 * The append-only history.
	 *
	 * @var FlowTimerEventMapperInterface
	 */
	private readonly FlowTimerEventMapperInterface $events;

	/**
	 * The task table, for the projection and the subject read.
	 *
	 * @var TaskMapper
	 */
	private readonly TaskMapper $tasks;

	/**
	 * Applies enforcing outcomes as named task actions.
	 *
	 * @var TaskServiceInterface
	 */
	private readonly TaskServiceInterface $taskService;

	/**
	 * Calendar resolution.
	 *
	 * @var WorkingCalendarService
	 */
	private readonly WorkingCalendarService $calendars;

	/**
	 * Business-time arithmetic.
	 *
	 * @var SlaCalculator
	 */
	private readonly SlaCalculator $calculator;

	/**
	 * Rung resolution and validation.
	 *
	 * @var EscalationLadderService
	 */
	private readonly EscalationLadderService $ladder;

	/**
	 * Holds the one transaction per operation.
	 *
	 * @var IDBConnection
	 */
	private readonly IDBConnection $db;

	/**
	 * Raises the fired transitions.
	 *
	 * @var IEventDispatcher
	 */
	private readonly IEventDispatcher $dispatcher;

	/**
	 * Constructor.
	 *
	 * @param FlowTimerStorage    $storage    The timer tables and the connection.
	 * @param FlowTimerSubjects   $subjects   The task table, the task actions and the dispatcher.
	 * @param FlowTimerArithmetic $arithmetic The calendar, the calculator and the ladder.
	 * @param LoggerInterface     $logger     Diagnostics.
	 */
	public function __construct(
		FlowTimerStorage $storage,
		FlowTimerSubjects $subjects,
		FlowTimerArithmetic $arithmetic,
		private readonly LoggerInterface $logger,
	) {
		$this->timers = $storage->timers;
		$this->fires = $storage->fires;
		$this->events = $storage->events;
		$this->tasks = $subjects->tasks;
		$this->taskService = $subjects->taskService;
		$this->calendars = $arithmetic->calendars;
		$this->calculator = $arithmetic->calculator;
		$this->ladder = $arithmetic->ladder;
		$this->db = $storage->db;
		$this->dispatcher = $subjects->dispatcher;
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
		$timer = $this->factory()->build(config: $config, act: new FlowTimerAct(actor: $actor, now: $now));
		$calendar = $this->calendars->resolve(calendarSlug: $timer->getCalendarSlug(), organisation: $timer->getOrganisation());

		// Arm-time validation is AUTHORITATIVE: resolve the ladder and check
		// every preBreach rung on the timeline before anything is written.
		$this->deadlines()->recompute(scope: new FlowTimerScope(timer: $timer, calendar: $calendar), firedKeys: []);
		$this->ladder->validateAgainstTimeline(
			rungs: $this->ladder->resolveLadder(timer: $timer)['rungs'],
			anchorAt: $timer->getAnchorAt(),
			fireAt: $timer->getFireAt(),
			calendar: $calendar
		);

		return $this->transactional(
			mutation: function () use ($timer, $actor, $now): FlowTimer {
				$persisted = $this->timers->insert($timer);
				$entry = new FlowTimerLedgerEntry(
					type: FlowTimerEvent::TYPE_ARMED,
					actor: $actor,
					reason: sprintf('Armed from anchor %s.', $persisted->getAnchorAt()->format('c')),
					moment: $now
				);
				$entry = $entry->withFireMoments(prior: null, next: $persisted->getFireAt());
				$this->record(timer: $persisted, entry: $entry->withBasis(basis: (string)($persisted->getMetadata()['basis'] ?? '')));
				$this->projection()->project(timer: $persisted);

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
				$this->recomputeFromLedger(timer: $timer, calendar: $calendar);
				$persisted = $this->timers->update($timer);

				$untilNote = '';
				if ($until !== null) {
					$untilNote = sprintf(' Expected until %s.', $until->format('c'));
				}

				$entry = new FlowTimerLedgerEntry(type: FlowTimerEvent::TYPE_SUSPENDED, actor: $actor, reason: $reason . $untilNote, moment: $now);
				$this->record(timer: $persisted, entry: $entry->withFireMoments(prior: $priorFireAt, next: null)->withBasis(basis: (string)$basis));
				$this->projection()->project(timer: $persisted, suspendedUntil: $until);

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
				$this->recomputeFromLedger(timer: $timer, calendar: $calendar);
				$persisted = $this->timers->update($timer);
				$entry = new FlowTimerLedgerEntry(type: FlowTimerEvent::TYPE_RESUMED, actor: $actor, reason: (string)$reason, moment: $now);
				$this->record(timer: $persisted, entry: $entry->withFireMoments(prior: null, next: $persisted->getFireAt()));
				$this->projection()->project(timer: $persisted);

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

		return $this->applyExtension(
			timer: $timer,
			extension: new FlowTimerExtension(amount: $amount, unit: $unit, rationale: $rationale, basis: 'Awb 4:14'),
			act: new FlowTimerAct(actor: $actor, now: $now)
		);
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

		return $this->applyExtension(
			timer: $timer,
			extension: new FlowTimerExtension(amount: $amount, unit: $unit, rationale: $rationale, basis: 'override'),
			act: new FlowTimerAct(actor: $actor, now: $now)
		);
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
		$successor = $this->factory()->successor(
			scope: new FlowTimerScope(timer: $prior, calendar: $calendar),
			act: new FlowTimerAct(actor: $actor, now: $now, when: $anchorEventAt)
		);
		$priorFired = $this->fires->findByTimer(timerUuid: $uuid);

		return $this->transactional(
			mutation: function () use ($prior, $successor, $priorFired, $calendar, $reason, $actor, $now): FlowTimer {
				$priorFireAt = $prior->getFireAt();
				$prior->setState(FlowTimer::STATE_SUPERSEDED);
				$this->deadlines()->recompute(scope: new FlowTimerScope(timer: $prior, calendar: $calendar), firedKeys: []);
				$this->timers->update($prior);
				$entry = new FlowTimerLedgerEntry(type: FlowTimerEvent::TYPE_SUPERSEDED, actor: $actor, reason: $reason, moment: $now);
				$this->record(timer: $prior, entry: $entry->withFireMoments(prior: $priorFireAt, next: $successor->getFireAt()));

				// The successor must exist before its inherited fire rows.
				$persisted = $this->timers->insert($successor);
				$inherited = $this->inheritFires(successor: $persisted, priorFired: $priorFired, calendar: $calendar, now: $now);
				$this->deadlines()->recompute(scope: new FlowTimerScope(timer: $persisted, calendar: $calendar), firedKeys: $inherited);
				$persisted = $this->timers->update($persisted);
				$entry = new FlowTimerLedgerEntry(
					type: FlowTimerEvent::TYPE_ARMED,
					actor: $actor,
					reason: sprintf("Re-armed from moved anchor; supersedes '%s'.", (string)$prior->getUuid()),
					moment: $now
				);
				$this->record(timer: $persisted, entry: $entry->withFireMoments(prior: $priorFireAt, next: $persisted->getFireAt()));
				$this->projection()->project(timer: $persisted);

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
		$scope = $this->scope(timer: $timer);
		$overdueBy = $this->overdueBy(scope: $scope, now: $now);

		return [
			'overdue' => ($overdueBy !== null),
			'remaining' => $this->deadlines()->remaining(scope: $scope, now: $now),
			'unit' => (string)$timer->getBudgetUnit(),
			'overdueBy' => $overdueBy,
			'fireAt' => $timer->getFireAt()?->format('c'),
			'state' => (string)$timer->getState(),
			// Both NULL unless a roll actually moved the deadline. A handler
			// looking at a term that ends on Tuesday has to be able to read
			// that Monday was Tweede Paasdag; a date that moved with no
			// explanation is one somebody will challenge and nobody can
			// defend.
			'unrolledAt' => $timer->getUnrolledAt()?->format('c'),
			'rolledBy' => $timer->getRolledBy(),
		];
	}//end describe()

	/**
	 * How far an armed timer is past its fire moment, or null when it is not.
	 *
	 * @param FlowTimerScope    $scope The timer and its calendar.
	 * @param DateTimeImmutable $now   The clock.
	 *
	 * @return float|null The overrun.
	 */
	private function overdueBy(FlowTimerScope $scope, DateTimeImmutable $now): ?float {
		$timer = $scope->timer;
		$fireAt = $timer->getFireAt();
		if ($timer->getState() !== FlowTimer::STATE_ARMED || $fireAt === null || $fireAt >= $now) {
			return null;
		}

		return $this->calculator->measure(from: $fireAt, to: $now, unit: (string)$timer->getBudgetUnit(), calendar: $scope->calendar);
	}//end overdueBy()

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

		$entry = new FlowTimerLedgerEntry(
			type: $type,
			actor: self::ACTOR_SWEEP,
			reason: sprintf("Expiry reached; outcome '%s'.", (string)$timer->getOnExpiry()),
			moment: $now
		);
		$this->record(timer: $timer, entry: $entry->withFireMoments(prior: $timer->getFireAt(), next: null));

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
		$this->projection()->project(timer: $timer);

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
		$subject = $this->projection()->subjectTask(timer: $timer);
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
					message: $rung['message'],
					consequence: ($rung['consequence'] ?? null)
				)
			);
		}//end foreach

		$this->deadlines()->recompute(scope: new FlowTimerScope(timer: $timer, calendar: $calendar), firedKeys: $firedKeys);
		$this->timers->update($timer);

		return $fired;
	}//end fireRungs()

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
	 * @param FlowTimer          $timer     The open timer.
	 * @param FlowTimerExtension $extension The amount, unit, rationale and basis.
	 * @param FlowTimerAct       $act       The acting identity and the clock.
	 *
	 * @return FlowTimer The extended timer.
	 *
	 * @throws FlowTimerValidationException When the rationale is empty or the amount is not positive.
	 */
	private function applyExtension(FlowTimer $timer, FlowTimerExtension $extension, FlowTimerAct $act): FlowTimer {
		$extension->assertValid();

		$calendar = $this->calendars->resolve(calendarSlug: $timer->getCalendarSlug(), organisation: $timer->getOrganisation());
		$added = $this->calculator->convert(
			value: (float)$extension->amount,
			fromUnit: $this->calculator->validateUnit(unit: $extension->unit),
			toUnit: (string)$timer->getBudgetUnit(),
			calendar: $calendar
		);

		return $this->transactional(
			mutation: function () use ($timer, $calendar, $added, $extension, $act): FlowTimer {
				$priorFireAt = $timer->getFireAt();
				$timer->setBudgetValue((float)$timer->getBudgetValue() + $added);
				$timer->setExtensionCount((int)$timer->getExtensionCount() + 1);
				$this->recomputeFromLedger(timer: $timer, calendar: $calendar);
				$persisted = $this->timers->update($timer);
				$entry = new FlowTimerLedgerEntry(
					type: FlowTimerEvent::TYPE_EXTENDED,
					actor: $act->actor,
					reason: $extension->reason(),
					moment: $act->now
				);
				$entry = $entry->withFireMoments(prior: $priorFireAt, next: $persisted->getFireAt());
				$this->record(timer: $persisted, entry: $entry->withBasis(basis: $extension->basis, impact: $added));
				$this->projection()->project(timer: $persisted);

				return $persisted;
			}
		);
	}//end applyExtension()

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
		$fireAt = $this->deadlines()->landed(scope: new FlowTimerScope(timer: $successor, calendar: $calendar));
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
			$entry = new FlowTimerLedgerEntry(type: FlowTimerEvent::TYPE_CANCELLED, actor: $actor, reason: $reason, moment: $now);
			$this->record(timer: $timer, entry: $entry->withFireMoments(prior: $priorFireAt, next: null));
			$cancelled++;
		}

		if ($cancelled > 0 && isset($timer) === true) {
			$this->projection()->project(timer: $timer);
		}

		return $cancelled;
	}//end cancelAll()

	/**
	 * Apply a fired expiry timer's outcome to a task subject.
	 *
	 * An enforcing timer applies its own validated `onExpiry`. A
	 * non-enforcing expiry timer falls back to the SUBJECT TASK's declared
	 * `onTimeout` (task-expiry-and-outcomes D-4): {@see project()} nulls the
	 * task's `expires_at` once the last expiry timer fires, so without this
	 * fallback the sweep's task scan would never reach a timer-managed task.
	 *
	 * @param FlowTimer $timer The fired timer.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/task-expiry-and-outcomes/spec.md#requirement-a-non-enforcing-expiry-timer-falls-back-to-the-tasks-declared-behaviour
	 */
	private function applyOutcome(FlowTimer $timer): void {
		if ($timer->getSubjectType() !== 'task') {
			return;
		}

		$outcome = $this->projection()->subjectTask(timer: $timer)?->getOnTimeout();
		$basis = 'task-declared onTimeout';
		if ($timer->isEnforcing() === true) {
			$outcome = (string)$timer->getOnExpiry();
			$basis = (string)$timer->getLegalEffect();
		}

		if ($outcome === null || $outcome === '') {
			return;
		}

		try {
			$this->taskService->applyTimerOutcome(
				uuid: (string)$timer->getSubjectUuid(),
				outcome: $outcome,
				source: 'flow-timer:' . (string)$timer->getUuid(),
				reason: sprintf("Expiry timer '%s' (%s) reached its deadline.", (string)$timer->getUuid(), $basis)
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
	 * Derive a timer's deadline against the rungs already in its ledger.
	 *
	 * @param FlowTimer       $timer    The timer, mutated in place.
	 * @param WorkingCalendar $calendar The resolved calendar.
	 *
	 * @return void
	 */
	private function recomputeFromLedger(FlowTimer $timer, WorkingCalendar $calendar): void {
		$firedKeys = $this->firedKeys(timerUuid: (string)$timer->getUuid());
		$this->deadlines()->recompute(scope: new FlowTimerScope(timer: $timer, calendar: $calendar), firedKeys: $firedKeys);
	}//end recomputeFromLedger()

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
	 * @param FlowTimer            $timer The timer.
	 * @param FlowTimerLedgerEntry $entry The row.
	 *
	 * @return void
	 */
	private function record(FlowTimer $timer, FlowTimerLedgerEntry $entry): void {
		$event = new FlowTimerEvent();
		$event->setTimerUuid((string)$timer->getUuid());
		$event->setType($entry->type);
		$event->setActor($entry->actor);
		$event->setReason($entry->reason);
		$event->setPriorFireAt($entry->priorFireAt());
		$event->setNewFireAt($entry->newFireAt());
		$event->setDaysImpact($entry->impact());
		$event->setBasis((new FlowTimerValues())->stringOrNull(value: $entry->basis()));
		// The ledger carries the explanation, not just the timer. A timer holds
		// one deadline; an auditor reading why a term ended on Tuesday a year
		// later is reading the ledger.
		$event->setUnrolledAt($timer->getUnrolledAt());
		$event->setRolledBy($timer->getRolledBy());
		$event->setCreated($this->mutable(value: $entry->moment));
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
		return (new FlowTimerValues())->instant(now: $now);
	}//end instant()

	/**
	 * A mutable copy, as the entities store.
	 *
	 * @param DateTimeInterface $value The instant.
	 *
	 * @return DateTime The copy.
	 */
	private function mutable(DateTimeInterface $value): DateTime {
		return (new FlowTimerValues())->mutable(value: $value);
	}//end mutable()

	/**
	 * The timer and its resolved calendar.
	 *
	 * @param FlowTimer $timer The timer.
	 *
	 * @return FlowTimerScope The scope.
	 */
	private function scope(FlowTimer $timer): FlowTimerScope {
		$calendar = $this->calendars->resolve(calendarSlug: $timer->getCalendarSlug(), organisation: $timer->getOrganisation());
		return new FlowTimerScope(timer: $timer, calendar: $calendar);
	}//end scope()

	/**
	 * The builder of timer rows.
	 *
	 * @return FlowTimerFactory The factory.
	 */
	private function factory(): FlowTimerFactory {
		return new FlowTimerFactory(calendars: $this->calendars, calculator: $this->calculator, ladder: $this->ladder);
	}//end factory()

	/**
	 * The deadline derivation.
	 *
	 * @return FlowTimerDeadlines The deadlines.
	 */
	private function deadlines(): FlowTimerDeadlines {
		return new FlowTimerDeadlines(calculator: $this->calculator, ladder: $this->ladder);
	}//end deadlines()

	/**
	 * The projection onto the subject task.
	 *
	 * @return FlowTimerProjection The projection.
	 */
	private function projection(): FlowTimerProjection {
		return new FlowTimerProjection(timers: $this->timers, tasks: $this->tasks, logger: $this->logger);
	}//end projection()
}//end class
