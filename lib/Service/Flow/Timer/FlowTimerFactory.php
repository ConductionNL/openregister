<?php

/**
 * Builds and validates timer rows. Writes nothing.
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
 * @spec openspec/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeImmutable;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use Symfony\Component\Uid\Uuid;

/**
 * Builds a timer from its configuration, and the successor of a moved anchor.
 */
final class FlowTimerFactory {

	/**
	 * Field families a successor does not inherit from its predecessor.
	 *
	 * @var string[]
	 */
	private const NOT_INHERITED = ['id', 'uuid', 'created', 'updated'];

	/**
	 * Shared conversions.
	 *
	 * @var FlowTimerValues
	 */
	private readonly FlowTimerValues $values;

	/**
	 * Calendar resolution.
	 *
	 * @var WorkingCalendarService
	 */
	private readonly WorkingCalendarService $calendars;

	/**
	 * Business-time arithmetic.
	 *
	 * @var SlaCalculatorInterface
	 */
	private readonly SlaCalculatorInterface $calculator;

	/**
	 * Normalises the escalation rules.
	 *
	 * @var EscalationLadderService
	 */
	private readonly EscalationLadderService $ladder;

	/**
	 * Constructor.
	 *
	 * @param FlowTimerArithmetic $arithmetic The calendar resolution, the business-time arithmetic and the ladder.
	 */
	public function __construct(FlowTimerArithmetic $arithmetic) {
		$this->calendars = $arithmetic->calendars;
		$this->calculator = $arithmetic->calculator;
		$this->ladder = $arithmetic->ladder;
		$this->values = new FlowTimerValues();
	}//end __construct()

	/**
	 * Build and validate a timer from its configuration.
	 *
	 * @param array<string, mixed> $config The configuration.
	 * @param FlowTimerAct         $act    The arming identity and the clock.
	 *
	 * @return FlowTimer The unsaved timer.
	 *
	 * @throws FlowTimerValidationException On any refused value.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function build(array $config, FlowTimerAct $act): FlowTimer {
		$timer = new FlowTimer();
		$this->applySubject(timer: $timer, config: $config);
		$this->applyKind(timer: $timer, config: $config);

		$sla = $this->calculator->validateSla(sla: ($config['sla'] ?? null));
		$this->applyBudget(timer: $timer, sla: $sla);
		$this->applyProvenance(timer: $timer, config: $config);
		$timer->setEscalationRules($this->ladder->normaliseRules(rules: ($config['escalationRules'] ?? []), sla: $sla));
		$this->applyStart(timer: $timer, actor: $act->actor);

		$eventAt = ($this->values->dateOrNull(value: ($config['anchorEventAt'] ?? null), field: 'anchorEventAt') ?? $act->now);
		$timer->setAnchorAt($this->values->mutable(value: $eventAt));
		$this->applyAnchor(timer: $timer, config: $config);
		$timer->setRunningSince($timer->getAnchorAt());

		return $timer;
	}//end build()

	/**
	 * The successor row of a supersession, unsaved.
	 *
	 * @param FlowTimerScope $scope The timer being superseded and its calendar.
	 * @param FlowTimerAct   $act   The clock, and in `when` the new anchoring instant.
	 *
	 * @return FlowTimer The successor.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function successor(FlowTimerScope $scope, FlowTimerAct $act): FlowTimer {
		$prior = $scope->timer;
		$successor = $this->inheritedCopy(prior: $prior);
		$anchorAt = $this->values->mutable(value: $this->movedAnchor(scope: $scope, act: $act));

		$successor->setUuid(Uuid::v4()->toRfc4122());
		$successor->setSupersedesUuid((string)$prior->getUuid());
		$successor->setAnchorAt($anchorAt);
		$successor->setRunningSince($this->values->mutable(value: $anchorAt));
		$successor->setCreated($this->values->mutable(value: $act->now));
		$this->resetLifecycle(successor: $successor);

		// The predecessor's CONSUMED time (its completed segments) carries
		// forward; its running segment does not, because the term now runs
		// from the new anchor and that segment is re-measured from there.
		$successor->setConsumedValue((float)$prior->getConsumedValue());

		return $successor;
	}//end successor()

	/**
	 * Validate and set the subject.
	 *
	 * @param FlowTimer            $timer  The timer being built.
	 * @param array<string, mixed> $config The configuration.
	 *
	 * @return void
	 *
	 * @throws FlowTimerValidationException On a refused subject.
	 */
	private function applySubject(FlowTimer $timer, array $config): void {
		$subjectType = (string)($config['subjectType'] ?? '');
		$subjectUuid = trim((string)($config['subjectUuid'] ?? ''));
		if (in_array($subjectType, FlowTimer::SUBJECT_TYPES, true) === false || $subjectUuid === '') {
			$types = implode(', ', FlowTimer::SUBJECT_TYPES);
			throw new FlowTimerValidationException(
				message: sprintf("A timer requires a subjectType in [%s] and a subjectUuid; got '%s' / '%s'.", $types, $subjectType, $subjectUuid)
			);
		}

		$timer->setSubjectType($subjectType);
		$timer->setSubjectUuid($subjectUuid);
	}//end applySubject()

	/**
	 * Validate and set the purpose, the legal effect and the enforcing outcome.
	 *
	 * @param FlowTimer            $timer  The timer being built.
	 * @param array<string, mixed> $config The configuration.
	 *
	 * @return void
	 *
	 * @throws FlowTimerValidationException On a refused value.
	 */
	private function applyKind(FlowTimer $timer, array $config): void {
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

		$timer->setPurpose($purpose);
		$timer->setLegalEffect($legalEffect);
		$timer->setOnExpiry($this->validOnExpiry(timer: $timer, onExpiry: $this->values->stringOrNull(value: ($config['onExpiry'] ?? null))));
	}//end applyKind()

	/**
	 * The enforcing outcome, permitted ONLY on an expiry timer with legal effect wettelijk.
	 *
	 * @param FlowTimer   $timer    The timer being built, its purpose and legal effect set.
	 * @param string|null $onExpiry The configured outcome.
	 *
	 * @return string|null The outcome, or null for an advisory timer.
	 *
	 * @throws FlowTimerValidationException When an outcome is given where none may be.
	 */
	private function validOnExpiry(FlowTimer $timer, ?string $onExpiry): ?string {
		if ($onExpiry === null) {
			return null;
		}

		$this->refuseAdvisory(timer: $timer, onExpiry: $onExpiry);
		$this->refuseUnknownOutcome(onExpiry: $onExpiry);

		return $onExpiry;
	}//end validOnExpiry()

	/**
	 * Refuse an outcome outside the vocabulary.
	 *
	 * @param string $onExpiry The configured outcome.
	 *
	 * @return void
	 *
	 * @throws FlowTimerValidationException When it is neither reserved nor a transition.
	 */
	private function refuseUnknownOutcome(string $onExpiry): void {
		if (in_array($onExpiry, FlowTimer::RESERVED_OUTCOMES, true) === false && str_starts_with($onExpiry, 'transition:') === false) {
			throw new FlowTimerValidationException(
				message: sprintf("onExpiry '%s' is refused: use skip, error, dead_letter or transition:<action>.", $onExpiry)
			);
		}
	}//end refuseUnknownOutcome()

	/**
	 * Refuse an outcome on a timer that does not enforce.
	 *
	 * @param FlowTimer $timer    The timer being built, its purpose and legal effect set.
	 * @param string    $onExpiry The configured outcome.
	 *
	 * @return void
	 *
	 * @throws FlowTimerValidationException When the timer is not an expiry with legal effect wettelijk.
	 */
	private function refuseAdvisory(FlowTimer $timer, string $onExpiry): void {
		if ($timer->getPurpose() !== FlowTimer::PURPOSE_EXPIRY) {
			throw new FlowTimerValidationException(
				message: sprintf("onExpiry '%s' is refused on a '%s' timer: only an expiry timer enforces.", $onExpiry, (string)$timer->getPurpose())
			);
		}

		if ($timer->getLegalEffect() !== FlowTimer::LEGAL_WETTELIJK) {
			$rule = "onExpiry '%s' is refused: legal effect '%s' is advisory, and only a 'wettelijk' timer may carry an enforcing outcome.";
			throw new FlowTimerValidationException(message: sprintf($rule, $onExpiry, (string)$timer->getLegalEffect()));
		}
	}//end refuseAdvisory()

	/**
	 * Set the budget from the validated SLA.
	 *
	 * `rollToWorkingDay` is `none` unless the configuration asked for something
	 * else. The default is off deliberately: rolling changes a deadline, and one
	 * that moved because the software thought it should is worse than one that
	 * lands on a Sunday.
	 *
	 * @param FlowTimer            $timer The timer being built.
	 * @param array<string, mixed> $sla   The validated SLA.
	 *
	 * @return void
	 */
	private function applyBudget(FlowTimer $timer, array $sla): void {
		$timer->setBudgetValue((float)$sla['value']);
		$timer->setBudgetUnit($sla['unit']);
		$timer->setRollToWorkingDay(($sla['rollToWorkingDay'] ?? SlaCalculatorInterface::ROLL_NONE));
		$timer->setConsumedValue(0.0);
	}//end applyBudget()

	/**
	 * Set where the timer comes from and which calendar and ladder it uses.
	 *
	 * @param FlowTimer            $timer  The timer being built.
	 * @param array<string, mixed> $config The configuration.
	 *
	 * @return void
	 */
	private function applyProvenance(FlowTimer $timer, array $config): void {
		$timer->setOrganisation($this->values->stringOrNull(value: ($config['organisation'] ?? null)));
		$timer->setRunUuid($this->values->stringOrNull(value: ($config['runUuid'] ?? null)));
		$timer->setNodeId($this->values->stringOrNull(value: ($config['nodeId'] ?? null)));
		$timer->setAppId($this->values->stringOrNull(value: ($config['appId'] ?? null)));
		$timer->setTitle($this->values->stringOrNull(value: ($config['title'] ?? null)));
		$timer->setMetadata($this->values->arrayOrNull(value: ($config['metadata'] ?? null)));
		$timer->setCalendarSlug($this->values->stringOrNull(value: ($config['calendar'] ?? null)));
		$timer->setLadderSlug($this->values->stringOrNull(value: ($config['ladder'] ?? null)));
		$timer->setExtensionMax(max(0, (int)($config['extensionMax'] ?? 1)));
		$this->applyCalendarScope(timer: $timer, config: $config);
	}//end applyProvenance()

	/**
	 * Record the record type's calendar and the person the term is computed for.
	 *
	 * A timer that names no calendar of its own takes the one its record type
	 * (`schema`) names, if any; the unit and instance calendars follow as
	 * before. `person` is the user whose working pattern the engine asks for.
	 * Both go into the timer's metadata under
	 * {@see WorkingCalendarScope::METADATA_KEY}, so every later computation
	 * resolves the same way. A timer configured with neither is built exactly
	 * as before.
	 *
	 * @param FlowTimer            $timer  The timer being built, its calendar slug set.
	 * @param array<string, mixed> $config The configuration.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-working-calendar-resolves-per-record-type-unit-and-instance-req-wca-005
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-persons-working-pattern-is-read-from-the-app-that-owns-it-req-wca-006
	 */
	private function applyCalendarScope(FlowTimer $timer, array $config): void {
		$scope  = [];
		$person = $this->values->stringOrNull(value: ($config['person'] ?? null));
		if ($person !== null) {
			$scope['person'] = $person;
		}

		$schema = $this->values->stringOrNull(value: ($config['schema'] ?? null));
		if ($timer->getCalendarSlug() === null && $schema !== null) {
			$slug = $this->calendars->recordTypeCalendar(schema: $schema);
			if ($slug !== null) {
				$timer->setCalendarSlug($slug);
				$scope['scope'] = WorkingCalendarScope::SCOPE_RECORD_TYPE;
				$scope['recordType'] = $schema;
			}
		}

		if ($scope === []) {
			return;
		}

		$metadata = ($timer->getMetadata() ?? []);
		$metadata[WorkingCalendarScope::METADATA_KEY] = $scope;
		$timer->setMetadata($metadata);
	}//end applyCalendarScope()

	/**
	 * Set the state a new timer starts in.
	 *
	 * @param FlowTimer   $timer The timer being built.
	 * @param string|null $actor The arming identity.
	 *
	 * @return void
	 */
	private function applyStart(FlowTimer $timer, ?string $actor): void {
		$timer->setUuid(Uuid::v4()->toRfc4122());
		$timer->setExtensionCount(0);
		$timer->setState(FlowTimer::STATE_ARMED);
		$timer->setBreached(false);
		$timer->setCreatedBy($actor);
		$timer->setSuspendedTotalSeconds(0);
	}//end applyStart()

	/**
	 * Store the anchor and resolve `anchor_at` = anchorEventAt + offset.
	 *
	 * @param FlowTimer            $timer  The timer being built, its anchor at the event instant.
	 * @param array<string, mixed> $config The configuration.
	 *
	 * @return void
	 *
	 * @throws FlowTimerValidationException On a malformed offset.
	 */
	private function applyAnchor(FlowTimer $timer, array $config): void {
		$offset = ($config['anchorOffset'] ?? null);
		$timer->setAnchorEvent($this->values->stringOrNull(value: ($config['anchorEvent'] ?? null)));
		$timer->setAnchorOffset($offset);
		$timer->setAnchorOffsetUnit(null);
		if ($offset === null) {
			return;
		}

		if (is_int($offset) === false) {
			throw new FlowTimerValidationException(message: sprintf("anchorOffset '%s' must be an integer.", var_export($offset, true)));
		}

		$timer->setAnchorOffsetUnit($this->calculator->validateUnit(unit: ($config['anchorOffsetUnit'] ?? SlaCalculatorInterface::UNIT_CALENDAR_DAYS)));
		$this->shiftAnchor(timer: $timer);
	}//end applyAnchor()

	/**
	 * Move the anchor from the event instant by the timer's offset.
	 *
	 * @param FlowTimer $timer The timer being built, its offset and unit set.
	 *
	 * @return void
	 */
	private function shiftAnchor(FlowTimer $timer): void {
		$calendar = WorkingCalendarScope::ofTimer(timer: $timer)->calendarFrom(calendars: $this->calendars);
		$anchorAt = $this->calculator->add(
			addition: new SlaAddition(
				start: DateTimeImmutable::createFromInterface($timer->getAnchorAt()),
				value: (float)$timer->getAnchorOffset(),
				unit: (string)$timer->getAnchorOffsetUnit(),
				calendar: $calendar
			)
		);
		$timer->setAnchorAt($this->values->mutable(value: $anchorAt));
	}//end shiftAnchor()

	/**
	 * A new row carrying every field of the predecessor but its ids and moments.
	 *
	 * @param FlowTimer $prior The timer being superseded.
	 *
	 * @return FlowTimer The copy.
	 */
	private function inheritedCopy(FlowTimer $prior): FlowTimer {
		$successor = new FlowTimer();
		foreach ($prior->jsonSerialize() as $field => $value) {
			if (in_array($field, self::NOT_INHERITED, true) === false && str_ends_with($field, 'At') === false) {
				$setter = 'set' . ucfirst((string)$field);
				$successor->$setter($value);
			}
		}

		return $successor;
	}//end inheritedCopy()

	/**
	 * The successor's anchor: the new event instant plus the predecessor's offset.
	 *
	 * @param FlowTimerScope $scope The timer being superseded and its calendar.
	 * @param FlowTimerAct   $act   Carries the new anchoring instant in `when`.
	 *
	 * @return DateTimeImmutable The anchor.
	 */
	private function movedAnchor(FlowTimerScope $scope, FlowTimerAct $act): DateTimeImmutable {
		$anchorAt = DateTimeImmutable::createFromInterface($act->when);
		$prior = $scope->timer;
		if ($prior->getAnchorOffset() === null) {
			return $anchorAt;
		}

		return $this->calculator->add(
			addition: new SlaAddition(
				start: DateTimeImmutable::createFromInterface($anchorAt),
				value: (float)$prior->getAnchorOffset(),
				unit: (string)$prior->getAnchorOffsetUnit(),
				calendar: $scope->calendar
			)
		);
	}//end movedAnchor()

	/**
	 * Clear the lifecycle fields a successor must not carry over.
	 *
	 * @param FlowTimer $successor The successor.
	 *
	 * @return void
	 */
	private function resetLifecycle(FlowTimer $successor): void {
		$successor->setState(FlowTimer::STATE_ARMED);
		$successor->setSuspendedSince(null);
		$successor->setSuspendReason(null);
		$successor->setFiredAt(null);
		$successor->setCancelledAt(null);
		$successor->setCancelReason(null);
		$successor->setBreached(false);
	}//end resetLifecycle()
}//end class
