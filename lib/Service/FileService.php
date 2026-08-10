<?php

/**
 * OpenRegister FileService.
 *
 * Service class for handling file operations in the OpenRegister application.
 * Provides functionality for managing files, folders, sharing, and versioning within
 * the NextCloud environment.
 *
 * This service provides methods for:
 * - CRUD operations on files and folders
 * - File versioning and version management
 * - File sharing and access control
 * - Tag management and attachment
 * - Object-specific file operations
 * - Audit trails and data aggregation
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/specs/content-versioning/spec.md
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-29
 */

declare(strict_types=1);

/*
 * @phpstan-type FileArray array{
 *     id: string,
 *     name: string,
 *     path: string,
 *     type: string,
 *     mtime: int,
 *     size: int,
 *     mimetype: string,
 *     preview: string,
 *     shareTypes: array<int>,
 *     shareOwner: string|null,
 *     tags: array<string>,
 *     shareLink: string|null
 * }
 */


namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use stdClass;
use RuntimeException;
use ZipArchive;
use OCP\AppFramework\Http\StreamResponse;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\File\CreateFileHandler;
use OCA\OpenRegister\Service\File\DeleteFileHandler;
use OCA\OpenRegister\Service\File\DocumentProcessingHandler;
use OCA\OpenRegister\Service\File\FileAuditHandler;
use OCA\OpenRegister\Service\File\FileBatchHandler;
use OCA\OpenRegister\Service\File\FileFormattingHandler;
use OCA\OpenRegister\Service\File\FileLockHandler;
use OCA\OpenRegister\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Service\File\FilePreviewHandler;
use OCA\OpenRegister\Service\File\FilePublishingHandler;
use OCA\OpenRegister\Service\File\FileSharingHandler;
use OCA\OpenRegister\Service\File\FileValidationHandler;
use OCA\OpenRegister\Service\File\FileVersioningHandler;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\File\Pdf\StructurePreservation;
use OCA\OpenRegister\Service\File\ReadFileHandler;
use OCA\OpenRegister\Service\File\TaggingHandler;
use OCA\OpenRegister\Service\File\UpdateFileHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use OCP\SystemTag\TagNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Service for handling file operations in OpenRegister.
 *
 * This service provides functionalities for managing files and folders within the NextCloud environment,
 * including creation, deletion, sharing, and file updates. It integrates with NextCloud's file and
 * sharing APIs to provide seamless file management for the application.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyFields)            File service orchestrates many handler classes
 * @SuppressWarnings(PHPMD.LongVariable)             Handler properties use descriptive names for clarity
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class FileService
{

    /**
     * Configuration service
     *
     * @var IConfig
     */
    private IConfig $config;

    /**
     * File mapper
     *
     * @var FileMapper
     */
    private FileMapper $fileMapper;

    /**
     * Group manager
     *
     * @var IGroupManager
     */
    private IGroupManager $groupManager;

    /**
     * Logger
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * REMOVED: Register mapper (unused, caused circular dependency)
     *
     * @var RegisterMapper|null
     */
    // Private ?RegisterMapper $registerMapper;.

    /**
     * Root folder
     *
     * @var IRootFolder
     */
    private IRootFolder $rootFolder;

    /**
     * Share manager
     *
     * @var IManager
     */
    private IManager $shareManager;

    /**
     * System tag manager
     *
     * @var ISystemTagManager
     */
    private ISystemTagManager $systemTagManager;

    /**
     * System tag mapper
     *
     * @var ISystemTagObjectMapper
     */
    private ISystemTagObjectMapper $systemTagMapper;

    /**
     * URL generator
     *
     * @var IURLGenerator
     */
    private IURLGenerator $urlGenerator;

    /**
     * User manager
     *
     * @var IUserManager
     */
    private IUserManager $userManager;

    /**
     * User session
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * File validation handler
     *
     * @var FileValidationHandler
     */
    private FileValidationHandler $fileValidationHandler;

    /**
     * Folder management handler
     *
     * @var FolderManagementHandler
     */
    private FolderManagementHandler $folderManagementHandler;

    /**
     * File ownership handler
     *
     * @var FileOwnershipHandler
     */
    private FileOwnershipHandler $fileOwnershipHandler;

    /**
     * File sharing handler
     *
     * @var FileSharingHandler
     */
    private FileSharingHandler $fileSharingHandler;

    /**
     * Create file handler (Single Responsibility: File creation)
     *
     * @var CreateFileHandler
     */
    private CreateFileHandler $createFileHandler;

    /**
     * Read file handler (Single Responsibility: File retrieval)
     *
     * @var ReadFileHandler
     */
    private ReadFileHandler $readFileHandler;

    /**
     * Update file handler (Single Responsibility: File modification)
     *
     * @var UpdateFileHandler
     */
    private UpdateFileHandler $updateFileHandler;

    /**
     * Delete file handler (Single Responsibility: File deletion)
     *
     * @var DeleteFileHandler
     */
    private DeleteFileHandler $deleteFileHandler;

    /**
     * Tagging handler (Single Responsibility: Tag management)
     *
     * @var TaggingHandler
     */
    private TaggingHandler $taggingHandler;

    /**
     * File formatting handler (Single Responsibility: File formatting and filtering)
     *
     * @var FileFormattingHandler
     */
    private FileFormattingHandler $fileFormattingHandler;

    /**
     * Document processing handler (Single Responsibility: Document manipulation and anonymization)
     *
     * @var DocumentProcessingHandler
     */
    private DocumentProcessingHandler $documentProcessingHandler;

    /**
     * File publishing handler (Single Responsibility: File publishing and ZIP archiving)
     *
     * @var FilePublishingHandler
     */
    private FilePublishingHandler $filePublishingHandler;

    /**
     * File versioning handler (Single Responsibility: Version listing and restore)
     *
     * @var FileVersioningHandler
     */
    private FileVersioningHandler $fileVersioningHandler;

    /**
     * File lock handler (Single Responsibility: File locking and unlocking)
     *
     * @var FileLockHandler
     */
    private FileLockHandler $fileLockHandler;

    /**
     * File batch handler (Single Responsibility: Batch file operations)
     *
     * @var FileBatchHandler
     */
    private FileBatchHandler $fileBatchHandler;

    /**
     * File preview handler (Single Responsibility: Preview and thumbnail generation)
     *
     * @var FilePreviewHandler
     */
    private FilePreviewHandler $filePreviewHandler;

    /**
     * File audit handler (Single Responsibility: Download audit logging)
     *
     * @var FileAuditHandler
     */
    private FileAuditHandler $fileAuditHandler;

    /**
     * Per-request memoization for the defense-in-depth folder-access
     * re-validation done by `assertObjectFolderAccessible`.
     *
     * Keyed `"{uid}:{folderId}"` (or `"__no_user__:{folderId}"` when no
     * acting user resolves). Only successful re-validations are cached;
     * denials re-throw and re-check on retry. The cache lives for the
     * lifetime of the FileService instance — i.e. one HTTP request — so
     * bulk imports / cascade saves stop re-running
     * `getUserFolder() + getById()` filesystem I/O on every iteration.
     * PR #1431 concern.
     *
     * Access changes are not propagated mid-request anyway, so a cache
     * hit here returns the same verdict the live check would.
     *
     * @var array<string, true>
     */
    private array $folderAccessRevalidationCache = [];

    /**
     * Root folder name for all OpenRegister files.
     *
     * @var            string
     * @readonly
     * @psalm-readonly
     */
    private const ROOT_FOLDER = 'Open Registers';

    /**
     * Application group name.
     *
     * @var            string
     * @readonly
     * @psalm-readonly
     */
    private const APP_GROUP = 'openregister';

    /**
     * Application user name.
     *
     * @var            string
     * @readonly
     * @psalm-readonly
     */
    private const APP_USER = 'OpenRegister';

    /**
     * File tag type identifier.
     *
     * @var            string
     * @readonly
     * @psalm-readonly
     */
    private const FILE_TAG_TYPE = 'files';

    /**
     * Constructor
     *
     * @param IConfig                   $config               Configuration service
     * @param FileMapper                $fileMapper           File mapper
     * @param IGroupManager             $groupManager         Group manager
     * @param LoggerInterface           $logger               Logger
     * @param IRootFolder               $rootFolder           Root folder
     * @param IManager                  $shareManager         Share manager
     * @param ISystemTagManager         $systemTagManager     System tag manager
     * @param ISystemTagObjectMapper    $systemTagMapper      System tag mapper
     * @param IURLGenerator             $urlGenerator         URL generator
     * @param IUserManager              $userManager          User manager
     * @param IUserSession              $userSession          User session
     * @param FileValidationHandler     $fileValidHandler     File validation handler
     * @param FolderManagementHandler   $folderMgmtHandler    Folder management handler
     * @param FileOwnershipHandler      $fileOwnershipHandler File ownership handler
     * @param FileSharingHandler        $fileSharingHandler   File sharing handler
     * @param CreateFileHandler         $createFileHandler    Create file handler
     * @param ReadFileHandler           $readFileHandler      Read file handler
     * @param UpdateFileHandler         $updateFileHandler    Update file handler
     * @param DeleteFileHandler         $deleteFileHandler    Delete file handler
     * @param TaggingHandler            $taggingHandler       Tagging handler
     * @param FileFormattingHandler     $fileFormatHandler    File formatting handler
     * @param DocumentProcessingHandler $docProcHandler       Document processing handler
     * @param FilePublishingHandler     $filePubHandler       File publishing handler
     * @param FileVersioningHandler     $fileVerHandler       File versioning handler
     * @param FileLockHandler           $fileLockHandler      File lock handler
     * @param FileBatchHandler          $fileBatchHandler     File batch handler
     * @param FilePreviewHandler        $filePreviewHandler   File preview handler
     * @param FileAuditHandler          $fileAuditHandler     File audit handler
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI requires constructor injection
     */
    public function __construct(
        IConfig $config,
        FileMapper $fileMapper,
        IGroupManager $groupManager,
        LoggerInterface $logger,
        IRootFolder $rootFolder,
        IManager $shareManager,
        ISystemTagManager $systemTagManager,
        ISystemTagObjectMapper $systemTagMapper,
        IURLGenerator $urlGenerator,
        IUserManager $userManager,
        IUserSession $userSession,
        FileValidationHandler $fileValidHandler,
        FolderManagementHandler $folderMgmtHandler,
        FileOwnershipHandler $fileOwnershipHandler,
        FileSharingHandler $fileSharingHandler,
        CreateFileHandler $createFileHandler,
        ReadFileHandler $readFileHandler,
        UpdateFileHandler $updateFileHandler,
        DeleteFileHandler $deleteFileHandler,
        TaggingHandler $taggingHandler,
        FileFormattingHandler $fileFormatHandler,
        DocumentProcessingHandler $docProcHandler,
        FilePublishingHandler $filePubHandler,
        FileVersioningHandler $fileVerHandler,
        FileLockHandler $fileLockHandler,
        FileBatchHandler $fileBatchHandler,
        FilePreviewHandler $filePreviewHandler,
        FileAuditHandler $fileAuditHandler
    ) {
        $this->logger = $logger;
        $this->logger->debug(
            message: '[FileService] FileService constructor started.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
        $this->config       = $config;
        $this->fileMapper   = $fileMapper;
        $this->groupManager = $groupManager;
        // REMOVED: registerMapper assignment (unused, caused circular dependency).
        $this->rootFolder            = $rootFolder;
        $this->shareManager          = $shareManager;
        $this->systemTagManager      = $systemTagManager;
        $this->systemTagMapper       = $systemTagMapper;
        $this->urlGenerator          = $urlGenerator;
        $this->userManager           = $userManager;
        $this->userSession           = $userSession;
        $this->fileValidationHandler = $fileValidHandler;
        $this->folderManagementHandler   = $folderMgmtHandler;
        $this->fileOwnershipHandler      = $fileOwnershipHandler;
        $this->fileSharingHandler        = $fileSharingHandler;
        $this->createFileHandler         = $createFileHandler;
        $this->readFileHandler           = $readFileHandler;
        $this->updateFileHandler         = $updateFileHandler;
        $this->deleteFileHandler         = $deleteFileHandler;
        $this->taggingHandler            = $taggingHandler;
        $this->fileFormattingHandler     = $fileFormatHandler;
        $this->documentProcessingHandler = $docProcHandler;
        $this->filePublishingHandler     = $filePubHandler;
        $this->fileVersioningHandler     = $fileVerHandler;
        $this->fileLockHandler           = $fileLockHandler;
        $this->fileBatchHandler          = $fileBatchHandler;
        $this->filePreviewHandler        = $filePreviewHandler;
        $this->fileAuditHandler          = $fileAuditHandler;

        // Break circular dependency: FolderManagementHandler needs FileService for cross-handler coordination.
        $this->logger->debug(
            message: '[FileService] About to call folderManagementHandler->setFileService.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
        $this->folderManagementHandler->setFileService($this);
        $this->logger->debug(
            message: '[FileService] Called folderManagementHandler->setFileService.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        // Break circular dependency: UpdateFileHandler needs FileService for utility methods (tags, path extraction).
        $this->logger->debug(
            message: '[FileService] About to call updateFileHandler->setFileService.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
        $this->updateFileHandler->setFileService($this);
        $this->logger->debug(
            message: '[FileService] Called updateFileHandler->setFileService.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        // Break circular dependency: CreateFileHandler needs FileService for sharing and tagging.
        $this->logger->debug(
            message: '[FileService] About to call createFileHandler->setFileService.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
        $this->createFileHandler->setFileService($this);
        $this->logger->debug(
            message: '[FileService] Called createFileHandler->setFileService.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        // Break circular dependency: ReadFileHandler needs FileService for utility methods.
        $this->logger->debug(
            message: '[FileService] About to call readFileHandler->setFileService.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
        $this->readFileHandler->setFileService($this);
        $this->logger->debug(
            message: '[FileService] Called readFileHandler->setFileService.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        // Break circular dependency: FileFormattingHandler needs FileService for utility methods (shares, tags, etc.).
        $this->logger->debug(
            message: '[FileService] About to call fileFormattingHandler->setFileService.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
        $this->fileFormattingHandler->setFileService($this);
        $this->logger->debug(
            message: '[FileService] Called fileFormattingHandler->setFileService.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        // Break circular dependency: DocumentProcessingHandler needs FileService for cross-handler coordination.
        $this->logger->debug(
            message: '[FileService] About to call documentProcessingHandler->setFileService.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
        $this->documentProcessingHandler->setFileService($this);
        $this->logger->debug(
            message: '[FileService] Called documentProcessingHandler->setFileService.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        // Break circular dependency: FilePublishingHandler needs FileService for file operations and utilities.
        $this->logger->debug(
            message: '[FileService] About to call filePublishingHandler->setFileService.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
        $this->filePublishingHandler->setFileService($this);
        $this->logger->debug(
            message: '[FileService] Called filePublishingHandler->setFileService.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        // Break circular dependency: FileBatchHandler needs FileService for action delegation.
        $this->fileBatchHandler->setFileService($this);
        $this->logger->debug(
            message: '[FileService] Called fileBatchHandler->setFileService.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        $this->logger->debug(
            message: '[FileService] FileService constructor completed.',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
    }//end __construct()

    /**
     * Clean and extract filename from a file path that may contain folder ID prefixes.
     *
     * This utility method handles the common pattern of cleaning file paths and extracting
     * just the filename from paths that might be in formats like:
     * - "filename.ext" -> "filename.ext"
     * - "8010/filename.ext" -> "filename.ext"
     * - "/path/to/filename.ext" -> "filename.ext"
     *
     * @param string $filePath The file path to process
     *
     * @return array{cleanPath: string, fileName: string} Array containing the cleaned path and extracted filename
     *
     * @psalm-return   array{cleanPath: string, fileName: string}
     * @phpstan-return array{cleanPath: string, fileName: string}
     *
     * @spec exclude Pure string/path-splitting utility; no business logic.
     */
    public function extractFileNameFromPath(string $filePath): array
    {
        // Clean and decode the file path.
        $cleanPath = trim(string: $filePath, characters: '/');
        $cleanPath = urldecode($cleanPath);

        // Extract just the filename if the path contains a folder ID prefix (like "8010/filename.ext").
        $fileName = $cleanPath;
        if (str_contains($cleanPath, '/') === true) {
            $pathParts = explode('/', $cleanPath);
            $fileName  = end($pathParts);
        }

        return [
            'cleanPath' => $cleanPath,
            'fileName'  => $fileName,
        ];
    }//end extractFileNameFromPath()

    /**
     * Creates a folder for a Schema to store files of Objects.
     *
     * This method creates a folder structure for a Schema within its parent Register's
     * folder. It ensures both the Register and Schema folders exist and are properly
     * linked in the database.
     *
     * @param Register|int $register The Register entity or its ID
     * @param Schema|int   $schema   The Schema entity or its ID
     *
     * @return string The path to the created Schema folder
     *
     * @throws Exception If folder creation fails or entities not found
     * @throws NotPermittedException If folder creation is not permitted
     * @throws NotFoundException If parent folders do not exist
     *
     * @phpstan-return string
     */


    /**
     * Creates a folder for an Object Entity.
     *
     * This method creates a folder structure for an Object Entity within its parent
     * Schema and Register folders. It ensures the complete folder hierarchy exists.
     * After creation, it sets the folder path on the ObjectEntity and persists it.
     *
     * @param ObjectEntity|string $objectEntity The Object Entity to create a folder for
     * @param Register|int|null  $register     Optional Register entity or ID
     * @param Schema|int|null    $schema       Optional Schema entity or ID
     * @param string|null        $folderPath   Optional custom folder path
     *
     * @return Node|null The created folder Node or null if creation fails
     *
     * @throws Exception If folder creation fails or entities not found
     * @throws NotPermittedException If folder creation is not permitted
     * @throws NotFoundException If parent folders do not exist
     *
     * @phpstan-return Node|null
     */


    /**
     * Get the folder for an Object Entity.
     *
     * This method retrieves the folder Node for an Object Entity, creating it
     * if it doesn't exist.
     *
     * @param ObjectEntity      $objectEntity The Object Entity to get the folder for
     * @param Register|int|null $register    Optional Register entity or ID
     * @param Schema|int|null   $schema      Optional Schema entity or ID
     *
     * @return Node|null The folder Node or null if not found/created
     *
     * @throws Exception If folder retrieval fails or entities not found
     * @throws NotPermittedException If folder access is not permitted
     * @throws NotFoundException If folders do not exist
     *
     * @phpstan-return Node|null
     */

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
    public function createEntityFolder(Register | ObjectEntity $entity): ?Node
    {
        // Get the current user for sharing.
        $currentUser = $this->getCurrentUser();

        try {
            if ($entity instanceof Register) {
                return $this->createRegisterFolderById(register: $entity, currentUser: $currentUser);
            }

            return $this->createObjectFolderById(objectEntity: $entity, currentUser: $currentUser);
        } catch (\OCA\OpenRegister\Exception\FolderAccessDeniedException $e) {
            // Access denials must propagate to the controller for HTTP 403 with structured body.
            throw $e;
        } catch (exception $e) {
            $this->logger->error(
                message: '[FileService] Failed to create folder for entity: {message}',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'message'   => $e->getMessage(),
                    'exception' => $e,
                ]
            );
            return null;
        }//end try
    }//end createEntityFolder()

    /**
     * Creates a folder for a Register and stores the folder ID.
     *
     * @param Register   $register    The register to create the folder for
     * @param IUser|null $currentUser The current user to share the folder with
     *
     * @throws Exception If folder creation fails
     * @throws NotPermittedException If folder creation is not permitted
     *
     * @return Node The created folder node
     */
    private function createRegisterFolderById(Register $register, ?IUser $currentUser=null): Node
    {
        return $this->folderManagementHandler->createRegisterFolderById(
            register: $register,
            currentUser: $currentUser
        );
    }//end createRegisterFolderById()

    /**
     * Creates a folder for an ObjectEntity nested under the register folder.
     *
     * @param ObjectEntity|string $objectEntity The object entity to create the folder for
     * @param IUser|null          $currentUser  The current user to share the folder with
     * @param int|string|null     $registerId   The register of the object to add the file to
     *
     * @throws Exception If folder creation fails
     * @throws NotPermittedException If folder creation is not permitted
     *
     * @return Node The created folder
     */
    private function createObjectFolderById(
        ObjectEntity|string $objectEntity,
        ?IUser $currentUser=null,
        int|string|null $registerId=null
    ): Node {
        return $this->folderManagementHandler->createObjectFolderById(
            objectEntity: $objectEntity,
            currentUser: $currentUser,
            registerId: $registerId
        );
    }//end createObjectFolderById()

    /**
     * Get the OpenRegister user root folder.
     *
     * This method provides a consistent way to access the OpenRegister user's
     * root folder across the entire FileService.
     *
     * @return Folder The OpenRegister user's root folder
     *
     * @throws Exception If the user folder cannot be accessed
     *
     * @psalm-return   Folder
     * @phpstan-return Folder
     */
    private function getOpenRegisterUserFolder(): Folder
    {
        return $this->folderManagementHandler->getOpenRegisterUserFolder();
    }//end getOpenRegisterUserFolder()

    /**
     * Get files for either a Register or ObjectEntity.
     *
     * This unified method handles file retrieval for both entity types,
     * using the stored folder IDs for stable access.
     *
     * @param Register|ObjectEntity $entity          The entity to get files for
     * @param bool|null             $sharedFilesOnly Whether to return only shared files
     *
     * @return Node[]
     *
     * @throws Exception If the entity folder cannot be accessed
     *
     * @psalm-return   list<\OCP\Files\Node>
     * @phpstan-return array<int, Node>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  Boolean flag is intentional for simple filter toggle
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) File retrieval requires entity type checking
     *
     * @spec openspec/specs/file-actions/spec.md#file-retrieval-resolves-by-id-or-name-and-projects-nodes-to-metadata
     *   (unified file listing for a Register or ObjectEntity via the stored folder, with optional shared-only filter)
     */
    public function getFilesForEntity(Register|ObjectEntity $entity, ?bool $sharedFilesOnly=false): array
    {
        if ($entity instanceof Register) {
            $folder = $this->getRegisterFolderById(register: $entity);

            if ($folder === null) {
                throw new Exception("Cannot access folder for entity ".$entity->getId());
            }
        } else {
            // Read/list path for an object. Objects that have never had a files
            // folder created (null/legacy/empty folder property), whose bound
            // folder node no longer resolves, or whose folder is not accessible
            // to the current caller simply have no files to list. Resolving the
            // folder must not fail the whole request: degrade to an empty list
            // (HTTP 200) instead of surfacing a 500. See ObjectFilesController::index.
            try {
                $folder = $this->getObjectFolder(objectEntity: $entity);
            } catch (\Throwable $e) {
                $this->logger->info(
                    message: '[FileService] No accessible files folder for object '.$entity->getId().'; returning empty list: '.$e->getMessage(),
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
                return [];
            }

            if ($folder === null) {
                return [];
            }
        }//end if

        $files = $folder->getDirectoryListing();

        if ($sharedFilesOnly === true) {
            $files = array_filter(
                $files,
                function ($file) {
                    $shares = $this->findShares(file: $file);
                    return empty($shares) === false;
                }
            );
        }

        return array_values($files);
    }//end getFilesForEntity()

    /**
     * Get a register folder by its stored ID.
     *
     * @param Register $register The register to get the folder for
     *
     * @return Folder|null The folder Node or null if not found
     *
     * @psalm-return   Folder|null
     * @phpstan-return Folder|null
     */
    private function getRegisterFolderById(Register $register): ?Folder
    {
        return $this->folderManagementHandler->getRegisterFolderById(register: $register);
    }//end getRegisterFolderById()

    /**
     * Get an object folder by its stored ID.
     *
     * @param ObjectEntity|string $objectEntity The object entity to get the folder for
     * @param int|string|null     $registerId   The register of the object to add the file to
     *
     * @return Folder|null The folder Node or null if not found
     *
     * @psalm-return   Folder|null
     * @phpstan-return Folder|null
     *
     * @spec openspec/specs/file-actions/spec.md
     */
    public function getObjectFolder(ObjectEntity|string $objectEntity, int|string|null $registerId=null): ?Folder
    {
        return $this->folderManagementHandler->getObjectFolder(
            objectEntity: $objectEntity,
            registerId: $registerId
        );
    }//end getObjectFolder()

    /**
     * Re-validate the access check on an existing object's bound folder.
     *
     * Used by `ObjectService::ensureObjectFolder` to close the
     * defense-in-depth gap flagged on PR #1431: pre-PR cross-tenant
     * bindings (rows where `_folder` references a node the current
     * actor cannot read) would otherwise pass through subsequent
     * saves that don't touch `@self.folder` because `setSelfMetadata`
     * only fires when `@self.folder` is present in the write payload.
     *
     * Re-running `assertFolderIsAccessible` on every save through
     * `ensureObjectFolder` makes the check apply uniformly. A
     * denial throws `FolderAccessDeniedException` (HTTP 403) at the
     * controller layer.
     *
     * No-op when the object has no folder bound or the bound value
     * is non-numeric (legacy path-style folder, handled by the
     * auto-create branch in ensureObjectFolder).
     *
     * **Acting user resolution.** `$currentUser` is forwarded verbatim
     * to `assertFolderIsAccessible` and follows that method's
     * documented precedence: when non-null it is used as-is, otherwise
     * `IUserSession::getUser()` is consulted, otherwise the bind is
     * denied. Non-HTTP callers (cron, import pipelines, event listeners)
     * MUST pass an explicit `$currentUser` to avoid the
     * session-user-is-null → default-deny path; HTTP callers can omit
     * the argument and rely on session resolution.
     *
     * @param ObjectEntity $object      The existing object whose folder must be re-validated.
     * @param IUser|null   $currentUser Explicit acting user; falls back to session resolution.
     *
     * @return void
     *
     * @throws \OCA\OpenRegister\Exception\FolderAccessDeniedException When the acting user cannot access the bound folder.
     */
    public function assertObjectFolderAccessible(ObjectEntity $object, ?IUser $currentUser=null): void
    {
        $folder = $object->getFolder();
        if ($folder === null || $folder === '' || is_numeric($folder) === false) {
            return;
        }

        // Per-request memoization. Resolve the acting user the same way
        // `FolderManagementHandler::assertFolderIsAccessible` will (explicit
        // arg → session → null) so the cache key matches the access-grant
        // tuple the inner method actually evaluates.
        $actingUser = ($currentUser ?? $this->userSession->getUser());
        $cacheKey   = (($actingUser?->getUID() ?? '__no_user__').':'.$folder);
        if (isset($this->folderAccessRevalidationCache[$cacheKey]) === true) {
            return;
        }

        $this->folderManagementHandler->assertFolderIsAccessible(
            folderId: (string) $folder,
            currentUser: $currentUser,
            objectEntity: $object
        );

        // Only cache successes — failures re-throw and re-check on retry.
        $this->folderAccessRevalidationCache[$cacheKey] = true;
    }//end assertObjectFolderAccessible()

    /**
     * Reset the per-request folder-access revalidation cache.
     *
     * `isReadable()` is a snapshot of mount/share/ACL/trash state. Within a
     * single request that state CAN change — a cascade save may move or trash
     * the parent folder. Because the cache lives for the FileService instance
     * (≈ the whole request), a stale "accessible" verdict could otherwise
     * survive such a mutation. Callers bound the cache to a single save API
     * call by invoking this at the entry of `saveObject` / `saveObjects`, so
     * each top-level write re-validates against current state.
     *
     * @return void
     */
    public function resetFolderAccessRevalidationCache(): void
    {
        $this->folderAccessRevalidationCache = [];
    }//end resetFolderAccessRevalidationCache()

    /**
     * Returns a share link for the given IShare object.
     *
     * @param IShare $share An IShare object we are getting the share link for
     *
     * @return string The share link needed to get the file or folder for the given IShare object
     */
    public function getShareLink(IShare $share): string
    {
        return $this->getCurrentDomain().'/index.php/s/'.$share->getToken();
    }//end getShareLink()

    /**
     * Gets and returns the current host/domain with correct protocol.
     *
     * @return string The current http/https domain URL
     */
    private function getCurrentDomain(): string
    {
        $baseUrl        = $this->urlGenerator->getBaseUrl();
        $trustedDomains = $this->config->getSystemValue('trusted_domains');

        if (($trustedDomains[1] ?? null) !== null) {
            $baseUrl = str_replace(search: 'localhost', replace: $trustedDomains[1], subject: $baseUrl);
        }

        return $baseUrl;
    }//end getCurrentDomain()

    /**
     * Gets or creates the OpenRegister user for file operations.
     *
     * Delegates to FileOwnershipHandler.
     *
     * @throws Exception If OpenRegister user cannot be created.
     *
     * @return IUser The OpenRegister user.
     *
     * @psalm-return   IUser
     * @phpstan-return IUser
     */
    public function getUser(): IUser
    {
        return $this->fileOwnershipHandler->getUser();
    }//end getUser()

    /**
     * Check file ownership and fix it if needed to prevent "File not found" errors.
     *
     * @param Node $file The file node to check ownership for
     *
     * @return void
     *
     * @throws Exception If ownership check/fix fails
     *
     * @TODO: This is a hack to fix NextCloud file ownership issues on production
     * @TODO: where files exist but can't be accessed due to permission problems.
     * @TODO: This should be removed once the underlying NextCloud rights issue is resolved.
     *
     * @psalm-return   void
     * @phpstan-return void
     *
     * @spec exclude Ownership-guard delegation; throws when the current user does not own the node, no standalone business logic.
     */
    public function checkOwnership(Node $file): void
    {
        $this->fileValidationHandler->checkOwnership(file: $file);
    }//end checkOwnership()

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
    public function formatFile(Node $file): array
    {
        return $this->fileFormattingHandler->formatFile($file);
    }//end formatFile()

    /**
     * Formats an array of Node files into an array of metadata arrays (DELEGATED to FileFormattingHandler).
     *
     * @param Node[] $files         Array of Node files to format.
     * @param array  $requestParams Optional request parameters including filters.
     *
     * @throws InvalidPathException If file paths are invalid.
     * @throws NotFoundException If files are not found.
     *
     * @return array Formatted file data with pagination
     *
     * @spec exclude File JSON formatting + pagination; deferred to the file-actions FileFormattingHandler
     *   follow-up pass (see file-actions tasks.md DROP list).
     */
    public function formatFiles(array $files, ?array $requestParams=[]): array
    {
        return $this->fileFormattingHandler->formatFiles(
            files: $files,
            requestParams: $requestParams
        );
    }//end formatFiles()

    /**
     * Get the tags associated with a file.
     *
     * Delegates to TaggingHandler for single-responsibility tag retrieval.
     *
     * @param string $fileId The ID of the file.
     *
     * @return string[] The list of tags associated with the file.
     *
     * @phpstan-return array<int, string>
     * @psalm-return   list<string>
     */
    public function getFileTags(string $fileId): array
    {
        return $this->taggingHandler->getFileTags($fileId);
    }//end getFileTags()

    /**
     * Finds shares associated with a file or folder.
     *
     * @param Node $file      The Node file or folder to find shares for
     * @param int  $shareType The type of share to look for (default: 3 for public link)
     *
     * @return IShare[] Array of shares associated with the file
     */

    /**
     * Find shares for a given file or folder.
     *
     * Delegates to FileSharingHandler for single-responsibility sharing operations.
     *
     * @param Node $file      The file or folder to find shares for.
     * @param int  $shareType The share type to filter by (default: public link = 3).
     *
     * @return IShare[] Array of shares.
     *
     * @psalm-return   array<IShare>
     * @phpstan-return array<int, IShare>
     *
     * @spec exclude File sharing; deferred to the file-actions FileSharingHandler follow-up pass (see file-actions tasks.md DROP list).
     */
    public function findShares(Node $file, int $shareType=3): array
    {
        // Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
        $this->checkOwnership(file: $file);

        return $this->fileSharingHandler->findShares(
            file: $file,
            shareType: $shareType
        );
    }//end findShares()

    /**
     * Creates a IShare object using the $shareData array data.
     *
     * @param array{
     *     path: string,
     *     file?: File,
     *     nodeId?: int,
     *     nodeType?: string,
     *     shareType: int,
     *     permissions?: int,
     *     sharedWith?: string
     * } $shareData The data to create a IShare with
     *
     * @throws Exception If creating the share fails
     *
     * @return IShare The Created IShare object
     *
     * @psalm-suppress UnusedReturnValue
     */

    /**
     * Create a share with the given share data.
     *
     * Delegates to FileSharingHandler for single-responsibility sharing operations.
     *
     * @param array $shareData The data to create a share with.
     *
     * @return IShare The created share object.
     *
     * @throws Exception If creating the share fails.
     */
    private function createShare(array $shareData): IShare
    {
        return $this->fileSharingHandler->createShare($shareData);
    }//end createShare()

    /**
     * Share a folder with a specific user.
     *
     * This method creates a user share for the given folder, allowing the specified
     * user to access the folder with the given permissions.
     *
     * @param Node   $folder      The folder node to share
     * @param string $userId      The user ID to share with
     * @param int    $permissions The permissions to grant (default: 31 = all permissions)
     *
     * @return IShare|null The created share or null if creation failed
     *
     * @throws Exception If share creation fails
     *
     * @psalm-return   IShare|null
     * @phpstan-return IShare|null
     * @psalm-suppress UnusedReturnValue - Return value may be used by callers
     */

    /**
     * Get the currently active user (not the OpenRegister system user).
     *
     * Delegates to FileOwnershipHandler.
     *
     * @return IUser|null The currently active user or null if no user is logged in.
     *
     * @psalm-return   IUser|null
     * @phpstan-return IUser|null
     */
    private function getCurrentUser(): ?IUser
    {
        return $this->fileOwnershipHandler->getCurrentUser();
    }//end getCurrentUser()

    /**
     * Creates and returns a share link for a file (or folder).
     *
     * See https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/ocs-share-api.html#create-a-new-share.
     *
     * @param string   $path        Path (from root) to the file/folder which should be shared
     * @param int|null $shareType   The share type (0=user, 1=group, 3=public link, 4=email, etc.)
     * @param int|null $permissions Permissions (1=read, 2=update, 4=create, 8=delete, 16=share, 31=all)
     *
     * @throws Exception If creating the share link fails
     *
     * @return string The share link
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Share link creation requires handling multiple scenarios
     * @SuppressWarnings(PHPMD.NPathComplexity)      Share link creation has multiple error paths
     *
     * @spec exclude Public share-link creation; deferred to the file-actions FileSharingHandler follow-up pass (see file-actions tasks.md DROP list).
     */
    public function createShareLink(string $path, ?int $shareType=3, ?int $permissions=null): string
    {
        $path = trim(string: $path, characters: '/');
        if ($permissions === null) {
            $permissions = 31;
            if ($shareType === 3) {
                $permissions = 1;
            }
        }

        try {
            // Note: userId and userFolder not currently used - file retrieved from rootFolder.
            $this->getOpenRegisterUserFolder();
        } catch (Exception) {
            $msg = "[FileService] Can't create share link for $path because OpenRegister user folder couldn't be found.";
            $this->logger->error(message: $msg, context: ['file' => __FILE__, 'line' => __LINE__]);
            return "OpenRegister user folder couldn't be found.";
        }

        try {
            $file = $this->rootFolder->get($path);
        } catch (NotFoundException $e) {
            $this->logger->error(
                message: "[FileService] Can't create share link for $path because file doesn't exist.",
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                ]
            );
            return 'File not found at '.$path;
        }

        // @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
        $this->checkOwnership(file: $file);

        try {
            $share = $this->createShare(
                shareData: [
                    'path'        => $path,
                    'file'        => $file,
                    'shareType'   => $shareType,
                    'permissions' => $permissions,
                ]
            );
            return $this->getShareLink(share: $share);
        } catch (Exception $exception) {
            $this->logger->error(
                message: "[FileService] Can't create share link for $path: ".$exception->getMessage(),
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                ]
            );
            throw new Exception('Can\'t create share link.');
        }
    }//end createShareLink()

    /**
     * Creates a new folder in NextCloud, unless it already exists.
     *
     * @param string $folderPath Path (from root) to where you want to create a folder, include the name of the folder
     *
     * @throws Exception If creating the folder is not permitted
     *
     * @return Node The Node object for the folder (existing or newly created), or null on failure
     *
     * @spec openspec/specs/file-actions/spec.md#object-and-register-folder-provisioning
     *   (idempotent get-or-create of a folder at the given path under the OpenRegister root)
     */
    public function createFolder(string $folderPath): Node
    {
        return $this->folderManagementHandler->createFolder($folderPath);
    }//end createFolder()

    /**
     * Overwrites an existing file in NextCloud.
     *
     * Delegates to UpdateFileHandler for single-responsibility file update operations.
     *
     * @param string|int           $filePath The path (from root) where to save the file,
     *                                       including filename and extension, or file ID.
     * @param string|resource|null $content  Optional content of the file: a byte string, or a
     *                                       readable stream resource (streamed straight to storage
     *                                       via OCP\Files\File::putContent()).
     *                                       If null, only metadata like tags will be updated.
     * @param array                $tags     Optional array of tags to attach to the file
     *                                       (excluding object tags which are preserved).
     * @param ObjectEntity|null    $object   Optional object entity to search in object folder first.
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
    public function updateFile(string|int $filePath, mixed $content=null, array $tags=[], ?ObjectEntity $object=null): File
    {
        // Reject content/metadata writes when the file is locked by another
        // user. Only resolve the lock check when we can identify a numeric
        // file ID -- string paths fall through (the lock map is keyed on ID
        // and unresolvable paths cannot be safely guarded here).
        if (is_int($filePath) === true) {
            $this->fileLockHandler->assertCanModify($filePath);
        }

        return $this->updateFileHandler->updateFile(
            filePath: $filePath,
            content: $content,
            tags: $tags,
            object: $object
        );
    }//end updateFile()

    /**
     * Deletes a file from NextCloud.
     *
     * This method can accept either a file path string, file ID integer, or a Node object for deletion.
     * When a Node object is provided, it will be deleted directly. When a string path or integer ID
     * is provided, the file will be located first and then deleted.
     *
     * If an ObjectEntity is provided, the method will also update the object's files
     * array to remove the reference to the deleted file and save the updated object.
     *
     * @param Node|string|int    $file   The file Node object, path (from root), or file ID to delete
     * @param ObjectEntity|null  $object Optional object entity to update the files array for
     *
     * @throws Exception If deleting the file is not permitted or file operations fail
     *
     * @psalm-param   Node|string|int $file
     * @psalm-param   ObjectEntity|null $object
     * @phpstan-param Node|string|int $file
     * @phpstan-param ObjectEntity|null $object
     *
     * @return bool True if successful, false if the file didn't exist
     */

    /**
     * Delete a file by node, path, or ID.
     *
     * Delegates to DeleteFileHandler for single-responsibility file deletion operations.
     *
     * @param Node|string|int   $file   The file Node object, path (from root), or file ID to delete.
     * @param ObjectEntity|null $object Optional object entity.
     *
     * @return bool True if successful, false if the file didn't exist.
     *
     * @throws Exception If deleting the file is not permitted or file operations fail.
     *
     * @spec openspec/specs/file-actions/spec.md#file-update-and-delete-enforce-per-action-node-permissions
     *   (deletes a file resolved by node/path/id, with object-folder context)
     */
    public function deleteFile(Node | string | int $file, ?ObjectEntity $object=null): bool
    {
        return $this->deleteFileHandler->deleteFile(
            file: $file,
            object: $object
        );
    }//end deleteFile()

    /**
     * Attach tags to a file.
     *
     * Delegates to TaggingHandler for single-responsibility tag attachment.
     *
     * @param string $fileId The file ID.
     * @param array  $tags   Tags to associate with the file.
     *
     * @return void
     *
     * @phpstan-param array<int, string> $tags
     * @psalm-param   array<int, string> $tags
     *
     * @spec openspec/specs/file-actions/spec.md#file-creation-and-upsert-run-a-fixed-validate-write-own-tag-pipeline
     *   (the `object:<uuid>` + caller-tag attachment step of the create/upsert pipeline)
     */
    public function attachTagsToFile(string $fileId, array $tags=[]): void
    {
        $this->taggingHandler->attachTagsToFile(
            fileId: $fileId,
            tags: $tags
        );
    }//end attachTagsToFile()

    /**
     * Generate the object tag for a given ObjectEntity.
     *
     * Delegates to TaggingHandler for single-responsibility tag generation.
     *
     * @param ObjectEntity|string $objectEntity The object entity to generate the tag for.
     *
     * @return string The object tag (e.g., 'object:uuid').
     *
     * @psalm-return   string
     * @phpstan-return string
     *
     * @spec openspec/specs/file-actions/spec.md
     */
    public function generateObjectTag(ObjectEntity|string $objectEntity): string
    {
        return $this->taggingHandler->generateObjectTag($objectEntity);
    }//end generateObjectTag()

    /**
     * Adds a new file to an object's folder.
     *
     * Delegates to CreateFileHandler for single-responsibility file creation operations.
     *
     * @param ObjectEntity|string      $objectEntity The object entity to add the file to.
     * @param string                   $fileName     The name of the file to create.
     * @param string|resource          $content      File content: a byte string, or a readable stream resource.
     * @param bool                     $share        Whether to create a share link for the file.
     * @param array                    $tags         Optional array of tags to attach to the file.
     * @param int|string|Schema|null   $_schema      The register of the object to add the file to.
     * @param int|string|Register|null $_register    The register of the object to add the file to.
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
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean flag is intentional for simple share toggle
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-29
     */
    public function addFile(
        ObjectEntity | string $objectEntity,
        string $fileName,
        mixed $content,
        bool $share=false,
        array $tags=[],
        int | string | Schema | null $_schema=null,
        int | string | Register | null $_register=null,
        int|string|null $registerId=null
    ): File {
        return $this->createFileHandler->addFile(
            objectEntity: $objectEntity,
            fileName: $fileName,
            content: $content,
            share: $share,
            tags: $tags,
            _schema: $_schema,
            _register: $_register,
            registerId: $registerId
        );
    }//end addFile()

    /**
     * Save a file to an object's folder (create new or update existing).
     *
     * Delegates to CreateFileHandler for single-responsibility upsert operations.
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
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean flag is intentional for simple share toggle
     *
     * @spec openspec/specs/file-actions/spec.md#file-creation-and-upsert-run-a-fixed-validate-write-own-tag-pipeline
     *   (creates/writes a file into an object's folder with optional tags and share toggle)
     */
    public function saveFile(
        ObjectEntity $objectEntity,
        string $fileName,
        mixed $content,
        bool $share=false,
        array $tags=[]
    ): File {
        return $this->createFileHandler->saveFile(
            objectEntity: $objectEntity,
            fileName: $fileName,
            content: $content,
            share: $share,
            tags: $tags
        );
    }//end saveFile()

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
    public function getAllTags(): array
    {
        // Get all tags from the handler.
        $allTags = $this->taggingHandler->getAllTags();

        // Filter out tags starting with 'object:'.
        $tagNames = array_filter(
            $allTags,
            static function ($tagName) {
                return !str_starts_with($tagName, 'object:');
            }
        );

        // Return sorted array of tag names.
        sort($tagNames);
        return array_values($tagNames);
    }//end getAllTags()

    /**
     * Get all files for an object.
     *
     * Delegates to ReadFileHandler for single-responsibility file retrieval operations.
     *
     * @param ObjectEntity|string $object          The object or object ID to fetch files for.
     * @param bool|null           $sharedFilesOnly Whether to return only shared files.
     *
     * @return array Array of file nodes.
     *
     * @throws NotFoundException If the folder is not found.
     * @throws DoesNotExistException If the object ID is not found.
     *
     * @psalm-return   list<\OCP\Files\Node>
     * @phpstan-return array<int, Node>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean flag is intentional for simple filter toggle
     *
     * @spec openspec/specs/file-actions/spec.md#file-retrieval-resolves-by-id-or-name-and-projects-nodes-to-metadata
     *   (lists an object's files via its stored folder, with optional shared-only filter)
     */
    public function getFiles(ObjectEntity | string $object, ?bool $sharedFilesOnly=false): array
    {
        return $this->readFileHandler->getFiles(
            object: $object,
            sharedFilesOnly: $sharedFilesOnly
        );
    }//end getFiles()

    /**
     * Get a file by file identifier (ID or name/path) or by object and file name/path.
     *
     * Delegates to ReadFileHandler for single-responsibility file retrieval operations.
     *
     * @param ObjectEntity|string|null $object The object or object ID to fetch files for (ignored if $file is an ID).
     * @param string|int               $file   The file name/path within the object folder,
     *                                         or the file ID (int or numeric string).
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
    public function getFile(ObjectEntity|string|null $object=null, string|int $file=''): ?File
    {
        return $this->readFileHandler->getFile($object, $file);
    }//end getFile()

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
            $this->checkOwnership(file: $node);

            return $node;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[FileService] getFileById: Error finding file by ID '.$fileId.': '.$e->getMessage(),
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                ]
            );
            return null;
        }//end try
    }//end getFileById()

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
    public function streamFile(File $file): \OCP\AppFramework\Http\StreamResponse
    {
        // Create a stream response with the file content.
        $response = new StreamResponse($file->fopen('r'));

        // Set appropriate headers.
        $response->addHeader('Content-Type', $file->getMimeType());
        // SEC-CTRL-9: RFC 6266-encode the filename to prevent header injection /
        // response splitting via quotes, control chars, or non-ASCII bytes.
        $response->addHeader(
            'Content-Disposition',
            $this->buildContentDisposition(disposition: 'attachment', filename: $file->getName())
        );
        $response->addHeader('Content-Length', (string) $file->getSize());

        return $response;
    }//end streamFile()

    /**
     * Build an RFC 6266 compliant Content-Disposition header value.
     *
     * SEC-CTRL-9: emits a sanitised ASCII `filename="..."` fallback plus a UTF-8
     * `filename*` parameter so a hostile or non-ASCII filename cannot split the
     * header or corrupt the response.
     *
     * @param string $disposition Either 'inline' or 'attachment'.
     * @param string $filename    The raw file name.
     *
     * @return string The encoded Content-Disposition header value.
     */
    private function buildContentDisposition(string $disposition, string $filename): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $filename);
        if ($clean === null) {
            $clean = '';
        }

        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $clean);
        if ($ascii === null) {
            $ascii = '';
        }

        $ascii   = str_replace(['\\', '"'], '_', $ascii);
        $encoded = rawurlencode($clean);

        return $disposition.'; filename="'.$ascii.'"; filename*=UTF-8\'\''.$encoded;
    }//end buildContentDisposition()

    /**
     * Publish a file by creating a public share link using direct database operations.
     *
     * @param ObjectEntity|string $object The object or object ID
     * @param string|int          $file   The path to the file or file ID to publish
     *
     * @return File The published file
     *
     * @throws Exception If file publishing fails
     * @throws NotFoundException If the file is not found
     * @throws NotPermittedException If sharing is not permitted
     *
     * @psalm-return   File
     * @phpstan-return File
     *
     * @spec exclude File publish via public share; deferred to the file-actions FilePublishingHandler
     *   follow-up pass (see file-actions tasks.md DROP list).
     */
    public function publishFile(ObjectEntity | string $object, string | int $file): File
    {
        return $this->filePublishingHandler->publishFile(
            object: $object,
            file: $file
        );
    }//end publishFile()

    /**
     * Unpublish a file by removing its public share link.
     *
     * @param ObjectEntity|string $object   The object or object ID
     * @param string|int          $filePath The path to the file to unpublish or file ID
     *
     * @return File The unpublished file
     *
     * @throws Exception If file unpublishing fails
     * @throws NotFoundException If the file is not found
     * @throws NotPermittedException If sharing operations are not permitted
     *
     * @psalm-return   File
     * @phpstan-return File
     *
     * @spec exclude File unpublish (remove public share); deferred to the file-actions FilePublishingHandler
     *   follow-up pass (see file-actions tasks.md DROP list).
     */
    public function unpublishFile(ObjectEntity | string $object, string|int $filePath): File
    {
        return $this->filePublishingHandler->unpublishFile(
            object: $object,
            filePath: $filePath
        );
    }//end unpublishFile()

    /**
     * Create a ZIP archive containing all files for a specific object.
     *
     * This method retrieves all files associated with an object and creates a ZIP archive
     * containing all the files. The ZIP file is created in the system's temporary directory
     * and can be downloaded by the client.
     *
     * @param ObjectEntity|string $object  The object entity or object UUID/ID
     * @param string|null         $zipName Optional custom name for the ZIP file
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
    public function createObjectFilesZip(ObjectEntity | string $object, ?string $zipName=null): array
    {
        return $this->filePublishingHandler->createObjectFilesZip(
            object: $object,
            zipName: $zipName
        );
    }//end createObjectFilesZip()

    /**
    /**
     * Debug method to find a file by its ID anywhere in the OpenRegister folder structure
     *
     * @param int $fileId The file ID to search for
     *
     * @return (float|int|string)[]|null File information or null if not found
     *
     * @psalm-return array{id: int, name: string, path: string,
     *     type: string, mimetype: string, size: float|int,
     *     parent_id: int, parent_path: string}|null
     *
     * @spec exclude Diagnostic/debug helper for troubleshooting file lookups; not a product behavior.
     */
    public function debugFindFileById(int $fileId): array|null
    {
        try {
            $userFolder = $this->getOpenRegisterUserFolder();
            $nodes      = $userFolder->getById($fileId);

            if (empty($nodes) === true) {
                $this->logger->info(
                    message: "[FileService] debugFindFileById: No file found with ID: $fileId",
                    context: [
                        'file' => __FILE__,
                        'line' => __LINE__,
                    ]
                );
                return null;
            }

            $file     = $nodes[0];
            $fileInfo = [
                'id'          => $file->getId(),
                'name'        => $file->getName(),
                'path'        => $file->getPath(),
                'type'        => $file->getType(),
                'mimetype'    => $file->getMimeType(),
                'size'        => $file->getSize(),
                'parent_id'   => $file->getParent()->getId(),
                'parent_path' => $file->getParent()->getPath(),
            ];

            $this->logger->info(
                message: "[FileService] debugFindFileById: Found file with ID $fileId: ".json_encode($fileInfo),
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                ]
            );
            return $fileInfo;
        } catch (Exception $e) {
            $this->logger->error(
                message: "[FileService] debugFindFileById: Error finding file by ID $fileId: ".$e->getMessage(),
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                ]
            );
            return null;
        }//end try
    }//end debugFindFileById()

    /**
     * Debug method to list all files in an object's folder
     * //end try
     *
     * //end foreach
     *
     * @param ObjectEntity $object The object to list files for
     *
     * @return (float|int|string)[][]
     *
     * @psalm-return list<array{id: int, mimetype: string, name: string,
     *     path: string, size: float|int, type: string}>
     *
     * @spec exclude Diagnostic/debug helper for troubleshooting object-file listings; not a product behavior.
     */
    public function debugListObjectFiles(ObjectEntity $object): array
    {
        try {
            $objectFolder = $this->getObjectFolder(objectEntity: $object);

            if ($objectFolder === null) {
                $objectId = $object->getId();
                $msg      = "[FileService] debugListObjectFiles: Could not get object folder for object ID: ".$objectId;
                $this->logger->warning(message: $msg, context: ['file' => __FILE__, 'line' => __LINE__]);
                return [];
            }

            $files    = $objectFolder->getDirectoryListing();
            $fileList = [];

            foreach ($files as $file) {
                $fileInfo   = [
                    'id'       => $file->getId(),
                    'name'     => $file->getName(),
                    'path'     => $file->getPath(),
                    'type'     => $file->getType(),
                    'mimetype' => $file->getMimeType(),
                    'size'     => $file->getSize(),
                ];
                $fileList[] = $fileInfo;
            }

            $objectId  = $object->getId();
            $fileCount = count($fileList);
            $filesJson = json_encode($fileList);
            $this->logger->info(
                message: "[FileService] debugListObjectFiles: Object $objectId folder contains $fileCount files: $filesJson",
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                ]
            );
            return $fileList;
        } catch (Exception $e) {
            $objectId = $object->getId();
            $errorMsg = $e->getMessage();
            $this->logger->error(
                message: "[FileService] debugListObjectFiles: Error listing files for object $objectId: $errorMsg",
                context: [
                    'file' => __FILE__,
                    'line' => __LINE__,
                ]
            );
            return [];
        }//end try
    }//end debugListObjectFiles()

    /**
     * Creates a folder for an ObjectEntity and returns the folder ID without updating the object.
     *
     * This method creates a folder structure for an Object Entity within its parent
     * Register and Schema folders, but does not update the object with the folder ID.
     * This allows for single-save workflows where the folder ID is set before saving.
     *
     * @param ObjectEntity $objectEntity The Object Entity to create a folder for
     * @param IUser|null   $currentUser  The current user to share the folder with
     *
     * @throws Exception If folder creation fails or entities not found
     * @throws NotPermittedException If folder creation is not permitted
     * @throws NotFoundException If parent folders do not exist
     *
     * @return int The created folder ID
     *
     * @spec openspec/specs/file-actions/spec.md#object-and-register-folder-provisioning
     *   (provisions an object's folder and returns its id without writing the id back onto the entity)
     */
    public function createObjectFolderWithoutUpdate(ObjectEntity $objectEntity, ?IUser $currentUser=null): int
    {
        return $this->folderManagementHandler->createObjectFolderWithoutUpdate(
            objectEntity: $objectEntity,
            currentUser: $currentUser
        );
    }//end createObjectFolderWithoutUpdate()

    /**
     * Replace words in a document
     *
     * This method replaces specified words/phrases in a document with
     * replacement text. It supports Word documents and text-based files.
     *
     * @param Node   $node         The file node to process
     * @param array  $replacements Array of replacement mappings ['original' => 'replacement']
     * @param string $outputName   Optional name for the output file (default: adds '_replaced' suffix)
     *
     * @return File The processed file
     *
     * @throws Exception If replacement fails
     *
     * @spec exclude One-line delegation to DocumentProcessingHandler::replaceWords; no facade-owned logic.
     */
    public function replaceWords(Node $node, array $replacements, ?string $outputName=null): File
    {
        return $this->documentProcessingHandler->replaceWords(
            node: $node,
            replacements: $replacements,
            outputName: $outputName
        );
    }//end replaceWords()

    /**
     * Anonymize a document by replacing detected entities (DELEGATED to DocumentProcessingHandler).
     *
     * This is a convenience method that creates replacement mappings
     * from entity detection results and applies them to a document.
     *
     * @param Node        $node              The file node to anonymize.
     * @param array       $entities          Array of detected entities with 'text' and 'key' fields.
     * @param string      $scope             Placeholder-numbering scope: 'document' (default) or 'dossier'.
     * @param string|null $dossierKey        Stable folder id of the dossier (per-dossier scope); null falls
     *                                       back to the file's parent folder.
     * @param bool|null   $preserveStructure PDF only (REQ-ORTPR-004): tri-state structure-preservation
     *                                       option — null/absent = auto (preserve iff the input is a
     *                                       tagged PDF), true = attempt, false = skip but still measure.
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
        string $scope='document',
        ?string $dossierKey=null,
        ?bool $preserveStructure=null
    ): Node {
        return $this->documentProcessingHandler->anonymizeDocument(
            node: $node,
            entities: $entities,
            scope: $scope,
            dossierKey: $dossierKey,
            preserveStructure: $preserveStructure
        );
    }//end anonymizeDocument()

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
    public function getLastResidualEntities(): array
    {
        return $this->documentProcessingHandler->getLastResidualEntities();
    }//end getLastResidualEntities()

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
    public function getLastStructurePreservation(): ?StructurePreservation
    {
        return $this->documentProcessingHandler->getLastStructurePreservation();
    }//end getLastStructurePreservation()

    /**
     * Per-entity placeholder map from the most recent anonymizeDocument() call.
     *
     * Maps the internal global entity id (stringified) to the exact placeholder
     * string emitted into the document (e.g. `"7" => "[PERSOON: 1]"`), so the
     * caller (DocuDesk's grondslagen-summary) can render the same placeholder
     * the document carries rather than re-deriving it from the global id.
     *
     * @return array<string, string> Map of global entity id → emitted placeholder.
     *
     * @spec exclude One-line delegation to DocumentProcessingHandler::getLastPlaceholderMap.
     */
    public function getLastPlaceholderMap(): array
    {
        return $this->documentProcessingHandler->getLastPlaceholderMap();
    }//end getLastPlaceholderMap()

    /**
     * Get the file versioning handler.
     *
     * @return FileVersioningHandler The versioning handler.
     *
     * @spec openspec/specs/content-versioning/spec.md
     */
    public function getVersioningHandler(): FileVersioningHandler
    {
        return $this->fileVersioningHandler;
    }//end getVersioningHandler()

    /**
     * Get the file lock handler.
     *
     * @return FileLockHandler The lock handler.
     */
    public function getLockHandler(): FileLockHandler
    {
        return $this->fileLockHandler;
    }//end getLockHandler()

    /**
     * Get the file batch handler.
     *
     * @return FileBatchHandler The batch handler.
     */
    public function getBatchHandler(): FileBatchHandler
    {
        return $this->fileBatchHandler;
    }//end getBatchHandler()

    /**
     * Get the file preview handler.
     *
     * @return FilePreviewHandler The preview handler.
     */
    public function getPreviewHandler(): FilePreviewHandler
    {
        return $this->filePreviewHandler;
    }//end getPreviewHandler()

    /**
     * Get the file audit handler.
     *
     * @return FileAuditHandler The audit handler.
     */
    public function getAuditHandler(): FileAuditHandler
    {
        return $this->fileAuditHandler;
    }//end getAuditHandler()

    /**
     * Rename a file attached to an object.
     *
     * @param ObjectEntity $object  The parent object entity.
     * @param int          $fileId  The file ID.
     * @param string       $newName The new file name.
     *
     * @return File The renamed file.
     *
     * @throws Exception If the rename fails.
     *
     * @spec openspec/specs/file-actions/spec.md
     */
    public function renameFile(ObjectEntity $object, int $fileId, string $newName): File
    {
        // Check lock.
        $this->fileLockHandler->assertCanModify($fileId);

        $file = $this->readFileHandler->getFile(object: $object, file: $fileId);
        if ($file === null) {
            throw new Exception("File not found");
        }

        // Validate new name.
        if (empty(trim($newName)) === true) {
            throw new Exception("File name is required");
        }

        $invalidChars = ["/", "\\", ":", "*", "?", "\"", "<", ">", "|"];
        foreach ($invalidChars as $char) {
            if (str_contains($newName, $char) === true) {
                throw new Exception("File name contains invalid characters");
            }
        }

        // Check for name conflict.
        $parent = $file->getParent();
        try {
            $parent->get($newName);
            throw new Exception("A file with name \"".$newName."\" already exists for this object");
        } catch (\OCP\Files\NotFoundException $e) {
            // Name is available.
        }

        // Perform the rename via move in same folder.
        $file->move($parent->getPath()."/".$newName);

        $this->logger->info(
            message: "[FileService] Renamed file {$fileId} to {$newName}",
            context: ["file" => __FILE__, "line" => __LINE__]
        );

        return $file;
    }//end renameFile()

    /**
     * Copy a file to another object.
     *
     * @param ObjectEntity $sourceObject The source object entity.
     * @param int          $fileId       The source file ID.
     * @param ObjectEntity $targetObject The target object entity.
     *
     * @return File The new file copy.
     *
     * @throws Exception If the copy fails.
     *
     * @spec openspec/specs/file-actions/spec.md
     */
    public function copyFile(ObjectEntity $sourceObject, int $fileId, ObjectEntity $targetObject): File
    {
        // Target validation: target object MUST have a UUID (closes
        // file-actions item 45 — cross-register/schema copy with target
        // validation). Without a UUID, the target's folder cannot be
        // resolved and the copy would land in the wrong place.
        if ($targetObject->getUuid() === null || $targetObject->getUuid() === '') {
            throw new Exception("Target object has no UUID; cannot resolve target folder for file copy");
        }

        // Reject when the source is locked by someone else. Copying through
        // a lock would let a second user observe a half-written state.
        $this->fileLockHandler->assertCanModify($fileId);

        $sourceFile = $this->readFileHandler->getFile(object: $sourceObject, file: $fileId);
        if ($sourceFile === null) {
            throw new Exception("Source file not found");
        }

        $content  = $sourceFile->getContent();
        $fileName = $sourceFile->getName();

        // Resolve the target folder up front so we can detect name
        // conflicts before delegating to CreateFileHandler.
        $targetFolder = $this->folderManagementHandler->getObjectFolder(objectEntity: $targetObject);

        // Name-conflict resolution (closes file-actions item 44):
        // when a node with the same name already exists in the target
        // folder, append a numeric suffix `(1)`, `(2)`, … before the
        // file extension until we find a free slot. Caps at 999 to
        // avoid runaway loops on pathological inputs.
        $resolvedName = $this->resolveCopyTargetName(
            folder: $targetFolder,
            desiredName: $fileName
        );

        // Use CreateFileHandler to create the file in target object folder.
        $newFile = $this->createFileHandler->addFile(
            objectEntity: $targetObject,
            fileName: $resolvedName,
            content: $content
        );

        $sourceUuid = $sourceObject->getUuid();
        $targetUuid = $targetObject->getUuid();
        $this->logger->info(
            message: "[FileService] Copied file {$fileId} from object {$sourceUuid} to {$targetUuid} as {$resolvedName}",
            context: ["file" => __FILE__, "line" => __LINE__]
        );

        return $newFile;
    }//end copyFile()

    /**
     * Resolve a non-conflicting file name within a target folder.
     *
     * If `$desiredName` is free, returns it unchanged. Otherwise
     * appends `(1)`, `(2)`, … before the extension until a free name
     * is found. Caps at 999 attempts to avoid runaway loops.
     *
     * @param \OCP\Files\Folder $folder      The target folder to check.
     * @param string            $desiredName The desired file name.
     *
     * @return string The resolved (possibly suffixed) file name.
     *
     * @throws Exception When 999 conflicts have been hit (pathological).
     */
    private function resolveCopyTargetName(\OCP\Files\Folder $folder, string $desiredName): string
    {
        if ($folder->nodeExists($desiredName) === false) {
            return $desiredName;
        }

        $dotPos = strrpos($desiredName, '.');
        // No extension or hidden file (".env"); append suffix to whole name.
        $stem = $desiredName;
        $ext  = '';
        if ($dotPos !== false && $dotPos !== 0) {
            $stem = substr($desiredName, 0, $dotPos);
            $ext  = substr($desiredName, $dotPos);
        }

        for ($i = 1; $i <= 999; $i++) {
            $candidate = $stem.' ('.$i.')'.$ext;
            if ($folder->nodeExists($candidate) === false) {
                return $candidate;
            }
        }

        throw new Exception("Could not resolve a non-conflicting copy name for '{$desiredName}' after 999 attempts");
    }//end resolveCopyTargetName()

    /**
     * Move a file to another object (copy + delete source).
     *
     * @param ObjectEntity $sourceObject The source object entity.
     * @param int          $fileId       The source file ID.
     * @param ObjectEntity $targetObject The target object entity.
     *
     * @return File The moved file.
     *
     * @throws Exception If the move fails.
     *
     * @spec openspec/specs/file-actions/spec.md
     */
    public function moveFile(ObjectEntity $sourceObject, int $fileId, ObjectEntity $targetObject): File
    {
        // Check lock.
        $this->fileLockHandler->assertCanModify($fileId);

        // Copy first.
        $newFile = $this->copyFile(sourceObject: $sourceObject, fileId: $fileId, targetObject: $targetObject);

        // Delete source.
        $this->deleteFile(file: $fileId, object: $sourceObject);

        $sourceUuid = $sourceObject->getUuid();
        $targetUuid = $targetObject->getUuid();
        $this->logger->info(
            message: "[FileService] Moved file {$fileId} from object {$sourceUuid} to {$targetUuid}",
            context: ["file" => __FILE__, "line" => __LINE__]
        );

        return $newFile;
    }//end moveFile()
}//end class
