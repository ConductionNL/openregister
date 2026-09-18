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
		$zone = new DateTimeZone($calendar->getTimezone());
		$moment = (new DateTimeImmutable('@'.$from->getTimestamp()))->setTimezone($zone);
		$remaining = (int)round($hours * 60);

		if ($remaining <= 0) {
			return $moment;
		}

		$day = $moment;

		// Only the day the term was armed on starts partway through. The cursor
		// is held across the loop and reset once the day rolls, so that reset is
		// load-bearing: reading the minute off each day would make it redundant,
		// and a redundant guard is one a later edit can delete without any test
		// noticing.
		$cursor = $this->minuteOfDay(moment: $moment);

		for ($crossed = 0; $crossed <= self::MAX_WALK_DAYS; $crossed++) {
			if ($calendar->isWorkingDay($day) === true) {
				foreach ($windows->forWeekday(iso: (int)$day->format('N')) as $window) {
					$start = max($cursor, $window['start']);
					if ($start >= $window['end']) {
						continue;
					}

					$available = ($window['end'] - $start);
					if ($remaining <= $available) {
						return $this->atMinute(day: $day, minute: ($start + $remaining));
					}

					$remaining -= $available;
				}
			}//end if

			$day = $day->modify('+1 day')->setTime(0, 0);
			$cursor = 0;
		}//end for

		// 🔴 NOT A BEST GUESS. A calendar that never opens has no answer to
		// "when are four hours up", and returning the cap's date would put a
		// deadline on a case that nobody could tell from a real one.
		throw new FlowTimerValidationException(
			message: sprintf(
				"Working calendar '%s' has no open service hours in the next %d days, so an hours term cannot be computed against it.",
				$calendar->getSlug(),
				self::MAX_WALK_DAYS
			)
		);
	}//end due()

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
	 * Minutes past midnight of one moment.
	 *
	 * @param DateTimeImmutable $moment The moment.
	 *
	 * @return int The minutes.
	 */
	private function minuteOfDay(DateTimeImmutable $moment): int {
		return (((int)$moment->format('G') * 60) + (int)$moment->format('i'));
	}//end minuteOfDay()

	/**
	 * One minute of one day, as a moment.
	 *
	 * @param DateTimeImmutable $day    The day.
	 * @param int               $minute Minutes past midnight.
	 *
	 * @return DateTimeImmutable The moment.
	 */
	private function atMinute(DateTimeImmutable $day, int $minute): DateTimeImmutable {
		return $day->setTime(intdiv($minute, 60), ($minute % 60));
	}//end atMinute()

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
