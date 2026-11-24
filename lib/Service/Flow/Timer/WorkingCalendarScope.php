<?php

/**
 * What a term is computed for, as far as choosing its working calendar goes.
 *
 * The calendar named on the timer (by its configuration, or by the record
 * type it was armed on), the organisational unit, and the person the term is
 * computed for. {@see WorkingCalendarService::resolveScoped()} turns this into
 * the one calendar the term measures against, in the order record type, unit,
 * instance (design D-C67-1).
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
 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-working-calendar-resolves-per-record-type-unit-and-instance-req-wca-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use OCA\OpenRegister\Db\FlowTimer;

/**
 * The inputs of calendar resolution for one term.
 */
final class WorkingCalendarScope {

	/**
	 * The timer metadata key the arm path records the scope under.
	 *
	 * @var string
	 */
	public const METADATA_KEY = 'workingCalendar';

	/**
	 * The calendar was named on the timer's own configuration.
	 *
	 * @var string
	 */
	public const SCOPE_TIMER = 'timer';

	/**
	 * The calendar was named by the record type (schema) the timer was armed on.
	 *
	 * @var string
	 */
	public const SCOPE_RECORD_TYPE = 'recordType';

	/**
	 * The calendar belongs to the subject's organisational unit.
	 *
	 * @var string
	 */
	public const SCOPE_UNIT = 'unit';

	/**
	 * Nothing nearer named one: the instance calendar.
	 *
	 * @var string
	 */
	public const SCOPE_INSTANCE = 'instance';

	/**
	 * Constructor.
	 *
	 * @param string|null $calendarSlug  The calendar named for this term, or null.
	 * @param string      $namedBy       Who named it: {@see self::SCOPE_TIMER} or {@see self::SCOPE_RECORD_TYPE}.
	 * @param string|null $organisation  The organisational unit, or null.
	 * @param string|null $person        The user the term is computed for, or null.
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-working-calendar-resolves-per-record-type-unit-and-instance-req-wca-005
	 */
	public function __construct(
		public readonly ?string $calendarSlug = null,
		public readonly string $namedBy = self::SCOPE_TIMER,
		public readonly ?string $organisation = null,
		public readonly ?string $person = null,
	) {
	}//end __construct()

	/**
	 * The scope a timer was armed with: its calendar, its organisation and what the arm path recorded.
	 *
	 * A timer armed before this scope existed carries no record, which reads
	 * as a calendar named on the timer (when it has one) and no person: the
	 * resolution it had before.
	 *
	 * @param FlowTimer $timer The timer.
	 *
	 * @return self The scope.
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-working-calendar-resolves-per-record-type-unit-and-instance-req-wca-005
	 */
	public static function ofTimer(FlowTimer $timer): self {
		$recorded = (($timer->getMetadata() ?? [])[self::METADATA_KEY] ?? []);
		if (is_array($recorded) === false) {
			$recorded = [];
		}

		$namedBy = self::SCOPE_TIMER;
		if (($recorded['scope'] ?? null) === self::SCOPE_RECORD_TYPE) {
			$namedBy = self::SCOPE_RECORD_TYPE;
		}

		return new self(
			calendarSlug: $timer->getCalendarSlug(),
			namedBy: $namedBy,
			organisation: $timer->getOrganisation(),
			person: self::text(value: ($recorded['person'] ?? null))
		);
	}//end ofTimer()

	/**
	 * The calendar a timer with this scope measures against.
	 *
	 * Without a person this is the plain resolution the engine always made
	 * (the record type's calendar, when it named one, is the timer's own
	 * calendar slug by then), so a timer for which nothing new was declared
	 * resolves exactly as before. With a person, the scoped resolution lays
	 * their working pattern over it when an app answers.
	 *
	 * @param WorkingCalendarService $calendars The resolver.
	 *
	 * @return WorkingCalendar The calendar.
	 *
	 * @throws \OCA\OpenRegister\Exception\FlowTimerValidationException When the named calendar does not exist.
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-persons-working-pattern-is-read-from-the-app-that-owns-it-req-wca-006
	 */
	public function calendarFrom(WorkingCalendarService $calendars): WorkingCalendar {
		if ($this->person === null) {
			return $calendars->resolve(calendarSlug: $this->calendarSlug, organisation: $this->organisation);
		}

		return $calendars->resolveScoped(scope: $this)->calendar;
	}//end calendarFrom()

	/**
	 * A trimmed string, or null when empty or not a string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The text.
	 */
	private static function text(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end text()
}//end class
