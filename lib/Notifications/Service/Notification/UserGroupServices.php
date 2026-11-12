<?php

/**
 * OpenRegister user group services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * The user and group resolvers that read which groups a user is in, for the
 * group-default layer of {@see NotificationPreferenceService}.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class UserGroupServices {
	/**
	 * Bundle the services.
	 *
	 * @param IGroupManager|null $groupManager Group resolver; null leaves the group layer empty.
	 * @param IUserManager|null  $userManager  User resolver, needed to read a user's groups.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function __construct(
		public readonly ?IGroupManager $groupManager = null,
		public readonly ?IUserManager $userManager = null,
	) {
	}//end __construct()
}//end class
