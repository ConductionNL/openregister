<?php

/**
 * A working calendar with its declared service-hour windows: what an hours term is measured in.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * The calendar deciding the days and the windows deciding the hours.
 *
 * Built by {@see ServiceHoursClock::frame()} for one computation.
 *
 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/flow-business-timers/spec.md
 */
final class ServiceHoursFrame {
	/**
	 * Constructor.
	 *
	 * @param WorkingCalendar $calendar The calendar deciding the days.
	 * @param ServiceHours    $windows  Its declared windows.
	 */
	public function __construct(
		public readonly WorkingCalendar $calendar,
		public readonly ServiceHours $windows,
	) {
	}//end __construct()

	/**
	 * The moment an hours term armed at `$from` is due.
	 *
	 * @param DateTimeInterface $from  When the term was armed.
	 * @param float             $hours How many service hours it runs for.
	 *
	 * @return DateTimeImmutable The moment it is due, in the calendar's zone.
	 *
	 * @throws FlowTimerValidationException When the calendar never opens.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/flow-business-timers/spec.md
	 */
	public function due(DateTimeInterface $from, float $hours): DateTimeImmutable {
		$moment = $this->inZone(moment: $from);
		$remaining = (int)round($hours * 60);
		if ($remaining <= 0) {
			return $moment;
		}

		// 🔴 NOT A BEST GUESS. A calendar that never opens has no answer to
		// "when are four hours up", and returning the cap's date would put a
		// deadline on a case that nobody could tell from a real one.
		return (new ServiceHoursWalk(calendar: $this->calendar, windows: $this->windows))->dueAfter(moment: $moment, minutes: $remaining)
			?? throw new FlowTimerValidationException(
				message: sprintf(
					"Working calendar '%s' has no open service hours in the next %d days, so an hours term cannot be computed against it.",
					$this->calendar->getSlug(),
					ServiceHoursClock::MAX_WALK_DAYS
				)
			);
	}//end due()

	/**
	 * How much open service time lies between two instants.
	 *
	 * @param DateTimeInterface $from The earlier instant.
	 * @param DateTimeInterface $to   The later instant; equal or earlier yields 0.0.
	 *
	 * @return float The open hours between the two.
	 *
	 * @throws FlowTimerValidationException When the span exceeds the bounded walk.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/flow-business-timers/spec.md
	 */
	public function elapsed(DateTimeInterface $from, DateTimeInterface $to): float {
		$start = $this->inZone(moment: $from);
		$end = $this->inZone(moment: $to);
		if ($end <= $start) {
			return 0.0;
		}

		$minutes = (new ServiceHoursWalk(calendar: $this->calendar, windows: $this->windows))->minutesBetween(start: $start, end: $end)
			?? throw new FlowTimerValidationException(
				message: sprintf(
					'Measuring service hours between %s and %s exceeds %d calendar days.',
					$from->format('c'),
					$to->format('c'),
					ServiceHoursClock::MAX_WALK_DAYS
				)
			);

		return round(($minutes / 60), 4);
	}//end elapsed()

	/**
	 * The same instant, in the calendar's zone.
	 *
	 * @param DateTimeInterface $moment Any instant.
	 *
	 * @return DateTimeImmutable The instant in the calendar's zone.
	 */
	private function inZone(DateTimeInterface $moment): DateTimeImmutable {
		return (new DateTimeImmutable('@'.$moment->getTimestamp()))->setTimezone(new DateTimeZone($this->calendar->getTimezone()));
	}//end inZone()
}//end class
