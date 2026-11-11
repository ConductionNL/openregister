<?php

/**
 * The calendar services the calendar integration provider reads and links events through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/integration-calendar/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCA\OpenRegister\Service\CalendarEventService;
use OCA\OpenRegister\Service\CalendarLinkService;

/**
 * A dependency bundle of {@see CalendarProvider}.
 *
 * @spec openspec/specs/integration-calendar/spec.md
 */
final class CalendarProviderServices {
	/**
	 * Bundle the services.
	 *
	 * @param CalendarEventService $calendarEventService Reads and writes the VEVENTs.
	 * @param CalendarLinkService  $calendarLinkService  Links an event to an object.
	 *
	 * @spec openspec/specs/integration-calendar/spec.md
	 */
	public function __construct(
		public readonly CalendarEventService $calendarEventService,
		public readonly CalendarLinkService $calendarLinkService,
	) {
	}//end __construct()
}//end class
