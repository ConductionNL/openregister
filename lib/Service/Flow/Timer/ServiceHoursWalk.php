<?php

/**
 * The day-by-day walk through one calendar's open service windows.
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

/**
 * Walks forward over the open minutes of a calendar's windows, bounded by
 * {@see ServiceHoursClock::MAX_WALK_DAYS}.
 */
final class ServiceHoursWalk {

	/**
	 * Constructor.
	 *
	 * @param WorkingCalendar $calendar The calendar deciding the days.
	 * @param ServiceHours    $windows  Its declared windows.
	 */
	public function __construct(
		private readonly WorkingCalendar $calendar,
		private readonly ServiceHours $windows,
	) {

	}//end __construct()

	/**
	 * The moment a number of open minutes after a moment have passed.
	 *
	 * Only the day the term was armed on starts partway through. The cursor
	 * is held across the loop and reset once the day rolls, so that reset is
	 * load-bearing: reading the minute off each day would make it redundant,
	 * and a redundant guard is one a later edit can delete without any test
	 * noticing.
	 *
	 * @param DateTimeImmutable $moment  The start, in the calendar's zone.
	 * @param int               $minutes The open minutes to consume, > 0.
	 *
	 * @return DateTimeImmutable|null The moment, or null when the walk's bound is reached first.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/flow-business-timers/spec.md
	 */
	public function dueAfter(DateTimeImmutable $moment, int $minutes): ?DateTimeImmutable {
		$day = $moment;
		$cursor = self::minuteOfDay(moment: $moment);
		$remaining = $minutes;
		for ($crossed = 0; $crossed <= ServiceHoursClock::MAX_WALK_DAYS; $crossed++) {
			foreach ($this->openSpans(day: $day, fromMinute: $cursor) as [$start, $end]) {
				if ($remaining <= ($end - $start)) {
					return self::atMinute(day: $day, minute: ($start + $remaining));
				}

				$remaining -= ($end - $start);
			}

			$day = $day->modify('+1 day')->setTime(0, 0);
			$cursor = 0;
		}

		return null;
	}//end dueAfter()

	/**
	 * The open minutes between two moments.
	 *
	 * @param DateTimeImmutable $start The earlier moment, in the calendar's zone.
	 * @param DateTimeImmutable $end   The later moment, in the calendar's zone.
	 *
	 * @return int|null The minutes, or null when the span exceeds the walk's bound.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/flow-business-timers/spec.md
	 */
	public function minutesBetween(DateTimeImmutable $start, DateTimeImmutable $end): ?int {
		$span = [$start->getTimestamp(), $end->getTimestamp()];
		$day = $start->setTime(0, 0);
		$minutes = 0;
		for ($crossed = 0; $crossed <= ServiceHoursClock::MAX_WALK_DAYS; $crossed++) {
			if ($day > $end) {
				return $minutes;
			}

			$minutes += $this->minutesOn(day: $day, span: $span);
			$day = $day->modify('+1 day')->setTime(0, 0);
		}

		return null;
	}//end minutesBetween()

	/**
	 * The parts of a day's windows from a minute on, as `[start, end]` minutes.
	 *
	 * @param DateTimeImmutable $day        The day.
	 * @param int               $fromMinute The minute the day is counted from.
	 *
	 * @return array<int, array{0: int, 1: int}> The open spans, in order; none on a non-working day.
	 */
	private function openSpans(DateTimeImmutable $day, int $fromMinute): array {
		$spans = [];
		foreach ($this->windowsOn(day: $day) as $window) {
			$start = max($fromMinute, $window['start']);
			if ($start < $window['end']) {
				$spans[] = [$start, $window['end']];
			}
		}

		return $spans;
	}//end openSpans()

	/**
	 * The open minutes of one day that fall inside a span.
	 *
	 * @param DateTimeImmutable $day  The day.
	 * @param array{0: int, 1: int} $span The span's start and end, as timestamps.
	 *
	 * @return integer The whole minutes, window by window.
	 */
	private function minutesOn(DateTimeImmutable $day, array $span): int {
		$minutes = 0;
		foreach ($this->windowsOn(day: $day) as $window) {
			$opens = self::atMinute(day: $day, minute: $window['start'])->getTimestamp();
			$closes = self::atMinute(day: $day, minute: $window['end'])->getTimestamp();
			$overlap = (min($closes, $span[1]) - max($opens, $span[0]));
			if ($overlap > 0) {
				$minutes += intdiv($overlap, 60);
			}
		}

		return $minutes;
	}//end minutesOn()

	/**
	 * A day's windows, or none when the calendar does not work that day.
	 *
	 * @param DateTimeImmutable $day The day.
	 *
	 * @return array<int, array{start: int, end: int}> The windows.
	 */
	private function windowsOn(DateTimeImmutable $day): array {
		if ($this->calendar->isWorkingDay($day) === false) {
			return [];
		}

		return $this->windows->forWeekday(iso: (int)$day->format('N'));
	}//end windowsOn()

	/**
	 * Minutes past midnight of one moment.
	 *
	 * @param DateTimeImmutable $moment The moment.
	 *
	 * @return int The minutes.
	 */
	private static function minuteOfDay(DateTimeImmutable $moment): int {
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
	private static function atMinute(DateTimeImmutable $day, int $minute): DateTimeImmutable {
		return $day->setTime(intdiv($minute, 60), ($minute % 60));
	}//end atMinute()
}//end class
