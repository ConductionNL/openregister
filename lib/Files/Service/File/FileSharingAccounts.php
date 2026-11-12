<?php

/**
 * OpenRegister file sharing accounts
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
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

namespace OCA\OpenRegister\Files\Service\File;

use OCP\IUserManager;

/**
 * The accounts a share is made between: the users that may receive it and the system account that makes it.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FileSharingAccounts {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IUserManager                  $userManager          User manager for user operations.
	 * @param FileOwnershipHandlerInterface $fileOwnershipHandler Ownership handler for user operations.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly IUserManager $userManager,
		public readonly FileOwnershipHandlerInterface $fileOwnershipHandler,
	) {
	}//end __construct()
}//end class
