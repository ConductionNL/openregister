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
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
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
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\File\CreateFileHandler;
use OCA\OpenRegister\Service\File\DeleteFileHandler;
use OCA\OpenRegister\Service\File\DocumentProcessingHandler;
use OCA\OpenRegister\Service\File\FileFormattingHandler;
use OCA\OpenRegister\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Service\File\FilePublishingHandler;
use OCA\OpenRegister\Service\File\FileSharingHandler;
use OCA\OpenRegister\Service\File\FileValidationHandler;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
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
     * Object entity mapper
     *
     * @var ObjectEntityMapper
     */
    private ObjectEntityMapper $objectEntityMapper;

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
     * @param IConfig                   $config                    Configuration service
     * @param FileMapper                $fileMapper                File mapper
     * @param IGroupManager             $groupManager              Group manager
     * @param LoggerInterface           $logger                    Logger
     * @param ObjectEntityMapper        $objectEntityMapper        Object entity mapper
     * @param IRootFolder               $rootFolder                Root folder
     * @param IManager                  $shareManager              Share manager
     * @param ISystemTagManager         $systemTagManager          System tag manager
     * @param ISystemTagObjectMapper    $systemTagMapper           System tag mapper
     * @param IURLGenerator             $urlGenerator              URL generator
     * @param IUserManager              $userManager               User manager
     * @param IUserSession              $userSession               User session
     * @param FileValidationHandler     $fileValidationHandler     File validation handler
     * @param FolderManagementHandler   $folderManagementHandler   Folder management handler
     * @param FileOwnershipHandler      $fileOwnershipHandler      File ownership handler
     * @param FileSharingHandler        $fileSharingHandler        File sharing handler
     * @param CreateFileHandler         $createFileHandler         Create file handler
     * @param ReadFileHandler           $readFileHandler           Read file handler
     * @param UpdateFileHandler         $updateFileHandler         Update file handler
     * @param DeleteFileHandler         $deleteFileHandler         Delete file handler
     * @param TaggingHandler            $taggingHandler            Tagging handler
     * @param FileFormattingHandler     $fileFormattingHandler     File formatting handler
     * @param DocumentProcessingHandler $documentProcessingHandler Document processing handler
     * @param FilePublishingHandler     $filePublishingHandler     File publishing handler
     */
    public function __construct(
        IConfig $config,
        FileMapper $fileMapper,
        IGroupManager $groupManager,
        LoggerInterface $logger,
        ObjectEntityMapper $objectEntityMapper,
        IRootFolder $rootFolder,
        IManager $shareManager,
        ISystemTagManager $systemTagManager,
        ISystemTagObjectMapper $systemTagMapper,
        IURLGenerator $urlGenerator,
        IUserManager $userManager,
        IUserSession $userSession,
        FileValidationHandler $fileValidationHandler,
        FolderManagementHandler $folderManagementHandler,
        FileOwnershipHandler $fileOwnershipHandler,
        FileSharingHandler $fileSharingHandler,
        CreateFileHandler $createFileHandler,
        ReadFileHandler $readFileHandler,
        UpdateFileHandler $updateFileHandler,
        DeleteFileHandler $deleteFileHandler,
        TaggingHandler $taggingHandler,
        FileFormattingHandler $fileFormattingHandler,
        DocumentProcessingHandler $documentProcessingHandler,
        FilePublishingHandler $filePublishingHandler
    ) {
        $this->logger = $logger;
        $this->logger->debug('FileService constructor started.');
        $this->config       = $config;
        $this->fileMapper   = $fileMapper;
        $this->groupManager = $groupManager;
        $this->objectEntityMapper = $objectEntityMapper;
        // REMOVED: registerMapper assignment (unused, caused circular dependency).
        $this->rootFolder            = $rootFolder;
        $this->shareManager          = $shareManager;
        $this->systemTagManager      = $systemTagManager;
        $this->systemTagMapper       = $systemTagMapper;
        $this->urlGenerator          = $urlGenerator;
        $this->userManager           = $userManager;
        $this->userSession           = $userSession;
        $this->fileValidationHandler = $fileValidationHandler;
        $this->folderManagementHandler   = $folderManagementHandler;
        $this->fileOwnershipHandler      = $fileOwnershipHandler;
        $this->fileSharingHandler        = $fileSharingHandler;
        $this->createFileHandler         = $createFileHandler;
        $this->readFileHandler           = $readFileHandler;
        $this->updateFileHandler         = $updateFileHandler;
        $this->deleteFileHandler         = $deleteFileHandler;
        $this->taggingHandler            = $taggingHandler;
        $this->fileFormattingHandler     = $fileFormattingHandler;
        $this->documentProcessingHandler = $documentProcessingHandler;
        $this->filePublishingHandler     = $filePublishingHandler;

        // Break circular dependency: FolderManagementHandler needs FileService for cross-handler coordination.
        $this->logger->debug('About to call folderManagementHandler->setFileService.');
        $this->folderManagementHandler->setFileService($this);
        $this->logger->debug('Called folderManagementHandler->setFileService.');

        // Break circular dependency: UpdateFileHandler needs FileService for utility methods (tags, path extraction).
        $this->logger->debug('About to call updateFileHandler->setFileService.');
        $this->updateFileHandler->setFileService($this);
        $this->logger->debug('Called updateFileHandler->setFileService.');

        // Break circular dependency: CreateFileHandler needs FileService for sharing and tagging.
        $this->logger->debug('About to call createFileHandler->setFileService.');
        $this->createFileHandler->setFileService($this);
        $this->logger->debug('Called createFileHandler->setFileService.');

        // Break circular dependency: ReadFileHandler needs FileService for utility methods.
        $this->logger->debug('About to call readFileHandler->setFileService.');
        $this->readFileHandler->setFileService($this);
        $this->logger->debug('Called readFileHandler->setFileService.');

        // Break circular dependency: FileFormattingHandler needs FileService for utility methods (shares, tags, etc.).
        $this->logger->debug('About to call fileFormattingHandler->setFileService.');
        $this->fileFormattingHandler->setFileService($this);
        $this->logger->debug('Called fileFormattingHandler->setFileService.');

        // Break circular dependency: DocumentProcessingHandler needs FileService for cross-handler coordination.
        $this->logger->debug('About to call documentProcessingHandler->setFileService.');
        $this->documentProcessingHandler->setFileService($this);
        $this->logger->debug('Called documentProcessingHandler->setFileService.');

        // Break circular dependency: FilePublishingHandler needs FileService for file operations and utilities.
        $this->logger->debug('About to call filePublishingHandler->setFileService.');
        $this->filePublishingHandler->setFileService($this);
        $this->logger->debug('Called filePublishingHandler->setFileService.');

        $this->logger->debug('FileService constructor completed.');

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
     * Get the name for the folder of a Register (used for storing files of Schemas/Objects).
     *
     * @param Register $register The Register to get the folder name for
     *
     * @return null|string The name the folder for this Register should have
     */
    private function getRegisterFolderName(Register $register): string|null
    {
        $title = $register->getTitle();

        if (str_ends_with(haystack: strtolower(rtrim($title ?? '')), needle: 'register') === true) {
            return $title;
        }

        return "$title Register";

    }//end getRegisterFolderName()

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
     * Get the folder name for an Object Entity.
     *
     * This method generates a folder name for an Object Entity based on its
     * identifier or other properties.
     *
     * @param ObjectEntity $objectEntity The Object Entity to get the folder name for
     *
     * @phpstan-return string
     *
     * @return string The object folder name
     */
    private function getObjectFolderName(ObjectEntity|string $objectEntity): string
    {
        /*
         * @psalm-suppress TypeDoesNotContainType - Function accepts ObjectEntity|string, but callers may always pass ObjectEntity
         */
        if (is_string($objectEntity) === true) {
            /*
             * @psalm-suppress NoValue - guaranteed to return string
             */

            return $objectEntity;
        }

        $uuid = $objectEntity->getUuid();
        if ($uuid !== null && $uuid !== '') {
            return $uuid;
        }

        $id = $objectEntity->getId();
        return (string) $id;

    }//end getObjectFolderName()

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
     */
    public function createEntityFolder(Register | ObjectEntity $entity): ?Node
    {
        // Get the current user for sharing.
        $currentUser = $this->getCurrentUser();

        try {
            if ($entity instanceof Register) {
                return $this->createRegisterFolderById(register: $entity, currentUser: $currentUser);
            } else {
                return $this->createObjectFolderById(objectEntity: $entity, currentUser: $currentUser);
            }
        } catch (exception $e) {
            $this->logger->error(
                    message: 'Failed to create folder for entity: {message}',
                    context: ['message' => $e->getMessage(), 'exception' => $e]
            );
            return null;
        }

    }//end createEntityFolder()

    /**
     * Creates a folder for a Register and stores the folder ID.
     *
     * @param Register   $register    The register to create the folder for
     * @param IUser|null $currentUser The current user to share the folder with
     *
     * @return Node|null The created folder Node or null if creation fails
     *
     * @throws Exception If folder creation fails
     * @throws NotPermittedException If folder creation is not permitted
     *
     * @phpstan-return Node|null
     */
    private function createRegisterFolderById(Register $register, ?IUser $currentUser=null): ?Node
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
     * @phpstan-return Node|null
     *
     * @return Node|null The created folder node
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
     * Get a Node by its ID.
     *
     * Delegates to FolderManagementHandler.
     *
     * @param int $nodeId The ID of the node to retrieve.
     *
     * @return Node|null The Node if found, null otherwise.
     *
     * @psalm-return   Node|null
     * @phpstan-return Node|null
     */
    private function getNodeById(int $nodeId): ?Node
    {
        return $this->folderManagementHandler->getNodeById($nodeId);

    }//end getNodeById()

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
     */
    public function getFilesForEntity(Register|ObjectEntity $entity, ?bool $sharedFilesOnly=false): array
    {

        if ($entity instanceof Register) {
            $folder = $this->getRegisterFolderById($entity);
        } else {
            $folder = $this->getObjectFolder($entity);
        }

        if ($folder === null) {
            throw new Exception("Cannot access folder for entity ".$entity->getId());
        }

        $files = $folder->getDirectoryListing();

        if ($sharedFilesOnly === true) {
            $files = array_filter(
                $files,
                function ($file) {
                    $shares = $this->findShares($file);
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
        return $this->folderManagementHandler->getRegisterFolderById($register);

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
     */
    public function getObjectFolder(ObjectEntity|string $objectEntity, int|string|null $registerId=null): ?Folder
    {
        return $this->folderManagementHandler->getObjectFolder(
            objectEntity: $objectEntity,
            registerId: $registerId
        );

    }//end getObjectFolder()

    /**
     * Create a folder path and return the Node.
     *
     * @param string $folderPath The full path to create
     *
     * @psalm-return   Node|null
     * @phpstan-return Node|null
     *
     * @return Node|null The created folder node
     */
    private function createFolderPath(string $folderPath): Node
    {
        return $this->folderManagementHandler->createFolderPath($folderPath);

    }//end createFolderPath()

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
     * Set file ownership to the OpenRegister user at database level.
     *
     * @param Node $file The file node to change ownership for
     *
     * @return bool True if ownership was updated successfully, false otherwise
     *
     * @throws Exception If the ownership update fails
     *
     * @TODO: This is a hack to fix NextCloud file ownership issues on production
     * @TODO: where files exist but can't be accessed due to permission problems.
     * @TODO: This should be removed once the underlying NextCloud rights issue is resolved.
     *
     * @psalm-return   bool
     * @phpstan-return bool
     */
    private function ownFile(Node $file): bool
    {
        return $this->fileValidationHandler->ownFile($file);

    }//end ownFile()

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
     */
    public function checkOwnership(Node $file): void
    {
        $this->fileValidationHandler->checkOwnership($file);

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
     * @return array Array of formatted file metadata arrays with pagination information.
     *
     * @throws InvalidPathException If file paths are invalid.
     * @throws NotFoundException If files are not found.
     *
     * @phpstan-return array{results: array<int, array<string, mixed>>, total: int, page: int, pages: int, limit: int, offset: int}
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
     */
    public function findShares(Node $file, int $shareType=3): array
    {
        // Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
        $this->checkOwnership($file);

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
     * Share a folder with a specific user.
     *
     * Delegates to FileSharingHandler for single-responsibility sharing operations.
     *
     * @param Node   $folder      The folder to share.
     * @param string $userId      The user ID to share with.
     * @param int    $permissions The permissions to grant (default: 31 = all).
     *
     * @return IShare|null The created share or null if user doesn't exist.
     */
    private function shareFolderWithUser(Node $folder, string $userId, int $permissions=31): ?IShare
    {
        return $this->fileSharingHandler->shareFolderWithUser(
            folder: $folder,
            userId: $userId,
            permissions: $permissions
        );

    }//end shareFolderWithUser()

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
     * Transfer file ownership to OpenRegister user and share with current user
     *
     * This method checks if the current user owns a file and if they are not the OpenRegister
     * system user. If so, it transfers ownership to the OpenRegister user and creates a share
     * with the current user to maintain access.
     *
     * @param File $file The file to potentially transfer ownership for
     *
     * @return void
     *
     * @throws \Exception If ownership transfer fails
     */
    private function transferFileOwnershipIfNeeded(File $file): void
    {
        $this->fileOwnershipHandler->transferFileOwnershipIfNeeded(
            file: $file,
            fileSharingHandler: $this->fileSharingHandler
        );

    }//end transferFileOwnershipIfNeeded()

    /**
     * Share a file with a specific user.
     *
     * Delegates to FileSharingHandler for single-responsibility sharing operations.
     *
     * @param File   $file        The file to share.
     * @param string $userId      The user ID to share with.
     * @param int    $permissions The permissions to grant (default: full permissions).
     *
     * @return void
     *
     * @throws \Exception If sharing fails.
     */
    private function shareFileWithUser(File $file, string $userId, int $permissions=31): void
    {
        $this->fileSharingHandler->shareFileWithUser(
            file: $file,
            userId: $userId,
            permissions: $permissions
        );

    }//end shareFileWithUser()

    /**
     * Transfer folder ownership to OpenRegister user and share with current user
     *
     * This method checks if the current user owns a folder and if they are not the OpenRegister
     * system user. If so, it transfers ownership to the OpenRegister user and creates a share
     * with the current user to maintain access.
     *
     * @param Node $folder The folder to potentially transfer ownership for
     *
     * @return void
     *
     * @throws \Exception If ownership transfer fails
     */
    private function transferFolderOwnershipIfNeeded(Node $folder): void
    {
        $this->fileOwnershipHandler->transferFolderOwnershipIfNeeded(
            folder: $folder,
            fileSharingHandler: $this->fileSharingHandler
        );

    }//end transferFolderOwnershipIfNeeded()

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
            $this->logger->error(message: "Can't create share link for $path because OpenRegister user folder couldn't be found.");
            return "OpenRegister user folder couldn't be found.";
        }

        try {
            $file = $this->rootFolder->get($path);
        } catch (NotFoundException $e) {
            $this->logger->error(message: "Can't create share link for $path because file doesn't exist.");
            return 'File not found at '.$path;
        }

        // @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
        $this->checkOwnership($file);

        try {
            $share = $this->createShare(
                    shareData: [
                        'path'        => $path,
                        'file'        => $file,
                        'shareType'   => $shareType,
                        'permissions' => $permissions,
                    ]
                    );
            return $this->getShareLink($share);
        } catch (Exception $exception) {
            $this->logger->error(message: "Can't create share link for $path: ".$exception->getMessage());
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
     * @param string|int        $filePath The path (from root) where to save the file, including filename and extension, or file ID.
     * @param mixed             $content  Optional content of the file. If null, only metadata like tags will be updated.
     * @param array             $tags     Optional array of tags to attach to the file (excluding object tags which are preserved).
     * @param ObjectEntity|null $object   Optional object entity to search in object folder first.
     *
     * @throws Exception If the file doesn't exist or if file operations fail.
     *
     * @return File The updated file.
     *
     * @phpstan-param array<int, string> $tags
     * @psalm-param   array<int, string> $tags
     */
    public function updateFile(string|int $filePath, mixed $content=null, array $tags=[], ?ObjectEntity $object=null): File
    {
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
     */
    private function attachTagsToFile(string $fileId, array $tags=[]): void
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
     * @param string                   $content      The content to write to the file.
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
     */
    public function addFile(ObjectEntity | string $objectEntity, string $fileName, string $content, bool $share=false, array $tags=[], int | string | Schema | null $_schema=null, int | string | Register | null $_register=null, int|string|null $registerId=null): File
    {
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
    public function saveFile(ObjectEntity $objectEntity, string $fileName, string $content, bool $share=false, array $tags=[]): File
    {
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
     * @param string|int               $file   The file name/path within the object folder, or the file ID (int or numeric string).
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
            $this->checkOwnership($node);

            return $node;
        } catch (Exception $e) {
            $this->logger->error(message: 'getFileById: Error finding file by ID '.$fileId.': '.$e->getMessage());
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
     */
    public function streamFile(File $file): \OCP\AppFramework\Http\StreamResponse
    {
        // Create a stream response with the file content.
        $response = new StreamResponse($file->fopen('r'));

        // Set appropriate headers.
        $response->addHeader('Content-Type', $file->getMimeType());
        $response->addHeader('Content-Disposition', 'attachment; filename="'.$file->getName().'"');
        $response->addHeader('Content-Length', (string) $file->getSize());

        return $response;

    }//end streamFile()

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
     * @psalm-return array{id: int, name: string, path: string, type: string, mimetype: string, size: float|int, parent_id: int, parent_path: string}|null
     */
    public function debugFindFileById(int $fileId): array|null
    {
        try {
            $userFolder = $this->getOpenRegisterUserFolder();
            $nodes      = $userFolder->getById($fileId);

            if (empty($nodes) === true) {
                $this->logger->info(message: "debugFindFileById: No file found with ID: $fileId");
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

            $this->logger->info(message: "debugFindFileById: Found file with ID $fileId: ".json_encode($fileInfo));
            return $fileInfo;
        } catch (Exception $e) {
            $this->logger->error(message: "debugFindFileById: Error finding file by ID $fileId: ".$e->getMessage());
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
     * @psalm-return list<array{id: int, mimetype: string, name: string, path: string, size: float|int, type: string}>
     */
    public function debugListObjectFiles(ObjectEntity $object): array
    {
        try {
            $objectFolder = $this->getObjectFolder($object);

            if ($objectFolder === null) {
                $this->logger->warning(message: "debugListObjectFiles: Could not get object folder for object ID: ".$object->getId());
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

            $this->logger->info(message: "debugListObjectFiles: Object ".$object->getId()." folder contains ".count($fileList)." files: ".json_encode($fileList));
            return $fileList;
        } catch (Exception $e) {
            $this->logger->error(message: "debugListObjectFiles: Error listing files for object ".$object->getId().": ".$e->getMessage());
            return [];
        }//end try

    }//end debugListObjectFiles()

    /**
     * Blocks executable files from being uploaded for security.
     *
     * Delegates to FileValidationHandler.
     *
     * @param string $fileName    The filename to check.
     * @param string $fileContent The file content to check.
     *
     * @return void
     *
     * @throws Exception If an executable file is detected.
     *
     * @psalm-return   void
     * @phpstan-return void
     */
    private function blockExecutableFile(string $fileName, string $fileContent): void
    {
        $this->fileValidationHandler->blockExecutableFile(fileName: $fileName, fileContent: $fileContent);

    }//end blockExecutableFile()

    /**
     * Detects executable magic bytes in file content.
     *
     * Magic bytes are signatures at the start of files that identify the file type.
     * This provides defense-in-depth against renamed executables.
     *
     * @param string $content  The file content to check
     * @param string $fileName The filename for error messages
     *
     * @return void
     *
     * @throws Exception If executable magic bytes are detected
     */
    private function detectExecutableMagicBytes(string $content, string $fileName): void
    {
        // Common executable magic bytes.
        $magicBytes = [
            'MZ'               => 'Windows executable (PE/EXE)',
            "\x7FELF"          => 'Linux/Unix executable (ELF)',
            "#!/bin/sh"        => 'Shell script',
            "#!/bin/bash"      => 'Bash script',
            "#!/usr/bin/env"   => 'Script with env shebang',
            "<?php"            => 'PHP script',
            "\xCA\xFE\xBA\xBE" => 'Java class file',
        ];

        foreach ($magicBytes as $signature => $description) {
            if (strpos($content, $signature) === 0) {
                $this->logger->warning(
                        message: 'Executable magic bytes detected',
                        context: [
                            'app'      => 'openregister',
                            'filename' => $fileName,
                            'type'     => $description,
                        ]
                        );

                throw new Exception(
                    "File '$fileName' contains executable code ($description). Executable files are blocked for security reasons."
                );
            }
        }

        // Check for script shebangs anywhere in first 4 lines.
        $firstLines = substr($content, 0, 1024);
        if (preg_match('/^#!.*\/(sh|bash|zsh|ksh|csh|python|perl|ruby|php|node)/m', $firstLines) === 1) {
            throw new Exception(
                "File '$fileName' contains script shebang. "."Script files are blocked for security reasons."
            );
        }

        // Check for embedded PHP tags.
        if (preg_match('/<\?php|<\?=|<script\s+language\s*=\s*["\']php/i', $firstLines) === 1) {
            throw new Exception(
                "File '$fileName' contains PHP code. "."PHP files are blocked for security reasons."
            );
        }

    }//end detectExecutableMagicBytes()

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
     * @psalm-return   int|null
     * @phpstan-return int|null
     */
    public function createObjectFolderWithoutUpdate(ObjectEntity $objectEntity, ?IUser $currentUser=null): int
    {
        return $this->folderManagementHandler->createObjectFolderWithoutUpdate(
            objectEntity: $objectEntity,
            currentUser: $currentUser
        );

    }//end createObjectFolderWithoutUpdate()

    /**
     * Get node type from folder (file or folder).
     *
     * @param Node $node The node to check.
     *
     * @return string Node type ('file' or 'folder').
     *
     * @psalm-return 'file'|'folder'|'unknown'
     */
    private function getNodeTypeFromFolder(Node $node): string
    {
        if ($node instanceof Folder) {
            return 'folder';
        }

        if ($node instanceof File) {
            return 'file';
        }

        return 'unknown';

    }//end getNodeTypeFromFolder()

    /**
     * Get access URL from shares array.
     *
     * @param array $shares Array of IShare objects.
     *
     * @return null|string Access URL or null if not found.
     */
    private function getAccessUrlFromShares(array $shares): string|null
    {
        foreach ($shares as $share) {
            if ($share instanceof IShare) {
                $url = $this->getShareLink($share);
                if ($url !== null && $url !== '') {
                    return $url;
                }
            }
        }

        return null;

    }//end getAccessUrlFromShares()

    /**
     * Get download URL from shares array.
     *
     * @param array $shares Array of IShare objects.
     *
     * @return null|string Download URL or null if not found. //end if
     */
    private function getDownloadUrlFromShares(array $shares): string|null
    {
        foreach ($shares as $share) {
            if ($share instanceof IShare) {
                $url = $this->getShareLink($share);
                if ($url !== null && $url !== '') {
                    return $url.'/download';
                }
            }
        }

        return null;

    }//end getDownloadUrlFromShares()

    /**
     * Get published time from shares array.
     *
     * @param array $shares Array of IShare objects.
     *
     * @return string|null Published time as ISO8601 string or null if not found.
     */
    private function getPublishedTimeFromShares(array $shares): ?string
    {
        foreach ($shares as $share) {
            if ($share instanceof IShare) {
                $stime = $share->getShareTime();
                if ($stime !== null) {
                    // GetShareTime() returns DateTime|null, convert to timestamp.
                    // GetShareTime() always returns DateTime|null, so use getTimestamp().
                    if ($stime instanceof \DateTime) {
                        $timestamp = $stime->getTimestamp();
                        return (new DateTime())->setTimestamp($timestamp)->format('c');
                    }

                    // If somehow not a DateTime (shouldn't happen), return current time.
                    return (new DateTime())->format('c');
                }
            }
        }

        return null;

    }//end getPublishedTimeFromShares()

    /**
     * Get object ID from ObjectEntity.
     *
     * @param ObjectEntity|null $object The object entity.
     *
     * @return string|null Object ID (UUID) or null if not available.
     */
    private function getObjectId(?ObjectEntity $object): ?string
    {
        if ($object === null) {
            return null;
        }

        return $object->getUuid() ?? (string) $object->getId();

    }//end getObjectId()

    /**
     * Get file in object folder message.
     *
     * @param bool $fileInObjectFolder Whether file is in object folder.
     * @param int  $fileId             File ID.
     *
     * @return string Message describing the result.
     */
    private function getFileInObjectFolderMessage(bool $fileInObjectFolder, int $fileId): string
    {
        if ($fileInObjectFolder === true) {
            return "File $fileId is correctly located in object folder";
        }

        return "File $fileId is not in object folder";

    }//end getFileInObjectFolderMessage()

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
     * @return Node The new file node with replaced content
     *
     * @throws Exception If replacement fails
     *
     * @phpstan-param  array<string, string> $replacements
     * @psalm-param    array<string, string> $replacements
     * @phpstan-return Node
     * @psalm-return   Node
     */
    public function replaceWords(Node $node, array $replacements, ?string $outputName=null): Node
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
     * @param Node  $node     The file node to anonymize.
     * @param array $entities Array of detected entities with 'text' and 'key' fields.
     *
     * @return Node The anonymized file node.
     *
     * @throws Exception If anonymization fails.
     *
     * @phpstan-param  array<int, array{text?: string, entityType?: string, key?: string}> $entities
     * @psalm-param    array<int, array{text?: string, entityType?: string, key?: string}> $entities
     * @phpstan-return Node
     * @psalm-return   Node
     */
    public function anonymizeDocument(Node $node, array $entities): Node
    {
        return $this->documentProcessingHandler->anonymizeDocument(
            node: $node,
            entities: $entities
        );

    }//end anonymizeDocument()
}//end class
