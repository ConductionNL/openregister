<?php

/**
 * OpenRegister calendar object reference
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
 * A stored CalDAV object: the numeric calendar id and the object's uri in it.
 *
 * @spec openspec/specs/calendar-integration/spec.md
 */
final class CalendarObjectRef {
	/**
	 * Hold the reference.
	 *
	 * @param int    $calendarId The CalDAV calendar id.
	 * @param string $uri        The object uri, such as `abc.ics`.
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function __construct(
		public readonly int $calendarId,
		public readonly string $uri,
	) {
	}//end __construct()
}//end class
