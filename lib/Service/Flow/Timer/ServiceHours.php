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
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/flow-business-timers/spec.md
	 */
	public static function none(): self {
		return new self(windows: []);
	}//end none()

	/**
	 * Read and validate a `serviceHours` declaration against its calendar's working week.
	 *
	 * @param mixed                   $value       The declared value.
	 * @param ServiceHoursDeclaration $declaration The calendar and the ISO weekdays it works.
	 *
	 * @return self The windows.
	 *
	 * @throws FlowTimerValidationException On any refused window, naming the weekday.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/flow-business-timers/spec.md
	 */
	public static function fromDeclaration(mixed $value, ServiceHoursDeclaration $declaration): self {
		if ($value === null || $value === []) {
			return self::none();
		}

		return new self(windows: $declaration->windows(value: $value));
	}//end fromDeclaration()

	/**
	 * Whether any windows are declared at all.
	 *
	 * @return bool True when the calendar keeps service hours.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/flow-business-timers/spec.md
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
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/flow-business-timers/spec.md
	 */
	public function forWeekday(int $iso): array {
		return ($this->windows[$iso] ?? []);
	}//end forWeekday()

	/**
	 * Every declared window, for a diagnostic to name.
	 *
	 * @return array<int, array<int, array{start: int, end: int}>> The windows.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/flow-business-timers/spec.md
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
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/flow-business-timers/spec.md
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
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/flow-business-timers/spec.md
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
}//end class
