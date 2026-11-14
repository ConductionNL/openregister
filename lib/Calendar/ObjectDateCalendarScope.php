<?php

/**
 * ObjectDateCalendarScope — which objects one platform calendar holds, and for whom.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Calendar
 * @package  OCA\OpenRegister\Calendar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Calendar;

/**
 * The calendar key, its display name and colour, the scope the feed's event
 * generator lists (a schema or a saved view) and the principal it lists for.
 *
 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
 */
final readonly class ObjectDateCalendarScope {

	/**
	 * Constructor.
	 *
	 * @param string $key          The calendar key and URI.
	 * @param string $displayName  The name the client shows.
	 * @param string $color        The CSS hex colour.
	 * @param string $scopeType    CalendarFeedToken::SCOPE_SCHEMA or CalendarFeedToken::SCOPE_VIEW.
	 * @param string $scopeId      The schema or view id.
	 * @param string $principalUri The principal the calendar is listed for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
	 */
	public function __construct(
		public string $key,
		public string $displayName,
		public string $color,
		public string $scopeType,
		public string $scopeId,
		public string $principalUri,
	) {

	}//end __construct()
}//end class
