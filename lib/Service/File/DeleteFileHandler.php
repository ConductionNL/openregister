<?php

/**
 * DeleteFileHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Service\File\FileOwnershipHandler;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotPermittedException;
use Psr\Log\LoggerInterface;

/**
 * Handles file deletion operations with Single Responsibility.
 *
 * This handler is responsible ONLY for:
 * - Deleting single files
 * - Deleting multiple files
 * - Cleaning up shares and tags
 * - Removing file metadata
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class DeleteFileHandler {
	/**
	 * Constructor for DeleteFileHandler.
	 *
	 * @param IRootFolder           $rootFolder           Root folder for file operations.
	 * @param ReadFileHandler       $readFileHandler      Read file handler.
	 * @param FileValidationHandler $fileValidHandler     File validation handler.
	 * @param FileOwnershipHandler  $fileOwnershipHandler File ownership handler.
	 * @param LoggerInterface       $logger               Logger for logging operations.
	 * @param FileLockHandler       $fileLockHandler      Lock handler used to release / verify file locks on delete.
	 */
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly ReadFileHandler $readFileHandler,
		private readonly FileValidationHandler $fileValidHandler,
		private readonly FileOwnershipHandler $fileOwnershipHandler,
		private readonly LoggerInterface $logger,
		private readonly FileLockHandler $fileLockHandler,
	) {
	}//end __construct()

	/**
	 * Delete a file by node, path, or ID.
	 *
	 * This method can accept either a file path string, file ID integer, or a Node object for deletion.
	 * When a Node object is provided, it will be deleted directly. When a string path or integer ID
	 * is provided, the file will be located first and then deleted.
	 *
	 * @param Node|string|int $file The file Node object, path (from root), or file ID to delete.
	 * @param ObjectEntity|null $object Optional object entity.
	 *
	 * @return bool True if successful, false if the file didn't exist.
	 *
	 * @throws Exception If deleting the file is not permitted or file operations fail.
	 *
	 * @psalm-param Node|string|int $file
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-file-update-and-delete-enforce-per-action-node-permissions
	 */
	public function deleteFile(Node|string|int $file, ?ObjectEntity $object = null): bool {
		$file = $this->resolveFile(file: $file, object: $object);
		if ($file === null) {
			return false;
		}

		if ($file instanceof File === false) {
			$this->logger->error(
				message: '[DeleteFileHandler] File is not a File instance, it\'s a: ' . get_class($file),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return false;
		}

		$this->assertMayDelete(file: $file);
		return $this->deleteAndConfirm(file: $file);
	}//end deleteFile()

	/**
	 * The node to delete: a Node as it is, a path or an id looked up in the object.
	 *
	 * Only a path or an id is cast for the log line: a Files Node is not
	 * Stringable, so casting before this check killed every Node caller
	 * (MergeHandler).
	 *
	 * @param Node|string|int   $file   The node, path or id.
	 * @param ObjectEntity|null $object The object whose folder holds it.
	 *
	 * @return Node|null The node, or null when a path or id finds nothing.
	 */
	private function resolveFile(Node|string|int $file, ?ObjectEntity $object): ?Node {
		if ($file instanceof Node === true) {
			return $file;
		}

		$node = $this->readFileHandler->getFile(object: $object, file: $file);
		if ($node === null) {
			$this->logger->error(
				message: '[DeleteFileHandler] File ' . (string)$file . ' not found for object ' . ($object?->getId() ?? 'unknown'),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

		return $node;
	}//end resolveFile()

	/**
	 * Refuse a delete the session may not make.
	 *
	 * In order: a lock held by someone else, a file the session cannot reach
	 * (owned or shared), and a file it may not delete. NC enforces the delete
	 * permission natively on delete(), but this fails fast with a clear message.
	 *
	 * @param File $file The file.
	 *
	 * @return void
	 *
	 * @throws Exception When the file is locked by someone else.
	 * @throws NotPermittedException When the session may not reach or delete it.
	 */
	private function assertMayDelete(File $file): void {
		$this->fileLockHandler->assertCanModify($file->getId());
		$this->fileValidHandler->checkOwnership($file);
		if ($file->isDeletable() === false) {
			throw new NotPermittedException("File {$file->getName()} is not deletable by the current session");
		}
	}//end assertMayDelete()

	/**
	 * Delete the file and confirm it is gone.
	 *
	 * @param File $file The file.
	 *
	 * @return bool True when the file is gone, false when the delete failed or did not take.
	 */
	private function deleteAndConfirm(File $file): bool {
		$fileId = $file->getId();
		try {
			$file->delete();
			return $this->confirmGone(fileId: $fileId);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[DeleteFileHandler] Failed to delete file: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return false;
		}
	}//end deleteAndConfirm()

	/**
	 * Whether a deleted file is really gone.
	 *
	 * Through the Node API Nextcloud ignores an operation a listener aborted
	 * (the frozen-object guard does): delete() returns, the file is still
	 * there. Saying true here would make every caller (the example set
	 * remover, the merge) believe a file is gone that is not.
	 *
	 * @param int $fileId The file id.
	 *
	 * @return bool True when no node has the id any more.
	 */
	private function confirmGone(int $fileId): bool {
		if ($this->rootFolder->getFirstNodeById($fileId) === null) {
			return true;
		}

		$this->logger->warning(
			message: '[DeleteFileHandler] The file was not deleted: it is still there after delete() '
				. '(an operation was aborted, for instance by a frozen object).',
			context: ['file' => __FILE__, 'line' => __LINE__, 'fileId' => $fileId]
		);
		return false;
	}//end confirmGone()
}//end class
