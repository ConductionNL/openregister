<?php

/**
 * OpenRegister calendar feed controller services
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

/**
 * The service bundles of the calendar feed API.
 *
 * @spec openspec/specs/calendar-provider/spec.md
 */
final class CalendarFeedControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param CalendarFeedDomain   $domain   The domain bundle.
	 * @param CalendarFeedPlatform $platform The platform bundle.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public function __construct(
		public readonly CalendarFeedDomain $domain,
		public readonly CalendarFeedPlatform $platform,
	) {
	}//end __construct()
}//end class
