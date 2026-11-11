<?php

/**
 * Where a calendar object is stored: its calendar and its object URI.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\CalendarEvent
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/calendar-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\CalendarEvent;

/**
 * The calendar ID and object URI an event array reports as `calendarId` and `id`.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\CalendarEvent
 *
 * @spec openspec/specs/calendar-integration/spec.md
 */
final readonly class CalendarObjectAddress {

	/**
	 * Constructor.
	 *
	 * @param string $calendarId The calendar ID.
	 * @param string $uri        The calendar object URI.
	 */
	public function __construct(
		public string $calendarId,
		public string $uri,
	) {
	}//end __construct()
}//end class
