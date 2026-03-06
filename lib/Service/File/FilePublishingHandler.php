<?php

/**
 * FilePublishingHandler
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
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * Handles file publishing and archiving operations.
 *
 * This handler is responsible for:
 * - Publishing files (creating public shares)
 * - Unpublishing files (removing public shares)
 * - Creating ZIP archives of object files
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class FilePublishingHandler
{

    /**
     * Reference to FileService for cross-handler coordination (circular dependency break).
     *
     * @var FileService|null
     */
    private ?FileService $fileService = null;

    /**
     * Constructor for FilePublishingHandler.
     *
     * @param ObjectEntityMapper $objectEntityMapper Object entity mapper for fetching objects.
     * @param FileMapper         $fileMapper         File mapper for share operations.
     * @param LoggerInterface    $logger             Logger for logging operations.
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly FileMapper $fileMapper,
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
     * Publish a file by creating a public share link.
     *
     * This method makes a file publicly accessible by creating a public share link.
     * It handles both file IDs and file paths, creating appropriate shares and tags.
     *
     * @param ObjectEntity|string $object The object entity or ID.
     * @param string|int          $file   The file ID or path.
     *
     * @return File The published file node.
     *
     * @throws Exception         If publishing fails.
     * @throws NotFoundException If the file is not found.
     *
     * @phpstan-return File
     * @psalm-return   File
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  File lookup requires handling ID vs path scenarios
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple file resolution paths with fallback logic
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive file lookup and sharing requires extensive code
     */
    public function publishFile(ObjectEntity | string $object, string | int $file): File
    {
        // If string ID provided, try to find the object entity.
        if (is_string($object) === true) {
            $object = $this->objectEntityMapper->find($object);
        }

        // Debug logging - original file parameter.
        $originalFile = $file;
        $this->logger->info(
            message: "[FilePublishingHandler] publishFile: Original file parameter received: '$originalFile'",
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        // Initialize fileNode before conditional assignment.
        $fileNode = null;

        // If $file is an integer (file ID), try to find the file directly by ID.
        if (is_int($file) === true) {
            $this->logger->info(
                message: "[FilePublishingHandler] publishFile: File ID provided: $file",
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            // Try to find the file in the object's folder by ID.
            $fileNode = $this->fileService->getFile(object: $object, file: $file);
            if ($fileNode === null) {
                $this->logger->error(
                    message: "[FilePublishingHandler] publishFile: No file found with ID: $file",
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                throw new Exception("File with ID $file does not exist");
            }

            $foundMsg  = "[FilePublishingHandler] publishFile: Found file by ID: ".$fileNode->getName();
            $foundMsg .= " (ID: ".$fileNode->getId().")";
            $this->logger->info(message: $foundMsg, context: ['file' => __FILE__, 'line' => __LINE__]);
        } else {
            // Handle string file paths (existing logic).
            // Clean file path and extract filename using utility method.
            $pathInfo = $this->fileService->extractFileNameFromPath($file);
            $filePath = $pathInfo['cleanPath'];
            $fileName = $pathInfo['fileName'];

            $this->logger->info(
                message: "[FilePublishingHandler] publishFile: After cleaning: '$filePath'",
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            if ($fileName !== $filePath) {
                $this->logger->info(
                    message: "[FilePublishingHandler] publishFile: Extracted filename from path: '$fileName' (from '$filePath')",
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
            }

            // Get the object folder (this is where the files actually are).
            $objectFolder = $this->fileService->getObjectFolder($object);

            if ($objectFolder === null) {
                $this->logger->error(
                    message: '[FilePublishingHandler] publishFile: Could not get object folder for object: '.$object->getId(),
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                throw new Exception('Object folder not found.');
            }

            $this->logger->info(
                message: "[FilePublishingHandler] publishFile: Object folder path: ".$objectFolder->getPath(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            // Debug: List all files in the object folder.
            try {
                $objectFiles     = $objectFolder->getDirectoryListing();
                $objectFileNames = array_map(
                    function ($file) {
                        return $file->getName();
                    },
                    $objectFiles
                );
                $this->logger->info(
                    message: "[FilePublishingHandler] publishFile: Files in object folder: ".json_encode($objectFileNames),
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
            } catch (Exception $e) {
                $this->logger->error(
                    message: "[FilePublishingHandler] publishFile: Error listing object folder contents: ".$e->getMessage(),
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
            }

            try {
                $this->logger->info(
                    message: "[FilePublishingHandler] publishFile: Attempting to get file '$fileName' from object folder",
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                $fileNode      = $objectFolder->get($fileName);
                $foundFileMsg  = "[FilePublishingHandler] publishFile: Successfully found file: ".$fileNode->getName();
                $foundFileMsg .= " at ".$fileNode->getPath();
                $this->logger->info(message: $foundFileMsg, context: ['file' => __FILE__, 'line' => __LINE__]);
            } catch (NotFoundException $e) {
                // Try with full path if filename didn't work.
                try {
                    $attemptMsg = "[FilePublishingHandler] publishFile: Attempting to get file '$filePath' (full path) from object folder";
                    $this->logger->info(message: $attemptMsg, context: ['file' => __FILE__, 'line' => __LINE__]);
                    $fileNode   = $objectFolder->get($filePath);
                    $nodeName   = $fileNode->getName();
                    $nodePath   = $fileNode->getPath();
                    $successMsg = "[FilePublishingHandler] publishFile: Successfully found file using full path: $nodeName at $nodePath";
                    $this->logger->info(message: $successMsg, context: ['file' => __FILE__, 'line' => __LINE__]);
                } catch (NotFoundException $e2) {
                    $errDetail = $e2->getMessage();
                    $prefix    = '[FilePublishingHandler] publishFile:';
                    $errMsg    = "$prefix File '$fileName' and '$filePath' not found in object folder. NotFoundException: $errDetail";
                    $this->logger->error(message: $errMsg, context: ['file' => __FILE__, 'line' => __LINE__]);
                    throw new Exception('File not found.');
                }
            } catch (Exception $e) {
                $errMsg  = "[FilePublishingHandler] publishFile: Unexpected error getting file from object folder: ";
                $errMsg .= $e->getMessage();
                $this->logger->error(message: $errMsg, context: ['file' => __FILE__, 'line' => __LINE__]);
                throw new Exception('File not found.');
            }//end try
        }//end if

        // Verify file exists and is a File instance.
        if ($fileNode instanceof File === false) {
            $this->logger->error(
                message: "[FilePublishingHandler] publishFile: Found node is not a File instance, it's a: ".get_class($fileNode),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            throw new Exception('File not found.');
        }

        // @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
        $this->fileService->checkOwnership($fileNode);

        $this->logger->info(
            message: "[FilePublishingHandler] publishFile: Creating share link for file: ".$fileNode->getPath(),
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        // Use FileMapper to create the share directly in the database.
        try {
            $openRegisterUser = $this->fileService->getUser();
            $shareInfo        = $this->fileMapper->publishFile(
                fileId: $fileNode->getId(),
                sharedBy: $openRegisterUser->getUID(),
                shareOwner: $openRegisterUser->getUID(),
                // Read only.
            );

            $shareId  = $shareInfo['id'];
            $token    = $shareInfo['token'];
            $url      = $shareInfo['accessUrl'];
            $message  = "[FilePublishingHandler] publishFile: Successfully created public share via FileMapper";
            $message .= " - ID: {$shareId}, Token: {$token}, URL: {$url}";
            $this->logger->info(message: $message, context: ['file' => __FILE__, 'line' => __LINE__]);
        } catch (Exception $e) {
            $errMsg = "[FilePublishingHandler] publishFile: Failed to create share via FileMapper: ".$e->getMessage();
            $this->logger->error(message: $errMsg, context: ['file' => __FILE__, 'line' => __LINE__]);
            throw new Exception('Failed to create share link: '.$e->getMessage());
        }

        $this->logger->info(
            message: "[FilePublishingHandler] publishFile: Successfully published file: ".$fileNode->getName(),
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
        return $fileNode;
    }//end publishFile()

    /**
     * Unpublish a file by removing public share links.
     *
     * This method removes public accessibility from a file by deleting its public
     * share links and associated tags.
     *
     * @param ObjectEntity|string $object   The object entity or ID.
     * @param string|int          $filePath The file ID or path.
     *
     * @return File The unpublished file node.
     *
     * @throws Exception         If unpublishing fails.
     * @throws NotFoundException If the file is not found.
     *
     * @phpstan-return File
     * @psalm-return   File
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  File lookup requires handling ID vs path scenarios
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple file resolution paths with fallback logic
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive file lookup and unsharing requires extensive code
     */
    public function unpublishFile(ObjectEntity | string $object, string|int $filePath): File
    {
        // If string ID provided, try to find the object entity.
        if (is_string($object) === true) {
            $object = $this->objectEntityMapper->find($object);
        }

        // Debug logging - original file path.
        $originalFilePath = $filePath;
        $this->logger->info(
            message: "[FilePublishingHandler] unpublishFile: Original file path received: '$originalFilePath'",
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        // Initialize file before conditional assignment.
        $file = null;

        // If $filePath is an integer (file ID), try to find the file directly by ID.
        if (is_int($filePath) === true) {
            $this->logger->info(
                message: "[FilePublishingHandler] unpublishFile: File ID provided: $filePath",
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            // Try to find the file in the object's folder by ID.
            $file = $this->fileService->getFile(object: $object, file: $filePath);
            if ($file === null) {
                $this->logger->error(
                    message: "[FilePublishingHandler] unpublishFile: No file found with ID: $filePath",
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                throw new Exception("File with ID $filePath does not exist");
            }

            $foundMsg  = "[FilePublishingHandler] unpublishFile: Found file by ID: ".$file->getName();
            $foundMsg .= " (ID: ".$file->getId().")";
            $this->logger->info(message: $foundMsg, context: ['file' => __FILE__, 'line' => __LINE__]);
        } else {
            // Handle string file paths (existing logic).
            // Clean file path and extract filename using utility method.
            $pathInfo = $this->fileService->extractFileNameFromPath($filePath);
            $filePath = $pathInfo['cleanPath'];
            $fileName = $pathInfo['fileName'];

            $this->logger->info(
                message: "[FilePublishingHandler] unpublishFile: After cleaning: '$filePath'",
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            if ($fileName !== $filePath) {
                $this->logger->info(
                    message: "[FilePublishingHandler] unpublishFile: Extracted filename from path: '$fileName' (from '$filePath')",
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
            }

            // Get the object folder (this is where the files actually are).
            $objectFolder = $this->fileService->getObjectFolder($object);

            if ($objectFolder === null) {
                $this->logger->error(
                    message: '[FilePublishingHandler] unpublishFile: Could not get object folder for object: '.$object->getId(),
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                throw new Exception('Object folder not found.');
            }

            $this->logger->info(
                message: "[FilePublishingHandler] unpublishFile: Object folder path: ".$objectFolder->getPath(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );

            // Debug: List all files in the object folder.
            try {
                $objectFiles     = $objectFolder->getDirectoryListing();
                $objectFileNames = array_map(
                    function ($file) {
                        return $file->getName();
                    },
                    $objectFiles
                );
                $this->logger->info(
                    message: "[FilePublishingHandler] unpublishFile: Files in object folder: ".json_encode($objectFileNames),
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
            } catch (Exception $e) {
                $this->logger->error(
                    message: '[FilePublishingHandler] unpublishFile: Error listing object folder contents: '.$e->getMessage(),
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
            }

            try {
                $this->logger->info(
                    message: "[FilePublishingHandler] unpublishFile: Attempting to get file '$fileName' from object folder",
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                $file          = $objectFolder->get($fileName);
                $foundFileMsg  = "[FilePublishingHandler] unpublishFile: Successfully found file: ".$file->getName();
                $foundFileMsg .= " at ".$file->getPath();
                $this->logger->info(message: $foundFileMsg, context: ['file' => __FILE__, 'line' => __LINE__]);
            } catch (NotFoundException $e) {
                // Try with full path if filename didn't work.
                try {
                    $attemptMsg = "[FilePublishingHandler] unpublishFile: Attempting to get file '$filePath' (full path) from object folder";
                    $this->logger->info(message: $attemptMsg, context: ['file' => __FILE__, 'line' => __LINE__]);
                    $file        = $objectFolder->get($filePath);
                    $successMsg  = "[FilePublishingHandler] unpublishFile: Successfully found file using full path: ";
                    $successMsg .= $file->getName()." at ".$file->getPath();
                    $this->logger->info(message: $successMsg, context: ['file' => __FILE__, 'line' => __LINE__]);
                } catch (NotFoundException $e2) {
                    $errDetail = $e2->getMessage();
                    $prefix    = '[FilePublishingHandler] unpublishFile:';
                    $errMsg    = "$prefix File '$fileName' and '$filePath' not found in object folder. NotFoundException: $errDetail";
                    $this->logger->error(message: $errMsg, context: ['file' => __FILE__, 'line' => __LINE__]);
                    throw new Exception('File not found.');
                }
            } catch (Exception $e) {
                $errMsg  = "[FilePublishingHandler] unpublishFile: Unexpected error getting file from object folder: ";
                $errMsg .= $e->getMessage();
                $this->logger->error(message: $errMsg, context: ['file' => __FILE__, 'line' => __LINE__]);
                throw new Exception('File not found.');
            }//end try
        }//end if

        // Verify file exists and is a File instance.
        if ($file instanceof File === false) {
            $this->logger->error(
                message: "[FilePublishingHandler] unpublishFile: Found node is not a File instance, it's a: ".get_class($file),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            throw new Exception('File not found.');
        }

        // @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
        $this->fileService->checkOwnership($file);

        $this->logger->info(
            message: "[FilePublishingHandler] unpublishFile: Removing share links for file: ".$file->getPath(),
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        // Use FileMapper to remove all public shares directly from the database.
        try {
            $deletionInfo = $this->fileMapper->depublishFile($file->getId());

            $deletedShares = $deletionInfo['deleted_shares'];
            $fileId        = $deletionInfo['file_id'];
            $message       = "[FilePublishingHandler] unpublishFile: Successfully removed public shares via FileMapper - ";
            $message      .= "Deleted shares: {$deletedShares}, File ID: {$fileId}";
            $this->logger->info(message: $message, context: ['file' => __FILE__, 'line' => __LINE__]);

            if ($deletionInfo['deleted_shares'] === 0) {
                $noSharesMsg  = "[FilePublishingHandler] unpublishFile: No public shares were found to delete for file: ";
                $noSharesMsg .= $file->getName();
                $this->logger->info(message: $noSharesMsg, context: ['file' => __FILE__, 'line' => __LINE__]);
            }
        } catch (Exception $e) {
            $errMsg = "[FilePublishingHandler] unpublishFile: Failed to remove shares via FileMapper: ".$e->getMessage();
            $this->logger->error(message: $errMsg, context: ['file' => __FILE__, 'line' => __LINE__]);
            throw new Exception('Failed to remove share links: '.$e->getMessage());
        }

        $this->logger->info(
            message: "[FilePublishingHandler] unpublishFile: Successfully unpublished file: ".$file->getName(),
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
        return $file;
    }//end unpublishFile()

    /**
     * Create a ZIP archive of all files for an object.
     *
     * This method collects all files associated with an object and creates a ZIP
     * archive containing them. The archive is stored in the system temporary directory.
     *
     * @param ObjectEntity|string $object  The object entity or ID.
     * @param string|null         $zipName Optional custom name for the ZIP file.
     *
     * @return (int|string)[]
     *
     * @throws Exception If ZIP creation fails.
     *
     * @phpstan-return array{path: string, filename: string, size: int, mimeType: string}
     *
     * @psalm-return array{path: string, filename: string, size: int, mimeType: 'application/zip'}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  ZIP creation requires handling multiple file and error scenarios
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple paths for file processing and error handling
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) ZIP archive creation with file processing requires extensive code
     */
    public function createObjectFilesZip(ObjectEntity | string $object, ?string $zipName=null): array
    {
        // If string ID provided, try to find the object entity.
        if (is_string($object) === true) {
            try {
                $object = $this->objectEntityMapper->find($object);
            } catch (Exception $e) {
                throw new Exception("Object not found: ".$e->getMessage());
            }
        }

        $this->logger->info(
            message: "[FilePublishingHandler] Creating ZIP archive for object: ".$object->getId(),
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        // Check if ZipArchive extension is available.
        if (class_exists('ZipArchive') === false) {
            throw new Exception('PHP ZipArchive extension is not available');
        }

        // Get all files for the object.
        $files = $this->fileService->getFiles($object);

        if (empty($files) === true) {
            throw new Exception('No files found for this object');
        }

        $this->logger->info(
            message: "[FilePublishingHandler] Found ".count($files)." files for object ".$object->getId(),
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        // Generate ZIP filename.
        if ($zipName === null) {
            $objectIdentifier = $object->getUuid() ?? (string) $object->getId();
            $zipName          = 'object_'.$objectIdentifier.'_files_'.date('Y-m-d_H-i-s').'.zip';
        } else if (pathinfo($zipName, PATHINFO_EXTENSION) !== 'zip') {
            $zipName .= '.zip';
        }

        // Create temporary file for the ZIP.
        $tempZipPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.$zipName;

        // Create new ZIP archive.
        $zip    = new ZipArchive();
        $result = $zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new Exception("Cannot create ZIP file: ".$this->getZipErrorMessage(errorCode: $result));
        }

        $addedFiles   = 0;
        $skippedFiles = 0;

        // Add each file to the ZIP archive.
        foreach ($files as $file) {
            try {
                if ($file instanceof \OCP\Files\File === false) {
                    $this->logger->warning(
                        message: "[FilePublishingHandler] Skipping non-file node: ".$file->getName(),
                        context: ['file' => __FILE__, 'line' => __LINE__]
                    );
                    $skippedFiles++;
                    continue;
                }

                // @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
                $this->fileService->checkOwnership($file);

                // Get file content.
                $fileContent = $file->getContent();
                $fileName    = $file->getName();

                // Add file to ZIP with its original name.
                $added = $zip->addFromString($fileName, $fileContent);

                if ($added === false) {
                    $this->logger->error(
                        message: "[FilePublishingHandler] Failed to add file to ZIP: ".$fileName,
                        context: ['file' => __FILE__, 'line' => __LINE__]
                    );
                    $skippedFiles++;
                    continue;
                }

                $addedFiles++;
                $this->logger->debug(
                    message: "[FilePublishingHandler] Added file to ZIP: ".$fileName,
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
            } catch (Exception $e) {
                $this->logger->error(
                    message: "[FilePublishingHandler] Error processing file ".$file->getName().": ".$e->getMessage(),
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                $skippedFiles++;
                continue;
            }//end try
        }//end foreach

        // Close the ZIP archive.
        $closeResult = $zip->close();
        if ($closeResult === false) {
            throw new Exception("Failed to finalize ZIP archive");
        }

        $this->logger->info(
            message: "[FilePublishingHandler] ZIP creation completed. Added: $addedFiles files, Skipped: $skippedFiles files",
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        // Check if ZIP file was created successfully.
        if (file_exists($tempZipPath) === false) {
            throw new Exception("ZIP file was not created successfully");
        }

        $fileSize = filesize($tempZipPath);
        if ($fileSize === false) {
            throw new Exception("Cannot determine ZIP file size");
        }

        return [
            'path'     => $tempZipPath,
            'filename' => $zipName,
            'size'     => $fileSize,
            'mimeType' => 'application/zip',
        ];
    }//end createObjectFilesZip()

    /**
     * Get a human-readable error message for ZipArchive error codes.
     *
     * @param int $errorCode The ZipArchive error code.
     *
     * @return string
     *
     * @psalm-return   string
     * @phpstan-return string
     */
    private function getZipErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            \ZipArchive::ER_OK => 'No error',
            \ZipArchive::ER_MULTIDISK => 'Multi-disk zip archives not supported',
            \ZipArchive::ER_RENAME => 'Renaming temporary file failed',
            \ZipArchive::ER_CLOSE => 'Closing zip archive failed',
            \ZipArchive::ER_SEEK => 'Seek error',
            \ZipArchive::ER_READ => 'Read error',
            \ZipArchive::ER_WRITE => 'Write error',
            \ZipArchive::ER_CRC => 'CRC error',
            \ZipArchive::ER_ZIPCLOSED => 'Containing zip archive was closed',
            \ZipArchive::ER_NOENT => 'No such file',
            \ZipArchive::ER_EXISTS => 'File already exists',
            \ZipArchive::ER_OPEN => 'Can\'t open file',
            \ZipArchive::ER_TMPOPEN => 'Failure to create temporary file',
            \ZipArchive::ER_ZLIB => 'Zlib error',
            \ZipArchive::ER_MEMORY => 'Memory allocation failure',
            \ZipArchive::ER_CHANGED => 'Entry has been changed',
            \ZipArchive::ER_COMPNOTSUPP => 'Compression method not supported',
            \ZipArchive::ER_EOF => 'Premature EOF',
            \ZipArchive::ER_INVAL => 'Invalid argument',
            \ZipArchive::ER_NOZIP => 'Not a zip archive',
            \ZipArchive::ER_INTERNAL => 'Internal error',
            \ZipArchive::ER_INCONS => 'Zip archive inconsistent',
            \ZipArchive::ER_REMOVE => 'Can\'t remove file',
            \ZipArchive::ER_DELETED => 'Entry has been deleted',
            default => "Unknown error code: $errorCode"
        };//end match
    }//end getZipErrorMessage()
}//end class
