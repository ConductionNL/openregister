<?php

declare(strict_types=1);

/*
 * CreateFileHandler
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
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\FileService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use Psr\Log\LoggerInterface;

/**
 * Handles file creation operations with Single Responsibility.
 *
 * This handler is responsible ONLY for:
 * - Creating new files with content
 * - Adding files to objects
 * - Upsert operations (saveFile)
 * - Coordinating tags, sharing, and ownership during creation
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class CreateFileHandler
{

    /**
     * Reference to FileService for cross-handler coordination (circular dependency break).
     *
     * @var FileService|null
     */
    private ?FileService $fileService = null;


    /**
     * Constructor for CreateFileHandler.
     *
     * @param IRootFolder             $rootFolder              Root folder for file operations.
     * @param FolderManagementHandler $folderManagementHandler Folder management handler.
     * @param FileValidationHandler   $fileValidationHandler   File validation handler.
     * @param FileOwnershipHandler    $fileOwnershipHandler    File ownership handler.
     * @param ObjectEntityMapper      $objectEntityMapper      Object entity mapper.
     * @param LoggerInterface         $logger                  Logger for logging operations.
     */
    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly FolderManagementHandler $folderManagementHandler,
        private readonly FileValidationHandler $fileValidationHandler,
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
     * Add a file to an object with content, tags, and sharing.
     *
     * This method automatically adds an 'object:' tag containing the object's UUID
     * in addition to any user-provided tags.
     *
     * @param ObjectEntity|string      $objectEntity The object entity to add the file to.
     * @param string                   $fileName     The name of the file to create.
     * @param string                   $content      The content to write to the file.
     * @param bool                     $share        Whether to create a share link for the file.
     * @param array                    $tags         Optional array of tags to attach to the file.
     * @param int|string|Schema|null   $_schema      The register of the object to add the file to (unused).
     * @param int|string|Register|null $_register    The register of the object to add the file to (unused).
     * @param int|string|null          $registerId   The registerId of the object to add the file to.
     *
     * @return File The created file.
     *
     * @throws NotPermittedException If file creation fails due to permissions.
     * @throws Exception If file creation fails for other reasons.
     *
     * @phpstan-param array<int, string> $tags
     * @psalm-param   array<int, string> $tags
     */
    public function addFile(
        ObjectEntity|string $objectEntity,
        string $fileName,
        string $content,
        bool $share=false,
        array $tags=[],
        Schema|int|string|null $_schema=null,
        Register|int|string|null $_register=null,
        int|string|null $registerId=null
    ): File {
        try {
            // Ensure we have an ObjectEntity instance.
            if (is_string($objectEntity) === true) {
                try {
                    $objectEntity = $this->objectEntityMapper->find($objectEntity);
                } catch (DoesNotExistException) {
                    // In this case it is a possibility the object gets created later in a process (for example: synchronization) so we create the file for a given uuid.
                }
            }

            // Use the new ID-based folder approach.
            $folder = $this->folderManagementHandler->getObjectFolder(objectEntity: $objectEntity, registerId: $registerId);

            // Check if the content is base64 encoded and decode it if necessary.
            if (base64_encode(base64_decode($content, true)) === $content) {
                $content = base64_decode($content);
            }

            // Check if the file name is empty.
            if (empty($fileName) === true) {
                throw new Exception("Failed to create file because no filename has been provided for object ".$objectEntity->getId());
            }

            // Security: Block executable files.
            $this->fileValidationHandler->blockExecutableFile(fileName: $fileName, fileContent: $content);

            $file = $folder->newFile($fileName);

            // @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
            $this->fileOwnershipHandler->checkOwnership($file);

            // Write content to the file.
            $file->putContent($content);

            // Transfer ownership to OpenRegister and share with current user if needed.
            $this->fileOwnershipHandler->transferFileOwnershipIfNeeded($file);

            // Create a share link for the file if requested.
            if ($share === true) {
                $this->fileService->createShareLink(path: $file->getPath());
            }

            // Automatically add object tag with the object's UUID.
            $objectTag = $this->fileService->generateObjectTag($objectEntity);
            $allTags   = array_merge([$objectTag], $tags);

            // Add tags to the file (including the automatic object tag).
            // $allTags always contains at least $objectTag, so it's never empty.
            $this->fileService->attachTagsToFile(fileId: (string) $file->getId(), tags: $allTags);

            // @TODO: This sets the file array of an object, but we should check why this array is not added elsewhere.
            // $objectFiles = $objectEntity->getFiles();
            //
            // $objectFiles[] = $this->formatFile($file);
            // $objectEntity->setFiles($objectFiles);
            //
            // $this->objectEntityMapper->update($objectEntity);
            return $file;
        } catch (NotPermittedException $e) {
            // Log permission error and rethrow exception.
            $this->logger->error(message: "Permission denied creating file $fileName: ".$e->getMessage());
            throw new NotPermittedException("Cannot create file $fileName: ".$e->getMessage());
        } catch (Exception $e) {
            // Log general error and rethrow exception.
            $this->logger->error(message: "Failed to create file $fileName: ".$e->getMessage());
            throw new Exception("Failed to create file $fileName: ".$e->getMessage());
        }//end try

    }//end addFile()


    /**
     * Save a file (upsert operation - create or update).
     *
     * This method provides a generic save functionality that checks if a file already exists
     * for the given object. If it exists, the file will be updated; if not, a new file will
     * be created. This is particularly useful for synchronization scenarios where you want
     * to "upsert" files.
     *
     * @param ObjectEntity $objectEntity The object entity to save the file to.
     * @param string       $fileName     The name of the file to save.
     * @param string       $content      The content to write to the file.
     * @param bool         $share        Whether to create a share link for the file (only for new files).
     * @param array        $tags         Optional array of tags to attach to the file.
     *
     * @return File The saved file.
     *
     * @throws NotPermittedException If file operations fail due to permissions.
     * @throws Exception If file operations fail for other reasons.
     *
     * @phpstan-param array<int, string> $tags
     * @psalm-param   array<int, string> $tags
     */
    public function saveFile(
        ObjectEntity $objectEntity,
        string $fileName,
        string $content,
        bool $share=false,
        array $tags=[]
    ): File {
        try {
            // Check if the file already exists for this object.
            $existingFile = $this->fileService->getFile(
                    object: $objectEntity,
                    file: $fileName
                    );

            if ($existingFile !== null) {
                // File exists, update it.
                $this->logger->info(message: "File $fileName already exists for object {$objectEntity->getId()}, updating...");

                // Update the existing file - pass the object so updateFile can find it in the object folder.
                return $this->fileService->updateFile(
                        filePath: $existingFile->getId(),
                        content: $content,
                        tags: $tags,
                        object: $objectEntity
                        );
            } else {
                // File doesn't exist, create it.
                $this->logger->info(message: "File $fileName doesn't exist for object {$objectEntity->getId()}, creating...");

                return $this->addFile(
                        objectEntity: $objectEntity,
                        fileName: $fileName,
                    content: $content,
                    share: $share,
                    tags: $tags
                );
            }//end if
        } catch (NotPermittedException $e) {
            // Log permission error and rethrow exception.
            $this->logger->error(message: "Permission denied saving file $fileName: ".$e->getMessage());
            throw new NotPermittedException("Cannot save file $fileName: ".$e->getMessage());
        } catch (Exception $e) {
            // Log general error and rethrow exception.
            $this->logger->error(message: "Failed to save file $fileName: ".$e->getMessage());
            throw new Exception("Failed to save file $fileName: ".$e->getMessage());
        }//end try

    }//end saveFile()


}//end class
