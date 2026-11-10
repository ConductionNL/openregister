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
use OCP\Files\File;
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
	 * Share a file with a specific user.
	 *
	 * @param File   $file        The file to share.
	 * @param string $userId      The user ID to share with.
	 * @param int    $permissions The permissions to grant (default: 31 = all).
	 *
	 * @throws Exception If sharing fails.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function shareFileWithUser(File $file, string $userId, int $permissions=31): void;

	/**
	 * Share a folder with a specific user.
	 *
	 * @param Node   $folder      The folder to share.
	 * @param string $userId      The user ID to share with.
	 * @param int    $permissions The permissions to grant (default: 31 = all).
	 *
	 * @return IShare|null The created share or null if user doesn't exist.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function shareFolderWithUser(Node $folder, string $userId, int $permissions=31): ?IShare;
}//end interface
