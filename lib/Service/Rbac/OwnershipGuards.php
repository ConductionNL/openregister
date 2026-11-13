<?php

/**
 * Who is acting on a record's ownership, and what they may do.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\IUserSession;

/**
 * The acting user and the rules that decide what they may change.
 *
 * A constructor bundle, autowired by Nextcloud: it carries no behaviour.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class OwnershipGuards {

	/**
	 * Bundle the collaborators.
	 *
	 * @param IUserSession               $userSession The acting user, which is where an owner comes from.
	 * @param GrantManageGuard           $grantManage Who may name a record's owning group.
	 * @param PermissionHandlerInterface $permissions  The rules, for "may this caller edit this record".
	 * @param SharerFolderAccess|null    $sharerAccess Ends a former owner's view of the folder, with their right to share it.
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly GrantManageGuard $grantManage,
		public readonly PermissionHandlerInterface $permissions,
		public readonly ?SharerFolderAccess $sharerAccess = null,
	) {
	}//end __construct()
}//end class
