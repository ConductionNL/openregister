<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * FileSharingHandlerInterface: what callers use of FileSharingHandler.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use Exception;
use OCP\Files\Node;
use OCP\Share\IShare;

/**
 * Creates and finds the shares of OpenRegister's files and folders.
 */
interface FileSharingHandlerInterface {

	/**
	 * Find shares for a given file or folder.
	 *
	 * @param Node $file      The file or folder to find shares for.
	 * @param int  $shareType The share type to filter by (default: public link = 3).
	 *
	 * @return array<int, IShare> Array of shares.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function findShares(Node $file, int $shareType=3): array;

	/**
	 * Creates and returns a share with the given data.
	 *
	 * @param array<string, mixed> $shareData The data to create a share with
	 *
	 * @throws Exception If creating the share fails
	 *
	 * @return IShare The created share object
	 *
	 * @psalm-param array{
	 *     path: string,
	 *     file?: File,
	 *     nodeId?: int,
	 *     nodeType?: string,
	 *     shareType: int,
	 *     permissions?: int,
	 *     sharedWith?: string
	 * } $shareData
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function createShare(array $shareData): IShare;

	/**
	 * Share a file with a user unless it already is.
	 *
	 * @param UserShareGrant $grant The file, the user and the permissions (default 31 = all).
	 *
	 * @throws Exception If sharing fails.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function shareFileWithUser(UserShareGrant $grant): void;

	/**
	 * Share a folder with an existing user.
	 *
	 * @param UserShareGrant $grant The folder, the user and the permissions (default 31 = all).
	 *
	 * @return IShare|null The created share, or null when the user does not exist or sharing fails.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function shareFolderWithUser(UserShareGrant $grant): ?IShare;
}//end interface
