<?php

/**
 * FileServiceInterface: what callers outside the Service component use of FileService.
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
use OCA\OpenRegister\Files\Service\File\FileAuditHandler;
use OCA\OpenRegister\Kernel\Contracts\StructurePreservation;
use OCA\OpenRegister\Service\File\FileBatchHandler;
use OCA\OpenRegister\Service\File\FileLockHandler;
use OCA\OpenRegister\Service\File\FilePreviewHandler;
use OCA\OpenRegister\Service\File\FileVersioningHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

/**
 * The file operations controllers and the Files component call on FileService.
 */
interface FileServiceInterface {

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
	 * Anonymize a document by replacing detected entities (DELEGATED to DocumentProcessingHandler).
	 *
	 * This is a convenience method that creates replacement mappings
	 * from entity detection results and applies them to a document.
	 *
	 * @param Node $node The file node to anonymize.
	 * @param array $entities Array of detected entities with 'text' and 'key' fields.
	 * @param string $scope Placeholder-numbering scope: 'document' (default) or 'dossier'.
	 * @param string|null $fileKey Stable folder id of the dossier (per-dossier scope); null falls
	 *                             back to the file's parent folder.
	 * @param bool|null $preserveStructure PDF only (REQ-ORTPR-004): tri-state structure-preservation
	 *                                     option — null/absent = auto (preserve iff the input is a
	 *                                     tagged PDF), true = attempt, false = skip but still measure.
	 *
	 * @throws Exception If anonymization fails.
	 *
	 * @return Node The anonymized file node.
	 *
	 * @spec exclude One-line delegation to DocumentProcessingHandler::anonymizeDocument; no facade-owned logic.
	 */
	public function anonymizeDocument(
		Node $node,
		array $entities,
		string $scope = 'document',
		?string $fileKey = null,
		?bool $preserveStructure = null,
	): Node;

	/**
	 * Copy a file to another object.
	 *
	 * @param ObjectEntity $sourceObject The source object entity.
	 * @param int $fileId The source file ID.
	 * @param ObjectEntity $targetObject The target object entity.
	 *
	 * @return File The new file copy.
	 *
	 * @throws Exception If the copy fails.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function copyFile(ObjectEntity $sourceObject, int $fileId, ObjectEntity $targetObject): File;

	/**
	 * Creates a folder for either a Register or ObjectEntity and stores the folder ID.
	 *
	 * This unified method creates folders and stores the folder ID as an integer
	 * in the entity's folder property instead of using unstable path mapping.
	 * For ObjectEntity, it ensures the folder is nested under the register folder.
	 *
	 * @param Register|ObjectEntity $entity The entity to create a folder for
	 *
	 * @return Node|null The created folder Node or null if creation fails
	 *
	 * @throws Exception If folder creation fails or entities not found
	 * @throws NotPermittedException If folder creation is not permitted
	 * @throws NotFoundException If parent folders do not exist
	 *
	 * @phpstan-return Node|null
	 *
	 * @spec openspec/specs/file-actions/spec.md#object-and-register-folder-provisioning
	 *   (unified entry: provisions the backing folder for a Register or ObjectEntity, degrading to null on failure)
	 */
	public function createEntityFolder(Register|ObjectEntity $entity): ?Node;

	/**
	 * Create a ZIP archive containing all files for a specific object.
	 *
	 * This method retrieves all files associated with an object and creates a ZIP archive
	 * containing all the files. The ZIP file is created in the system's temporary directory
	 * and can be downloaded by the client.
	 *
	 * @param ObjectEntity|string $object The object entity or object UUID/ID
	 * @param string|null $zipName Optional custom name for the ZIP file
	 *
	 * @throws Exception If ZIP creation fails or object not found
	 * @throws NotFoundException If the object folder is not found
	 * @throws NotPermittedException If file access is not permitted
	 *
	 * @return (int|string)[]
	 *
	 * @psalm-return   array{path: string, filename: string, size: int, mimeType: 'application/zip'}
	 * @phpstan-return array{path: string, filename: string, size: int, mimeType: string}
	 *
	 * @spec exclude Object-files ZIP build; deferred to the file-actions FilePublishingHandler follow-up pass (see file-actions tasks.md DROP list).
	 */
	public function createObjectFilesZip(ObjectEntity|string $object, ?string $zipName = null): array;

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
	 * Formats an array of Node files into an array of metadata arrays (DELEGATED to FileFormattingHandler).
	 *
	 * @param Node[] $files Array of Node files to format.
	 * @param array $requestParams Optional request parameters including filters.
	 *
	 * @throws InvalidPathException If file paths are invalid.
	 * @throws NotFoundException If files are not found.
	 *
	 * @return array Formatted file data with pagination
	 *
	 * @spec exclude File JSON formatting + pagination; deferred to the file-actions FileFormattingHandler
	 *   follow-up pass (see file-actions tasks.md DROP list).
	 */
	public function formatFiles(array $files, ?array $requestParams = []): array;

	/**
	 * Retrieves all available tags in the system.
	 *
	 * Delegates to TaggingHandler for single-responsibility tag management operations.
	 *
	 * @throws \Exception If there's an error retrieving the tags.
	 *
	 * @return string[]
	 *
	 * @psalm-return   list<string>
	 * @phpstan-return array<int, string>
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getAllTags(): array;

	/**
	 * Get the file audit handler.
	 *
	 * @return FileAuditHandler The audit handler.
	 */
	public function getAuditHandler(): FileAuditHandler;

	/**
	 * Get the file batch handler.
	 *
	 * @return FileBatchHandler The batch handler.
	 */
	public function getBatchHandler(): FileBatchHandler;

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
	 * @throws NotFoundException If the folder is not found.
	 * @throws DoesNotExistException If the object ID is not found.
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
	 * Get all files for an object.
	 *
	 * Delegates to ReadFileHandler for single-responsibility file retrieval operations.
	 *
	 * @param ObjectEntity|string $object The object or object ID to fetch files for.
	 * @param bool|null $sharedFilesOnly Whether to return only shared files.
	 *
	 * @return array Array of file nodes.
	 *
	 * @throws NotFoundException If the folder is not found.
	 * @throws DoesNotExistException If the object ID is not found.
	 *
	 * @psalm-return   list<\OCP\Files\Node>
	 * @phpstan-return array<int, Node>
	 *
	 * @spec openspec/specs/file-actions/spec.md#file-retrieval-resolves-by-id-or-name-and-projects-nodes-to-metadata
	 *   (lists an object's files via its stored folder, with optional shared-only filter)
	 */
	public function getFiles(ObjectEntity|string $object, ?bool $sharedFilesOnly = false): array;

	/**
	 * Residual entities from the most recent anonymizeDocument() call.
	 *
	 * Best-effort policy: the anonymised file is produced even when some entity
	 * text could not be removed (e.g. ExApp NER over-capture across table
	 * cells); these records describe what remains so the caller can warn the
	 * operator. Empty when the last run fully redacted everything.
	 *
	 * @return array<int, array{text: string, type: string, id: string}> Residual records.
	 *
	 * @spec exclude One-line delegation to DocumentProcessingHandler::getLastResidualEntities.
	 */
	public function getLastResidualEntities(): array;

	/**
	 * The `structurePreservation` result block from the most recent PDF
	 * redaction (best-effort accessibility-structure preservation).
	 *
	 * @return StructurePreservation|null The result block, or null when the
	 *                                    last redaction did not take the PDF
	 *                                    branch.
	 *
	 * @spec exclude One-line delegation to DocumentProcessingHandler::getLastStructurePreservation.
	 */
	public function getLastStructurePreservation(): ?StructurePreservation;

	/**
	 * Get the file lock handler.
	 *
	 * @return FileLockHandler The lock handler.
	 */
	public function getLockHandler(): FileLockHandler;

	/**
	 * Get an object folder by its stored ID.
	 *
	 * @param ObjectEntity|string $objectEntity The object entity to get the folder for
	 * @param int|string|null $registerId The register of the object to add the file to
	 *
	 * @return Folder|null The folder Node or null if not found
	 *
	 * @psalm-return   Folder|null
	 * @phpstan-return Folder|null
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getObjectFolder(ObjectEntity|string $objectEntity, int|string|null $registerId = null): ?Folder;

	/**
	 * Get the file preview handler.
	 *
	 * @return FilePreviewHandler The preview handler.
	 */
	public function getPreviewHandler(): FilePreviewHandler;

	/**
	 * Get the file versioning handler.
	 *
	 * @return FileVersioningHandler The versioning handler.
	 *
	 * @spec openspec/specs/content-versioning/spec.md
	 */
	public function getVersioningHandler(): FileVersioningHandler;

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

	/**
	 * Move a file to another object (copy + delete source).
	 *
	 * @param ObjectEntity $sourceObject The source object entity.
	 * @param int $fileId The source file ID.
	 * @param ObjectEntity $targetObject The target object entity.
	 *
	 * @return File The moved file.
	 *
	 * @throws Exception If the move fails.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function moveFile(ObjectEntity $sourceObject, int $fileId, ObjectEntity $targetObject): File;

	/**
	 * Rename a file attached to an object.
	 *
	 * @param ObjectEntity $object The parent object entity.
	 * @param int $fileId The file ID.
	 * @param string $newName The new file name.
	 *
	 * @return File The renamed file.
	 *
	 * @throws Exception If the rename fails.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function renameFile(ObjectEntity $object, int $fileId, string $newName): File;

	/**
	 * Save a file to an object's folder (create new or update existing).
	 *
	 * Delegates to CreateFileHandler for single-responsibility upsert operations.
	 *
	 * @param ObjectEntity $objectEntity The object entity to save the file to.
	 * @param string $fileName The name of the file to save.
	 * @param string|resource $content File content: a byte string, or a readable stream resource.
	 * @param bool $share Whether to create a share link for the file (only for new files).
	 * @param array $tags Optional array of tags to attach to the file.
	 *
	 * @return File The saved file.
	 *
	 * @throws NotPermittedException If file operations fail due to permissions.
	 * @throws Exception If file operations fail for other reasons.
	 *
	 * @phpstan-param array<int, string> $tags
	 * @psalm-param   array<int, string> $tags
	 *
	 * @spec openspec/specs/file-actions/spec.md#file-creation-and-upsert-run-a-fixed-validate-write-own-tag-pipeline
	 *   (creates/writes a file into an object's folder with optional tags and share toggle)
	 */
	public function saveFile(
		ObjectEntity $objectEntity,
		string $fileName,
		mixed $content,
		bool $share = false,
		array $tags = [],
	): File;

	/**
	 * Stream a file for download.
	 *
	 * This method creates a StreamResponse that sends the file content
	 * directly to the client with appropriate headers.
	 *
	 * @param File $file The file to stream
	 *
	 * @return \OCP\AppFramework\Http\StreamResponse Stream response with file content
	 *
	 * @phpstan-param File $file
	 *
	 * @phpstan-return \OCP\AppFramework\Http\StreamResponse
	 *
	 * @psalm-return \OCP\AppFramework\Http\StreamResponse<200, array<never, never>>
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function streamFile(File $file): \OCP\AppFramework\Http\StreamResponse;

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

	/**
	 * Update a file's OpenRegister-side metadata.
	 *
	 * Fronts `UpdateFileHandler::updateFileMetadata()` the same way this
	 * service already fronts `updateFile()`, so the controller keeps talking to
	 * one collaborator instead of reaching through this one to the handler.
	 *
	 * @param integer       $fileId      The Nextcloud filecache fileid.
	 * @param string|null   $description New description; '' clears, null skips.
	 * @param string|null   $category    New category; '' clears, null skips.
	 * @param string[]|null $labels      New labels; [] clears, null skips.
	 *
	 * @return \OCA\OpenRegister\Files\Db\File The persisted metadata row.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function updateFileMetadata(
		int $fileId,
		?string $description = null,
		?string $category = null,
		?array $labels = null,
	): \OCA\OpenRegister\Files\Db\File;
}//end interface
