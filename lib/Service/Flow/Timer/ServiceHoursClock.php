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

use OCA\OpenRegister\Exception\FlowTimerValidationException;

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
	 * The frame a term is walked in: the calendar deciding the days and the
	 * windows deciding the hours. Its `due()` and `elapsed()` are the two
	 * halves of one measurement and always run on the same frame.
	 *
	 * @param WorkingCalendar $calendar The calendar deciding the days.
	 * @param ServiceHours    $windows  Its declared windows.
	 *
	 * @return ServiceHoursFrame The frame.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/flow-business-timers/spec.md
	 */
	public function frame(WorkingCalendar $calendar, ServiceHours $windows): ServiceHoursFrame {
		return new ServiceHoursFrame(calendar: $calendar, windows: $windows);
	}//end frame()

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
		return [
			'calendar' => $calendar->getSlug(),
			'timezone' => $calendar->getTimezone(),
			'serviceHoursDeclared' => $windows->areDeclared(),
			'windows' => $this->printedWindows(windows: $windows),
		];
	}//end diagnostic()

	/**
	 * The service-hour windows per ISO weekday, printed as HH:MM-HH:MM.
	 *
	 * @param ServiceHours $windows The service hours.
	 *
	 * @return array<int, array<int, string>> The printed windows.
	 */
	private function printedWindows(ServiceHours $windows): array {
		$applied = [];
		foreach ($windows->all() as $iso => $dayWindows) {
			$printed = [];
			foreach ($dayWindows as $window) {
				$printed[] = $this->printMinute(minute: $window['start']).'-'.$this->printMinute(minute: $window['end']);
			}

			$applied[(int)$iso] = $printed;
		}

		return $applied;
	}//end printedWindows()

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
