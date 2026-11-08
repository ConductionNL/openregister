<?php

/**
 * OpenRegister user domain
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

use OCA\OpenRegister\Service\SecurityService;
use OCA\OpenRegister\Service\UserServiceInterface;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * Services of the user API.
 *
 * @spec openspec/specs/auth-system/spec.md
 */
final class UserDomain {
	/**
	 * Bundle the services.
	 *
	 * @param UserServiceInterface     $userService     User service for user operations
	 * @param SecurityService $securityService Security service for input sanitization
	 * @param IUserManager    $userManager     User manager for authentication
	 * @param IUserSession    $userSession     User session manager
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function __construct(
		public readonly UserServiceInterface $userService,
		public readonly SecurityService $securityService,
		public readonly IUserManager $userManager,
		public readonly IUserSession $userSession,
	) {
	}//end __construct()
}//end class
