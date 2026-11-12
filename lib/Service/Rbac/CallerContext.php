<?php

/**
 * The calling principal: its session and the group manager that answers for it.
 *
 * Several RBAC checks ask the same two things about the caller — who they are
 * (`IUserSession`) and which groups they are in or whether they administer
 * (`IGroupManager`). The two are always read together, so they are handed over
 * as one collaborator. This bundle carries no behaviour.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The caller's session and group manager, wired by DI autowiring.
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */
final class CallerContext {
	/**
	 * Bundle the caller's collaborators.
	 *
	 * @param IUserSession  $userSession  The calling principal.
	 * @param IGroupManager $groupManager Administrator detection and the caller's groups.
	 *
	 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly IGroupManager $groupManager,
	) {
	}//end __construct()
}//end class
