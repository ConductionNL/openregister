<?php

/**
 * A calendar's `serviceHours` declaration, read and VALIDATED.
 *
 * 🔴 THE REFUSALS NAME THE WEEKDAY. A window whose end is not after its start,
 * two windows overlapping on one weekday, and a window on a day the calendar
 * does not work are each refused, and each says which weekday.
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

use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * Validates the windows per weekday that {@see ServiceHours} holds.
 */
final class ServiceHoursDeclaration {

	/**
	 * Constructor.
	 *
	 * @param string          $slug            The calendar, for the refusals.
	 * @param array<int, int> $workingWeekdays The ISO weekdays the calendar works.
	 */
	public function __construct(
		private readonly string $slug,
		private readonly array $workingWeekdays,
	) {

	}//end __construct()

	/**
	 * The declared windows per ISO weekday, validated, ordered by weekday.
	 *
	 * @param mixed $value The declared map of weekday to windows.
	 *
	 * @return array<int, array<int, array{start: int, end: int}>> The windows.
	 *
	 * @throws FlowTimerValidationException On any refused weekday or window.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/flow-business-timers/spec.md
	 */
	public function windows(mixed $value): array {
		if (is_array($value) === false) {
			throw $this->refusal(template: 'declares serviceHours that is not a map of weekday to windows.', detail: '');
		}

		$windows = [];
		foreach ($value as $weekday => $declared) {
			$iso = $this->workingWeekday(weekday: $weekday);
			$windows[$iso] = $this->dayWindows(declared: $declared, iso: $iso);
		}

		ksort($windows);

		return $windows;
	}//end windows()

	/**
	 * A declared weekday as the ISO number of one of the calendar's working days.
	 *
	 * A window on a day the calendar does not work is refused rather than
	 * ignored: it is somebody believing the office is open, and silently
	 * dropping it leaves them believing it.
	 *
	 * @param int|string $weekday The declared weekday key.
	 *
	 * @return integer The ISO weekday.
	 *
	 * @throws FlowTimerValidationException When it is no weekday, or not a working one.
	 */
	private function workingWeekday(int|string $weekday): int {
		$iso = self::isoWeekday(weekday: $weekday);
		if ($iso === null) {
			throw $this->refusal(template: "declares serviceHours for '%s', which is not a weekday.", detail: (string)$weekday);
		}

		if (in_array($iso, $this->workingWeekdays, true) === false) {
			throw $this->refusal(template: 'declares service hours on %s, which is not one of its working weekdays.', detail: self::weekdayName(iso: $iso));
		}

		return $iso;
	}//end workingWeekday()

	/**
	 * The windows of one weekday, validated and ordered.
	 *
	 * @param mixed $declared The declared windows.
	 * @param int   $iso      The ISO weekday.
	 *
	 * @return array<int, array{start: int, end: int}> The windows.
	 *
	 * @throws FlowTimerValidationException On any refused window.
	 */
	private function dayWindows(mixed $declared, int $iso): array {
		if (is_array($declared) === false || $declared === []) {
			throw $this->refusal(template: 'declares no usable window on %s.', detail: self::weekdayName(iso: $iso));
		}

		$windows = array_map(fn (mixed $window): array => $this->window(window: $window, iso: $iso), $declared);
		usort($windows, static fn (array $left, array $right): int => ($left['start'] <=> $right['start']));
		$this->refuseOverlap(windows: $windows, iso: $iso);

		return $windows;
	}//end dayWindows()

	/**
	 * One window, as minutes past midnight.
	 *
	 * @param mixed $window The declared window: `{start, end}` in `HH:MM` or minutes.
	 * @param int   $iso    The ISO weekday, for the refusals.
	 *
	 * @return array{start: int, end: int} The window.
	 *
	 * @throws FlowTimerValidationException When unreadable or not ending after it starts.
	 */
	private function window(mixed $window, int $iso): array {
		$bounds = ['start' => null, 'end' => null];
		if (is_array($window) === true) {
			$bounds = ['start' => ($window['start'] ?? null), 'end' => ($window['end'] ?? null)];
		}

		$start = self::minute(value: $bounds['start']);
		$end = self::minute(value: $bounds['end']);
		if ($start === null || $end === null) {
			throw $this->refusal(template: 'declares a window on %s without a readable start and end (HH:MM).', detail: self::weekdayName(iso: $iso));
		}

		if ($end <= $start) {
			throw $this->refusal(template: 'declares a window on %s that ends at or before it starts.', detail: self::weekdayName(iso: $iso));
		}

		return ['start' => $start, 'end' => $end];
	}//end window()

	/**
	 * Refuse two windows that overlap on one weekday.
	 *
	 * 🔴 AN OVERLAP DOUBLE-COUNTS ITS OVERLAP, so a six-hour term fires early,
	 * every time, for everybody on the calendar, and the fired term looks
	 * exactly like a correct one. There is no screen on which this would show.
	 *
	 * @param array<int, array{start: int, end: int}> $windows The ordered windows.
	 * @param int                                     $iso     The ISO weekday.
	 *
	 * @return void
	 *
	 * @throws FlowTimerValidationException When two windows overlap.
	 */
	private function refuseOverlap(array $windows, int $iso): void {
		$previousEnd = null;
		foreach ($windows as $window) {
			if ($previousEnd !== null && $window['start'] < $previousEnd) {
				throw $this->refusal(
					template: 'declares overlapping service hours on %s; the overlap would be counted twice and every hours term on this calendar would fire early.',
					detail: self::weekdayName(iso: $iso)
				);
			}

			$previousEnd = $window['end'];
		}
	}//end refuseOverlap()

	/**
	 * A refusal naming the calendar.
	 *
	 * @param string $template What the calendar declares, with `%s` for the detail.
	 * @param string $detail   The weekday or value the refusal names.
	 *
	 * @return FlowTimerValidationException The exception to throw.
	 */
	private function refusal(string $template, string $detail): FlowTimerValidationException {
		return new FlowTimerValidationException(message: sprintf("Working calendar '%s' " . $template, $this->slug, $detail));
	}//end refusal()

	/**
	 * One `HH:MM`, or a number of minutes, as minutes past midnight.
	 *
	 * @param mixed $value The declared time.
	 *
	 * @return int|null The minutes, or null when it says nothing usable.
	 */
	private static function minute(mixed $value): ?int {
		if (is_int($value) === true) {
			return self::withinDay(minutes: $value);
		}

		if (is_string($value) === false) {
			return null;
		}

		return self::clockMinute(text: $value);
	}//end minute()

	/**
	 * Minutes, when they fall within one day.
	 *
	 * @param int $minutes The declared minutes.
	 *
	 * @return int|null The minutes, or null outside 0..1440.
	 */
	private static function withinDay(int $minutes): ?int {
		if ($minutes < 0 || $minutes > (24 * 60)) {
			return null;
		}

		return $minutes;
	}//end withinDay()

	/**
	 * An `HH:MM` text as minutes past midnight.
	 *
	 * @param string $text The declared time.
	 *
	 * @return int|null The minutes, or null when it is not a time.
	 */
	private static function clockMinute(string $text): ?int {
		if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($text), $matches) !== 1) {
			return null;
		}

		$hour = (int)$matches[1];
		$minute = (int)$matches[2];
		if ($hour > 24 || $minute > 59) {
			return null;
		}

		return (($hour * 60) + $minute);
	}//end clockMinute()

	/**
	 * A declared weekday as its ISO number.
	 *
	 * @param mixed $weekday The declared weekday.
	 *
	 * @return int|null The ISO number, or null.
	 */
	private static function isoWeekday(mixed $weekday): ?int {
		if (is_int($weekday) === true || (is_string($weekday) === true && ctype_digit($weekday) === true)) {
			return self::isoNumber(number: (int)$weekday);
		}

		if (is_string($weekday) === false) {
			return null;
		}

		return (ServiceHours::WEEKDAYS[strtolower(trim($weekday))] ?? null);
	}//end isoWeekday()

	/**
	 * A weekday number, when it is one.
	 *
	 * @param int $number The declared number.
	 *
	 * @return int|null The ISO weekday, or null outside 1..7.
	 */
	private static function isoNumber(int $number): ?int {
		if ($number >= 1 && $number <= 7) {
			return $number;
		}

		return null;
	}//end isoNumber()

	/**
	 * An ISO weekday as the name a refusal prints.
	 *
	 * @param int $iso The ISO weekday.
	 *
	 * @return string The name.
	 */
	private static function weekdayName(int $iso): string {
		$names = array_flip(ServiceHours::WEEKDAYS);

		return ucfirst((string)($names[$iso] ?? (string)$iso));
	}//end weekdayName()
}//end class
