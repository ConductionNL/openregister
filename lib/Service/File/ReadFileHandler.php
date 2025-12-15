<?php

declare(strict_types=1);

/*
 * ReadFileHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\File;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Service\FileService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Handles file retrieval operations with Single Responsibility.
 *
 * This handler is responsible ONLY for:
 * - Getting files by ID, name, or path
 * - Retrieving all files for an object
 * - Finding and searching for files
 * - Reading file metadata
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class ReadFileHandler
{

    /**
     * Reference to FileService for cross-handler coordination (circular dependency break).
     *
     * @var FileService|null
     */
    private ?FileService $fileService = null;


    /**
     * Constructor for ReadFileHandler.
     *
     * @param IRootFolder             $rootFolder              Root folder for file operations.
     * @param FolderManagementHandler $folderManagementHandler Folder management handler.
     * @param FileOwnershipHandler    $fileOwnershipHandler    File ownership handler.
     * @param ObjectEntityMapper      $objectEntityMapper      Object entity mapper.
     * @param LoggerInterface         $logger                  Logger for logging operations.
     */
    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly FolderManagementHandler $folderManagementHandler,
        private readonly FileOwnershipHandler $fileOwnershipHandler,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    /**
     * Set the FileService instance for cross-handler coordination.
     *
     * @param FileService $fileService The file service instance.
     *
     * @return void
     */
    public function setFileService(FileService $fileService): void
    {
        $this->fileService = $fileService;

    }//end setFileService()


    /**
     * Get a file by file identifier (ID or name/path) or by object and file name/path.
     *
     * If $file is an integer or a string that is an integer (e.g. '23234234'), the file will be fetched by ID
     * and the $object parameter will be ignored. Otherwise, the file will be fetched by name/path within the object folder.
     *
     * See https://nextcloud-server.netlify.app/classes/ocp-files-file for the Nextcloud documentation on the File class.
     * See https://nextcloud-server.netlify.app/classes/ocp-files-node for the Nextcloud documentation on the Node superclass.
     *
     * @param ObjectEntity|string|null $object The object or object ID to fetch files for (ignored if $file is an ID).
     * @param string|int               $file   The file name/path within the object folder, or the file ID (int or numeric string).
     *
     * @return File|null The file if found, null otherwise.
     *
     * @throws NotFoundException If the folder is not found.
     * @throws DoesNotExistException If the object ID is not found.
     *
     * @psalm-param    ObjectEntity|string|null $object
     * @phpstan-param  ObjectEntity|string|null $object
     * @psalm-param    string|int $file
     * @phpstan-param  string|int $file
     * @psalm-return   File|null
     * @phpstan-return File|null
     */
    public function getFile(ObjectEntity|string|null $object=null, string|int $file=''): ?File
    {

        // If string ID provided for object, try to find the object entity.
        if (is_string($object) === true && empty($object) === false) {
            $object = $this->objectEntityMapper->find($object);
        }

        // Use the new ID-based folder approach.
        $folder = $this->folderManagementHandler->getObjectFolder($object);

        // If $file is an integer or a string that is an integer, treat as file ID.
        if (is_int($file) === true || (is_string($file) === true && ctype_digit($file) === true) === true) {
            // Try to get the file by ID.
            try {
                $nodes = $folder->getById((int) $file);
                if (empty($nodes) === false && $nodes[0] instanceof File) {
                    $fileNode = $nodes[0];
                    // Check ownership for NextCloud rights issues.
                    $this->fileOwnershipHandler->checkOwnership($fileNode);
                    return $fileNode;
                }
            } catch (Exception $e) {
                $this->logger->error(message: 'getFile: Error finding file by ID '.$file.': '.$e->getMessage());
                return null;
            }

            // If not found by ID, return null.
            return null;
        }

        // Clean file path and extract filename using utility method.
        $pathInfo = $this->fileService->extractFileNameFromPath((string) $file);
        $filePath = $pathInfo['cleanPath'];
        $fileName = $pathInfo['fileName'];

        // Check if folder exists and get the file.
        if ($folder instanceof Folder === true) {
            try {
                // First try with just the filename.
                $fileNode = $folder->get($fileName);

                // Check ownership for NextCloud rights issues.
                $this->fileOwnershipHandler->checkOwnership($fileNode);

                return $fileNode;
            } catch (NotFoundException) {
                try {
                    // If that fails, try with the full path.
                    $fileNode = $folder->get($filePath);

                    // Check ownership for NextCloud rights issues.
                    $this->fileOwnershipHandler->checkOwnership($fileNode);

                    return $fileNode;
                } catch (NotFoundException) {
                    // File not found.
                    return null;
                }
            }//end try
        }//end if

        return null;

    }//end getFile()


    /**
     * Get a file by its Nextcloud file ID without needing object context.
     *
     * This method retrieves a file directly using its Nextcloud file ID,
     * which is useful for authenticated file access endpoints.
     *
     * @param int $fileId The Nextcloud file ID.
     *
     * @return File|null The file node or null if not found.
     *
     * @throws \Exception If there's an error accessing the file.
     *
     * @phpstan-param  int $fileId
     * @phpstan-return File|null
     */
    public function getFileById(int $fileId): ?File
    {
        try {
            // Use root folder to search for file by ID.
            $nodes = $this->rootFolder->getById($fileId);

            if (empty($nodes) === true) {
                return null;
            }

            // Get the first node (file IDs are unique).
            $node = $nodes[0];

            // Ensure it's a file, not a folder.
            if (($node instanceof File) === false) {
                return null;
            }

            // Check ownership for NextCloud rights issues.
            $this->fileOwnershipHandler->checkOwnership($node);

            return $node;
        } catch (Exception $e) {
            $this->logger->error(message: 'getFileById: Error finding file by ID '.$fileId.': '.$e->getMessage());
            return null;
        }//end try

    }//end getFileById()


    /**
     * Get all files for an object.
     *
     * @param ObjectEntity|string $object          The object or object ID to fetch files for.
     * @param bool|null           $sharedFilesOnly Whether to return only shared files.
     *
     * @return array Array of file nodes.
     *
     * @throws DoesNotExistException If the object ID is not found.
     *
     * @psalm-return   list<\OCP\Files\Node>
     * @phpstan-return array<int, \OCP\Files\Node>
     */
    public function getFiles(ObjectEntity|string $object, ?bool $sharedFilesOnly=false): array
    {
        // If string ID provided, try to find the object entity.
        if (is_string($object) === true) {
            $object = $this->objectEntityMapper->find($object);
        }

        // Use the new ID-based folder approach.
        return $this->fileService->getFilesForEntity(entity: $object, sharedFilesOnly: $sharedFilesOnly);

    }//end getFiles()


}//end class
