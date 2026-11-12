<?php

/**
 * OpenRegister BackfillCalendarLinksSources
 *
 * The link table, the legacy CalDAV events and the users the calendar link backfill walks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec exclude Disabled-by-default one-time Tier-2 migration backfill; not a production capability surface.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCA\OpenRegister\Db\CalendarLinkMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarEventsInterface;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * The link table, the legacy CalDAV events and the users the calendar link backfill walks.
 *
 * @spec exclude Disabled-by-default one-time Tier-2 migration backfill; not a production capability surface.
 */
final class BackfillCalendarLinksSources {
	/**
	 * Bundle the services.
	 *
	 * @param CalendarLinkMapperInterface   $linkMapper           Link-table mapper.
	 * @param ObjectCalendarEventsInterface $calendarEventService Legacy X-OR-* CalDAV service.
	 * @param IUserManager                  $userManager          User manager.
	 * @param IUserSession                  $userSession          User session.
	 *
	 * @spec exclude Disabled-by-default one-time Tier-2 migration backfill; not a production capability surface.
	 */
	public function __construct(
		public readonly CalendarLinkMapperInterface $linkMapper,
		public readonly ObjectCalendarEventsInterface $calendarEventService,
		public readonly IUserManager $userManager,
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
