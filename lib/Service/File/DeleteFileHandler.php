<?php

/**
 * DeleteFileHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FileValidationHandler;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
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
 * @license  AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class DeleteFileHandler
{


    /**
     * Constructor for DeleteFileHandler.
     *
     * @param IRootFolder          $rootFolder           Root folder for file operations.
     * @param ReadFileHandler      $readFileHandler      Read file handler.
     * @param FileValidationHandler $fileValidationHandler File validation handler.
     * @param FileOwnershipHandler $fileOwnershipHandler File ownership handler.
     * @param LoggerInterface      $logger               Logger for logging operations.
     */
    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly ReadFileHandler $readFileHandler,
        private readonly FileValidationHandler $fileValidationHandler,
        private readonly FileOwnershipHandler $fileOwnershipHandler,
        private readonly LoggerInterface $logger
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
     */
    public function deleteFile(Node|string|int $file, ?ObjectEntity $object=null): bool
    {
        if ($file instanceof Node === false) {
            $fileName = (string) $file;
            $file     = $this->readFileHandler->getFile(object: $object, file: $file);
        }

        if ($file === null) {
            $this->logger->error(message: 'File '.$fileName.' not found for object '.($object?->getId() ?? 'unknown'));
            return false;
        }

        if ($file instanceof File === false) {
            $this->logger->error(message: 'File is not a File instance, it\'s a: '.get_class($file));
            return false;
        }

        // @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
        $this->fileValidationHandler->checkOwnership($file);

        try {
            $file->delete();
        } catch (Exception $e) {
            $this->logger->error(message: 'Failed to delete file: '.$e->getMessage());
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
     * @return array Array of deletion results.
     */
    public function deleteFiles(array $files, ?ObjectEntity $object=null): array
    {
        $results = [];
        foreach ($files as $file) {
            try {
                $results[] = ['file' => $file, 'success' => $this->deleteFile($file, $object)];
            } catch (Exception $e) {
                $results[] = ['file' => $file, 'success' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;

    }//end deleteFiles()


}//end class
