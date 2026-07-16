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
 * @license   AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FileLockHandler;
use OCA\OpenRegister\Service\File\FileValidationHandler;
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
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class DeleteFileHandler
{
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
        private readonly FileLockHandler $fileLockHandler
    ) {
    }//end __construct()

    /**
     * Delete a file by node, path, or ID.
     *
     * This method can accept either a file path string, file ID integer, or a Node object for deletion.
     * When a Node object is provided, it will be deleted directly. When a string path or integer ID
     * is provided, the file will be located first and then deleted.
     *
     * @param Node|string|int   $file   The file Node object, path (from root), or file ID to delete.
     * @param ObjectEntity|null $object Optional object entity.
     *
     * @return bool True if successful, false if the file didn't exist.
     *
     * @throws Exception If deleting the file is not permitted or file operations fail.
     *
     * @psalm-param Node|string|int $file
     *
     * @spec openspec/specs/file-actions/spec.md
     */
    public function deleteFile(Node|string|int $file, ?ObjectEntity $object=null): bool
    {
        // Determine file name for error logging.
        $fileName = (string) $file;
        if ($file instanceof Node === true) {
            $fileName = $file->getName();
        }

        if ($file instanceof Node === false) {
            $file = $this->readFileHandler->getFile(object: $object, file: $file);
        }

        if ($file === null) {
            $this->logger->error(
                message: '[DeleteFileHandler] File '.$fileName.' not found for object '.($object?->getId() ?? 'unknown'),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return false;
        }

        if ($file instanceof File === false) {
            $this->logger->error(
                message: '[DeleteFileHandler] File is not a File instance, it\'s a: '.get_class($file),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return false;
        }

        // Reject when the file is locked by someone else.
        $this->fileLockHandler->assertCanModify($file->getId());

        // Assert the session can reach the file (owned or shared).
        $this->fileValidHandler->checkOwnership($file);

        // Deleting additionally requires delete permission. NC enforces this
        // natively on delete(), but we fail fast with a clear message.
        if ($file->isDeletable() === false) {
            throw new NotPermittedException("File {$file->getName()} is not deletable by the current session");
        }

        try {
            $file->delete();
        } catch (Exception $e) {
            $this->logger->error(
                message: '[DeleteFileHandler] Failed to delete file: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return false;
        }

        return true;
    }//end deleteFile()

    /**
     * Delete multiple files.
     *
     * @param array             $files  Array of file nodes, paths, or IDs.
     * @param ObjectEntity|null $object Object entity (optional).
     *
     * @return (Node|bool|int|mixed|string)[][] Array of deletion results.
     *
     * @psalm-return list<array{error?: string, file: Node|int|mixed|string, success: bool}>
     *
     * @spec openspec/specs/file-actions/spec.md
     */
    public function deleteFiles(array $files, ?ObjectEntity $object=null): array
    {
        $results = [];
        foreach ($files as $file) {
            try {
                $results[] = ['file' => $file, 'success' => $this->deleteFile(file: $file, object: $object)];
            } catch (Exception $e) {
                $results[] = ['file' => $file, 'success' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }//end deleteFiles()
}//end class
