<?php

/**
 * OpenRegister CalendarEventsController services
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

use OCA\OpenRegister\Kernel\Contracts\ObjectCalendarEventsInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\CalendarLinkServiceInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;

/**
 * The collaborators of CalendarEventsController, wired by DI autowiring.
 *
 * @spec openspec/specs/calendar-integration/spec.md
 */
final class CalendarEventsControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param ObjectCalendarEventsInterface $calendarEventService Calendar event service (legacy)
	 * @param CalendarLinkServiceInterface $calendarLinkService Calendar link service (Tier-2)
	 * @param ObjectOperationsInterface $objectService Object service
	 * @param PermissionHandlerInterface $permissions Answers the object update right
	 *
	 * @spec openspec/specs/calendar-integration/spec.md
	 */
	public function __construct(
		public readonly ObjectCalendarEventsInterface $calendarEventService,
		public readonly CalendarLinkServiceInterface $calendarLinkService,
		public readonly ObjectOperationsInterface $objectService,
		public readonly PermissionHandlerInterface $permissions,
	) {
	}//end __construct()
}//end class
