<?php

/**
 * CreateFileHandler
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
use OCA\OpenRegister\Db\MagicMapper;
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
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class CreateFileHandler
{

    /**
     * Number of leading bytes read from a stream resource for the magic-byte
     * executable check. Executable signatures live at offset 0, so a small
     * bounded prefix gives full parity with the string path without buffering
     * the whole file into memory.
     *
     * @var int
     */
    private const EXECUTABLE_MAGIC_BYTE_PREFIX_LENGTH = 512;

    /**
     * Reference to FileService for cross-handler coordination (circular dependency break).
     *
     * @var FileService|null
     */
    private ?FileService $fileService = null;

    /**
     * Constructor for CreateFileHandler.
     *
     * @param IRootFolder             $rootFolder           Root folder for file operations.
     * @param FolderManagementHandler $folderMgmtHandler    Folder management handler.
     * @param FileValidationHandler   $fileValidHandler     File validation handler.
     * @param FileOwnershipHandler    $fileOwnershipHandler File ownership handler.
     * @param MagicMapper             $objectEntityMapper   Object entity mapper.
     * @param LoggerInterface         $logger               Logger for logging operations.
     */
    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly FolderManagementHandler $folderMgmtHandler,
        private readonly FileValidationHandler $fileValidHandler,
        private readonly FileOwnershipHandler $fileOwnershipHandler,
        private readonly MagicMapper $objectEntityMapper,
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
     * @param string|resource          $content      File content: a byte string, or a readable stream resource.
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
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  Boolean flag is intentional for simple share toggle.
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * File creation requires handling multiple content formats and error cases.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple execution paths for content processing and validation.
     *
     * @spec openspec/specs/file-actions/spec.md
     */
    public function addFile(
        ObjectEntity|string $objectEntity,
        string $fileName,
        mixed $content,
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
                    // In this case it is a possibility the object gets created later
                    // in a process (for example: synchronization) so we create
                    // the file for a given uuid.
                }
            }

            // Use the new ID-based folder approach.
            $folder = $this->folderMgmtHandler->getObjectFolder(objectEntity: $objectEntity, registerId: $registerId);

            // String-only preprocessing (data-URI extraction and base64 auto-decode)
            // is skipped for a stream resource: the caller has already produced decoded
            // bytes on the streamed (binary-download) path, so there is nothing to
            // decode. This is a behaviour-preserving skip, not a security control.
            if (is_resource($content) === false) {
                // Check if content is a data URI and extract the base64 content.
                if (str_starts_with($content, 'data:') === true) {
                    // Extract the base64 content from the data URI.
                    // Format: data:mime/type;base64,actual-base64-data.
                    $parts = explode(',', $content, 2);
                    if (count($parts) === 2 && str_contains($parts[0], 'base64') === true) {
                        $content = $parts[1];
                    }

                    // If it's not base64-encoded data URI, leave it as is (it might be URL-encoded).
                }

                // Check if the content is base64 encoded and decode it if necessary.
                $decodedContent = base64_decode($content, true);
                if ($decodedContent !== false && base64_encode($decodedContent) === $content) {
                    $content = $decodedContent;
                }
            }

            // Check if the file name is empty.
            if (empty($fileName) === true) {
                $objectId = $objectEntity->getId();
                throw new Exception("Failed to create file because no filename has been provided for object ".$objectId);
            }

            // Security: Block executable files. Executable magic-byte signatures live at
            // offset 0, so on the streamed (resource) path we read a bounded prefix and
            // rewind before writing — full parity with the string path at a fixed, small
            // memory cost. A string is checked directly.
            $execCheckBytes = $content;
            if (is_resource($content) === true) {
                $execCheckBytes = (string) fread($content, self::EXECUTABLE_MAGIC_BYTE_PREFIX_LENGTH);
                rewind($content);
            }

            $this->fileValidHandler->blockExecutableFile(fileName: $fileName, fileContent: $execCheckBytes);

            // The newFile() call already enforces create permission on the folder; it
            // creates the node owned by the folder's mount owner (the openregister
            // system user for the OpenRegister folder, or the original owner for a
            // folder linked from outside it).
            $file = $folder->newFile($fileName);

            // Assert the session can reach the freshly created node.
            $this->fileValidHandler->checkOwnership($file);

            // Write content to the file.
            $file->putContent($content);

            // Re-own to the openregister user only as a fallback when the session
            // lacks write rights; otherwise ownership is left following the folder.
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
            $this->logger->error(
                message: '[CreateFileHandler] Permission denied creating file '.$fileName.': '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            throw new NotPermittedException("Cannot create file $fileName: ".$e->getMessage());
        } catch (Exception $e) {
            // Log general error and rethrow exception.
            $this->logger->error(
                message: '[CreateFileHandler] Failed to create file '.$fileName.': '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
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
     * @param ObjectEntity    $objectEntity The object entity to save the file to.
     * @param string          $fileName     The name of the file to save.
     * @param string|resource $content      File content: a byte string, or a readable stream resource.
     * @param bool            $share        Whether to create a share link for the file (only for new files).
     * @param array           $tags         Optional array of tags to attach to the file.
     *
     * @return File The saved file.
     *
     * @throws NotPermittedException If file operations fail due to permissions.
     * @throws Exception If file operations fail for other reasons.
     *
     * @phpstan-param array<int, string> $tags
     * @psalm-param   array<int, string> $tags
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean flag is intentional for simple share toggle.
     *
     * @spec openspec/specs/file-actions/spec.md
     */
    public function saveFile(
        ObjectEntity $objectEntity,
        string $fileName,
        mixed $content,
        bool $share=false,
        array $tags=[]
    ): File {
        try {
            // Check if the file already exists for this object.
            $existingFile = $this->fileService->getFile(
                object: $objectEntity,
                file: $fileName
            );

            $objectId = $objectEntity->getId();
            if ($existingFile !== null) {
                // File exists, update it.
                $this->logger->info(
                    message: "[CreateFileHandler] File {$fileName} already exists for object {$objectId}, updating...",
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );

                // Update the existing file - pass the object so updateFile can find it in the object folder.
                return $this->fileService->updateFile(
                    filePath: $existingFile->getId(),
                    content: $content,
                    tags: $tags,
                    object: $objectEntity
                );
            }

            // File doesn't exist, create it.
            $this->logger->info(
                message: "[CreateFileHandler] File {$fileName} does not exist for object {$objectId}, creating...",
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            return $this->addFile(
                objectEntity: $objectEntity,
                fileName: $fileName,
                content: $content,
                share: $share,
                tags: $tags
            );
        } catch (NotPermittedException $e) {
            // Log permission error and rethrow exception.
            $this->logger->error(
                message: '[CreateFileHandler] Permission denied saving file '.$fileName.': '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            throw new NotPermittedException("Cannot save file $fileName: ".$e->getMessage());
        } catch (Exception $e) {
            // Log general error and rethrow exception.
            $this->logger->error(
                message: '[CreateFileHandler] Failed to save file '.$fileName.': '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            throw new Exception("Failed to save file $fileName: ".$e->getMessage());
        }//end try
    }//end saveFile()
}//end class
