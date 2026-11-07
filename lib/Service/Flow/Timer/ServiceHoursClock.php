<?php

/**
 * Advancing an hours term only while the counter is open.
 *
 * Four service hours from Friday at 16:00, on a calendar open nine to five,
 * land on Monday at 11:00. One hour on Friday, three on Monday. Before service
 * hours existed the answer came from a single opening minute and a day length,
 * which is right only for an organisation whose day is one unbroken block, and
 * wrong by the length of the lunch break for one that closes at midday.
 *
 * 🔴 THE WALK IS BOUNDED, AND RUNNING OUT IS AN ERROR RATHER THAN AN ANSWER. A
 * calendar whose every weekday window was somehow empty would otherwise walk
 * forward forever, or, worse, be given a cap and return whatever date the cap
 * landed on. A term that silently lands a year out is indistinguishable from
 * one that is correct, and somebody's statutory deadline is computed from it.
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
 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeImmutable;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use DateTimeInterface;
use DateTimeZone;

/**
 * Walks an hours term through a calendar's declared windows.
 */
class ServiceHoursClock {

	/**
	 * The most days the walk will cross before it refuses.
	 *
	 * Generous enough for a year of holidays, short enough that a calendar with
	 * no open minutes fails in a test rather than in a request.
	 *
	 * @var int
	 */
	public const MAX_WALK_DAYS = 3650;

	/**
	 * The moment an hours term armed at `$from` is due.
	 *
	 * @param DateTimeInterface $from     When the term was armed.
	 * @param float             $hours    How many service hours it runs for.
	 * @param WorkingCalendar   $calendar The calendar deciding the days.
	 * @param ServiceHours      $windows  Its declared windows.
	 *
	 * @return DateTimeImmutable The moment it is due, in the calendar's zone.
	 *
	 * @throws FlowTimerValidationException When the calendar never opens.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/flow-business-timers/spec.md
	 */
	public function due(DateTimeInterface $from, float $hours, WorkingCalendar $calendar, ServiceHours $windows): DateTimeImmutable {
		$moment = self::inZone(moment: $from, calendar: $calendar);
		$remaining = (int)round($hours * 60);
		if ($remaining <= 0) {
			return $moment;
		}

		// 🔴 NOT A BEST GUESS. A calendar that never opens has no answer to
		// "when are four hours up", and returning the cap's date would put a
		// deadline on a case that nobody could tell from a real one.
		return (new ServiceHoursWalk(calendar: $calendar, windows: $windows))->dueAfter(moment: $moment, minutes: $remaining)
			?? throw new FlowTimerValidationException(
				message: sprintf(
					"Working calendar '%s' has no open service hours in the next %d days, so an hours term cannot be computed against it.",
					$calendar->getSlug(),
					self::MAX_WALK_DAYS
				)
			);
	}//end due()

	/**
	 * How much open service time lies between two instants.
	 *
	 * The mirror of {@see due()}, and it exists for the same reason the two
	 * halves of every other unit live side by side: a deadline computed
	 * inside the windows and an elapsed figure measured across one unbroken
	 * block disagree by the length of the lunch break, and the report and the
	 * badge then say different things about one case. Whoever reads them
	 * cannot tell which is wrong.
	 *
	 * @param DateTimeInterface $from     The earlier instant.
	 * @param DateTimeInterface $to       The later instant; equal or earlier yields 0.0.
	 * @param WorkingCalendar   $calendar The calendar deciding the days.
	 * @param ServiceHours      $windows  Its declared windows.
	 *
	 * @return float The open hours between the two.
	 *
	 * @throws FlowTimerValidationException When the span exceeds the bounded walk.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/flow-business-timers/spec.md
	 */
	public function elapsed(DateTimeInterface $from, DateTimeInterface $to, WorkingCalendar $calendar, ServiceHours $windows): float {
		$start = self::inZone(moment: $from, calendar: $calendar);
		$end = self::inZone(moment: $to, calendar: $calendar);
		if ($end <= $start) {
			return 0.0;
		}

		$minutes = (new ServiceHoursWalk(calendar: $calendar, windows: $windows))->minutesBetween(start: $start, end: $end)
			?? throw new FlowTimerValidationException(
				message: sprintf('Measuring service hours between %s and %s exceeds %d calendar days.', $from->format('c'), $to->format('c'), self::MAX_WALK_DAYS)
			);

		return round(($minutes / 60), 4);
	}//end elapsed()

	/**
	 * The diagnostic that explains one computed term.
	 *
	 * The answer explains itself or it cannot be argued with. A handler told a
	 * deadline is Monday at 11:00 needs to see which calendar said so and which
	 * windows it applied, because the alternative is a support ticket that
	 * nobody can answer without a debugger.
	 *
	 * @param WorkingCalendar $calendar The calendar that decided it.
	 * @param ServiceHours    $windows  The windows applied.
	 *
	 * @return array<string, mixed> The diagnostic.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/flow-business-timers/spec.md
	 */
	public function diagnostic(WorkingCalendar $calendar, ServiceHours $windows): array {
		$applied = [];
		foreach ($windows->all() as $iso => $dayWindows) {
			$printed = [];
			foreach ($dayWindows as $window) {
				$printed[] = $this->printMinute(minute: $window['start']).'-'.$this->printMinute(minute: $window['end']);
			}

			$applied[(int)$iso] = $printed;
		}

		return [
			'calendar' => $calendar->getSlug(),
			'timezone' => $calendar->getTimezone(),
			'serviceHoursDeclared' => $windows->areDeclared(),
			'windows' => $applied,
		];
	}//end diagnostic()

	/**
	 * An instant in the calendar's zone.
	 *
	 * @param DateTimeInterface $moment   The instant.
	 * @param WorkingCalendar   $calendar The calendar whose zone counts.
	 *
	 * @return DateTimeImmutable The same instant, in that zone.
	 */
	private static function inZone(DateTimeInterface $moment, WorkingCalendar $calendar): DateTimeImmutable {
		return (new DateTimeImmutable('@'.$moment->getTimestamp()))->setTimezone(new DateTimeZone($calendar->getTimezone()));
	}//end inZone()

	/**
	 * One minute as `HH:MM`, for the diagnostic.
	 *
	 * @param int $minute Minutes past midnight.
	 *
	 * @return string The time.
	 */
	private function printMinute(int $minute): string {
		return sprintf('%02d:%02d', intdiv($minute, 60), ($minute % 60));
	}//end printMinute()
}//end class
