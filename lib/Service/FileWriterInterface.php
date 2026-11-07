<?php

/**
 * FileWriterInterface: adding, overwriting and deleting an object's files through FileService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\Files\File;
use OCP\Files\Node;
use OCP\Files\NotPermittedException;

/**
 * The file writes callers make on an object's folder.
 */
interface FileWriterInterface {

	/**
	 * Adds a new file to an object's folder.
	 *
	 * Delegates to CreateFileHandler for single-responsibility file creation operations.
	 *
	 * @param ObjectEntity|string $objectEntity The object entity to add the file to.
	 * @param string $fileName The name of the file to create.
	 * @param string|resource $content File content: a byte string, or a readable stream resource.
	 * @param bool $share Whether to create a share link for the file.
	 * @param array $tags Optional array of tags to attach to the file.
	 * @param int|string|Schema|null $_schema The register of the object to add the file to.
	 * @param int|string|Register|null $_register The register of the object to add the file to.
	 * @param int|string|null $registerId The registerId of the object to add the file to.
	 *
	 * @return File The created file.
	 *
	 * @throws NotPermittedException If file creation fails due to permissions.
	 * @throws Exception If file creation fails for other reasons.
	 *
	 * @phpstan-param array<int, string> $tags
	 * @psalm-param   array<int, string> $tags
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-29
	 */
	public function addFile(
		ObjectEntity|string $objectEntity,
		string $fileName,
		mixed $content,
		bool $share = false,
		array $tags = [],
		int|string|Schema|null $_schema = null,
		int|string|Register|null $_register = null,
		int|string|null $registerId = null,
	): File;

	/**
	 * Delete a file by node, path, or ID.
	 *
	 * Delegates to DeleteFileHandler for single-responsibility file deletion operations.
	 *
	 * @param Node|string|int $file The file Node object, path (from root), or file ID to delete.
	 * @param ObjectEntity|null $object Optional object entity.
	 *
	 * @return bool True if successful, false if the file didn't exist.
	 *
	 * @throws Exception If deleting the file is not permitted or file operations fail.
	 *
	 * @spec openspec/specs/file-actions/spec.md#file-update-and-delete-enforce-per-action-node-permissions
	 *   (deletes a file resolved by node/path/id, with object-folder context)
	 */
	public function deleteFile(Node|string|int $file, ?ObjectEntity $object = null): bool;

	/**
	 * Overwrites an existing file in NextCloud.
	 *
	 * Delegates to UpdateFileHandler for single-responsibility file update operations.
	 *
	 * @param string|int $filePath The path (from root) where to save the file,
	 *                             including filename and extension, or file ID.
	 * @param string|resource|null $content Optional content of the file: a byte string, or a
	 *                                      readable stream resource (streamed straight to storage
	 *                                      via OCP\Files\File::putContent()).
	 *                                      If null, only metadata like tags will be updated.
	 * @param array $tags Optional array of tags to attach to the file
	 *                    (excluding object tags which are preserved).
	 * @param ObjectEntity|null $object Optional object entity to search in object folder first.
	 *
	 * @throws Exception If the file doesn't exist or if file operations fail.
	 *
	 * @return File The updated file.
	 *
	 * @phpstan-param array<int, string> $tags
	 * @psalm-param   array<int, string> $tags
	 *
	 * @spec openspec/specs/file-actions/spec.md#file-update-guards-locks-preserves-object-tags-and-persists-or-side-metadata-separately
	 *   (updates a file's content and tags within an object's folder)
	 */
	public function updateFile(string|int $filePath, mixed $content = null, array $tags = [], ?ObjectEntity $object = null): File;
}//end interface
