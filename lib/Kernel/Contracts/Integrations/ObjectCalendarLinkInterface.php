<?php
/**
 * ObjectCalendarLinkInterface: checks and removes the link between an object and one of the caller's calendar events.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Integrations
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Integrations;

use OCA\OpenRegister\Service\CalendarEvent\CalendarObjectAddress;

/**
 * Unlinks one calendar event from an object, after checking it is one of the caller's events on that object.
 *
 * The calendar integration leaf (Integrations) asks; the calendar event
 * service (Processes) answers and is bound to this contract.
 */
interface ObjectCalendarLinkInterface {

	/**
	 * Refuse an event that is not one of the caller's events on the given object.
	 *
	 * @param string                $objectUuid The object the caller addressed.
	 * @param CalendarObjectAddress $event      The calendar and event the caller named.
	 *
	 * @return void
	 *
	 * @throws \Exception With code 404 when it is not.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function requireEventOf(string $objectUuid, CalendarObjectAddress $event): void;

	/**
	 * Unlink an event from an object (remove X-OPENREGISTER-* properties).
	 *
	 * @param string $calendarId The calendar ID
	 * @param string $eventUri   The event URI
	 *
	 * @return void
	 *
	 * @throws \Exception If the event is not found
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function unlinkEvent(string $calendarId, string $eventUri): void;
}//end interface
