<?php

/**
 * OpenRegister controller session services
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

use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The session, group and logging services most controllers inject together.
 *
 * Injected as one constructor argument, so a controller names its own
 * collaborators and not this common trio.
 *
 * @spec openspec/specs/auth-system/spec.md
 */
final class ControllerSession {
	/**
	 * Bundle the services.
	 *
	 * @param IUserSession    $userSession  The user session.
	 * @param IGroupManager   $groupManager The group manager (admin checks).
	 * @param LoggerInterface $logger       The logger.
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the session user is an administrator; false without a session user.
	 *
	 * @return bool True for an administrator.
	 *
	 * @spec openspec/specs/auth-system/spec.md
	 */
	public function isAdmin(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}//end isAdmin()
}//end class
