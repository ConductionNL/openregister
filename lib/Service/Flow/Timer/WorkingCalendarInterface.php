<?php

/**
 * WorkingCalendarInterface: what callers outside the component use of WorkingCalendar.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

/**
 * Declares only what callers outside the component use of WorkingCalendar; the rest
 * of the class stays its own.
 */
interface WorkingCalendarInterface {

	/**
	 * The calendar's name.
	 *
	 * @return string The slug.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function getSlug(): string;

	/**
	 * The zone the organisation counts its days in.
	 *
	 * WHY A CALENDAR HAS A ZONE, AND WHY IT IS NOT THE VIEWER'S. A calendar
	 * date is not an instant: "the term ends on 2 June" becomes a moment only
	 * once somebody says where midnight is. Without a zone the answer is the
	 * server's, which means a term computed at 23:30 UTC lands a day early for
	 * an organisation in Amsterdam and nothing on screen says why.
	 *
	 * It is the ORGANISATION's zone and not the signed-in person's. A display
	 * preference must not move a statutory deadline: two handlers on one case
	 * would then be owed different days, and the one who travelled would be
	 * right.
	 *
	 * @return string An IANA zone name.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function getTimezone(): string;

	/**
	 * The ISO weekdays that are working days.
	 *
	 * Exposed so the admin preview can echo back the weekdays it actually
	 * validated: a panel that previews the holidays but shows the weekdays
	 * straight from its own form cannot tell the reader that a malformed
	 * weekday list was normalised away.
	 *
	 * @return array<int, int> ISO weekdays, 1 = Monday.
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
	 */
	public function getWorkingWeekdays(): array;

	/**
	 * The minute of the day the working day opens.
	 *
	 * WHY THE CALENDAR CARRIES A TIME OF DAY AT ALL. Until now a calendar
	 * answered which DAYS are worked and how many hours one of them holds,
	 * which is everything a deadline needs: "five business days from now"
	 * never asks what time the office opens. Elapsed business time does ask.
	 * A case entered at 16:00 on Friday and left at 09:00 on Monday spans one
	 * working hour or eight depending entirely on where the day starts, and
	 * there is no honest way to answer without knowing.
	 *
	 * The window is start plus `hoursPerWorkingDay`, so the two can never
	 * disagree: a calendar cannot say the day is eight hours long and then
	 * describe a nine-hour window.
	 *
	 * @return integer Minutes past midnight.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function getDayStartsAtMinute(): int;

	/**
	 * The minute of the day the working day closes.
	 *
	 * @return integer Minutes past midnight, never beyond the end of the day.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function getDayEndsAtMinute(): int;

	/**
	 * Working hours in one working day.
	 *
	 * @return float The hours.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function getHoursPerWorkingDay(): float;

	/**
	 * The non-working dates of a year, computed from the rules and memoised.
	 *
	 * @param int $year The year.
	 *
	 * @return array<string, string> `Y-m-d` => name.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function nonWorkingDates(int $year): array;
}//end interface
