<?php

/**
 * OpenRegister ObjectCalendarFeedRendering
 *
 * The collaborators that turn object dates into an iCalendar body.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calendar;

use OCP\IDateTimeZone;

/**
 * The collaborators that turn object dates into an iCalendar body.
 *
 * Groups collaborators of {@see ObjectCalendarFeedService} so its constructor stays small;
 * the container autowires it.
 */
final class ObjectCalendarFeedRendering {

	/**
	 * Bundle the collaborators.
	 *
	 * @param IDateTimeZone          $dateTimeZone The tenant time zone.
	 * @param ObjectDateEventBuilder $eventBuilder Builds one VEVENT per declared date.
	 * @param IcalendarWriter        $writer       Folds and assembles the body.
	 */
	public function __construct(
		public readonly IDateTimeZone $dateTimeZone,
		public readonly ObjectDateEventBuilder $eventBuilder,
		public readonly IcalendarWriter $writer,
	) {
	}//end __construct()
}//end class
