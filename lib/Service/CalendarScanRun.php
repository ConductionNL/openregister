<?php

/**
 * One scan of a calendar for events that mention an object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * One scan of a calendar's stored objects for VEVENTs whose raw data mentions a needle.
 *
 * @spec openspec/specs/calendar-integration/spec.md
 */
final class CalendarScanRun {
	/**
	 * Hold the values.
	 *
	 * @param mixed  $calendarId The calendar ID, as the CalDAV backend lists it.
	 * @param string $needle     Text the raw iCalendar data must contain.
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function __construct(
		public readonly mixed $calendarId,
		public readonly string $needle,
	) {
	}//end __construct()
}//end class
