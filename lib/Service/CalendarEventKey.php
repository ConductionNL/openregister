<?php

/**
 * OpenRegister calendar event key
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
 * A VEVENT by its (calendarUri, eventUid) key: the user's calendar URI and the event's UID.
 *
 * @spec openspec/specs/generic-integrations/spec.md
 */
final class CalendarEventKey {
	/**
	 * Hold the key.
	 *
	 * @param string $calendarUri The calendar URI, such as `personal`.
	 * @param string $eventUid    The VEVENT UID.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md
	 */
	public function __construct(
		public readonly string $calendarUri,
		public readonly string $eventUid,
	) {
	}//end __construct()
}//end class
