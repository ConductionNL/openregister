<?php

/**
 * UpdateFileHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use Psr\Log\LoggerInterface;

/**
 * Handles file update operations with Single Responsibility.
 *
 * This handler is responsible ONLY for:
 * - Updating file content
 * - Updating file metadata
 * - Updating file tags
 * - Handling ownership transfer during updates
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class UpdateFileHandler
{

    /**
     * Reference to FileService for cross-handler coordination (circular dependency break).
     *
     * @var FileService|null
     */
    private ?FileService $fileService = null;

    /**
     * Constructor for UpdateFileHandler.
     *
     * @param IRootFolder             $rootFolder              Root folder for file operations.
     * @param FolderManagementHandler $folderManagementHandler Folder management handler.
     * @param FileValidationHandler   $fileValidationHandler   File validation handler.
     * @param FileOwnershipHandler    $fileOwnershipHandler    File ownership handler.
     * @param ReadFileHandler         $readFileHandler         Read file handler.
     * @param ISystemTagManager       $systemTagManager        System tag manager.
     * @param ISystemTagObjectMapper  $systemTagMapper         System tag object mapper.
     * @param LoggerInterface         $logger                  Logger for logging operations.
     */
    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly FolderManagementHandler $folderManagementHandler,
        private readonly FileValidationHandler $fileValidationHandler,
        private readonly FileOwnershipHandler $fileOwnershipHandler,
        private readonly ReadFileHandler $readFileHandler,
        private readonly ISystemTagManager $systemTagManager,
        private readonly ISystemTagObjectMapper $systemTagMapper,
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
     * Update a file's content, metadata, and tags.
     *
     * This method updates the content and/or tags of an existing file. When updating tags,
     * it preserves any existing 'object:' tags while replacing other user-defined tags.
     *
     * @param string|int        $filePath The path or file ID.
     * @param mixed             $content  Optional content of the file.
     * @param array             $tags     Optional array of tags.
     * @param ObjectEntity|null $object   Optional object entity.
     *
     * @return File The updated file.
     *
     * @throws Exception If the file doesn't exist or if file operations fail.
     *
     * @phpstan-param array<int, string> $tags
     * @psalm-param   array<int, string> $tags
     */
    public function updateFile(
        string|int $filePath,
        mixed $content=null,
        array $tags=[],
        ?ObjectEntity $object=null
    ): File {
        // Debug logging - original file path.
        $originalFilePath = $filePath;
        $this->logger->info(message: "updateFile: Original file path received: '$originalFilePath'");

        $file = null;

        // If $filePath is an integer (file ID), try to find the file directly by ID.
        if (is_int($filePath) === true) {
            $this->logger->info(message: "updateFile: File ID provided: $filePath");

            if ($object !== null) {
                // Try to find the file in the object's folder by ID.
                $file = $this->readFileHandler->getFile(object: $object, file: $filePath);
                if ($file !== null) {
                    $fileName = $file->getName();
                    $fileId   = $file->getId();
                    $this->logger->info(message: "updateFile: Found file by ID in object folder: $fileName (ID: $fileId)");
                }
            }

            if ($file === null) {
                // Try to find the file in the user folder by ID.
                try {
                    $userFolder = $this->folderManagementHandler->getOpenRegisterUserFolder();
                    $nodes      = $userFolder->getById($filePath);
                    if (empty($nodes) === true) {
                        $this->logger->error(message: "updateFile: No file found with ID: $filePath");
                        throw new Exception("File with ID $filePath does not exist");
                    }

                    $file  = $nodes[0];
                    $fname = $file->getName();
                    $fid   = $file->getId();
                    $this->logger->info(message: "updateFile: Found file by ID in user folder: $fname (ID: $fid)");
                } catch (Exception $e) {
                    $this->logger->error(message: "updateFile: Error finding file by ID $filePath: ".$e->getMessage());
                    throw new Exception("File with ID $filePath does not exist: ".$e->getMessage());
                }
            }
        }//end if

        if (is_int($originalFilePath) === false) {
            // Handle string file paths (existing logic).
            // Clean file path and extract filename using utility method.
            $pathInfo = $this->fileService->extractFileNameFromPath($filePath);
            $filePath = $pathInfo['cleanPath'];
            $fileName = $pathInfo['fileName'];

            $this->logger->info(message: "updateFile: After cleaning: '$filePath'");
            if ($fileName !== $filePath) {
                $this->logger->info(message: "updateFile: Extracted filename from path: '$fileName' (from '$filePath')");
            }
        }

        // Skip the existing object/user folder search logic for file IDs since we already found the file.
        if ($file === null) {
            // If object is provided, try to find the file in the object folder first.
            if ($object !== null) {
                try {
                    $objectFolder = $this->folderManagementHandler->getObjectFolder($object);

                    if ($objectFolder !== null) {
                        $this->logger->info(message: "updateFile: Object folder path: ".$objectFolder->getPath());
                        $this->logger->info(message: "updateFile: Object folder ID: ".$objectFolder->getId());

                        // List all files in the object folder for debugging.
                        try {
                            $folderFiles = $objectFolder->getDirectoryListing();
                            $fileNames   = array_map(fn($f) => $f->getName(), $folderFiles);
                            $this->logger->info(message: "updateFile: Files in object folder: ".implode(', ', $fileNames));
                        } catch (Exception $e) {
                            $this->logger->warning(message: "updateFile: Could not list folder contents: ".$e->getMessage());
                        }

                        // Try to get the file from object folder using just the filename.
                        try {
                            $file = $objectFolder->get($fileName);
                            $msg  = "updateFile: Found file in object folder: ".$file->getName()." (ID: ".$file->getId().")";
                            $this->logger->info(message: $msg);
                        } catch (NotFoundException) {
                            $this->logger->warning(message: "updateFile: File '$fileName' not found in object folder.");

                            // Also try with the full path in case it's nested.
                            try {
                                $file = $objectFolder->get($filePath);
                                $msg  = "updateFile: Found file using full path in object folder: ".$file->getName();
                                $this->logger->info(message: $msg);
                            } catch (NotFoundException) {
                                $msg = "updateFile: File '$filePath' also not found with full path in object folder.";
                                $this->logger->warning(message: $msg);
                            }
                        }
                    } else {
                        $msg = "updateFile: Could not get object folder for object ID: ".$object->getId();
                        $this->logger->warning(message: $msg);
                    }//end if
                } catch (Exception $e) {
                    $this->logger->error(message: "updateFile: Error accessing object folder: ".$e->getMessage());
                }//end try
            }//end if

            if ($object === null) {
                $this->logger->info(message: "updateFile: No object provided, will search in user folder");
            }

            // If object wasn't provided or file wasn't found in object folder, try user folder.
            if ($file === null) {
                $this->logger->info(message: "updateFile: Trying user folder approach with path: '$filePath'");
                try {
                    $userFolder = $this->folderManagementHandler->getOpenRegisterUserFolder();
                    $file       = $userFolder->get(path: $filePath);
                    $msg        = "updateFile: Found file in user folder at path: $filePath (ID: ".$file->getId().")";
                    $this->logger->info(message: $msg);
                } catch (NotFoundException $e) {
                    $this->logger->error(message: "updateFile: File $filePath not found in user folder either.");

                    // Try to find the file by ID if the path starts with a number.
                    if (preg_match('/^(\d+)\//', $filePath, $matches) === 1) {
                        $fileId = (int) $matches[1];
                        $this->logger->info(message: "updateFile: Attempting to find file by ID: $fileId");

                        try {
                            $nodes = $userFolder->getById($fileId);
                            if (empty($nodes) === false) {
                                $file     = $nodes[0];
                                $fileName = $file->getName();
                                $path     = $file->getPath();
                                $msg      = "updateFile: Found file by ID $fileId: $fileName at path: $path";
                                $this->logger->info(message: $msg);
                            } else {
                                $this->logger->warning(message: "updateFile: No file found with ID: $fileId");
                            }
                        } catch (Exception $e) {
                            $this->logger->error(message: "updateFile: Error finding file by ID $fileId: ".$e->getMessage());
                        }
                    }

                    if ($file === null) {
                        throw new Exception("File $filePath does not exist");
                    }
                } catch (NotPermittedException | InvalidPathException $e) {
                    $this->logger->error(message: "updateFile: Can't access file $filePath: ".$e->getMessage());
                    throw new Exception("Can't access file $filePath: ".$e->getMessage());
                }//end try
            }//end if
        }//end if

        // Update the file content if provided and content is not equal to the current content.
        if ($content !== null && $file instanceof File && $file->hash(type: 'md5') !== md5(string: $content)) {
            try {
                    // Check if the content is base64 encoded and decode it if necessary.
                if (base64_encode(base64_decode($content, true)) === $content) {
                    $content = base64_decode($content);
                }

                // Security: Block executable files.
                $this->fileValidationHandler->blockExecutableFile(fileName: $file->getName(), fileContent: $content);

                // @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
                $this->fileValidationHandler->checkOwnership($file);

                $file->putContent(data: $content);
                $this->logger->info(message: "updateFile: Successfully updated file content: ".$file->getName());

                // Transfer ownership to OpenRegister and share with current user if needed.
                $this->fileOwnershipHandler->transferFileOwnershipIfNeeded($file);
            } catch (NotPermittedException $e) {
                $this->logger->error(message: "updateFile: Can't write content to file: ".$e->getMessage());
                throw new Exception("Can't write content to file: ".$e->getMessage());
            }//end try
        }//end if

        // Update tags if provided.
        if (empty($tags) === false) {
            // Get existing object tags to preserve them.
            $existingTags = $this->fileService->getFileTags(fileId: (string) $file->getId());
            $objectTags   = array_filter(
                $existingTags,
                static function (string $tag): bool {
                        return str_starts_with($tag, 'object:');
                }
            );

            // Combine object tags with new tags, avoiding duplicates.
            $allTags = array_unique(array_merge($objectTags, $tags));

            $this->fileService->attachTagsToFile(fileId: (string) $file->getId(), tags: $allTags);
            $this->logger->info(message: "updateFile: Successfully updated file tags: ".$file->getName());
        }

        return $file;
    }//end updateFile()
}//end class
