<?php

/**
 * FileOwnershipHandlerInterface: what callers use of FileOwnershipHandler.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service\File
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

namespace OCA\OpenRegister\Files\Service\File;

use OCP\Files\File;
use OCP\Files\Node;
use OCP\IUser;

/**
 * Gives openregister's own account and moves files and folders into it.
 */
interface FileOwnershipHandlerInterface {

	/**
	 * Gets or creates the OpenRegister user for file operations.
	 *
	 * @throws \Exception If OpenRegister user cannot be created.
	 *
	 * @return IUser The OpenRegister user.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-existing-files-move-into-openregisters-own-account-req-ofoa-004
	 */
	public function getUser(): IUser;

	/**
	 * The user logged in to this request, or null when nobody is.
	 *
	 * @return IUser|null The currently active user or null if no user is logged in.
	 */
	public function getCurrentUser(): ?IUser;

	/**
	 * Transfer file ownership to OpenRegister user and share with current user.
	 *
	 * @param File                    $file               The file to potentially transfer ownership for.
	 * @param FileSharingHandlerInterface|null $fileSharingHandler Optional sharing handler for creating shares.
	 *
	 * @return void
	 *
	 * @throws \Exception If ownership transfer fails.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function transferFileOwnershipIfNeeded(File $file, ?FileSharingHandlerInterface $fileSharingHandler=null): void;

	/**
	 * Transfer folder ownership to OpenRegister user and share with current user.
	 *
	 * @param Node                    $folder             The folder to potentially transfer ownership for.
	 * @param FileSharingHandlerInterface|null $fileSharingHandler Optional sharing handler for creating shares.
	 *
	 * @return void
	 *
	 * @throws \Exception If ownership transfer fails.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function transferFolderOwnershipIfNeeded(Node $folder, ?FileSharingHandlerInterface $fileSharingHandler=null): void;
}//end interface
