<?php

/**
 * OpenRegister ObjectService caller services: who asks and the token they carry
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\Collaborators
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

namespace OCA\OpenRegister\Service\Object\Collaborators;

use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;

/**
 * ObjectService caller services: who asks and the token they carry.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class ObjectCallerServices {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession          $userSession      User session for getting current user.
	 * @param IUserManager          $userManager      User manager for getting user objects.
	 * @param IGroupManager         $groupManager     Group manager for checking user groups.
	 * @param TokenGrantSource|null $tokenGrantSource The grant the request's token carries; suspended inside runAsAnonymous().
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IUserManager $userManager,
		public readonly IGroupManager $groupManager,
		public readonly ?TokenGrantSource $tokenGrantSource = null,
	) {
	}//end __construct()
}//end class
