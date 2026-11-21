<?php

/**
 * The file tree and accounts an object file move works on.
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

use OCP\Files\IRootFolder;
use OCP\IUserManager;

/**
 * The file tree, user manager, ownership handler and share re-owner the object file migration moves files with.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class ObjectFileMigrationFiles {
	/**
	 * Bundle the collaborators.
	 *
	 * @param IRootFolder            $rootFolder   The file tree.
	 * @param IUserManager           $userManager  Walks the people whose homes may hold managed folders.
	 * @param FileOwnershipHandlerInterface   $ownership    Resolves the openregister account.
	 * @param ObjectFileShareReowner $shareReowner Re-owns published link shares.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly IRootFolder $rootFolder,
		public readonly IUserManager $userManager,
		public readonly FileOwnershipHandlerInterface $ownership,
		public readonly ObjectFileShareReowner $shareReowner,
	) {
	}//end __construct()
}//end class
