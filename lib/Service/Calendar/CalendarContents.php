<?php

/**
 * What one VCALENDAR body holds.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/calendar-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calendar;

use DateTimeZone;

/**
 * The display name, tenant zone, component lines and year range one
 * VCALENDAR body is assembled from.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @spec openspec/specs/calendar-provider/spec.md
 */
final readonly class CalendarContents {

	/**
	 * Constructor.
	 *
	 * @param string $calendarName The display name the client shows.
	 * @param DateTimeZone|null $timeZone The tenant time zone, when the body holds timed events.
	 * @param array<int, string> $componentLines The unfolded lines of every component.
	 * @param CalendarYearSpan $years The years the VTIMEZONE must cover.
	 *
	 * @return void
	 */
	public function __construct(
		public string $calendarName,
		public ?DateTimeZone $timeZone,
		public array $componentLines,
		public CalendarYearSpan $years,
	) {

	}//end __construct()
}//end class
