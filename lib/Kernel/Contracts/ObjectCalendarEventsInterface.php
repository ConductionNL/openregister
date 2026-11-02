<?php

/**
 * ObjectCalendarEventsInterface: reads and removes the calendar events linked to an object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Reads and removes the calendar events linked to an object. Implemented by Service\CalendarEventService.
 */
interface ObjectCalendarEventsInterface {

	/**
	 * Get all calendar events linked to a specific OpenRegister object.
	 *
	 * @param string $objectUuid The UUID of the OpenRegister object
	 *
	 * @return array Array of event arrays in JSON-friendly format
	 *
	 * @throws \Exception If no user is logged in or no calendar found
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function getEventsForObject(string $objectUuid): array;

	/**
	 * Delete an event from the calendar outright.
	 *
	 * The counterpart to {@see unlinkEvent()}, and genuinely different from it:
	 * unlink strips the OpenRegister properties and leaves the meeting on the
	 * user's calendar, this removes the meeting.
	 *
	 * The distinction had been lost. `CalendarEventsController::destroy()`, the
	 * endpoint behind the leaf's "Delete meeting" row action, called
	 * `unlinkEvent()` and answered `{"success": true}`, so cancelling a hearing
	 * left the hearing on everybody's calendar while the case confirmed the
	 * deletion. The two row actions did the same thing.
	 *
	 * @param string $calendarId The calendar ID
	 * @param string $eventUri The event URI
	 *
	 * @return void
	 *
	 * @throws \Exception If the event is not found
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function deleteEvent(string $calendarId, string $eventUri): void;
}//end interface
