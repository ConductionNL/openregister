<?php

/**
 * Resolves the ONE working calendar a timer measures against.
 *
 * Resolution order, fixed: the calendar named on the timer, else the calendar
 * configured for the subject's organisation, else the seeded national
 * default. An unresolvable name is an error at ARM time with the name in the
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
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

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
	 * Constructor.
	 *
	 * @param FlowTimerDefinitionStore $definitions The seeded calendar definitions.
	 */
	public function __construct(
		private readonly FlowTimerDefinitionStore $definitions,
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
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
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
	 * Forget resolved calendars (once per sweep pass).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function reset(): void {
		$this->resolved = [];
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
			throw new FlowTimerValidationException(
				message: sprintf(
					"Working calendar '%s' does not exist; known calendars: %s. No weekday-only substitute is made.",
					$slug,
					implode(', ', array_keys($definitions))
				)
			);
		}

		$calendar = WorkingCalendar::fromArray(definition: $definitions[$slug]);
		$this->resolved[$slug] = $calendar;

		return $calendar;
	}//end bySlug()

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
