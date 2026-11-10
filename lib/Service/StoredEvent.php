<?php

/**
 * One stored calendar object's raw iCalendar data and where it is stored.
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
 * One stored calendar object: the calendar and URI it is stored under, and its raw iCalendar data.
 *
 * @spec openspec/specs/calendar-integration/spec.md
 */
final class StoredEvent {
	/**
	 * Hold the values.
	 *
	 * @param string $calendarId   The calendar ID.
	 * @param string $uri          The calendar object URI.
	 * @param string $calendarData The raw iCalendar string.
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function __construct(
		public readonly string $calendarId,
		public readonly string $uri,
		public readonly string $calendarData,
	) {
	}//end __construct()
}//end class
