<?php
/**
 * UserCalendarEventsInterface: the signed-in user's calendar events, a page at a time.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * The calendar events of the signed-in user, as the calendar object source lists them.
 *
 * Core's object-source provider reads them to present events as virtual objects;
 * Processes reads CalDAV and binds its calendar event service in its own wiring.
 *
 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
 */
interface UserCalendarEventsInterface {

	/**
	 * Every event in the user's calendars, a page at a time.
	 *
	 * @param int $limit  The most events to return.
	 * @param int $offset How many events to skip.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int} The events, as VEVENT arrays, with the total count.
	 *
	 * @throws \Exception When no user is signed in.
	 *
	 * @spec openspec/changes/virtual-schema-semantic-providers/tasks.md#task-5.1
	 */
	public function getAllUserEvents(int $limit = 200, int $offset = 0): array;
}//end interface
