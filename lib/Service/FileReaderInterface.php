<?php

/**
 * FileReaderInterface: reading an object's files through FileService.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Files\File;
use OCP\Files\Node;

/**
 * The file look-ups and projections callers make without changing a file.
 */
interface FileReaderInterface {

	/**
	 * Formats a single Node file into a metadata array (DELEGATED to FileFormattingHandler).
	 *
	 * @param Node $file The Node file to format.
	 *
	 * @return array The formatted file metadata array.
	 *
	 * @psalm-return   array{labels: list<string>,...}
	 * @phpstan-return array<string, mixed>
	 *
	 * @spec exclude File JSON formatting; deferred to the file-actions FileFormattingHandler follow-up pass (see file-actions tasks.md DROP list).
	 */
	public function formatFile(Node $file): array;

	/**
	 * The object a managed file belongs to, or null.
	 *
	 * @param Node $file The file.
	 *
	 * @return ObjectEntity|null The owning object.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
	 */
	public function findObjectForFile(Node $file): ?ObjectEntity;

	/**
	 * Get a file by file identifier (ID or name/path) or by object and file name/path.
	 *
	 * Delegates to ReadFileHandler for single-responsibility file retrieval operations.
	 *
	 * @param ObjectEntity|string|null $object The object or object ID to fetch files for (ignored if $file is an ID).
	 * @param string|int $file The file name/path within the object folder,
	 *                         or the file ID (int or numeric string).
	 *
	 * @return File|null The file if found, null otherwise.
	 *
	 * @throws \OCP\Files\NotFoundException If the folder is not found.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If the object ID is not found.
	 *
	 * @psalm-param   ObjectEntity|string|null $object
	 * @psalm-param   string|int $file
	 * @phpstan-param ObjectEntity|string|null $object
	 * @phpstan-param string|int $file
	 *
	 * @psalm-return   File|null
	 * @phpstan-return File|null
	 */
	public function getFile(ObjectEntity|string|null $object = null, string|int $file = ''): ?File;

	/**
	 * Get a file by its Nextcloud file ID without needing object context.
	 *
	 * This method retrieves a file directly using its Nextcloud file ID,
	 * which is useful for authenticated file access endpoints.
	 *
	 * @param int $fileId The Nextcloud file ID
	 *
	 * @return File|null The file node or null if not found
	 *
	 * @throws \Exception If there's an error accessing the file
	 *
	 * @phpstan-param  int $fileId
	 * @phpstan-return File|null
	 *
	 * @spec openspec/specs/file-actions/spec.md#file-retrieval-resolves-by-id-or-name-and-projects-nodes-to-metadata
	 *   (resolves a single file node by NC file id, null on miss)
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
	 */
	public function getFileById(int $fileId): ?File;

	/**
	 * Get the tags associated with a file.
	 *
	 * Delegates to TaggingHandler for single-responsibility tag retrieval.
	 *
	 * @param string $fileId The ID of the file.
	 *
	 * @return string[] The list of tags associated with the file.
	 *
	 * @phpstan-return array<int, string>
	 * @psalm-return   list<string>
	 */
	public function getFileTags(string $fileId): array;

	/**
	 * Get all files for an object.
	 *
	 * Delegates to ReadFileHandler for single-responsibility file retrieval operations.
	 *
	 * @param ObjectEntity|string $object The object or object ID to fetch files for.
	 * @param bool|null $sharedFilesOnly Whether to return only shared files.
	 *
	 * @return array Array of file nodes.
	 *
	 * @throws \OCP\Files\NotFoundException If the folder is not found.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If the object ID is not found.
	 *
	 * @psalm-return   list<\OCP\Files\Node>
	 * @phpstan-return array<int, Node>
	 *
	 * @spec openspec/specs/file-actions/spec.md#file-retrieval-resolves-by-id-or-name-and-projects-nodes-to-metadata
	 *   (lists an object's files via its stored folder, with optional shared-only filter)
	 */
	public function getFiles(ObjectEntity|string $object, ?bool $sharedFilesOnly = false): array;

	/**
	 * Whether a node lies in OpenRegister's managed folder tree.
	 *
	 * @param Node $node The node.
	 *
	 * @return bool True when it is an object's or a register's file or folder.
	 *
	 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-reading-an-objects-files-follows-the-objects-read-rule-req-ofoa-002
	 */
	public function isManagedFile(Node $node): bool;
}//end interface
