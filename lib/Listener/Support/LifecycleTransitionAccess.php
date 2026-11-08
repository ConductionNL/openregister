<?php

/**
 * OpenRegister LifecycleTransitionAccess
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener\Support;

use OCA\OpenRegister\Listener\LifecycleEditReader;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\IUserSession;

/**
 * Who is moving the lifecycle, and whether the transition admits them.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
final class LifecycleTransitionAccess {
	/**
	 * Bundle the services.
	 *
	 * @param LifecycleEditReader        $edits             Reads the edit and the transition it amounts to.
	 * @param IUserSession               $userSession       Current user session.
	 * @param PermissionHandlerInterface $permissionHandler RBAC handler used to evaluate declarative per-transition authorization.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		public readonly LifecycleEditReader $edits,
		public readonly IUserSession $userSession,
		public readonly PermissionHandlerInterface $permissionHandler,
	) {
	}//end __construct()
}//end class
