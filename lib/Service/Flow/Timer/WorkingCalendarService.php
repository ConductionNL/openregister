<?php

/**
 * Resolves the ONE working calendar a timer measures against.
 *
 * Resolution order, fixed: the calendar named on the timer (by its own
 * configuration, or by the record type it was armed on), else the calendar
 * configured for the subject's organisation, else the seeded national
 * default (design D-C67-1). When the term is computed for a named person and
 * an installed app answers with that person's working pattern, the pattern is
 * laid over the resolved calendar; otherwise the calendar decides (D-C67-2). An unresolvable name is an error at ARM time with the name in the
 * message — never a quiet downgrade to weekdays at fire time, because that
 * downgrade is precisely the failure mode live in the fleet today (design
 * D-5). Resolved calendars are memoised per slug; the sweep resets the store
 * once per pass, which is where the per-(calendar, year) cost concentrates.
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
 * @spec openspec/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-working-calendar-resolves-per-record-type-unit-and-instance-req-wca-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * Timer → organisation → seeded default, refusing rather than downgrading.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) WorkingCalendar::fromArray() is the
 * value object's validating named constructor.
 */
class WorkingCalendarService {

	/**
	 * Resolved calendars, keyed by slug.
	 *
	 * @var array<string, WorkingCalendar>
	 */
	private array $resolved = [];

	/**
	 * Calendars with a person's pattern laid over them, keyed by slug and person.
	 *
	 * @var array<string, ResolvedWorkingCalendar>
	 */
	private array $personal = [];

	/**
	 * Constructor.
	 *
	 * @param FlowTimerDefinitionStore     $definitions The seeded calendar definitions.
	 * @param WorkingPatternProviders|null $patterns    The apps that answer with a person's working pattern; none, and the calendar decides.
	 * @param RecordTypeCalendars|null     $recordTypes Reads the calendar a schema names; none, and no record type names one.
	 */
	public function __construct(
		private readonly FlowTimerDefinitionStore $definitions,
		private readonly ?WorkingPatternProviders $patterns = null,
		private readonly ?RecordTypeCalendars $recordTypes = null,
	) {

	}//end __construct()

	/**
	 * Resolve the calendar for a timer.
	 *
	 * @param string|null $calendarSlug The calendar named on the timer, when any.
	 * @param string|null $organisation The subject's organisation, when any.
	 *
	 * @return WorkingCalendar The resolved calendar.
	 *
	 * @throws FlowTimerValidationException When the named calendar, or the
	 *         default, does not exist — naming the missing calendar.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function resolve(?string $calendarSlug, ?string $organisation): WorkingCalendar {
		$named = trim((string)$calendarSlug);
		if ($named !== '') {
			return $this->bySlug(slug: $named);
		}

		$organisationSlug = $this->organisationCalendarSlug(organisation: $organisation);
		if ($organisationSlug !== null) {
			return $this->bySlug(slug: $organisationSlug);
		}

		return $this->bySlug(slug: WorkingCalendar::DEFAULT_SLUG);
	}//end resolve()

	/**
	 * Resolve the calendar for a scope, and say which scope named it and whether a person's pattern applied.
	 *
	 * Record type (or the timer itself), then unit, then instance. A scope
	 * that names nothing falls through, so an instance where nothing is
	 * declared resolves exactly as {@see self::resolve()} always did.
	 *
	 * @param WorkingCalendarScope $scope The calendar named for the term, the unit and the person.
	 *
	 * @return ResolvedWorkingCalendar The calendar and its provenance.
	 *
	 * @throws FlowTimerValidationException When a named calendar does not exist.
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-working-calendar-resolves-per-record-type-unit-and-instance-req-wca-005
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-persons-working-pattern-is-read-from-the-app-that-owns-it-req-wca-006
	 */
	public function resolveScoped(WorkingCalendarScope $scope): ResolvedWorkingCalendar {
		[$slug, $named] = $this->scopedSlug(scope: $scope);
		$person = $scope->person;
		if ($person === null || trim($person) === '') {
			return new ResolvedWorkingCalendar(calendar: $this->bySlug(slug: $slug), scope: $named);
		}

		$person = trim($person);
		if ($this->patterns === null) {
			return new ResolvedWorkingCalendar(calendar: $this->bySlug(slug: $slug), scope: $named, person: $person);
		}

		$key = $slug . "\0" . $named . "\0" . $person;
		if (array_key_exists($key, $this->personal) === false) {
			$this->personal[$key] = $this->withPattern(slug: $slug, named: $named, person: $person);
		}

		return $this->personal[$key];
	}//end resolveScoped()

	/**
	 * Resolve the calendar a timer measures against, with the scope its arm path recorded.
	 *
	 * @param FlowTimer $timer The timer.
	 *
	 * @return ResolvedWorkingCalendar The calendar and its provenance.
	 *
	 * @throws FlowTimerValidationException When the named calendar does not exist.
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-working-calendar-resolves-per-record-type-unit-and-instance-req-wca-005
	 */
	public function resolveForTimer(FlowTimer $timer): ResolvedWorkingCalendar {
		return $this->resolveScoped(scope: WorkingCalendarScope::ofTimer(timer: $timer));
	}//end resolveForTimer()

	/**
	 * The calendar slug a record type names, or null when it names none or cannot be read.
	 *
	 * @param string $schema The schema id, uuid or slug.
	 *
	 * @return string|null The slug.
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-working-calendar-resolves-per-record-type-unit-and-instance-req-wca-005
	 */
	public function recordTypeCalendar(string $schema): ?string {
		if ($this->recordTypes === null) {
			return null;
		}

		return $this->recordTypes->calendarOf(schema: $schema);
	}//end recordTypeCalendar()

	/**
	 * The slug the scope resolves to, and which scope named it.
	 *
	 * @param WorkingCalendarScope $scope The scope.
	 *
	 * @return array{0: string, 1: string} The slug and the WorkingCalendarScope::SCOPE_* that named it.
	 */
	private function scopedSlug(WorkingCalendarScope $scope): array {
		$named = trim((string)$scope->calendarSlug);
		if ($named !== '') {
			return [$named, $scope->namedBy];
		}

		$organisationSlug = $this->organisationCalendarSlug(organisation: $scope->organisation);
		if ($organisationSlug !== null) {
			return [$organisationSlug, WorkingCalendarScope::SCOPE_UNIT];
		}

		return [WorkingCalendar::DEFAULT_SLUG, WorkingCalendarScope::SCOPE_INSTANCE];
	}//end scopedSlug()

	/**
	 * The scope calendar with the person's pattern laid over it, or the calendar alone when nobody answers.
	 *
	 * The pattern replaces the working weekdays and hours it gives, and adds
	 * its absences as non-working days. The calendar's own closure days, zone,
	 * rules and blackouts stay: a person's pattern says when they work, not
	 * when the organisation is closed. Nothing of the pattern is stored.
	 *
	 * @param string $slug   The scope calendar.
	 * @param string $named  Which scope named it.
	 * @param string $person The user.
	 *
	 * @return ResolvedWorkingCalendar The resolution.
	 *
	 * @throws FlowTimerValidationException When the scope calendar does not exist.
	 */
	private function withPattern(string $slug, string $named, string $person): ResolvedWorkingCalendar {
		$calendar = $this->bySlug(slug: $slug);
		$pattern = $this->patterns?->patternFor(userId: $person);
		if ($pattern === null) {
			return new ResolvedWorkingCalendar(calendar: $calendar, scope: $named, person: $person);
		}

		try {
			$overlaid = $calendar->withPersonalDays(
				weekdays: $pattern['workingWeekdays'],
				hours: $pattern['hoursPerWorkingDay'],
				absences: $pattern['absences']
			);
		} catch (FlowTimerValidationException $refused) {
			// A pattern the calendar cannot carry is not applied: the calendar
			// decides, and the diagnostic says so, rather than failing a term.
			return new ResolvedWorkingCalendar(calendar: $calendar, scope: $named, person: $person);
		}

		return new ResolvedWorkingCalendar(
			calendar: $overlaid,
			scope: $named,
			pattern: ResolvedWorkingCalendar::PATTERN_PERSON,
			person: $person,
			patternSource: $pattern['source']
		);
	}//end withPattern()

	/**
	 * Forget resolved calendars (once per sweep pass).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function reset(): void {
		$this->resolved = [];
		$this->personal = [];
		$this->definitions->reset();
	}//end reset()

	/**
	 * A calendar by name, validated and memoised.
	 *
	 * @param string $slug The calendar name.
	 *
	 * @return WorkingCalendar The calendar.
	 *
	 * @throws FlowTimerValidationException When no calendar carries that name.
	 */
	private function bySlug(string $slug): WorkingCalendar {
		if (array_key_exists($slug, $this->resolved) === true) {
			return $this->resolved[$slug];
		}

		$definitions = $this->definitions->calendars();
		if (array_key_exists($slug, $definitions) === false) {
			throw self::unknownCalendar(slug: $slug, definitions: $definitions);
		}

		$calendar = WorkingCalendar::fromArray(definition: $definitions[$slug]);
		$this->resolved[$slug] = $calendar;

		return $calendar;
	}//end bySlug()

	/**
	 * The refusal of a calendar slug that names no defined calendar.
	 *
	 * @param string               $slug        The slug asked for.
	 * @param array<string, mixed> $definitions The defined calendars, by slug.
	 *
	 * @return FlowTimerValidationException The refusal.
	 */
	private static function unknownCalendar(string $slug, array $definitions): FlowTimerValidationException {
		return new FlowTimerValidationException(
			message: sprintf(
				"Working calendar '%s' does not exist; known calendars: %s. No weekday-only substitute is made.",
				$slug,
				implode(', ', array_keys($definitions))
			)
		);
	}//end unknownCalendar()

	/**
	 * The slug of the calendar configured for an organisation, when one is.
	 *
	 * @param string|null $organisation The organisation uuid.
	 *
	 * @return string|null The slug, or null when the organisation has no calendar of its own.
	 */
	private function organisationCalendarSlug(?string $organisation): ?string {
		$organisation = trim((string)$organisation);
		if ($organisation === '') {
			return null;
		}

		foreach ($this->definitions->calendars() as $slug => $definition) {
			if (trim((string)($definition['organisation'] ?? '')) === $organisation) {
				return (string)$slug;
			}
		}

		return null;
	}//end organisationCalendarSlug()
}//end class
