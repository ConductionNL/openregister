<?php

/**
 * OpenRegister UserDirectory
 *
 * The Nextcloud user, group, account and avatar stores the user service reads and writes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\User
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/profile-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\User;

use OCP\Accounts\IAccountManager;
use OCP\IAvatarManager;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * The Nextcloud user, group, account and avatar stores the user service reads and writes.
 *
 * @spec openspec/specs/profile-actions/spec.md
 */
final readonly class UserDirectory {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IUserManager    $userManager    The user manager service.
	 * @param IGroupManager   $groupManager   The group manager service.
	 * @param IAccountManager $accountManager The account manager service.
	 * @param IAvatarManager  $avatarManager  The avatar manager service.
	 */
	public function __construct(
		public IUserManager $userManager,
		public IGroupManager $groupManager,
		public IAccountManager $accountManager,
		public IAvatarManager $avatarManager,
	) {
	}//end __construct()
}//end class
