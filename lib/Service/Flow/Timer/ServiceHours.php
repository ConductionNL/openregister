<?php

/**
 * The hours of the day a working calendar's clock actually runs.
 *
 * 🔴 `hoursPerWorkingDay` CANNOT ANSWER WHEN. It exists so that hours and
 * business days are commensurable, and it stays for that. It says a day is
 * eight hours long; it cannot say a counter closes at half past twelve. So a
 * four-hour term armed at 16:00 on Friday against a nine-to-five calendar was
 * answered with a number derived from a single opening minute and a day length,
 * which is right only for an organisation whose day is one unbroken block.
 * Most municipal counters are not: they close for lunch, and a front office
 * keeps different hours from the back office behind it.
 *
 * A calendar declaring no windows behaves exactly as it did, which is the
 * property that lets this land without recomputing anybody's live terms.
 *
 * 🔴 THE REFUSALS NAME THE WEEKDAY. A window whose end is not after its start,
 * two windows overlapping on one weekday, and a window on a day the calendar
 * does not work are each refused at write time, and each says which weekday.
 * Accepting them would be worse than a validation error: an overlapping window
 * double-counts the overlap, so a six-hour term quietly fires early, every time,
 * for everyone on that calendar, and the fired term looks exactly like a
 * correct one.
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
 * Windows per weekday, validated, with the minutes they are worth.
 */
final class ServiceHours {

	/**
	 * Weekday names accepted in a declaration, ISO numbered.
	 *
	 * @var array<string, int>
	 */
	public const WEEKDAYS = [
		'monday' => 1,
		'tuesday' => 2,
		'wednesday' => 3,
		'thursday' => 4,
		'friday' => 5,
		'saturday' => 6,
		'sunday' => 7,
	];

	/**
	 * Hold the validated windows.
	 *
	 * @param array<int, array<int, array{start: int, end: int}>> $windows ISO weekday to its windows, in minutes past midnight.
	 */
	private function __construct(private readonly array $windows) {
	}//end __construct()

	/**
	 * A calendar that declares no windows.
	 *
	 * @return self The empty declaration.
	 */
	public static function none(): self {
		return new self(windows: []);
	}//end none()

	/**
	 * Read and validate a `serviceHours` declaration.
	 *
	 * @param mixed           $value           The declared value.
	 * @param array<int, int> $workingWeekdays The ISO weekdays the calendar works.
	 * @param string          $slug            The calendar, for the refusals.
	 *
	 * @return self The windows.
	 *
	 * @throws FlowTimerValidationException On any refused window, naming the weekday.
	 */
	public static function fromArray(mixed $value, array $workingWeekdays, string $slug): self {
		if ($value === null || $value === []) {
			return self::none();
		}

		if (is_array($value) === false) {
			throw new FlowTimerValidationException(
				message: sprintf("Working calendar '%s' declares serviceHours that is not a map of weekday to windows.", $slug)
			);
		}

		$windows = [];
		foreach ($value as $weekday => $declared) {
			$iso = self::isoWeekday(weekday: $weekday);
			if ($iso === null) {
				throw new FlowTimerValidationException(
					message: sprintf("Working calendar '%s' declares serviceHours for '%s', which is not a weekday.", $slug, (string)$weekday)
				);
			}

			if (in_array($iso, $workingWeekdays, true) === false) {
				// Refused rather than ignored. A window on a day the calendar
				// does not work is somebody believing the office is open, and
				// silently dropping it leaves them believing it.
				throw new FlowTimerValidationException(
					message: sprintf(
						"Working calendar '%s' declares service hours on %s, which is not one of its working weekdays.",
						$slug,
						self::weekdayName(iso: $iso)
					)
				);
			}

			$windows[$iso] = self::validWindows(declared: $declared, iso: $iso, slug: $slug);
		}//end foreach

		ksort($windows);

		return new self(windows: $windows);
	}//end fromArray()

	/**
	 * Whether any windows are declared at all.
	 *
	 * @return bool True when the calendar keeps service hours.
	 */
	public function areDeclared(): bool {
		return ($this->windows !== []);
	}//end areDeclared()

	/**
	 * The windows for one ISO weekday, in order.
	 *
	 * @param int $iso The ISO weekday.
	 *
	 * @return array<int, array{start: int, end: int}> The windows.
	 */
	public function forWeekday(int $iso): array {
		return ($this->windows[$iso] ?? []);
	}//end forWeekday()

	/**
	 * Every declared window, for a diagnostic to name.
	 *
	 * @return array<int, array<int, array{start: int, end: int}>> The windows.
	 */
	public function all(): array {
		return $this->windows;
	}//end all()

	/**
	 * How many minutes this weekday is open.
	 *
	 * @param int $iso The ISO weekday.
	 *
	 * @return int The open minutes.
	 */
	public function minutesOn(int $iso): int {
		$minutes = 0;
		foreach ($this->forWeekday(iso: $iso) as $window) {
			$minutes += ($window['end'] - $window['start']);
		}

		return $minutes;
	}//end minutesOn()

	/**
	 * The longest open day, in hours.
	 *
	 * 🔴 DERIVED, SO THE TWO CANNOT DISAGREE. A calendar that declares windows
	 * and also declares `hoursPerWorkingDay` has two answers to one question,
	 * and nothing to say which one a term used. Deriving the scalar from the
	 * windows removes the disagreement rather than validating it.
	 *
	 * @return float The hours, or 0.0 when no windows are declared.
	 */
	public function derivedHoursPerWorkingDay(): float {
		if ($this->areDeclared() === false) {
			return 0.0;
		}

		$longest = 0;
		foreach (array_keys($this->windows) as $iso) {
			$longest = max($longest, $this->minutesOn(iso: (int)$iso));
		}

		return round(($longest / 60), 2);
	}//end derivedHoursPerWorkingDay()

	/**
	 * The windows of one weekday, validated and ordered.
	 *
	 * @param mixed  $declared The declared windows.
	 * @param int    $iso      The ISO weekday.
	 * @param string $slug     The calendar, for the refusals.
	 *
	 * @return array<int, array{start: int, end: int}> The windows.
	 *
	 * @throws FlowTimerValidationException On any refused window.
	 */
	private static function validWindows(mixed $declared, int $iso, string $slug): array {
		if (is_array($declared) === false || $declared === []) {
			throw new FlowTimerValidationException(
				message: sprintf("Working calendar '%s' declares no usable window on %s.", $slug, self::weekdayName(iso: $iso))
			);
		}

		$windows = [];
		foreach ($declared as $window) {
			$declaredStart = null;
			$declaredEnd = null;
			if (is_array($window) === true) {
				$declaredStart = ($window['start'] ?? null);
				$declaredEnd = ($window['end'] ?? null);
			}

			$start = self::minute(value: $declaredStart);
			$end = self::minute(value: $declaredEnd);

			if ($start === null || $end === null) {
				throw new FlowTimerValidationException(
					message: sprintf(
						"Working calendar '%s' declares a window on %s without a readable start and end (HH:MM).",
						$slug,
						self::weekdayName(iso: $iso)
					)
				);
			}

			if ($end <= $start) {
				throw new FlowTimerValidationException(
					message: sprintf(
						"Working calendar '%s' declares a window on %s that ends at or before it starts.",
						$slug,
						self::weekdayName(iso: $iso)
					)
				);
			}

			$windows[] = ['start' => $start, 'end' => $end];
		}//end foreach

		usort(
			$windows,
			static function (array $left, array $right): int {
				return ($left['start'] <=> $right['start']);
			}
		);

		self::refuseOverlap(windows: $windows, iso: $iso, slug: $slug);

		return $windows;
	}//end validWindows()

	/**
	 * Refuse two windows that overlap on one weekday.
	 *
	 * 🔴 AN OVERLAP DOUBLE-COUNTS ITS OVERLAP, so a six-hour term fires early,
	 * every time, for everybody on the calendar, and the fired term looks
	 * exactly like a correct one. There is no screen on which this would show.
	 *
	 * @param array<int, array{start: int, end: int}> $windows The ordered windows.
	 * @param int                                     $iso     The ISO weekday.
	 * @param string                                  $slug    The calendar.
	 *
	 * @return void
	 *
	 * @throws FlowTimerValidationException When two windows overlap.
	 */
	private static function refuseOverlap(array $windows, int $iso, string $slug): void {
		$previousEnd = null;
		foreach ($windows as $window) {
			if ($previousEnd !== null && $window['start'] < $previousEnd) {
				throw new FlowTimerValidationException(
					message: sprintf(
						"Working calendar '%s' declares overlapping service hours on %s; the overlap would be "
						."counted twice and every hours term on this calendar would fire early.",
						$slug,
						self::weekdayName(iso: $iso)
					)
				);
			}

			$previousEnd = $window['end'];
		}
	}//end refuseOverlap()

	/**
	 * One `HH:MM` as minutes past midnight.
	 *
	 * @param mixed $value The declared time.
	 *
	 * @return int|null The minutes, or null when it says nothing usable.
	 */
	private static function minute(mixed $value): ?int {
		if (is_int($value) === true) {
			if ($value < 0 || $value > (24 * 60)) {
				return null;
			}

			return $value;
		}

		if (is_string($value) === false) {
			return null;
		}

		if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $matches) !== 1) {
			return null;
		}

		$hour = (int)$matches[1];
		$minute = (int)$matches[2];
		if ($hour > 24 || $minute > 59) {
			return null;
		}

		return (($hour * 60) + $minute);
	}//end minute()

	/**
	 * A declared weekday as its ISO number.
	 *
	 * @param mixed $weekday The declared weekday.
	 *
	 * @return int|null The ISO number, or null.
	 */
	private static function isoWeekday(mixed $weekday): ?int {
		if (is_int($weekday) === true || (is_string($weekday) === true && ctype_digit($weekday) === true)) {
			$iso = (int)$weekday;
			if ($iso >= 1 && $iso <= 7) {
				return $iso;
			}

			return null;
		}

		if (is_string($weekday) === false) {
			return null;
		}

		return (self::WEEKDAYS[strtolower(trim($weekday))] ?? null);
	}//end isoWeekday()

	/**
	 * An ISO weekday as the name a refusal prints.
	 *
	 * @param int $iso The ISO weekday.
	 *
	 * @return string The name.
	 */
	private static function weekdayName(int $iso): string {
		$names = array_flip(self::WEEKDAYS);

		return ucfirst((string)($names[$iso] ?? (string)$iso));
	}//end weekdayName()
}//end class
