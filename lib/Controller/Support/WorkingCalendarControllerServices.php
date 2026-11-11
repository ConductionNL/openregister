<?php

/**
 * OpenRegister WorkingCalendarController services
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

use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarDefinitionsInterface;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The collaborators of WorkingCalendarController, wired by DI autowiring.
 */
final class WorkingCalendarControllerServices {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession                        $userSession  Resolves the caller.
	 * @param IGroupManager                       $groupManager Answers whether they are an administrator.
	 * @param WorkingCalendarDefinitionsInterface $calendars    Builds and validates the definition.
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
		public readonly WorkingCalendarDefinitionsInterface $calendars,
	) {
	}//end __construct()
}//end class
