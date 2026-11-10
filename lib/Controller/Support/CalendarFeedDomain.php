<?php

/**
 * OpenRegister calendar feed domain
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Service\Calendar\AppointmentAttendeeServiceInterface;
use OCA\OpenRegister\Service\Calendar\CalendarFeedTokenServiceInterface;
use OCA\OpenRegister\Service\Calendar\ObjectCalendarFeedService;
use OCP\IUserSession;

/**
 * Services of the calendar feed API.
 *
 * @spec openspec/specs/calendar-provider/spec.md
 */
final class CalendarFeedDomain {
	/**
	 * Bundle the services.
	 *
	 * @param CalendarFeedTokenServiceInterface   $tokens      The feed-token lifecycle.
	 * @param ObjectCalendarFeedService  $feed        The feed generator.
	 * @param AppointmentAttendeeServiceInterface $attendees   The attendee-response store.
	 * @param IUserSession               $userSession The current session, which names the principal.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public function __construct(
		public readonly CalendarFeedTokenServiceInterface $tokens,
		public readonly ObjectCalendarFeedService $feed,
		public readonly AppointmentAttendeeServiceInterface $attendees,
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
