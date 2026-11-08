<?php

/**
 * OpenRegister ObjectCalendarFeedSources
 *
 * The stores the object calendar feed reads from.
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

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use OCP\IUserManager;

/**
 * The stores the object calendar feed reads from.
 *
 * Groups collaborators of {@see ObjectCalendarFeedService} so its constructor stays small;
 * the container autowires it.
 */
final class ObjectCalendarFeedSources {

	/**
	 * Bundle the collaborators.
	 *
	 * @param ObjectOperationsInterface $objects     The object search path, RBAC included.
	 * @param SchemaMapperInterface     $schemas     The schema store.
	 * @param ViewMapperInterface       $views       The saved-view store.
	 * @param IUserManager              $userManager Resolves the principal a token names.
	 */
	public function __construct(
		public readonly ObjectOperationsInterface $objects,
		public readonly SchemaMapperInterface $schemas,
		public readonly ViewMapperInterface $views,
		public readonly IUserManager $userManager,
	) {
	}//end __construct()
}//end class
