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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Service\File\FileAuditHandler;
use OCA\OpenRegister\Kernel\Contracts\StructurePreservation;
use OCA\OpenRegister\Service\File\DocumentAnonymization;
use OCA\OpenRegister\Service\File\FileBatchHandler;
use OCA\OpenRegister\Service\File\FileMetadataChange;
use OCA\OpenRegister\Service\File\FileRename;
use OCA\OpenRegister\Service\File\FileTransfer;
use OCA\OpenRegister\Service\File\FileLockHandler;
use OCA\OpenRegister\Service\File\FilePreviewHandler;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\File\FileVersioningHandler;
use OCP\Files\File;
use OCP\Files\Node;

/**
 * The file operations controllers and the Files component call on FileService.
 */
interface FileServiceInterface extends ObjectFilesInterface, FileFolderServiceInterface {

	/**
	 * Anonymize a document by replacing detected entities (DELEGATED to DocumentProcessingHandler).
	 *
	 * This is a convenience method that creates replacement mappings
	 * from entity detection results and applies them to a document.
	 *
	 * @param Node                  $node         The file node to anonymize.
	 * @param DocumentAnonymization $anonymization The entities, the placeholder scope and the PDF structure option.
	 *
	 * @throws \Exception If anonymization fails.
	 *
	 * @return Node The anonymized file node.
	 *
	 * @spec exclude One-line delegation to DocumentProcessingHandler::anonymizeDocument; no facade-owned logic.
	 */
	public function anonymizeDocument(Node $node, DocumentAnonymization $anonymization): Node;

	/**
	 * Copy a file to another object.
	 *
	 * @param ObjectEntity $sourceObject The source object entity.
	 * @param int $fileId The source file ID.
	 * @param ObjectEntity $targetObject The target object entity.
	 *
	 * @return File The new file copy.
	 *
	 * @throws \Exception If the copy fails.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function copyFile(ObjectEntity $sourceObject, int $fileId, ObjectEntity $targetObject): File;

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
	 * @throws \Exception If ZIP creation fails or object not found
	 * @throws \OCP\Files\NotFoundException If the object folder is not found
	 * @throws \OCP\Files\NotPermittedException If file access is not permitted
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
	 * Formats an array of Node files into an array of metadata arrays (DELEGATED to FileFormattingHandler).
	 *
	 * @param Node[] $files Array of Node files to format.
	 * @param array $requestParams Optional request parameters including filters.
	 *
	 * @throws \OCP\Files\InvalidPathException If file paths are invalid.
	 * @throws \OCP\Files\NotFoundException If files are not found.
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
	 * Move a file to another object (copy + delete source).
	 *
	 * @param FileTransfer $transfer The source object, the source file ID and the target object.
	 *
	 * @return File The moved file.
	 *
	 * @throws \Exception If the move fails.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function moveAttachedFile(FileTransfer $transfer): File;

	/**
	 * Rename a file attached to an object.
	 *
	 * @param FileRename $rename The parent object entity, the file ID and the new file name.
	 *
	 * @return File The renamed file.
	 *
	 * @throws \Exception If the rename fails.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function renameAttachedFile(FileRename $rename): File;

	/**
	 * Save a file to an object's folder (create new or update existing).
	 *
	 * Delegates to CreateFileHandler for single-responsibility upsert operations.
	 *
	 * @param ObjectEntity $objectEntity The object entity to save the file to.
	 * @param FileUpload   $upload       The file name, content, share toggle (new files only) and tags.
	 *
	 * @return File The saved file.
	 *
	 * @throws \OCP\Files\NotPermittedException If file operations fail due to permissions.
	 * @throws \Exception If file operations fail for other reasons.
	 *
	 * @spec openspec/specs/file-actions/spec.md#file-creation-and-upsert-run-a-fixed-validate-write-own-tag-pipeline
	 *   (creates/writes a file into an object's folder with optional tags and share toggle)
	 */
	public function saveFile(ObjectEntity $objectEntity, FileUpload $upload): File;

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
	 * Update a file's OpenRegister-side metadata.
	 *
	 * Fronts `UpdateFileHandler::updateFileMetadata()` the same way this
	 * service already fronts `overwriteFile()`, so the controller keeps talking to
	 * one collaborator instead of reaching through this one to the handler.
	 *
	 * @param FileMetadataChange $change The Nextcloud filecache fileid and the new description, category and labels
	 *                                   ('' or [] clears, null skips).
	 *
	 * @return \OCA\OpenRegister\Files\Db\File The persisted metadata row.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function changeFileMetadata(FileMetadataChange $change): \OCA\OpenRegister\Files\Db\File;
}//end interface
