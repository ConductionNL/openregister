<?php

declare(strict_types=1);

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
 * @category       Service
 * @package        OCA\OpenRegister\Service
 * @author         Conduction Development Team <info@conduction.nl>
 * @copyright      2024 Conduction B.V.
 * @license        EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version        GIT: <git_id>
 * @link           https://www.OpenRegister.app
 *
 * @psalm-suppress PropertyNotSetInConstructor
 * @phpstan-type   FileArray array{
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
use OCA\Files_Versions\Versions\VersionManager;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
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
     * Root folder name for all OpenRegister files.
     *
     * @var        string
     * @readonly
     * @psalm-readonly
     */
    private const ROOT_FOLDER = 'Open Registers';

    /**
     * Application group name.
     *
     * @var        string
     * @readonly
     * @psalm-readonly
     */
    private const APP_GROUP = 'openregister';

    /**
     * Application user name.
     *
     * @var        string
     * @readonly
     * @psalm-readonly
     */
    private const APP_USER = 'OpenRegister';

    /**
     * File tag type identifier.
     *
     * @var        string
     * @readonly
     * @psalm-readonly
     */
    private const FILE_TAG_TYPE = 'files';

    /**
     * Constructor for FileService.
     *
     * @param IUserSession           $userSession        The user session
     * @param IUserManager           $userManager        The user manager
     * @param LoggerInterface        $logger             The logger interface
     * @param IRootFolder           $rootFolder         The root folder interface
     * @param IManager              $shareManager       The share manager interface
     * @param IURLGenerator         $urlGenerator       URL generator service
     * @param IConfig               $config             Configuration service
     * @param RegisterMapper        $registerMapper     Register data mapper
     * @param SchemaMapper         $schemaMapper       Schema data mapper
     * @param IGroupManager         $groupManager       Group manager service
     * @param ISystemTagManager     $systemTagManager   System tag manager
     * @param ISystemTagObjectMapper $systemTagMapper    System tag object mapper
     * @param ObjectEntityMapper    $objectEntityMapper Object entity mapper
     * @param VersionManager        $versionManager     Version manager service
     * @param FileMapper            $fileMapper         File mapper for direct database operations
     */
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
        private readonly IRootFolder $rootFolder,
        private readonly IManager $shareManager,
        private readonly IURLGenerator $urlGenerator,
        private readonly IConfig $config,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly IGroupManager $groupManager,
        private readonly ISystemTagManager $systemTagManager,
        private readonly ISystemTagObjectMapper $systemTagMapper,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly VersionManager $versionManager,
        private readonly FileMapper $fileMapper
    ) {
        // Dependency injection confirmed working - debug logging removed.
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
     * @psalm-return array{cleanPath: string, fileName: string}
     * @phpstan-return array{cleanPath: string, fileName: string}
     */
    private function extractFileNameFromPath(string $filePath): array
    {
        // Clean and decode the file path.
        $cleanPath = trim(string: $filePath, characters: '/');
        $cleanPath = urldecode($cleanPath);

        // Extract just the filename if the path contains a folder ID prefix (like "8010/filename.ext").
        $fileName = $cleanPath;
        if (str_contains($cleanPath, '/') === true) {
            $pathParts = explode('/', $cleanPath);
            $fileName = end($pathParts);
        }

        return [
            'cleanPath' => $cleanPath,
            'fileName' => $fileName
        ];
    }//end extractFileNameFromPath()

    /**
     * Creates a new version of a file if the object is updated.
     *
     * @param File        $file     The file to update
     * @param string|null $filename Optional new filename for the file
     *
     * @return File The updated file with a new version
     */
    public function createNewVersion(File $file, ?string $filename=null): File
    {
        // @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
        $this->checkOwnership($file);

        $this->versionManager->createVersion(user: $this->userManager->get(self::APP_USER), file: $file);

        if ($filename !== null) {
            $file->move(targetPath: $file->getParent()->getPath().'/'.$filename);
        }

        return $file;
    }//end createNewVersion()

    /**
     * Get a specific version of a file.
     *
     * @param Node   $file    The file to get a version for
     * @param string $version The version to retrieve
     *
     * @return Node|null The requested version of the file or null if not found
     */
    public function getVersion(Node $file, string $version): ?Node
    {
        if ($file instanceof File === false) {
            return $file;
        }

        // @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
        $this->checkOwnership($file);

        return $this->versionManager->getVersionFile($this->userManager->get(self::APP_USER), $file, $version);
    }//end getVersion()

    /**
     * Creates a folder for a Register (used for storing files of Schemas/Objects).
     *
     * @param Register|int $register The Register to create the folder for
     *
     * @throws Exception In case we can't create the folder because it is not permitted
     *
     * @return string The path to the folder
     */
    public function createRegisterFolder(Register | int $register): string
    {
        if (is_int($register) === true) {
            $register = $this->registerMapper->find($register);
        }

        $registerFolderName = $this->getRegisterFolderName($register);
        // @todo maybe we want to use ShareLink here for register->folder as well?
        $register->setFolder($this::ROOT_FOLDER."/$registerFolderName");
        $this->registerMapper->update($register);

        $folderPath = $this::ROOT_FOLDER."/$registerFolderName";
        $this->createFolder(folderPath: $folderPath);

        return $folderPath;
    }//end createRegisterFolder()

    /**
     * Get the name for the folder of a Register (used for storing files of Schemas/Objects).
     *
     * @param Register $register The Register to get the folder name for
     *
     * @return string The name the folder for this Register should have
     */
    private function getRegisterFolderName(Register $register): string
    {
        $title = $register->getTitle();

        if (str_ends_with(haystack: strtolower(rtrim($title)), needle: 'register') === true) {
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
     * @psalm-suppress InvalidNullableReturnType
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
     * @psalm-suppress InvalidNullableReturnType
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
     * @psalm-suppress InvalidNullableReturnType
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
     * @return string The folder name
     *
     * @psalm-suppress PossiblyNullReference
     * @phpstan-return string
     */
    private function getObjectFolderName(ObjectEntity|string $objectEntity): string
    {
		if (is_string($objectEntity) === true) {
			return $objectEntity;
		}

        return $objectEntity->getUuid() ?? (string) $objectEntity->getId();
    }

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
     * @psalm-suppress InvalidNullableReturnType
     * @phpstan-return Node|null
     */
    public function createEntityFolder(Register | ObjectEntity $entity): ?Node
    {
        // Get the current user for sharing.
        $currentUser = $this->getCurrentUser();

        try {
            if ($entity instanceof Register) {
                return $this->createRegisterFolderById($entity, $currentUser);
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
     * @psalm-suppress InvalidNullableReturnType
     * @phpstan-return Node|null
     */
    private function createRegisterFolderById(Register $register, ?IUser $currentUser = null): ?Node
    {
        $folderProperty = $register->getFolder();

        // Check if folder ID is already set and valid (not legacy string).
        if ($folderProperty !== null && $folderProperty !== '' && is_string($folderProperty) === false) {
            try {
                $existingFolder = $this->getNodeById((int) $folderProperty);
                if ($existingFolder !== null && $existingFolder instanceof Folder) {
                    $this->logger->info(message: "Register folder already exists with ID: " . $folderProperty);
                    return $existingFolder;
                }
            } catch (Exception $e) {
                $this->logger->warning(message: "Stored folder ID invalid, creating new folder: " . $e->getMessage());
            }
        }

        // Create the folder path and node.
        $registerFolderName = $this->getRegisterFolderName($register);
        $folderPath = self::ROOT_FOLDER . '/' . $registerFolderName;

        $folderNode = $this->createFolderPath($folderPath);

        if ($folderNode !== null) {
            // Store the folder ID instead of the path.
            $register->setFolder((string) $folderNode->getId());
            $this->registerMapper->update($register);

            $this->logger->info(message: "Created register folder with ID: " . $folderNode->getId());

            // Transfer ownership to OpenRegister and share with current user if needed.
            $this->transferFolderOwnershipIfNeeded($folderNode);

            // Share the folder with the currently active user if there is one.
            if ($currentUser !== null && $currentUser->getUID() !== $this->getUser()->getUID()) {
                $this->shareFolderWithUser($folderNode, $currentUser->getUID());
            }
        }

        return $folderNode;
    }//end createRegisterFolderById()

    /**
     * Creates a folder for an ObjectEntity nested under the register folder.
     *
     * @param ObjectEntity|string $objectEntity The object entity to create the folder for
     * @param IUser|null          $currentUser  The current user to share the folder with
     * @param int|string|null     $registerId   The register of the object to add the file to
     *
     * @return Node|null The created folder Node or null if creation fails
     *
     * @throws Exception If folder creation fails
     * @throws NotPermittedException If folder creation is not permitted
     *
     * @psalm-suppress InvalidNullableReturnType
     * @phpstan-return Node|null
     */
    private function createObjectFolderById(ObjectEntity|string $objectEntity, ?IUser $currentUser = null, int|string|null $registerId = null): ?Node
    {
        $folderProperty = null;
        if ($objectEntity instanceof ObjectEntity === true) {
            $folderProperty = $objectEntity->getFolder();
        }

        // Check if folder ID is already set and valid (not legacy string).
        if ($folderProperty !== null && $folderProperty !== '' && is_string($folderProperty) === false) {
            try {
                $existingFolder = $this->getNodeById((int) $folderProperty);
                if ($existingFolder !== null && $existingFolder instanceof Folder) {
                    $this->logger->info(message: "Object folder already exists with ID: " . $folderProperty);
                    return $existingFolder;
                }
            } catch (Exception $e) {
                $this->logger->warning(message: "Stored folder ID invalid, creating new folder: " . $e->getMessage());
            }
        }

        // Ensure register folder exists first.
        $register = null;
        if ($objectEntity instanceof ObjectEntity === true) {
            $register = $this->registerMapper->find($objectEntity->getRegister());
            if ($register === null) {
                throw new Exception("Failed to create file, could not find register for objects register: {$objectEntity->getRegister()}");
            }
        } else if ($registerId !== null) {
            $register = $this->registerMapper->find($registerId);
            if ($register === null) {
                throw new Exception("Failed to create file, could not find register with register id: $registerId");
            }
        }

        if ($register === null) {
            throw new Exception("Failed to create file because no objectEntity or registerId given");
        }

        $registerFolder = $this->createRegisterFolderById($register, $currentUser);

        if ($registerFolder === null) {
            throw new Exception("Failed to create or access register folder");
        }

        // Create object folder within the register folder.
        $objectFolderName = $this->getObjectFolderName($objectEntity);

        try {
            // Try to get existing folder first.
            $objectFolder = $registerFolder->get($objectFolderName);
            $this->logger->info(message: "Object folder already exists: " . $objectFolderName);
        } catch (NotFoundException) {
            // Create new folder if it doesn't exist.
            $objectFolder = $registerFolder->newFolder($objectFolderName);
            $this->logger->info(message: "Created object folder: " . $objectFolderName);
        }

        // Store the folder ID.
        if ($objectEntity instanceof ObjectEntity === true) {
            $objectEntity->setFolder((string) $objectFolder->getId());
            $this->objectEntityMapper->update($objectEntity);
        }

        $this->logger->info(message: "Created object folder with ID: " . $objectFolder->getId());

        // Transfer ownership to OpenRegister and share with current user if needed.
        $this->transferFolderOwnershipIfNeeded($objectFolder);

        // Share the folder with the currently active user if there is one.
        if ($currentUser !== null && $currentUser->getUID() !== $this->getUser()->getUID()) {
            $this->shareFolderWithUser($objectFolder, $currentUser->getUID());
        }

        return $objectFolder;
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
     * @psalm-return Folder
     * @phpstan-return Folder
     */
    private function getOpenRegisterUserFolder(): Folder
    {
        try {
            $user = $this->getUser();
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            return $userFolder;
        } catch (Exception $e) {
            $this->logger->error(message: "Failed to get OpenRegister user folder: " . $e->getMessage());
            throw new Exception("Cannot access OpenRegister user folder: " . $e->getMessage());
        }
    }//end getOpenRegisterUserFolder()

    /**
     * Get a Node by its ID.
     *
     * @param int $nodeId The ID of the node to retrieve
     *
     * @return Node|null The Node if found, null otherwise
     *
     * @psalm-return Node|null
     * @phpstan-return Node|null
     */
    private function getNodeById(int $nodeId): ?Node
    {
        try {
            $userFolder = $this->getOpenRegisterUserFolder();
            $nodes = $userFolder->getById($nodeId);
            if (empty($nodes) === false) {
                return $nodes[0];
            }
            return null;
        } catch (Exception $e) {
            $this->logger->error(message: "Failed to get node by ID $nodeId: " . $e->getMessage());
            return null;
        }
    }//end getNodeById()

    /**
     * Get files for either a Register or ObjectEntity.
     *
     * This unified method handles file retrieval for both entity types,
     * using the stored folder IDs for stable access.
     *
     * @param Register|ObjectEntity $entity         The entity to get files for
     * @param bool|null            $sharedFilesOnly Whether to return only shared files
     *
     * @return Node[] Array of file nodes
     *
     * @throws Exception If the entity folder cannot be accessed
     *
     * @psalm-return array<int, Node>
     * @phpstan-return array<int, Node>
     */
    public function getFilesForEntity(Register | ObjectEntity $entity, ?bool $sharedFilesOnly = false): array
    {
        $folder = null;

        if ($entity instanceof Register) {
            $folder = $this->getRegisterFolderById($entity);
        } else {
            $folder = $this->getObjectFolder($entity);
        }

        if ($folder === null) {
            throw new Exception("Cannot access folder for entity " . $entity->getId());
        }

        $files = $folder->getDirectoryListing();

        if ($sharedFilesOnly === true) {
            $files = array_filter($files, function ($file) {
                $shares = $this->findShares($file);
                return !empty($shares);
            });
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
     * @psalm-return Folder|null
     * @phpstan-return Folder|null
     */
    private function getRegisterFolderById(Register $register): ?Folder
    {
        $folderProperty = $register->getFolder();

        // Handle legacy cases where folder might be null, empty string, or a string path.
        if ($folderProperty === null || $folderProperty === '' || is_string($folderProperty) === true) {
            $this->logger->info(message: "Register {$register->getId()} has legacy folder property, creating new folder");
            return $this->createRegisterFolderById($register);
        }

        // Try to get folder by ID.
        $folder = $this->getNodeById((int) $folderProperty);

        if ($folder instanceof Folder) {
            return $folder;
        }

        // If stored ID is invalid, recreate the folder.
        $this->logger->warning(message: "Register {$register->getId()} has invalid folder ID, recreating folder");
        return $this->createRegisterFolderById($register);
    }//end getRegisterFolderById()

    /**
     * Get an object folder by its stored ID.
     *
     * @param ObjectEntity|string $objectEntity The object entity to get the folder for
     * @param int|string|null     $registerId   The register of the object to add the file to
     *
     * @return Folder|null The folder Node or null if not found
     *
     * @psalm-return Folder|null
     * @phpstan-return Folder|null
     */
    public function getObjectFolder(ObjectEntity|string $objectEntity, int|string|null $registerId = null): ?Folder
    {
        $folderProperty = null;
        if ($objectEntity instanceof ObjectEntity === true) {
            $folderProperty = $objectEntity->getFolder();
        }

        // Handle legacy cases where folder might be null, empty string, or a non-numeric string path.
        if ($folderProperty === null || $folderProperty === '' || (is_string($folderProperty) === true && is_numeric($folderProperty) === false)) {
            $objectEntityId = $objectEntity;
            if ($objectEntity instanceof ObjectEntity) {
                $objectEntityId = $objectEntity->getId();
            }
            $this->logger->info(message: "Object $objectEntityId has legacy folder property, creating new folder");
            return $this->createObjectFolderById(objectEntity: $objectEntity, registerId: $registerId);
        }

        // Convert string numeric ID to integer.
        $folderId = (int) $folderProperty;

        // Try to get folder by ID.
        $folder = $this->getNodeById($folderId);

        if ($folder instanceof Folder) {
            return $folder;
        }

        // If stored ID is invalid, recreate the folder.
        $this->logger->warning(message: "Object {$objectEntity->getId()} has invalid folder ID, recreating folder");

        return $this->createObjectFolderById(objectEntity: $objectEntity);
    }//end getObjectFolder()

    /**
     * Create a folder path and return the Node.
     *
     * @param string $folderPath The full path to create
     *
     * @return Node|null The created folder Node or null on failure
     *
     * @psalm-return Node|null
     * @phpstan-return Node|null
     */
    private function createFolderPath(string $folderPath): ?Node
    {
        $folderPath = trim(string: $folderPath, characters: '/');

        // Get the open registers user folder.
        $userFolder = $this->getOpenRegisterUserFolder();

        // Check if folder exists and if not create it.
        try {
            // First, check if the root folder exists, and if not, create it and share it with the openregister group.
            try {
                $userFolder->get(self::ROOT_FOLDER);
            } catch (NotFoundException) {
                $rootFolder = $userFolder->newFolder(self::ROOT_FOLDER);

                if ($this->groupManager->groupExists(self::APP_GROUP) === false) {
                    $this->groupManager->createGroup(self::APP_GROUP);
                }

                $this->createShare([
                    'path'        => self::ROOT_FOLDER,
                    'nodeId'      => $rootFolder->getId(),
                    'nodeType'    => $this->getNodeTypeFromFolder($rootFolder),
                    'shareType'   => 1,
                    'permissions' => 31,
                    'sharedWith'  => self::APP_GROUP,
                ]);
            }

            try {
                // Try to get the folder if it already exists.
                $node = $userFolder->get(path: $folderPath);
                $this->logger->info(message: "This folder already exists: $folderPath");
                return $node;
            } catch (NotFoundException) {
                // Folder does not exist, create it.
                $node = $userFolder->newFolder(path: $folderPath);
                $this->logger->info(message: "Created folder: $folderPath");

                // Transfer ownership to OpenRegister and share with current user if needed.
                $this->transferFolderOwnershipIfNeeded($node);

                return $node;
            }
        } catch (NotPermittedException $e) {
            $this->logger->error(message: "Can't create folder $folderPath: ".$e->getMessage());
            throw new Exception("Can't create folder $folderPath");
        }
    }//end createFolderPath()

    /**
     * Returns a link to the given folder path.
     *
     * @param string $folderPath The path to a folder in NextCloud
     *
     * @return string The URL to access the folder through the web interface
     */
    private function getFolderLink(string $folderPath): string
    {
        $folderPath = str_replace('%2F', '/', urlencode($folderPath));
        return $this->getCurrentDomain()."/index.php/apps/files/files?dir=$folderPath";
    }//end getFolderLink()

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
        $baseUrl = $this->urlGenerator->getBaseUrl();
        $trustedDomains = $this->config->getSystemValue('trusted_domains');

        if (isset($trustedDomains[1]) === true) {
            $baseUrl = str_replace(search: 'localhost', replace: $trustedDomains[1], subject: $baseUrl);
        }

        return $baseUrl;
    }//end getCurrentDomain()

    /**
     * Gets or creates the OpenCatalogi user for file operations.
     *
     * @throws Exception If OpenCatalogi user cannot be created
     *
     * @return IUser The OpenCatalogi user
     */
    private function getUser(): IUser
    {
        $openCatalogiUser = $this->userManager->get(self::APP_USER);

        if ($openCatalogiUser === null) {
            // Create OpenCatalogi user if it doesn't exist.
            $password = bin2hex(random_bytes(16)); // Generate random password.
            $openCatalogiUser = $this->userManager->createUser(self::APP_USER, $password);

            if ($openCatalogiUser === false) {
                throw new Exception('Failed to create OpenCatalogi user account.');
            }

            // Add user to OpenCatalogi group.
            $group = $this->groupManager->get(self::APP_GROUP);
            if ($group === null) {
                $group = $this->groupManager->createGroup(self::APP_GROUP);
            }

            // Get the current user from the session.
            $currentUser = $this->userSession->getUser();

            // Add the current user to the group.
            if ($currentUser !== null) {
                $group->addUser($currentUser);
            }

            // Add the OpenCatalogi user to the group.
            $group->addUser($openCatalogiUser);
        }

        return $openCatalogiUser;
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
     * @psalm-return bool
     * @phpstan-return bool
     */
    private function ownFile(Node $file): bool
    {
        try {
            $openRegisterUser = $this->getUser();
            $userId = $openRegisterUser->getUID();
            $fileId = $file->getId();

            $this->logger->info(message: "ownFile: Attempting to set ownership of file {$file->getName()} (ID: $fileId) to user: $userId");

            $result = $this->fileMapper->setFileOwnership($fileId, $userId);

            if ($result === true) {
                $this->logger->info(message: "ownFile: Successfully set ownership of file {$file->getName()} (ID: $fileId) to user: $userId");
            } else {
                $this->logger->warning(message: "ownFile: Failed to set ownership of file {$file->getName()} (ID: $fileId) to user: $userId");
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error(message: "ownFile: Error setting ownership of file {$file->getName()}: " . $e->getMessage());
            throw new Exception("Failed to set file ownership: " . $e->getMessage());
        }
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
     * @psalm-return void
     * @phpstan-return void
     */
    private function checkOwnership(Node $file): void
    {
        try {
            // Try to read the file to trigger any potential access issues.
            if ($file instanceof File) {
                $file->getContent();
            } else {
                // For folders, try to list contents.
                $file->getDirectoryListing();
            }

            // If we get here, the file is accessible.
            $this->logger->debug(message: "checkOwnership: File {$file->getName()} (ID: {$file->getId()}) is accessible, no ownership fix needed");
        } catch (NotFoundException $e) {
            // File exists but we can't access it - likely an ownership issue.
            $this->logger->warning(message: "checkOwnership: File {$file->getName()} (ID: {$file->getId()}) exists but not accessible, checking ownership");

            try {
                $fileOwner = $file->getOwner();
                $openRegisterUser = $this->getUser();

                if ($fileOwner === null || $fileOwner->getUID() !== $openRegisterUser->getUID()) {
                    $this->logger->info(message: "checkOwnership: File {$file->getName()} (ID: {$file->getId()}) has incorrect owner, attempting to fix");

                    // Try to fix the ownership.
                    $ownershipFixed = $this->ownFile($file);

                    if ($ownershipFixed === true) {
                        $this->logger->info(message: "checkOwnership: Successfully fixed ownership for file {$file->getName()} (ID: {$file->getId()})");
                    } else {
                        $this->logger->error(message: "checkOwnership: Failed to fix ownership for file {$file->getName()} (ID: {$file->getId()})");
                        throw new Exception("Failed to fix file ownership for file: " . $file->getName());
                    }
                } else {
                    $this->logger->info(message: "checkOwnership: File {$file->getName()} (ID: {$file->getId()}) already has correct owner, but still not accessible");
                }
            } catch (Exception $ownershipException) {
                $this->logger->error(message: "checkOwnership: Error checking/fixing ownership for file {$file->getName()}: " . $ownershipException->getMessage());
                throw new Exception("Ownership check failed for file: " . $file->getName());
            }
        } catch (NotPermittedException $e) {
            // Permission denied - likely an ownership issue.
            $this->logger->warning(
                message: "checkOwnership: Permission denied for file {$file->getName()} (ID: {$file->getId()}), attempting ownership fix"
            );

            try {
                $ownershipFixed = $this->ownFile($file);

                if ($ownershipFixed === true) {
                    $this->logger->info(message: "checkOwnership: Successfully fixed ownership for file {$file->getName()} (ID: {$file->getId()}) after permission error");
                } else {
                    $this->logger->error(message: "checkOwnership: Failed to fix ownership for file {$file->getName()} (ID: {$file->getId()}) after permission error");
                    throw new Exception("Failed to fix file ownership after permission error: " . $file->getName());
                }
            } catch (Exception $ownershipException) {
                $this->logger->error(message: "checkOwnership: Error fixing ownership after permission error for file {$file->getName()}: " . $ownershipException->getMessage());
                throw new Exception("Ownership fix failed after permission error: " . $file->getName());
            }
        } catch (Exception $e) {
            // Other exceptions - log but don't necessarily fix ownership.
            $this->logger->debug(message: "checkOwnership: Other exception while checking file {$file->getName()}: " . $e->getMessage());
        }
    }//end checkOwnership()

    /**
     * Gets a NextCloud Node object for the given file or folder path.
     *
     * @param string $path The path to get the Node object for
     *
     * @return Node|null The Node object if found, null otherwise
     */
    public function getNode(string $path): ?Node
    {
        try {
            $userFolder = $this->getOpenRegisterUserFolder();
            $node = $userFolder->get(path: $path);

            // @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
            $this->checkOwnership($node);

            return $node;
        } catch (NotFoundException | NotPermittedException $e) {
            $this->logger->error(message: $e->getMessage());
            return null;
        }
    }//end getNode()

    /**
     * Formats a single Node file into a metadata array.
     *
     * See https://nextcloud-server.netlify.app/classes/ocp-files-file for the Nextcloud documentation on the File class.
     * See https://nextcloud-server.netlify.app/classes/ocp-files-node for the Nextcloud documentation on the Node superclass.
     *
     * @param Node $file The Node file to format
     *
     * @return array<string, mixed> The formatted file metadata array
     */
    public function formatFile(Node $file): array
    {
        // @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
        $this->checkOwnership($file);

        // IShare documentation see https://nextcloud-server.netlify.app/classes/ocp-share-ishare.
        $shares = $this->findShares($file);

        // Get base metadata array.
        $metadata = [
            'id'          => $file->getId(),
            'path'        => $file->getPath(),
            'title'       => $file->getName(),
            'accessUrl'   => $this->getAccessUrlFromShares($shares),
            'downloadUrl' => $this->getDownloadUrlFromShares($shares),
            'type'        => $file->getMimetype(),
            'extension'   => $file->getExtension(),
            'size'        => $file->getSize(),
            'hash'        => $file->getEtag(),
            'published'   => $this->getPublishedTimeFromShares($shares),
            'modified'    => (new DateTime())->setTimestamp($file->getUploadTime())->format('c'),
            'labels'      => $this->getFileTags(fileId: $file->getId()),
            'owner'       => $file->getOwner()?->getUID(),
        ];

        // Process labels that contain ':' to add as separate metadata fields.
        // Exclude labels starting with 'object:' as they are internal system labels.
        $remainingLabels = [];
        foreach ($metadata['labels'] as $label) {
            // Skip internal object labels - these should not be exposed in the API.
            if (str_starts_with($label, 'object:') === true) {
                continue;
            }

            if (strpos($label, ':') !== false) {
                list($key, $value) = explode(':', $label, 2);
                $key = trim($key);
                $value = trim($value);

                // Skip if key exists in base metadata.
                if (isset($metadata[$key]) === true) {
                    $remainingLabels[] = $label;
                    continue;
                }

                // If key already exists as array, append value.
                if (isset($metadata[$key]) && is_array($metadata[$key]) === true) {
                    $metadata[$key][] = $value;
                } else if (isset($metadata[$key])) {
                    // If key exists but not as array, convert to array with both values.
                    $metadata[$key] = [$metadata[$key], $value];
                } else {
                    // If key doesn't exist, create new entry.
                    $metadata[$key] = $value;
                }
            } else {
                $remainingLabels[] = $label;
            }
        }

        // Update labels array to only contain non-processed, non-internal labels.
        $metadata['labels'] = $remainingLabels;

        return $metadata;
    }//end formatFile()

    /**
     * Formats an array of Node files into an array of metadata arrays.
     *
     * See https://nextcloud-server.netlify.app/classes/ocp-files-file for the Nextcloud documentation on the File class.
     * See https://nextcloud-server.netlify.app/classes/ocp-files-node for the Nextcloud documentation on the Node superclass.
     *
     * @param Node[] $files         Array of Node files to format
     * @param array  $requestParams Optional request parameters including filters:
     *     _hasLabels: bool,
     *     _noLabels: bool,
     *     labels: string|array,
     *     extension: string,
     *     extensions: array,
     *     minSize: int,
     *     maxSize: int,
     *     title: string,
     *     search: string,
     *     limit: int,
     *     offset: int,
     *     order: string|array,
     *     page: int,
     *     extend: string|array
     *
     * @throws InvalidPathException
     * @throws NotFoundException
     *
     * @return array{
     *     results: array<int, array<string, mixed>>,
     *     total: int,
     *     page: int,
     *     pages: int,
     *     limit: int,
     *     offset: int
     * } Array of formatted file metadata arrays with pagination information
     */
    public function formatFiles(array $files, ?array $requestParams=[]): array
    {
        // Extract pagination parameters.
        $limit = $requestParams['limit'] ?? $requestParams['_limit'] ?? 20;
        $offset = $requestParams['offset'] ?? $requestParams['_offset'] ?? 0;
        $order = $requestParams['order'] ?? $requestParams['_order'] ?? [];
        $extend = $requestParams['extend'] ?? $requestParams['_extend'] ?? null;
        $page = $requestParams['page'] ?? $requestParams['_page'] ?? null;
        $search = $requestParams['_search'] ?? null;

        if ($page !== null && isset($limit) === true) {
            $page = (int) $page;
            $offset = $limit * ($page - 1);
        }

        // Ensure order and extend are arrays.
        if (is_string($order) === true) {
            $order = array_map('trim', explode(',', $order));
        }
        if (is_string($extend) === true) {
            $extend = array_map('trim', explode(',', $extend));
        }

        // Extract filter parameters.
        $filters = $this->extractFilterParameters($requestParams);

        // Format ALL files first (before filtering and pagination).
        $formattedFiles = [];
        foreach ($files as $file) {
            $formattedFiles[] = $this->formatFile($file);
        }

        // Apply filters to formatted files.
        $filteredFiles = $this->applyFileFilters($formattedFiles, $filters);

        // Count total after filtering but before pagination.
        $totalFiltered = count($filteredFiles);

        // Apply pagination to filtered results.
        $paginatedFiles = array_slice($filteredFiles, $offset, $limit);

        // Calculate pages based on filtered total.
        $pages = 1;
        if ($limit !== null) {
            $pages = ceil($totalFiltered / $limit);
        }

        return [
            'results' => $paginatedFiles,
            'total'   => $totalFiltered,
            'page'    => $page ?? 1,
            'pages'   => $pages,
            'limit'   => $limit,
            'offset'  => $offset,
        ];
    }//end formatFiles()

    /**
     * Extract and normalize filter parameters from request parameters.
     *
     * This method extracts filter-specific parameters from the request, excluding
     * pagination and other control parameters. It normalizes string parameters
     * to arrays where appropriate for consistent filtering logic.
     *
     * @param array $requestParams The request parameters array
     *
     * @return array{
     *     _hasLabels?: bool,
     *     _noLabels?: bool,
     *     labels?: array<string>,
     *     extension?: string,
     *     extensions?: array<string>,
     *     minSize?: int,
     *     maxSize?: int,
     *     title?: string,
     *     search?: string
     * } Normalized filter parameters
     *
     * @psalm-param array<string, mixed> $requestParams
     * @phpstan-param array<string, mixed> $requestParams
     */
    private function extractFilterParameters(array $requestParams): array
    {
        $filters = [];

        // Labels filtering (business logic filters prefixed with underscore).
        if (isset($requestParams['_hasLabels']) === true) {
            $filters['_hasLabels'] = (bool) $requestParams['_hasLabels'];
        }

        if (isset($requestParams['_noLabels']) === true) {
            $filters['_noLabels'] = (bool) $requestParams['_noLabels'];
        }

        if (isset($requestParams['labels']) === true) {
            $labels = $requestParams['labels'];
            if (is_string($labels) === true) {
                $filters['labels'] = array_map('trim', explode(',', $labels));
            } elseif (is_array($labels) === true) {
                $filters['labels'] = $labels;
            }
        }

        // Extension filtering.
        if (isset($requestParams['extension'])) {
            $filters['extension'] = trim($requestParams['extension']);
        }

        if (isset($requestParams['extensions'])) {
            $extensions = $requestParams['extensions'];
            if (is_string($extensions)) {
                $filters['extensions'] = array_map('trim', explode(',', $extensions));
            } else if (is_array($extensions)) {
                $filters['extensions'] = $extensions;
            }
        }

        // Size filtering.
        if (isset($requestParams['minSize'])) {
            $filters['minSize'] = (int) $requestParams['minSize'];
        }

        if (isset($requestParams['maxSize'])) {
            $filters['maxSize'] = (int) $requestParams['maxSize'];
        }

        // Title/search filtering.
        if (isset($requestParams['title'])) {
            $filters['title'] = trim($requestParams['title']);
        }

        if (isset($requestParams['search']) || isset($requestParams['_search'])) {
            $filters['search'] = trim($requestParams['search'] ?? $requestParams['_search']);
        }

        return $filters;
    }//end extractFilterParameters()

    /**
     * Apply filters to an array of formatted file metadata.
     *
     * This method applies various filters to the formatted file metadata based on
     * the provided filter parameters. Filters are applied in sequence and files
     * must match ALL specified criteria to be included in the results.
     *
     * @param array $formattedFiles Array of formatted file metadata
     * @param array $filters        Filter parameters to apply
     *
     * @return array Filtered array of file metadata
     *
     * @psalm-param array<int, array<string, mixed>> $formattedFiles
     * @phpstan-param array<int, array<string, mixed>> $formattedFiles
     * @psalm-param array<string, mixed> $filters
     * @phpstan-param array<string, mixed> $filters
     * @psalm-return array<int, array<string, mixed>>
     * @phpstan-return array<int, array<string, mixed>>
     */
    private function applyFileFilters(array $formattedFiles, array $filters): array
    {
        if (empty($filters)) {
            return $formattedFiles;
        }

        return array_filter($formattedFiles, function (array $file) use ($filters): bool {
            // Filter by label presence (business logic filter).
            if (isset($filters['_hasLabels'])) {
                $hasLabels = !empty($file['labels']);
                if ($filters['_hasLabels'] !== $hasLabels) {
                    return false;
                }
            }

            // Filter for files without labels (business logic filter).
            if (isset($filters['_noLabels']) && $filters['_noLabels'] === true) {
                $hasLabels = !empty($file['labels']);
                if ($hasLabels === true) {
                    return false;
                }
            }

            // Filter by specific labels.
            if (isset($filters['labels']) && !empty($filters['labels'])) {
                $fileLabels = $file['labels'] ?? [];
                $hasMatchingLabel = false;

                foreach ($filters['labels'] as $requiredLabel) {
                    if (in_array($requiredLabel, $fileLabels, true)) {
                        $hasMatchingLabel = true;
                        break;
                    }
                }

                if ($hasMatchingLabel === false) {
                    return false;
                }
            }

            // Filter by single extension.
            if (isset($filters['extension'])) {
                $fileExtension = $file['extension'] ?? '';
                if (strcasecmp($fileExtension, $filters['extension']) !== 0) {
                    return false;
                }
            }

            // Filter by multiple extensions.
            if (isset($filters['extensions']) && !empty($filters['extensions'])) {
                $fileExtension = $file['extension'] ?? '';
                $hasMatchingExtension = false;

                foreach ($filters['extensions'] as $allowedExtension) {
                    if (strcasecmp($fileExtension, $allowedExtension) === 0) {
                        $hasMatchingExtension = true;
                        break;
                    }
                }

                if ($hasMatchingExtension === false) {
                    return false;
                }
            }

            // Filter by file size range.
            if (isset($filters['minSize'])) {
                $fileSize = $file['size'] ?? 0;
                if ($fileSize < $filters['minSize']) {
                    return false;
                }
            }

            if (isset($filters['maxSize'])) {
                $fileSize = $file['size'] ?? 0;
                if ($fileSize > $filters['maxSize']) {
                    return false;
                }
            }

            // Filter by title/filename content.
            if (isset($filters['title']) && !empty($filters['title'])) {
                $fileTitle = $file['title'] ?? '';
                if (stripos($fileTitle, $filters['title']) === false) {
                    return false;
                }
            }

            // Filter by search term (searches in title).
            if (isset($filters['search']) && !empty($filters['search'])) {
                $fileTitle = $file['title'] ?? '';
                if (stripos($fileTitle, $filters['search']) === false) {
                    return false;
                }
            }

            // File passed all filters.
            return true;
        });
    }//end applyFileFilters()

    /**
     * Get the tags associated with a file.
     *
     * @param string $fileId The ID of the file
     *
     * @return array<int, string> The list of tags associated with the file
     */
    private function getFileTags(string $fileId): array
    {
        // @TODO: This method takes a file ID instead of a Node, so we can't check ownership here.
        // @TODO: The ownership check should be done on the Node before calling this method.

        $tagIds = $this->systemTagMapper->getTagIdsForObjects(
            objIds: [$fileId],
            objectType: $this::FILE_TAG_TYPE
        );
        if (isset($tagIds[$fileId]) === false || empty($tagIds[$fileId]) === true) {
            return [];
        }

        $tags = $this->systemTagManager->getTagsByIds(tagIds: $tagIds[$fileId]);

        $tagNames = array_map(static function ($tag) {
            return $tag->getName();
        }, $tags);

        return array_values($tagNames);
    }//end getFileTags()

    /**
     * Finds shares associated with a file or folder.
     *
     * @param Node $file      The Node file or folder to find shares for
     * @param int  $shareType The type of share to look for (default: 3 for public link)
     *
     * @return IShare[] Array of shares associated with the file
     */
    public function findShares(Node $file, int $shareType=3): array
    {
        // @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
        $this->checkOwnership($file);

        // Use the OpenRegister system user instead of current user session.
        // This ensures we can find shares created by the OpenRegister system user.
        $userId = $this->getUser()->getUID();

        return $this->shareManager->getSharesBy(userId: $userId, shareType: $shareType, path: $file, reshares: true);
    }//end findShares()

    /**
     * Try to find a IShare object with given $path & $shareType.
     *
     * @param string   $path      The path to a file we are trying to find a IShare object for
     * @param int|null $shareType The shareType of the share we are trying to find (default: 3 for public link)
     *
     * @return IShare|null An IShare object if found, null otherwise
     */
    public function findShare(string $path, ?int $shareType=3): ?IShare
    {
        $path = trim(string: $path, characters: '/');
        // Use the OpenRegister system user for consistency.
        $userId = $this->getUser()->getUID();

        try {
            $userFolder = $this->getOpenRegisterUserFolder();
        } catch (Exception) {
            $this->logger->error(message: "Can't find share for $path because OpenRegister user folder couldn't be found.");
            return null;
        }

        try {
            // Note: if we ever want to find shares for folders instead of files, this should work for folders as well?
            $file = $userFolder->get(path: $path);
        } catch (NotFoundException $e) {
            $this->logger->error(message: "Can't find share for $path because file doesn't exist.");
            return null;
        }

        if ($file instanceof File) {
            // @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
            $this->checkOwnership($file);

            $shares = $this->shareManager->getSharesBy(userId: $userId, shareType: $shareType, path: $file, reshares: true);
            if (count($shares) > 0) {
                return $shares[0];
            }
        }

        return null;
    }//end findShare()

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
     */
    private function createShare(array $shareData): IShare
    {
        $userId = $this->getUser()->getUID();

        // Create a new share.
        $share = $this->shareManager->newShare();
        $share->setTarget(target: '/'.$shareData['path']);
        if (empty($shareData['file']) === false) {
            $share->setNodeId(fileId: $shareData['file']->getId());
        }
        if (empty($shareData['nodeId']) === false) {
            $share->setNodeId(fileId: $shareData['nodeId']);
        }
        $share->setNodeType(type: $shareData['nodeType'] ?? 'file');
        $share->setShareType(shareType: $shareData['shareType']);
        if ($shareData['permissions'] !== null) {
            $share->setPermissions(permissions: $shareData['permissions']);
        }
        $share->setSharedBy(sharedBy: $userId);
        $share->setShareOwner(shareOwner: $userId);
        $share->setShareTime(shareTime: new DateTime());
        if (empty($shareData['sharedWith']) === false) {
            $share->setSharedWith(sharedWith: $shareData['sharedWith']);
        }
        $share->setStatus(status: $share::STATUS_ACCEPTED);

        return $this->shareManager->createShare(share: $share);
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
     * @psalm-return IShare|null
     * @phpstan-return IShare|null
     */
    private function shareFolderWithUser(Node $folder, string $userId, int $permissions = 31): ?IShare
    {
        try {
            // Check if user exists.
            if ($this->userManager->userExists($userId) === false) {
                $this->logger->warning(message: "Cannot share folder with user '$userId' - user does not exist");
                return null;
            }

            // Create the share.
            $share = $this->createShare([
                'path'        => ltrim($folder->getPath(), '/'),
                'nodeId'      => $folder->getId(),
                'nodeType'    => 'folder',
                'shareType'   => 0, // User share
                'permissions' => $permissions,
                'sharedWith'  => $userId,
            ]);

            $this->logger->info(message: "Successfully shared folder '{$folder->getName()}' with user '$userId'");
            return $share;
        } catch (Exception $e) {
            $this->logger->error(message: "Failed to share folder '{$folder->getName()}' with user '$userId': " . $e->getMessage());
            return null;
        }
    }//end shareFolderWithUser()

    /**
     * Get the currently active user (not the OpenRegister system user).
     *
     * This method returns the user who is currently logged in and making the request,
     * which is different from the OpenRegister system user used for file operations.
     *
     * @return IUser|null The currently active user or null if no user is logged in
     *
     * @psalm-return IUser|null
     * @phpstan-return IUser|null
     */
    private function getCurrentUser(): ?IUser
    {
        return $this->userSession->getUser();
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
        try {
            // Get current user.
            $currentUser = $this->getCurrentUser();
            if ($currentUser === null) {
                // No user logged in, nothing to do.
                return;
            }

            $currentUserId = $currentUser->getUID();

            // Get OpenRegister system user.
            $openRegisterUser = $this->getUser();
            $openRegisterUserId = $openRegisterUser->getUID();

            // If current user is already the OpenRegister user, nothing to do.
            if ($currentUserId === $openRegisterUserId) {
                return;
            }

            // Get file owner.
            $fileOwner = $file->getOwner();
            if ($fileOwner === null) {
                $this->logger->warning(message: "File {$file->getName()} has no owner, skipping ownership transfer");
                return;
            }

            $fileOwnerId = $fileOwner->getUID();

            // Check if current user is the owner and is not OpenRegister.
            if ($fileOwnerId === $currentUserId && $currentUserId !== $openRegisterUserId) {
                $this->logger->info(message: "Transferring ownership of file {$file->getName()} from {$currentUserId} to {$openRegisterUserId}");

                // Change file ownership to OpenRegister user.
                $file->getStorage()->chown($file->getInternalPath(), $openRegisterUserId);

                // Create a share with the current user to maintain access.
                $this->shareFileWithUser($file, $currentUserId);

                $this->logger->info(message: "Successfully transferred ownership and shared file {$file->getName()} with {$currentUserId}");
            }
        } catch (\Exception $e) {
            $this->logger->error(message: "Failed to transfer file ownership for {$file->getName()}: " . $e->getMessage());
            // Don't throw the exception to avoid breaking file operations.
            // The file operation should succeed even if ownership transfer fails.
        }
    }//end transferFileOwnershipIfNeeded()

    /**
     * Share a file with a specific user
     *
     * @param File   $file   The file to share
     * @param string $userId The user ID to share with
     * @param int    $permissions The permissions to grant (default: full permissions)
     *
     * @return void
     *
     * @throws \Exception If sharing fails
     */
    private function shareFileWithUser(File $file, string $userId, int $permissions = 31): void
    {
        try {
            // Check if a share already exists with this user.
            $existingShares = $this->shareManager->getSharesBy(
                sharedBy: $this->getUser()->getUID(),
                shareType: \OCP\Share\IShare::TYPE_USER,
                node: $file
            );

            foreach ($existingShares as $share) {
                if ($share->getSharedWith() === $userId) {
                    $this->logger->info(message: "Share already exists for file {$file->getName()} with user {$userId}");
                    return;
                }
            }

            // Create new share.
            $share = $this->shareManager->newShare();
            $share->setNode($file);
            $share->setShareType(\OCP\Share\IShare::TYPE_USER);
            $share->setSharedWith($userId);
            $share->setSharedBy($this->getUser()->getUID());
            $share->setPermissions($permissions);

            $this->shareManager->createShare($share);

            $this->logger->info(message: "Created share for file {$file->getName()} with user {$userId}");
        } catch (\Exception $e) {
            $this->logger->error(message: "Failed to share file {$file->getName()} with user {$userId}: " . $e->getMessage());
            throw $e;
        }
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
        try {
            // Get current user.
            $currentUser = $this->getCurrentUser();
            if ($currentUser === null) {
                // No user logged in, nothing to do.
                return;
            }

            $currentUserId = $currentUser->getUID();

            // Get OpenRegister system user.
            $openRegisterUser = $this->getUser();
            $openRegisterUserId = $openRegisterUser->getUID();

            // If current user is already the OpenRegister user, nothing to do.
            if ($currentUserId === $openRegisterUserId) {
                return;
            }

            // Get folder owner.
            $folderOwner = $folder->getOwner();
            if ($folderOwner === null) {
                $this->logger->warning(message: "Folder {$folder->getName()} has no owner, skipping ownership transfer");
                return;
            }

            $folderOwnerId = $folderOwner->getUID();

            // Check if current user is the owner and is not OpenRegister.
            if ($folderOwnerId === $currentUserId && $currentUserId !== $openRegisterUserId) {
                $this->logger->info(message: "Transferring ownership of folder {$folder->getName()} from {$currentUserId} to {$openRegisterUserId}");

                // Change folder ownership to OpenRegister user.
                $folder->getStorage()->chown($folder->getInternalPath(), $openRegisterUserId);

                // Create a share with the current user to maintain access.
                $this->shareFolderWithUser($folder, $currentUserId);

                $this->logger->info(message: "Successfully transferred ownership and shared folder {$folder->getName()} with {$currentUserId}");
            }
        } catch (\Exception $e) {
            $this->logger->error(message: "Failed to transfer folder ownership for {$folder->getName()}: " . $e->getMessage());
            // Don't throw the exception to avoid breaking folder operations.
            // The folder operation should succeed even if ownership transfer fails.
        }
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

        $userId = $this->getUser()->getUID();

        try {
            $userFolder = $this->getOpenRegisterUserFolder();
        } catch (Exception) {
            $this->logger->error(message: "Can't create share link for $path because OpenRegister user folder couldn't be found.");
            return "OpenRegister user folder couldn't be found.";
        }

        try {
            $file = $this->rootFolder->get($path);
            // $file = $userFolder->get(path: $path);
        } catch (NotFoundException $e) {
            $this->logger->error(message: "Can't create share link for $path because file doesn't exist.");
            return 'File not found at '.$path;
        }

        // @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
        $this->checkOwnership($file);

        try {
            $share = $this->createShare([
                'path'        => $path,
                'file'        => $file,
                'shareType'   => $shareType,
                'permissions' => $permissions,
            ]);
            return $this->getShareLink($share);
        } catch (Exception $exception) {
            $this->logger->error(message: "Can't create share link for $path: ".$exception->getMessage());
            throw new Exception('Can\'t create share link.');
        }
    }//end createShareLink()

    /**
     * Deletes all share links for a file or folder.
     *
     * @param Node $file The file or folder whose shares should be deleted
     *
     * @throws Exception If the shares cannot be deleted
     *
     * @return Node The file with shares deleted
     */
    public function deleteShareLinks(Node $file): Node
    {
        // @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
        $this->checkOwnership($file);

        // IShare documentation see https://nextcloud-server.netlify.app/classes/ocp-share-ishare.
        $shares = $this->findShares($file);

        foreach ($shares as $share) {
            try {
                $this->shareManager->deleteShare($share);
                $this->logger->info(message: "Successfully deleted share for path: {$share->getNode()->getPath()}.");
            } catch (Exception $e) {
                $this->logger->error(message: "Failed to delete share for path {$share->getNode()->getPath()}: ".$e->getMessage());
                throw new Exception("Failed to delete share for path {$share->getNode()->getPath()}: ".$e->getMessage());
            }
        }

        return $file;
    }//end deleteShareLinks()

    /**
     * Creates a new folder in NextCloud, unless it already exists.
     *
     * @param string $folderPath Path (from root) to where you want to create a folder, include the name of the folder
     *
     * @throws Exception If creating the folder is not permitted
     *
     * @return Node|null The Node object for the folder (existing or newly created), or null on failure
     */
    public function createFolder(string $folderPath): ?Node
    {
        $folderPath = trim(string: $folderPath, characters: '/');

        // Get the current user.
        $userFolder = $this->getOpenRegisterUserFolder();

        // Check if folder exists and if not create it.
        try {
            // First, check if the root folder exists, and if not, create it and share it with the openregister group.
            try {
                $userFolder->get(self::ROOT_FOLDER);
            } catch (NotFoundException) {
                $rootFolder = $userFolder->newFolder(self::ROOT_FOLDER);

                if ($this->groupManager->groupExists(self::APP_GROUP) === false) {
                    $this->groupManager->createGroup(self::APP_GROUP);
                }

                $this->createShare([
                    'path'        => self::ROOT_FOLDER,
                    'nodeId'      => $rootFolder->getId(),
                    'nodeType'    => $this->getNodeTypeFromFolder($rootFolder),
                    'shareType'   => 1,
                    'permissions' => 31,
                    'sharedWith'  => self::APP_GROUP,
                ]);
            }

            try {
                // Try to get the folder if it already exists.
                $node = $userFolder->get(path: $folderPath);
                $this->logger->info(message: "This folder already exists: $folderPath");
                return $node;
            } catch (NotFoundException) {
                // Folder does not exist, create it.
                $node = $userFolder->newFolder(path: $folderPath);
                $this->logger->info(message: "Created folder: $folderPath");

                // Transfer ownership to OpenRegister and share with current user if needed.
                $this->transferFolderOwnershipIfNeeded($node);

                return $node;
            }
        } catch (NotPermittedException $e) {
            $this->logger->error(message: "Can't create folder $folderPath: ".$e->getMessage());
            throw new Exception("Can't create folder $folderPath");
        }
    }//end createFolder()

    /**
     * Overwrites an existing file in NextCloud.
     *
     * This method updates the content and/or tags of an existing file. When updating tags,
     * it preserves any existing 'object:' tags while replacing other user-defined tags.
     *
     * @param string|int         $filePath The path (from root) where to save the file, including filename and extension, or file ID
     * @param mixed              $content  Optional content of the file. If null, only metadata like tags will be updated
     * @param array              $tags     Optional array of tags to attach to the file (excluding object tags which are preserved)
     * @param ObjectEntity|null  $object   Optional object entity to search in object folder first
     *
     * @throws Exception If the file doesn't exist or if file operations fail
     *
     * @return File The updated file
     *
     * @phpstan-param array<int, string> $tags
     * @psalm-param array<int, string> $tags
     */
    public function updateFile(string|int $filePath, mixed $content=null, array $tags=[], ?ObjectEntity $object = null): File
    {
        // Debug logging - original file path.
        $originalFilePath = $filePath;
        $this->logger->info(message: "updateFile: Original file path received: '$originalFilePath'");

        $file = null;

        // If $filePath is an integer (file ID), try to find the file directly by ID.
        if (is_int($filePath)) {
            $this->logger->info(message: "updateFile: File ID provided: $filePath");

            if ($object !== null) {
                // Try to find the file in the object's folder by ID.
                $file = $this->getFile($object, $filePath);
                if ($file !== null) {
                    $this->logger->info(message: "updateFile: Found file by ID in object folder: " . $file->getName() . " (ID: " . $file->getId() . ")");
                }
            }

            if ($file === null) {
                // Try to find the file in the user folder by ID.
                try {
                    $userFolder = $this->getOpenRegisterUserFolder();
                    $nodes = $userFolder->getById($filePath);
                    if (!empty($nodes)) {
                        $file = $nodes[0];
                        $this->logger->info(message: "updateFile: Found file by ID in user folder: " . $file->getName() . " (ID: " . $file->getId() . ")");
                    } else {
                        $this->logger->error(message: "updateFile: No file found with ID: $filePath");
                        throw new Exception("File with ID $filePath does not exist");
                    }
                } catch (Exception $e) {
                    $this->logger->error(message: "updateFile: Error finding file by ID $filePath: " . $e->getMessage());
                    throw new Exception("File with ID $filePath does not exist: " . $e->getMessage());
                }
            }
        } else {
            // Handle string file paths (existing logic).
            // Clean file path and extract filename using utility method.
            $pathInfo = $this->extractFileNameFromPath((string)$filePath);
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
                $objectFolder = $this->getObjectFolder($object);

                if ($objectFolder !== null) {
                    $this->logger->info(message: "updateFile: Object folder path: " . $objectFolder->getPath());
                    $this->logger->info(message: "updateFile: Object folder ID: " . $objectFolder->getId());

                    // List all files in the object folder for debugging.
                    try {
                        $folderFiles = $objectFolder->getDirectoryListing();
                        $fileNames = array_map(fn($f) => $f->getName(), $folderFiles);
                        $this->logger->info(message: "updateFile: Files in object folder: " . implode(', ', $fileNames));
                    } catch (Exception $e) {
                        $this->logger->warning(message: "updateFile: Could not list folder contents: " . $e->getMessage());
                    }

                    // Try to get the file from object folder using just the filename.
                    try {
                        $file = $objectFolder->get($fileName);
                        $this->logger->info(message: "updateFile: Found file in object folder: " . $file->getName() . " (ID: " . $file->getId() . ")");
                    } catch (NotFoundException) {
                        $this->logger->warning(message: "updateFile: File '$fileName' not found in object folder.");

                        // Also try with the full path in case it's nested.
                        try {
                            $file = $objectFolder->get($filePath);
                            $this->logger->info(message: "updateFile: Found file using full path in object folder: " . $file->getName());
                        } catch (NotFoundException) {
                            $this->logger->warning(message: "updateFile: File '$filePath' also not found with full path in object folder.");
                        }
                    }
                } else {
                    $this->logger->warning(message: "updateFile: Could not get object folder for object ID: " . $object->getId());
                }
            } catch (Exception $e) {
                $this->logger->error(message: "updateFile: Error accessing object folder: " . $e->getMessage());
            }
        } else {
            $this->logger->info(message: "updateFile: No object provided, will search in user folder");
        }

        // If object wasn't provided or file wasn't found in object folder, try user folder.
        if ($file === null) {
            $this->logger->info(message: "updateFile: Trying user folder approach with path: '$filePath'");
            try {
                $userFolder = $this->getOpenRegisterUserFolder();
                $file = $userFolder->get(path: $filePath);
                $this->logger->info(message: "updateFile: Found file in user folder at path: $filePath (ID: " . $file->getId() . ")");
            } catch (NotFoundException $e) {
                $this->logger->error(message: "updateFile: File $filePath not found in user folder either.");

                // Try to find the file by ID if the path starts with a number.
                if (preg_match('/^(\d+)\//', $filePath, $matches)) {
                    $fileId = (int) $matches[1];
                    $this->logger->info(message: "updateFile: Attempting to find file by ID: $fileId");

                    try {
                        $nodes = $userFolder->getById($fileId);
                        if (!empty($nodes)) {
                            $file = $nodes[0];
                            $this->logger->info(message: "updateFile: Found file by ID $fileId: " . $file->getName() . " at path: " . $file->getPath());
                        } else {
                            $this->logger->warning(message: "updateFile: No file found with ID: $fileId");
                        }
                    } catch (Exception $e) {
                        $this->logger->error(message: "updateFile: Error finding file by ID $fileId: " . $e->getMessage());
                    }
                }

                if ($file === null) {
                    throw new Exception("File $filePath does not exist");
                }
            } catch (NotPermittedException | InvalidPathException $e) {
                $this->logger->error(message: "updateFile: Can't access file $filePath: ".$e->getMessage());
                throw new Exception("Can't access file $filePath: ".$e->getMessage());
            }
        }
        }

        // Update the file content if provided and content is not equal to the current content.
        if ($content !== null && $file->hash(type: 'md5') !== md5(string: $content)) {
                try {
					// Check if the content is base64 encoded and decode it if necessary.
					if (base64_encode(base64_decode($content, true)) === $content) {
						$content = base64_decode($content);
					}

                // Security: Block executable files.
                $this->blockExecutableFile($file->getName(), $content);

                // @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
                $this->checkOwnership($file);

                $file->putContent(data: $content);
                $this->logger->info(message: "updateFile: Successfully updated file content: " . $file->getName());

                // Transfer ownership to OpenRegister and share with current user if needed.
                $this->transferFileOwnershipIfNeeded($file);
            } catch (NotPermittedException $e) {
                $this->logger->error(message: "updateFile: Can't write content to file: ".$e->getMessage());
                throw new Exception("Can't write content to file: ".$e->getMessage());
            }
        }

        // Update tags if provided.
        if (empty($tags) === false) {
            // Get existing object tags to preserve them.
            $existingTags = $this->getFileTags(fileId: $file->getId());
            $objectTags = array_filter($existingTags, static function (string $tag): bool {
                return str_starts_with($tag, 'object:');
            });

            // Combine object tags with new tags, avoiding duplicates.
            $allTags = array_unique(array_merge($objectTags, $tags));

            $this->attachTagsToFile(fileId: $file->getId(), tags: $allTags);
            $this->logger->info(message: "updateFile: Successfully updated file tags: " . $file->getName());
        }

        return $file;
    }//end updateFile()

    /**
     * Constructs a file path for a specific object.
     *
     * @param string|ObjectEntity $object   The object entity or object UUID
     * @param string             $filePath The relative file path within the object folder
     *
     * @return string The complete file path
     */
    public function getObjectFilePath(string | ObjectEntity $object, string $filePath): string
    {
        return $object->getFolder().'/'.$filePath;
    }//end getObjectFilePath()

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
     * @return bool True if successful, false if the file didn't exist
     *
     * @psalm-param Node|string|int $file
     * @phpstan-param Node|string|int $file
     * @psalm-param ObjectEntity|null $object
     * @phpstan-param ObjectEntity|null $object
     */
    public function deleteFile(Node | string | int $file, ?ObjectEntity $object = null): bool
    {
        if ($file instanceof Node === false) {
            $fileName = (string) $file;
            $file = $this->getFile($object, $file);
        }

        if($file === null) {
            $this->logger->error(message: 'File '.$fileName.' not found for object '.($object?->getId() ?? 'unknown'));
            return false;
        }

        if ($file instanceof File === false) {
            $this->logger->error(message: 'File is not a File instance, it\'s a: ' . get_class($file));
            return false;
        }

        // @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
        $this->checkOwnership($file);

        try {
            $file->delete();
        } catch (\Exception $e) {
            $this->logger->error(message: 'Failed to delete file: ' . $e->getMessage());
            return false;
        }

        return true;
    }//end deleteFile()

    /**
     * Update an object's files array by removing a deleted file reference.
     *
     * This method searches through the object's files array and removes any entries
     * that reference the deleted file path. It handles both absolute and relative paths.
     *
     * @param ObjectEntity $object           The object entity to update
     * @param string       $deletedFilePath  The path of the deleted file
     *
     * @return void
     *
     * @throws Exception If updating the object fails
     *
     * @psalm-return void
     * @phpstan-return void
     */
    private function updateObjectFilesArray(ObjectEntity $object, string $deletedFilePath): void
    {
        try {
            // Get the current files array from the object.
            $objectFiles = $object->getFiles() ?? [];

            if (empty($objectFiles)) {
                $this->logger->debug(message: "Object {$object->getId()} has no files array to update");
                return;
            }

            $originalCount = count($objectFiles);
            $updatedFiles = [];

            // Extract just the filename from the deleted file path for comparison.
            $deletedFileName = basename($deletedFilePath);

            // Filter out any files that match the deleted file.
            foreach ($objectFiles as $fileEntry) {
                $shouldKeep = true;

                // Handle different possible structures of file entries.
                if (is_array($fileEntry)) {
                    // Check various possible path fields in the file entry.
                    $pathFields = ['path', 'title', 'name', 'filename', 'accessUrl', 'downloadUrl'];

                    foreach ($pathFields as $field) {
                        if (isset($fileEntry[$field])) {
                            $entryPath = $fileEntry[$field];
                            $entryFileName = basename($entryPath);

                            // Check if this entry references the deleted file.
                            if ($entryPath === $deletedFilePath ||
                                $entryFileName === $deletedFileName ||
                                str_ends_with($entryPath, $deletedFilePath)) {
                                $shouldKeep = false;
                                $this->logger->info(message: "Removing file entry from object {$object->getId()}: $entryPath");
                                break;
                            }
                        }
                    }
                } else if (is_string($fileEntry)) {
                    // Handle simple string entries.
                    $entryFileName = basename($fileEntry);
                    if ($fileEntry === $deletedFilePath ||
                        $entryFileName === $deletedFileName ||
                        str_ends_with($fileEntry, $deletedFilePath)) {
                        $shouldKeep = false;
                        $this->logger->info(message: "Removing file entry from object {$object->getId()}: $fileEntry");
                    }
                }

                if ($shouldKeep === true) {
                    $updatedFiles[] = $fileEntry;
                }
            }

            // Only update the object if files were actually removed.
            if (count($updatedFiles) < $originalCount) {
                $removedCount = $originalCount - count($updatedFiles);
                $this->logger->info(message: "Removed $removedCount file reference(s) from object {$object->getId()}");

//                $object->setFiles($updatedFiles);
//                $this->objectEntityMapper->update($object);
            } else {
                $this->logger->debug(message: "No file references found to remove from object {$object->getId()}");
            }

        } catch (Exception $e) {
            $this->logger->error(message: "Failed to update object files array for object {$object->getId()}: " . $e->getMessage());
            throw new Exception("Failed to update object files array: " . $e->getMessage());
        }
    }//end updateObjectFilesArray()

    /**
     * Attach tags to a file.
     *
     * @param string $fileId The file ID
     * @param array  $tags   Tags to associate with the file
     *
     * @return void
     */
    private function attachTagsToFile(string $fileId, array $tags=[]): void
    {
        // Get all existing tags for the file and convert to array of just the IDs.
        $oldTagIds = $this->systemTagMapper->getTagIdsForObjects(objIds: [$fileId], objectType: $this::FILE_TAG_TYPE);
        if (isset($oldTagIds[$fileId]) === false || empty($oldTagIds[$fileId]) === true) {
            $oldTagIds = [];
        } else {
            $oldTagIds = $oldTagIds[$fileId];
        }

        // Create new tags if they don't exist.
        $newTagIds = [];
        foreach ($tags as $tagName) {
            // Skip empty tag names.
            if (empty($tagName)) {
                continue;
            }

            try {
				$tag = $this->systemTagManager->getTag(tagName: $tagName, userVisible: true, userAssignable: true);
			} catch (Exception $exception) {
                $tag = $this->systemTagManager->createTag(tagName: $tagName, userVisible: true, userAssignable: true);
            }

            $newTagIds[] = $tag->getId();
        }

        // Only assign new tags if we have any.
        if (empty($newTagIds) === false) {
				$newTagIds = array_unique($newTagIds);
				$this->systemTagMapper->assignTags(objId: $fileId, objectType: $this::FILE_TAG_TYPE, tagIds: $newTagIds);
        }

        // Find tags that exist in old tags but not in new tags (tags to be removed).
        $tagsToRemove = array_diff($oldTagIds ?? [], $newTagIds ?? []);
        // Remove any keys with value 0 from tags to remove array.
        $tagsToRemove = array_filter($tagsToRemove, function ($value) {
            return $value !== 0;
        });

        // Remove old tags that aren't in new tags.
        if (empty($tagsToRemove) === false) {
            $this->systemTagMapper->unassignTags(objId: $fileId, objectType: $this::FILE_TAG_TYPE, tagIds: $tagsToRemove);
        }

        // @todo Let's check if there are now existing tags without files (orphans) that need to be deleted.
    }//end attachTagsToFile()

    /**
     * Generate the object tag for a given ObjectEntity.
     *
     * This method creates a standardized object tag that links a file to its parent object.
     * The tag format is 'object:' followed by the object's UUID or ID.
     *
     * @param ObjectEntity $objectEntity The object entity to generate the tag for
     *
     * @return string The object tag in format 'object:uuid' or 'object:id'
     *
     * @psalm-return string
     * @phpstan-return string
     */
    private function generateObjectTag(ObjectEntity|string $objectEntity): string
    {
		if($objectEntity instanceof ObjectEntity === false) {
			return 'object:'.$objectEntity;
		}

        // Use UUID if available, otherwise fall back to the numeric ID.
        $identifier = $objectEntity->getUuid() ?? (string) $objectEntity->getId();
        return 'object:' . $identifier;
    }//end generateObjectTag()

    /**
     * Adds a new file to an object's folder with the OpenCatalogi user as owner.
     *
     * This method automatically adds an 'object:' tag containing the object's UUID
     * in addition to any user-provided tags.
     *
     * @param ObjectEntity|string      $objectEntity The object entity to add the file to
     * @param string                   $fileName     The name of the file to create
     * @param string                   $content      The content to write to the file
     * @param bool                     $share        Whether to create a share link for the file
     * @param array                    $tags         Optional array of tags to attach to the file
     * @param int|string|Schema|null   $schema       The register of the object to add the file to
     * @param int|string|Register|null $register     The register of the object to add the file to   (?)
     * @param int|string|null          $registerId   The registerId of the object to add the file to (?)
     *
     * @throws NotPermittedException If file creation fails due to permissions
     * @throws Exception If file creation fails for other reasons
     *
     * @return File The created file
     *
     * @phpstan-param array<int, string> $tags
     * @psalm-param array<int, string> $tags
     */
    public function addFile(ObjectEntity | string $objectEntity, string $fileName, string $content, bool $share = false, array $tags = [], int | string | Schema | null $schema = null, int | string | Register | null $register = null, int|string|null $registerId = null): File
    {
		try {
			// Ensure we have an ObjectEntity instance.
			if (is_string($objectEntity)) {
                try {
				    $objectEntity = $this->objectEntityMapper->find($objectEntity);
                } catch (DoesNotExistException) {
                    // In this case it is a possibility the object gets created later in a process (for example: synchronization) so we create the file for a given uuid.
                }
			}

			// Use the new ID-based folder approach.
            $folder = $this->getObjectFolder(objectEntity: $objectEntity, registerId: $registerId);

            // Check if the content is base64 encoded and decode it if necessary.
            if (base64_encode(base64_decode($content, true)) === $content) {
                $content = base64_decode($content);
            }

            // Check if the file name is empty.
            if (empty($fileName) === true) {
                throw new Exception("Failed to create file because no filename has been provided for object " . $objectEntity->getId());
            }

            // Security: Block executable files.
            $this->blockExecutableFile($fileName, $content);

            /**
             * @var File $file
             */
            $file = $folder->newFile($fileName);

            // @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
            $this->checkOwnership($file);

            // Write content to the file.
            $file->putContent($content);

            // Transfer ownership to OpenRegister and share with current user if needed.
            $this->transferFileOwnershipIfNeeded($file);

            // Create a share link for the file if requested.
            if ($share === true) {
                $this->createShareLink(path: $file->getPath());
            }

            // Automatically add object tag with the object's UUID.
            $objectTag = $this->generateObjectTag($objectEntity);
            $allTags = array_merge([$objectTag], $tags);

            // Add tags to the file (including the automatic object tag).
            if (empty($allTags) === false) {
                $this->attachTagsToFile(fileId: $file->getId(), tags: $allTags);
            }

            // @TODO: This sets the file array of an object, but we should check why this array is not added elsewhere.
//                $objectFiles = $objectEntity->getFiles();
//
//                $objectFiles[] = $this->formatFile($file);
//                $objectEntity->setFiles($objectFiles);
//
//                $this->objectEntityMapper->update($objectEntity);

            return $file;

        } catch (NotPermittedException $e) {
            // Log permission error and rethrow exception.
            $this->logger->error(message: "Permission denied creating file $fileName: ".$e->getMessage());
            throw new NotPermittedException("Cannot create file $fileName: ".$e->getMessage());
        } catch (\Exception $e) {
            // Log general error and rethrow exception.
            $this->logger->error(message: "Failed to create file $fileName: ".$e->getMessage());
            throw new \Exception("Failed to create file $fileName: ".$e->getMessage());
        }
    }//end addFile()

    /**
     * Save a file to an object's folder (create new or update existing).
     *
     * This method provides a generic save functionality that checks if a file already exists
     * for the given object. If it exists, the file will be updated; if not, a new file will
     * be created. This is particularly useful for synchronization scenarios where you want
     * to "upsert" files.
     *
     * @param ObjectEntity $objectEntity The object entity to save the file to
     * @param string       $fileName     The name of the file to save
     * @param string       $content      The content to write to the file
     * @param bool         $share        Whether to create a share link for the file (only for new files)
     * @param array        $tags         Optional array of tags to attach to the file
     *
     * @throws NotPermittedException If file operations fail due to permissions
     * @throws Exception If file operations fail for other reasons
     *
     * @return File The saved file
     *
     * @phpstan-param array<int, string> $tags
     * @psalm-param array<int, string> $tags
     */
    public function saveFile(ObjectEntity $objectEntity, string $fileName, string $content, bool $share = false, array $tags = []): File
    {
		try {
            // Check if the file already exists for this object.
            $existingFile = $this->getFile(
                object: $objectEntity,
                file: $fileName
            );

            if ($existingFile !== null) {
                // File exists, update it.
                $this->logger->info(message: "File $fileName already exists for object {$objectEntity->getId()}, updating...");

                // Update the existing file - pass the object so updateFile can find it in the object folder.
                return $this->updateFile(
                    filePath: $fileName,  // Just pass the filename, not the full path
                    content: $content,
                    tags: $tags,
                    object: $objectEntity  // Pass the object so updateFile can locate the file
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
            }
        } catch (NotPermittedException $e) {
            // Log permission error and rethrow exception.
            $this->logger->error(message: "Permission denied saving file $fileName: ".$e->getMessage());
            throw new NotPermittedException("Cannot save file $fileName: ".$e->getMessage());
        } catch (\Exception $e) {
            // Log general error and rethrow exception.
            $this->logger->error(message: "Failed to save file $fileName: ".$e->getMessage());
            throw new \Exception("Failed to save file $fileName: ".$e->getMessage());
        }
    }//end saveFile()

    /**
     * Retrieves all available tags in the system.
     *
     * This method fetches all tags that are visible and assignable by users
     * from the system tag manager, and filters out any tags that start with 'object:'.
     *
     * @throws \Exception If there's an error retrieving the tags
     *
     * @return array An array of tag names
     *
     * @psalm-return array<int, string>
     *
     * @phpstan-return array<int, string>
     */
    public function getAllTags(): array
    {
        try {
            // Get all tags that are visible and assignable by users.
            $tags = $this->systemTagManager->getAllTags(visibilityFilter: true);

            // Extract just the tag names and filter out those starting with 'object:'.
            $tagNames = array_filter(
                array_map(static function ($tag) {
                    return $tag->getName();
                }, $tags),
                static function ($tagName) {
                    return !str_starts_with($tagName, 'object:');
                }
            );

            // Return sorted array of tag names.
            sort($tagNames);
            return array_values($tagNames);
        } catch (\Exception $e) {
            $this->logger->error(message: 'Failed to retrieve tags: '.$e->getMessage());
            throw new \Exception('Failed to retrieve tags: '.$e->getMessage());
        }
    }//end getAllTags()

    /**
     * Get all files for an object.
     *
     * See https://nextcloud-server.netlify.app/classes/ocp-files-file for the Nextcloud documentation on the File class.
     * See https://nextcloud-server.netlify.app/classes/ocp-files-node for the Nextcloud documentation on the Node superclass.
     *
     * @param ObjectEntity|string $object The object or object ID to fetch files for
     *
     * @return Node[] The files found
     *
     * @throws NotFoundException If the folder is not found
     * @throws DoesNotExistException If the object ID is not found
     *
     * @psalm-return array<int, Node>
     * @phpstan-return array<int, Node>
     */
    public function getFiles(ObjectEntity | string $object, ?bool $sharedFilesOnly = false): array
    {
        // If string ID provided, try to find the object entity.
        if (is_string($object) === true) {
            $object = $this->objectEntityMapper->find($object);
        }

        // Use the new ID-based folder approach.
        return $this->getFilesForEntity($object, $sharedFilesOnly);
    }

    /**
     * Get a file by file identifier (ID or name/path) or by object and file name/path.
     *
     * If $file is an integer or a string that is an integer (e.g. '23234234'), the file will be fetched by ID
     * and the $object parameter will be ignored. Otherwise, the file will be fetched by name/path within the object folder.
     *
     * See https://nextcloud-server.netlify.app/classes/ocp-files-file for the Nextcloud documentation on the File class.
     * See https://nextcloud-server.netlify.app/classes/ocp-files-node for the Nextcloud documentation on the Node superclass.
     *
     * @param ObjectEntity|string|null $object The object or object ID to fetch files for (ignored if $file is an ID)
     * @param string|int $file The file name/path within the object folder, or the file ID (int or numeric string)
     *
     * @return File|null The file if found, null otherwise
     *
     * @throws NotFoundException If the folder is not found
     * @throws DoesNotExistException If the object ID is not found
     *
     * @psalm-param ObjectEntity|string|null $object
     * @phpstan-param ObjectEntity|string|null $object
     * @psalm-param string|int $file
     * @phpstan-param string|int $file
     * @psalm-return File|null
     * @phpstan-return File|null
     */
    public function getFile(ObjectEntity|string|null $object = null, string|int $file = ''): ?File
    {

        // If string ID provided for object, try to find the object entity.
        if (is_string($object) === true && !empty($object)) {
            $object = $this->objectEntityMapper->find($object);
        }

        // Use the new ID-based folder approach.
        $folder = $this->getObjectFolder($object);

        // If $file is an integer or a string that is an integer, treat as file ID.
        if (is_int($file) || (is_string($file) && ctype_digit($file))) {

            // Try to get the file by ID.
            try {
                $nodes = $folder->getById((int)$file);
                if (!empty($nodes) && $nodes[0] instanceof File) {
                    $fileNode = $nodes[0];
                    // Check ownership for NextCloud rights issues.
                    $this->checkOwnership($fileNode);
                    return $fileNode;
                }
            } catch (\Exception $e) {
                $this->logger->error(message: 'getFile: Error finding file by ID ' . $file . ': ' . $e->getMessage());
                return null;
            }
            // If not found by ID, return null.
            return null;
        }

        // Clean file path and extract filename using utility method.
        $originalFile = $file;
        $pathInfo = $this->extractFileNameFromPath((string)$file);
        $filePath = $pathInfo['cleanPath'];
        $fileName = $pathInfo['fileName'];


        // Check if folder exists and get the file.
        if ($folder instanceof Folder === true) {
            try {
                // First try with just the filename.
                $fileNode = $folder->get($fileName);

                // Check ownership for NextCloud rights issues.
                $this->checkOwnership($fileNode);

                return $fileNode;
            } catch (NotFoundException) {
                try {
                    // If that fails, try with the full path.
                    $fileNode = $folder->get($filePath);

                    // Check ownership for NextCloud rights issues.
                    $this->checkOwnership($fileNode);

                    return $fileNode;
                } catch (NotFoundException) {
                    // File not found.
                    return null;
                }
            }
        }

        return null;
    }


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

            if (empty($nodes)) {
                return null;
            }

            // Get the first node (file IDs are unique).
            $node = $nodes[0];

            // Ensure it's a file, not a folder.
            if (!($node instanceof File)) {
                return null;
            }

            // Check ownership for NextCloud rights issues.
            $this->checkOwnership($node);

            return $node;
        } catch (\Exception $e) {
            $this->logger->error(message: 'getFileById: Error finding file by ID ' . $fileId . ': ' . $e->getMessage());
            return null;
        }

    }//end getFileById()


    /**
     * Stream a file for download.
     *
     * This method creates a StreamResponse that sends the file content
     * directly to the client with appropriate headers.
     *
     * @param File $file The file to stream
     *
     * @return \OCP\AppFramework\Http\StreamResponse The stream response
     *
     * @phpstan-param  File $file
     * @phpstan-return \OCP\AppFramework\Http\StreamResponse
     */
    public function streamFile(File $file): \OCP\AppFramework\Http\StreamResponse
    {
        // Create a stream response with the file content.
        $response = new \OCP\AppFramework\Http\StreamResponse($file->fopen('r'));

        // Set appropriate headers.
        $response->addHeader('Content-Type', $file->getMimeType());
        $response->addHeader('Content-Disposition', 'attachment; filename="' . $file->getName() . '"');
        $response->addHeader('Content-Length', (string) $file->getSize());

        return $response;

    }//end streamFile()


    /**
     * Publish a file by creating a public share link using direct database operations.
     *
     * @param ObjectEntity|string $object The object or object ID
     * @param string|int         $file   The path to the file or file ID to publish
     *
     * @return File The published file
     *
     * @throws Exception If file publishing fails
     * @throws NotFoundException If the file is not found
     * @throws NotPermittedException If sharing is not permitted
     *
     * @psalm-return File
     * @phpstan-return File
     */
    public function publishFile(ObjectEntity | string $object, string | int $file): File
    {
        // If string ID provided, try to find the object entity.
        if (is_string($object) === true) {
            $object = $this->objectEntityMapper->find($object);
        }

        // Debug logging - original file parameter.
        $originalFile = $file;
        $this->logger->info(message: "publishFile: Original file parameter received: '$originalFile'");

        $fileNode = null;

        // If $file is an integer (file ID), try to find the file directly by ID.
        if (is_int($file)) {
            $this->logger->info(message: "publishFile: File ID provided: $file");

            // Try to find the file in the object's folder by ID.
            $fileNode = $this->getFile($object, $file);
            if ($fileNode !== null) {
                $this->logger->info(message: "publishFile: Found file by ID: " . $fileNode->getName() . " (ID: " . $fileNode->getId() . ")");
            } else {
                $this->logger->error(message: "publishFile: No file found with ID: $file");
                throw new Exception("File with ID $file does not exist");
            }
        } else {
            // Handle string file paths (existing logic).
            // Clean file path and extract filename using utility method.
            $pathInfo = $this->extractFileNameFromPath((string)$file);
            $filePath = $pathInfo['cleanPath'];
            $fileName = $pathInfo['fileName'];

            $this->logger->info(message: "publishFile: After cleaning: '$filePath'");
            if ($fileName !== $filePath) {
                $this->logger->info(message: "publishFile: Extracted filename from path: '$fileName' (from '$filePath')");
            }

            // Get the object folder (this is where the files actually are).
            $objectFolder = $this->getObjectFolder($object);

            if ($objectFolder === null) {
                $this->logger->error(message: "publishFile: Could not get object folder for object: " . $object->getId());
                throw new Exception('Object folder not found.');
            }

            $this->logger->info(message: "publishFile: Object folder path: " . $objectFolder->getPath());

            // Debug: List all files in the object folder.
            try {
                $objectFiles = $objectFolder->getDirectoryListing();
                $objectFileNames = array_map(function($file) { return $file->getName(); }, $objectFiles);
                $this->logger->info(message: "publishFile: Files in object folder: " . json_encode($objectFileNames));
            } catch (Exception $e) {
                $this->logger->error(message: "publishFile: Error listing object folder contents: " . $e->getMessage());
            }

            try {
                $this->logger->info(message: "publishFile: Attempting to get file '$fileName' from object folder");
                $fileNode = $objectFolder->get($fileName);
                $this->logger->info(message: "publishFile: Successfully found file: " . $fileNode->getName() . " at " . $fileNode->getPath());
            } catch (NotFoundException $e) {
                // Try with full path if filename didn't work.
                try {
                    $this->logger->info(message: "publishFile: Attempting to get file '$filePath' (full path) from object folder");
                    $fileNode = $objectFolder->get($filePath);
                    $this->logger->info(message: "publishFile: Successfully found file using full path: " . $fileNode->getName() . " at " . $fileNode->getPath());
                } catch (NotFoundException $e2) {
                    $this->logger->error(message: "publishFile: File '$fileName' and '$filePath' not found in object folder. NotFoundException: " . $e2->getMessage());
                    throw new Exception('File not found.');
                }
            } catch (Exception $e) {
                $this->logger->error(message: "publishFile: Unexpected error getting file from object folder: " . $e->getMessage());
                throw new Exception('File not found.');
            }
        }

        // Verify file exists and is a File instance.
        if ($fileNode instanceof File === false) {
            $this->logger->error(message: "publishFile: Found node is not a File instance, it's a: " . get_class($fileNode));
            throw new Exception('File not found.');
        }

        // @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
        $this->checkOwnership($fileNode);

        $this->logger->info(message: "publishFile: Creating share link for file: " . $fileNode->getPath());

        // Use FileMapper to create the share directly in the database.
        try {
            $openRegisterUser = $this->getUser();
            $shareInfo = $this->fileMapper->publishFile(
                fileId: $fileNode->getId(),
                sharedBy: $openRegisterUser->getUID(),
                shareOwner: $openRegisterUser->getUID(),
                permissions: 1 // Read only
            );

            $this->logger->info(message: "publishFile: Successfully created public share via FileMapper - ID: {$shareInfo['id']}, Token: {$shareInfo['token']}, URL: {$shareInfo['accessUrl']}");
        } catch (Exception $e) {
            $this->logger->error(message: "publishFile: Failed to create share via FileMapper: " . $e->getMessage());
            throw new Exception('Failed to create share link: ' . $e->getMessage());
        }

        $this->logger->info(message: "publishFile: Successfully published file: " . $fileNode->getName());
        return $fileNode;
    }

    /**
     * Unpublish a file by removing its public share link.
     *
     * @param ObjectEntity|string $object   The object or object ID
     * @param string|int         $filePath The path to the file to unpublish or file ID
     *
     * @return File The unpublished file
     *
     * @throws Exception If file unpublishing fails
     * @throws NotFoundException If the file is not found
     * @throws NotPermittedException If sharing operations are not permitted
     *
     * @psalm-return File
     * @phpstan-return File
     */
    public function unpublishFile(ObjectEntity | string $object, string|int $filePath): File
    {
        // If string ID provided, try to find the object entity.
        if (is_string($object) === true) {
            $object = $this->objectEntityMapper->find($object);
        }

        // Debug logging - original file path.
        $originalFilePath = $filePath;
        $this->logger->info(message: "unpublishFile: Original file path received: '$originalFilePath'");

        $file = null;

        // If $filePath is an integer (file ID), try to find the file directly by ID.
        if (is_int($filePath)) {
            $this->logger->info(message: "unpublishFile: File ID provided: $filePath");

            // Try to find the file in the object's folder by ID.
            $file = $this->getFile($object, $filePath);
            if ($file !== null) {
                $this->logger->info(message: "unpublishFile: Found file by ID: " . $file->getName() . " (ID: " . $file->getId() . ")");
            } else {
                $this->logger->error(message: "unpublishFile: No file found with ID: $filePath");
                throw new Exception("File with ID $filePath does not exist");
            }
        } else {
            // Handle string file paths (existing logic).
            // Clean file path and extract filename using utility method.
            $pathInfo = $this->extractFileNameFromPath((string)$filePath);
            $filePath = $pathInfo['cleanPath'];
            $fileName = $pathInfo['fileName'];

            $this->logger->info(message: "unpublishFile: After cleaning: '$filePath'");
            if ($fileName !== $filePath) {
                $this->logger->info(message: "unpublishFile: Extracted filename from path: '$fileName' (from '$filePath')");
            }

            // Get the object folder (this is where the files actually are).
            $objectFolder = $this->getObjectFolder($object);

            if ($objectFolder === null) {
                $this->logger->error(message: "unpublishFile: Could not get object folder for object: " . $object->getId());
                throw new Exception('Object folder not found.');
            }

            $this->logger->info(message: "unpublishFile: Object folder path: " . $objectFolder->getPath());

            // Debug: List all files in the object folder.
            try {
                $objectFiles = $objectFolder->getDirectoryListing();
                $objectFileNames = array_map(function($file) { return $file->getName(); }, $objectFiles);
                $this->logger->info(message: "unpublishFile: Files in object folder: " . json_encode($objectFileNames));
            } catch (Exception $e) {
                $this->logger->error(message: "unpublishFile: Error listing object folder contents: " . $e->getMessage());
            }

            try {
                $this->logger->info(message: "unpublishFile: Attempting to get file '$fileName' from object folder");
                $file = $objectFolder->get($fileName);
                $this->logger->info(message: "unpublishFile: Successfully found file: " . $file->getName() . " at " . $file->getPath());
            } catch (NotFoundException $e) {
                // Try with full path if filename didn't work.
                try {
                    $this->logger->info(message: "unpublishFile: Attempting to get file '$filePath' (full path) from object folder");
                    $file = $objectFolder->get($filePath);
                    $this->logger->info(message: "unpublishFile: Successfully found file using full path: " . $file->getName() . " at " . $file->getPath());
                } catch (NotFoundException $e2) {
                    $this->logger->error(message: "unpublishFile: File '$fileName' and '$filePath' not found in object folder. NotFoundException: " . $e2->getMessage());
                    throw new Exception('File not found.');
                }
            } catch (Exception $e) {
                $this->logger->error(message: "unpublishFile: Unexpected error getting file from object folder: " . $e->getMessage());
                throw new Exception('File not found.');
            }
        }

        // Verify file exists and is a File instance.
        if ($file instanceof File === false) {
            $this->logger->error(message: "unpublishFile: Found node is not a File instance, it's a: " . get_class($file));
            throw new Exception('File not found.');
        }

        // @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
        $this->checkOwnership($file);

        $this->logger->info(message: "unpublishFile: Removing share links for file: " . $file->getPath());

        // Use FileMapper to remove all public shares directly from the database.
        try {
            $deletionInfo = $this->fileMapper->depublishFile($file->getId());

            $this->logger->info(message: "unpublishFile: Successfully removed public shares via FileMapper - Deleted shares: {$deletionInfo['deleted_shares']}, File ID: {$deletionInfo['file_id']}");

            if ($deletionInfo['deleted_shares'] === 0) {
                $this->logger->info(message: "unpublishFile: No public shares were found to delete for file: " . $file->getName());
            }
        } catch (Exception $e) {
            $this->logger->error(message: "unpublishFile: Failed to remove shares via FileMapper: " . $e->getMessage());
            throw new Exception('Failed to remove share links: ' . $e->getMessage());
        }

        $this->logger->info(message: "unpublishFile: Successfully unpublished file: " . $file->getName());
        return $file;
    }

    /**
     * Create a ZIP archive containing all files for a specific object.
     *
     * This method retrieves all files associated with an object and creates a ZIP archive
     * containing all the files. The ZIP file is created in the system's temporary directory
     * and can be downloaded by the client.
     *
     * @param ObjectEntity|string $object The object entity or object UUID/ID
     * @param string|null        $zipName Optional custom name for the ZIP file
     *
     * @throws Exception If ZIP creation fails or object not found
     * @throws NotFoundException If the object folder is not found
     * @throws NotPermittedException If file access is not permitted
     *
     * @return array{
     *     path: string,
     *     filename: string,
     *     size: int,
     *     mimeType: string
     * } Information about the created ZIP file
     *
     * @psalm-return array{path: string, filename: string, size: int, mimeType: string}
     * @phpstan-return array{path: string, filename: string, size: int, mimeType: string}
     */
    public function createObjectFilesZip(ObjectEntity | string $object, ?string $zipName = null): array
    {
        // If string ID provided, try to find the object entity.
        if (is_string($object) === true) {
            try {
                $object = $this->objectEntityMapper->find($object);
            } catch (Exception $e) {
                throw new Exception("Object not found: " . $e->getMessage());
            }
        }

        $this->logger->info(message: "Creating ZIP archive for object: " . $object->getId());

        // Check if ZipArchive extension is available.
        if (class_exists('ZipArchive') === false) {
            throw new Exception('PHP ZipArchive extension is not available');
        }

        // Get all files for the object.
        $files = $this->getFiles($object);

        if (empty($files) === true) {
            throw new Exception('No files found for this object');
        }

        $this->logger->info(message: "Found " . count($files) . " files for object " . $object->getId());

        // Generate ZIP filename.
        if ($zipName === null) {
            $objectIdentifier = $object->getUuid() ?? (string) $object->getId();
            $zipName = 'object_' . $objectIdentifier . '_files_' . date('Y-m-d_H-i-s') . '.zip';
        } else if (pathinfo($zipName, PATHINFO_EXTENSION) !== 'zip') {
            $zipName .= '.zip';
        }

        // Create temporary file for the ZIP.
        $tempZipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $zipName;

        // Create new ZIP archive.
        $zip = new \ZipArchive();
        $result = $zip->open($tempZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        if ($result !== true) {
            throw new Exception("Cannot create ZIP file: " . $this->getZipErrorMessage($result));
        }

        $addedFiles = 0;
        $skippedFiles = 0;

        // Add each file to the ZIP archive.
        foreach ($files as $file) {
            try {
                if ($file instanceof \OCP\Files\File === false) {
                    $this->logger->warning(message: "Skipping non-file node: " . $file->getName());
                    $skippedFiles++;
                    continue;
                }

                // @TODO: Check ownership to prevent "File not found" errors - hack for NextCloud rights issues.
                $this->checkOwnership($file);

                // Get file content.
                $fileContent = $file->getContent();
                $fileName = $file->getName();

                // Add file to ZIP with its original name.
                $added = $zip->addFromString($fileName, $fileContent);

                if ($added === false) {
                    $this->logger->error(message: "Failed to add file to ZIP: " . $fileName);
                    $skippedFiles++;
                    continue;
                }

                $addedFiles++;
                $this->logger->debug(message: "Added file to ZIP: " . $fileName);

            } catch (Exception $e) {
                $this->logger->error(message: "Error processing file " . $file->getName() . ": " . $e->getMessage());
                $skippedFiles++;
                continue;
            }
        }

        // Close the ZIP archive.
        $closeResult = $zip->close();
        if ($closeResult === false) {
            throw new Exception("Failed to finalize ZIP archive");
        }

        $this->logger->info(message: "ZIP creation completed. Added: $addedFiles files, Skipped: $skippedFiles files");

        // Check if ZIP file was created successfully.
        if (file_exists($tempZipPath) === false) {
            throw new Exception("ZIP file was not created successfully");
        }

        $fileSize = filesize($tempZipPath);
        if ($fileSize === false) {
            throw new Exception("Cannot determine ZIP file size");
        }

        return [
            'path' => $tempZipPath,
            'filename' => $zipName,
            'size' => $fileSize,
            'mimeType' => 'application/zip'
        ];
    }//end createObjectFilesZip()

    /**
     * Get a human-readable error message for ZipArchive error codes.
     *
     * @param int $errorCode The ZipArchive error code
     *
     * @return string Human-readable error message
     *
     * @psalm-return string
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
        };
    }//end getZipErrorMessage()

    /**
     * Find all files tagged with a specific object identifier.
     *
     * This method searches for files that have been tagged with the 'object:' prefix
     * followed by the specified object identifier (UUID or ID).
     *
     * @param string $objectIdentifier The object UUID or ID to search for
     *
     * @return array Array of file nodes that belong to the specified object
     *
     * @throws \Exception If there's an error during the search
     *
     * @psalm-return array<int, Node>
     * @phpstan-return array<int, Node>
     */
    public function findFilesByObjectId(string $objectIdentifier): array
    {
        try {
            // Create the object tag we're looking for.
            $objectTag = 'object:' . $objectIdentifier;

            // Get the tag object.
            $tag = $this->systemTagManager->getTag(tagName: $objectTag, userVisible: true, userAssignable: true);

            // Get all file IDs that have this tag.
            $fileIds = $this->systemTagMapper->getObjectIdsForTags(
                tagIds: [$tag->getId()],
                objectType: self::FILE_TAG_TYPE
            );

            $files = [];
            if (empty($fileIds) === false) {
                // Get the user folder to resolve file paths.
                $userFolder = $this->getOpenRegisterUserFolder();

                // Convert file IDs to actual file nodes.
                foreach ($fileIds as $fileId) {
                    try {
                        $file = $userFolder->getById($fileId);
                        if (!empty($file)) {
                            $files = array_merge($files, $file);
                        }
                    } catch (NotFoundException) {
                        // File might have been deleted, skip it.
                        continue;
                    }
                }
            }

            return $files;
        } catch (\Exception $e) {
            $this->logger->error(message: 'Failed to find files by object ID: ' . $e->getMessage());
            throw new \Exception('Failed to find files by object ID: ' . $e->getMessage());
        }
    }//end findFilesByObjectId()

    /**
     * Debug method to find a file by its ID anywhere in the OpenRegister folder structure
     *
     * @param int $fileId The file ID to search for
     *
     * @return array|null File information or null if not found
     */
    public function debugFindFileById(int $fileId): ?array
    {
        try {
            $userFolder = $this->getOpenRegisterUserFolder();
            $nodes = $userFolder->getById($fileId);

            if (empty($nodes)) {
                $this->logger->info(message: "debugFindFileById: No file found with ID: $fileId");
                return null;
            }

            $file = $nodes[0];
            $fileInfo = [
                'id' => $file->getId(),
                'name' => $file->getName(),
                'path' => $file->getPath(),
                'type' => $file->getType(),
                'mimetype' => $file->getMimeType(),
                'size' => $file->getSize(),
                'parent_id' => $file->getParent()->getId(),
                'parent_path' => $file->getParent()->getPath(),
            ];

            $this->logger->info(message: "debugFindFileById: Found file with ID $fileId: " . json_encode($fileInfo));
            return $fileInfo;

        } catch (Exception $e) {
            $this->logger->error(message: "debugFindFileById: Error finding file by ID $fileId: " . $e->getMessage());
            return null;
        }
    }//end debugFindFileById()

    /**
     * Debug method to list all files in an object's folder
     *
     * @param ObjectEntity $object The object to list files for
     *
     * @return array List of file information
     */
    public function debugListObjectFiles(ObjectEntity $object): array
    {
        try {
            $objectFolder = $this->getObjectFolder($object);

            if ($objectFolder === null) {
                $this->logger->warning(message: "debugListObjectFiles: Could not get object folder for object ID: " . $object->getId());
                return [];
            }

            $files = $objectFolder->getDirectoryListing();
            $fileList = [];

            foreach ($files as $file) {
                $fileInfo = [
                    'id' => $file->getId(),
                    'name' => $file->getName(),
                    'path' => $file->getPath(),
                    'type' => $file->getType(),
                    'mimetype' => $file->getMimeType(),
                    'size' => $file->getSize(),
                ];
                $fileList[] = $fileInfo;
            }

            $this->logger->info(message: "debugListObjectFiles: Object " . $object->getId() . " folder contains " . count($fileList) . " files: " . json_encode($fileList));
            return $fileList;

        } catch (Exception $e) {
            $this->logger->error(message: "debugListObjectFiles: Error listing files for object " . $object->getId() . ": " . $e->getMessage());
            return [];
        }
    }//end debugListObjectFiles()

    /**
     * Test method to verify file ID lookup functionality
     * This method can be called to test if files can be found by ID
     *
     * @param int $fileId The file ID to test lookup for
     * @param ObjectEntity|null $object Optional object to test object folder lookup
     *
     * @return array Test results
     */
    public function testFileLookup(int $fileId, ?ObjectEntity $object = null): array
    {
        $results = [
            'file_id' => $fileId,
            'object_id' => $this->getObjectId($object),
            'tests' => []
        ];

        // Test 1: Find file by ID in OpenRegister folder.
        $this->logger->info(message: "testFileLookup: Testing file ID lookup for file $fileId");
        $fileInfo = $this->debugFindFileById($fileId);
        $results['tests']['find_by_id'] = [
            'success' => $fileInfo !== null,
            'file_info' => $fileInfo
        ];

        // Test 2: If object provided, test object folder listing.
        if ($object !== null) {
            $this->logger->info(message: "testFileLookup: Testing object folder listing for object " . $object->getId());
            $objectFiles = $this->debugListObjectFiles($object);
            $results['tests']['object_folder_listing'] = [
                'success' => !empty($objectFiles),
                'file_count' => count($objectFiles),
                'files' => $objectFiles
            ];

            // Test 3: Check if the file ID is in the object's folder.
            $fileInObjectFolder = false;
            foreach ($objectFiles as $file) {
                if ($file['id'] === $fileId) {
                    $fileInObjectFolder = true;
                    break;
                }
            }
            $results['tests']['file_in_object_folder'] = [
                'success' => $fileInObjectFolder,
                'message' => $this->getFileInObjectFolderMessage($fileInObjectFolder, $fileId)
            ];
        }

        // Test 4: Test updateFile with file ID path format.
        if ($fileInfo !== null) {
            $testPath = $fileId . '/' . $fileInfo['name'];
            $this->logger->info(message: "testFileLookup: Testing updateFile with path: $testPath");

            try {
                // Don't actually update, just test the lookup logic.
                $userFolder = $this->getOpenRegisterUserFolder();

                // Simulate the updateFile logic.
                $fileName = $fileInfo['name'];
                $foundByFilename = false;
                $foundByPath = false;
                $foundById = false;

                // Test object folder lookup if object provided.
                if ($object !== null) {
                    try {
                        $objectFolder = $this->getObjectFolder($object);
                        if ($objectFolder !== null) {
                            try {
                                $file = $objectFolder->get($fileName);
                                $foundByFilename = true;
                            } catch (\Exception $e) {
                                // Not found by filename.
                            }
                        }
                    } catch (\Exception $e) {
                        // Object folder error.
                    }
                }

                // Test user folder path lookup.
                try {
                    $file = $userFolder->get($testPath);
                    $foundByPath = true;
                } catch (\Exception $e) {
                    // Not found by path.
                }

                // Test user folder ID lookup.
                try {
                    $nodes = $userFolder->getById($fileId);
                    if (!empty($nodes)) {
                        $foundById = true;
                    }
                } catch (\Exception $e) {
                    // Not found by ID.
                }

                $results['tests']['updateFile_simulation'] = [
                    'test_path' => $testPath,
                    'found_by_filename_in_object_folder' => $foundByFilename,
                    'found_by_path_in_user_folder' => $foundByPath,
                    'found_by_id_in_user_folder' => $foundById,
                    'success' => $foundByFilename || $foundByPath || $foundById
                ];

            } catch (\Exception $e) {
                $results['tests']['updateFile_simulation'] = [
                    'error' => $e->getMessage(),
                    'success' => false
                ];
            }
        }

        $this->logger->info(message: "testFileLookup: Test results: " . json_encode($results));
        return $results;
    }//end testFileLookup()

    /**
     * Blocks executable files from being uploaded for security.
     *
     * This method checks both file extensions and magic bytes to detect executables.
     * This is the central security check for ALL file uploads in OpenRegister.
     *
     * @param string $fileName    The filename to check
     * @param string $fileContent The file content to check
     *
     * @return void
     *
     * @throws Exception If an executable file is detected
     */
    private function blockExecutableFile(string $fileName, string $fileContent): void
    {
        // List of dangerous executable extensions.
        $dangerousExtensions = [
            // Windows executables.
            'exe', 'bat', 'cmd', 'com', 'msi', 'scr', 'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh', 'ps1', 'dll',
            // Unix/Linux executables.
            'sh', 'bash', 'csh', 'ksh', 'zsh', 'run', 'bin', 'app', 'deb', 'rpm',
            // Scripts and code.
            'php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'phar',
            'py', 'pyc', 'pyo', 'pyw',
            'pl', 'pm', 'cgi',
            'rb', 'rbw',
            'jar', 'war', 'ear', 'class',
            // Containers and packages.
            'appimage', 'snap', 'flatpak',
            // MacOS.
            'dmg', 'pkg', 'command',
            // Android.
            'apk',
            // Other dangerous.
            'elf', 'out', 'o', 'so', 'dylib',
        ];

        // Check file extension.
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (in_array($extension, $dangerousExtensions, true)) {
            $this->logger->warning(message: 'Executable file upload blocked', context: [
                'app' => 'openregister',
                'filename' => $fileName,
                'extension' => $extension,
            ]);

            throw new Exception(
                "File '$fileName' is an executable file (.$extension). "
                ."Executable files are blocked for security reasons. "
                ."Allowed formats: documents, images, archives, data files."
            );
        }

        // Check magic bytes (file signatures) in content.
        if (!empty($fileContent)) {
            $this->detectExecutableMagicBytes($fileContent, $fileName);
        }
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
            'MZ' => 'Windows executable (PE/EXE)',
            "\x7FELF" => 'Linux/Unix executable (ELF)',
            "#!/bin/sh" => 'Shell script',
            "#!/bin/bash" => 'Bash script',
            "#!/usr/bin/env" => 'Script with env shebang',
            "<?php" => 'PHP script',
            "\xCA\xFE\xBA\xBE" => 'Java class file',
        ];

        foreach ($magicBytes as $signature => $description) {
            if (strpos($content, $signature) === 0) {
                $this->logger->warning(message: 'Executable magic bytes detected', context: [
                    'app' => 'openregister',
                    'filename' => $fileName,
                    'type' => $description
                ]);

                throw new Exception(
                    "File '$fileName' contains executable code ($description). "
                    ."Executable files are blocked for security reasons."
                );
            }
        }

        // Check for script shebangs anywhere in first 4 lines.
        $firstLines = substr($content, 0, 1024);
        if (preg_match('/^#!.*\/(sh|bash|zsh|ksh|csh|python|perl|ruby|php|node)/m', $firstLines)) {
            throw new Exception(
                "File '$fileName' contains script shebang. "
                ."Script files are blocked for security reasons."
            );
        }

        // Check for embedded PHP tags.
        if (preg_match('/<\?php|<\?=|<script\s+language\s*=\s*["\']php/i', $firstLines)) {
            throw new Exception(
                "File '$fileName' contains PHP code. "
                ."PHP files are blocked for security reasons."
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
     * @return int|null The folder ID or null if creation fails
     *
     * @throws Exception If folder creation fails or entities not found
     * @throws NotPermittedException If folder creation is not permitted
     * @throws NotFoundException If parent folders do not exist
     *
     * @psalm-return int|null
     * @phpstan-return int|null
     */
    public function createObjectFolderWithoutUpdate(ObjectEntity $objectEntity, ?IUser $currentUser = null): ?int
    {
        // Ensure register folder exists first.
        $register = $this->registerMapper->find($objectEntity->getRegister());
        $registerFolder = $this->createRegisterFolderById($register, $currentUser);

        if ($registerFolder === null) {
            throw new Exception("Failed to create or access register folder");
        }

        // Create object folder within the register folder.
        $objectFolderName = $this->getObjectFolderName($objectEntity);

        try {
            // Try to get existing folder first.
            $objectFolder = $registerFolder->get($objectFolderName);
            $this->logger->info(message: "Object folder already exists: " . $objectFolderName);
        } catch (NotFoundException) {
            // Create new folder if it doesn't exist.
            $objectFolder = $registerFolder->newFolder($objectFolderName);
            $this->logger->info(message: "Created object folder: " . $objectFolderName);
        }

        $this->logger->info(message: "Created object folder with ID: " . $objectFolder->getId());

        // Transfer ownership to OpenRegister and share with current user if needed.
        $this->transferFolderOwnershipIfNeeded($objectFolder);

        // Share the folder with the currently active user if there is one.
        if ($currentUser !== null && $currentUser->getUID() !== $this->getUser()->getUID()) {
            $this->shareFolderWithUser($objectFolder, $currentUser->getUID());
        }

        return $objectFolder->getId();
    }//end createObjectFolderWithoutUpdate()

}//end class


