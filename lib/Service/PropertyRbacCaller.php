<?php

/**
 * OpenRegister PropertyRbacCaller
 *
 * Who a property-level RBAC verdict is about: the session that names the
 * caller and the group manager that resolves their groups.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The caller collaborators of PropertyRbacHandler, wired by DI autowiring.
 */
final class PropertyRbacCaller {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession  $userSession  User session for current user context.
	 * @param IGroupManager $groupManager Group manager for user group operations.
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
	) {
	}//end __construct()
}//end class
