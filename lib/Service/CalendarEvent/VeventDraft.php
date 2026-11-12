<?php

/**
 * A new OpenRegister-linked VEVENT, before it is serialised.
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

use DateTime;

/**
 * The UID, start, event data and linked object VeventCodec::calendarDataOf() serialises.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\CalendarEvent
 *
 * @spec openspec/specs/calendar-integration/spec.md
 */
final readonly class VeventDraft {

	/**
	 * Constructor.
	 *
	 * @param string               $uid     The event UID.
	 * @param DateTime             $dtstart The start time.
	 * @param array<string, mixed> $data    Event data: summary, dtend, location, description, attendees.
	 * @param array<string, mixed> $link    The linked object: registerId, schemaId, objectUuid, objectTitle.
	 */
	public function __construct(
		public string $uid,
		public DateTime $dtstart,
		public array $data,
		public array $link,
	) {
	}//end __construct()
}//end class
