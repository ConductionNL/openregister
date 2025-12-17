<?php

/**
 * FolderManagementHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Handles folder management operations for files.
 *
 * This handler is responsible for:
 * - Creating entity folders (registers and objects)
 * - Managing folder hierarchy and structure
 * - Folder lookup by ID or entity
 * - Folder path creation
 * - Folder naming conventions
 *
 * NOTE: This handler coordinates with other handlers for:
 * - FileOwnershipHandler (transferFolderOwnershipIfNeeded) - via FileService facade
 * - FileSharingHandler (shareFolderWithUser, createShare) - via FileService facade
 * These methods are accessed through the FileService to avoid circular dependencies.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class FolderManagementHandler
{
    /**
     * Root folder name for all OpenRegister files.
     *
     * @var string
     */
    private const ROOT_FOLDER = 'Open Registers';

    /**
     * Application group name.
     *
     * @var string
     */
    private const APP_GROUP = 'openregister';

    /**
     * Constructor for FolderManagementHandler.
     *
     * @param IRootFolder        $rootFolder         Root folder for file operations.
     * @param ObjectEntityMapper $objectEntityMapper Mapper for object entities.
     * @param RegisterMapper     $registerMapper     Mapper for registers.
     * @param IUserSession       $userSession        User session for user context.
     * @param IGroupManager      $groupManager       Group manager for group operations.
     * @param LoggerInterface    $logger             Logger for logging operations.
     * @param FileService|null   $fileService        File service facade for cross-handler coordination
     *                                               (injected lazily to avoid circular dependency).
     */
    public function __construct(
        private readonly IRootFolder $rootFolder,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger,
        private ?FileService $fileService=null
    ) {
    }//end __construct()

    /**
     * Set the FileService facade for cross-handler coordination.
     *
     * This allows accessing other handlers (ownership, sharing) through the facade.
     * Called by FileService after construction to avoid circular dependencies.
     *
     * @param FileService $fileService The file service facade.
     *
     * @return void
     *
     * @psalm-return   void
     * @phpstan-return void
     */
    public function setFileService(FileService $fileService): void
    {
        $this->fileService = $fileService;
    }//end setFileService()

    /**
     * Creates a folder for an entity (Register or ObjectEntity).
     *
     * This is the main entry point for folder creation, delegating to specific
     * methods based on the entity type.
     *
     * @param Register|ObjectEntity $entity The entity to create a folder for.
     *
     * @return Node|null The created folder Node or null if creation fails.
     *
     * @psalm-return   Node|null
     * @phpstan-return Node|null
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
        } catch (Exception $e) {
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
     * @param Register   $register    The register to create the folder for.
     * @param IUser|null $currentUser The current user to share the folder with.
     *
     * @throws Exception             If folder creation fails.
     * @throws NotPermittedException If folder creation is not permitted.
     *
     * @return Node The created or existing register folder node.
     *
     * @phpstan-return Node|null
     *
     * @psalm-return Node|null
     */
    public function createRegisterFolderById(Register $register, ?IUser $currentUser=null): Node
    {
        $folderProperty = $register->getFolder();

        // Try to get existing folder by ID.
        $existingFolder = $this->getExistingFolderFromProperty($folderProperty);
        if ($existingFolder !== null) {
            $this->logger->info(message: "Register folder already exists with ID: ".$folderProperty);
            return $existingFolder;
        }

        // Create the folder path and node.
        $registerFolderName = $this->getRegisterFolderName($register);
        $folderPath         = self::ROOT_FOLDER.'/'.$registerFolderName;

        $folderNode = $this->createFolderPath($folderPath);

        if ($folderNode === null) {
            return $folderNode;
        }

        // Store the folder ID instead of the path.
        $register->setFolder((string) $folderNode->getId());
        $this->logger->info('🔹 FolderManagementHandler: About to update register with folder ID');
        $this->registerMapper->update($register);
        $this->logger->info('🔹 FolderManagementHandler: Register updated with folder ID');

        $this->logger->info(message: "Created register folder with ID: ".$folderNode->getId());

        // Transfer ownership to OpenRegister and share with current user if needed.
        if ($this->fileService !== null) {
            // TODO: Call $this->fileService->transferFolderOwnershipIfNeeded($folderNode) once FileOwnershipHandler is extracted.
        }

        // Share the folder with the currently active user if there is one.
        $this->shareFolderWithCurrentUser(folderNode: $folderNode, currentUser: $currentUser);

        return $folderNode;
    }//end createRegisterFolderById()

    /**
     * Creates a folder for an ObjectEntity nested under the register folder.
     *
     * @param ObjectEntity|string $objectEntity The object entity to create the folder for.
     * @param IUser|null          $currentUser  The current user to share the folder with.
     * @param int|string|null     $registerId   The register of the object to add the file to.
     *
     * @throws Exception             If folder creation fails.
     * @throws NotPermittedException If folder creation is not permitted.
     *
     * @phpstan-return Node
     * @psalm-return   Node
     * @return         Node The created object folder.
     */
    public function createObjectFolderById(
        ObjectEntity|string $objectEntity,
        ?IUser $currentUser=null,
        int|string|null $registerId=null
    ): Node {
        $folderProperty = null;
        if ($objectEntity instanceof ObjectEntity === true) {
            $folderProperty = $objectEntity->getFolder();
        }

        // Try to get existing folder by ID.
        $existingFolder = $this->getExistingFolderFromProperty($folderProperty);
        if ($existingFolder !== null) {
            $this->logger->info(message: "Object folder already exists with ID: ".$folderProperty);
            return $existingFolder;
        }

        // Ensure register folder exists first.
        $register       = $this->getRegisterFromObjectOrId(objectEntity: $objectEntity, registerId: $registerId);
        $registerFolder = $this->createRegisterFolderById(register: $register, currentUser: $currentUser);

        if ($registerFolder === null || ($registerFolder instanceof Folder) === false) {
            throw new Exception("Failed to create or access register folder");
        }

        // Create object folder within the register folder.
        $objectFolder = $this->createObjectFolderInRegister(registerFolder: $registerFolder, objectEntity: $objectEntity);

        // Store the folder ID.
        if ($objectEntity instanceof ObjectEntity === true) {
            $objectEntity->setFolder((string) $objectFolder->getId());
            $this->objectEntityMapper->update($objectEntity);
        }

        $this->logger->info(message: "Created object folder with ID: ".$objectFolder->getId());

        // Transfer ownership to OpenRegister and share with current user if needed.
        if ($this->fileService !== null) {
            // TODO: Call $this->fileService->transferFolderOwnershipIfNeeded($objectFolder) once FileOwnershipHandler is extracted.
        }

        // Share the folder with the currently active user if there is one.
        $this->shareFolderWithCurrentUser(folderNode: $objectFolder, currentUser: $currentUser);

        return $objectFolder;
    }//end createObjectFolderById()

    /**
     * Get the register folder by ID.
     *
     * Attempts to retrieve the register folder using the stored folder ID.
     * If the stored ID is invalid or the folder doesn't exist, creates a new folder.
     *
     * @param Register $register The register to get the folder for.
     *
     * @return Folder|null The register folder or null if not found/created.
     *
     * @psalm-return   Folder|null
     * @phpstan-return Folder|null
     */
    public function getRegisterFolderById(Register $register): ?Folder
    {
        $folderProperty = $register->getFolder();

        // Handle legacy cases where folder might be null, empty string, or a non-numeric string path.
        if ($folderProperty === null || $folderProperty === '' || is_string($folderProperty) === true) {
            $this->logger->info(message: "Register {$register->getId()} has legacy folder property, creating new folder");
            return $this->createRegisterFolderById(register: $register);
        }

        /*
         * Type-safe casting to int if numeric.
         *
         * @psalm-suppress TypeDoesNotContainType - Legacy numeric folder IDs
         */

        if (is_numeric($folderProperty) === false) {
            $this->logger->warning(message: "Invalid folder ID type for register {$register->getId()}, creating new folder");
            return $this->createRegisterFolderById(register: $register);
        }

        $folderId = (int) $folderProperty;

        // Try to get folder by ID.
        $folder = $this->getNodeById($folderId);

        if ($folder instanceof Folder) {
            return $folder;
        }

        // If stored ID is invalid, recreate the folder.
        $this->logger->warning(message: "Register {$register->getId()} has invalid folder ID, recreating folder");
        return $this->createRegisterFolderById($register);
    }//end getRegisterFolderById()

    /**
     * Get the object folder for an object entity.
     *
     * Attempts to retrieve the object folder using the stored folder ID.
     * If the stored ID is invalid or the folder doesn't exist, creates a new folder.
     *
     * @param ObjectEntity|string $objectEntity The object entity or UUID.
     * @param int|string|null     $registerId   Optional register ID for folder creation.
     *
     * @return Folder|null The object folder or null if not found/created.
     *
     * @psalm-return   Folder|null
     * @phpstan-return Folder|null
     */
    public function getObjectFolder(ObjectEntity|string $objectEntity, int|string|null $registerId=null): ?Folder
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
        }//end if

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
     * Creates a folder for an ObjectEntity and returns the folder ID without updating the object.
     *
     * This method creates a folder structure for an Object Entity within its parent
     * Register and Schema folders, but does not update the object with the folder ID.
     * This allows for single-save workflows where the folder ID is set before saving.
     *
     * @param ObjectEntity $objectEntity The Object Entity to create a folder for.
     * @param IUser|null   $currentUser  The current user to share the folder with.
     *
     * @throws Exception             If folder creation fails or entities not found.
     * @throws NotPermittedException If folder creation is not permitted.
     * @throws NotFoundException     If parent folders do not exist.
     *
     * @psalm-return   int
     * @phpstan-return int
     * @return         int The folder ID.
     */
    public function createObjectFolderWithoutUpdate(ObjectEntity $objectEntity, ?IUser $currentUser=null): int
    {
        // Ensure register folder exists first.
        $register       = $this->registerMapper->find($objectEntity->getRegister());
        $registerFolder = $this->createRegisterFolderById(register: $register, currentUser: $currentUser);

        if ($registerFolder === null || ($registerFolder instanceof Folder) === false) {
            throw new Exception("Failed to create or access register folder");
        }

        // Create object folder within the register folder.
        $objectFolderName = $this->getObjectFolderName($objectEntity);

        try {
            // Try to get existing folder first.
            $objectFolder = $registerFolder->get($objectFolderName);
            $this->logger->info(message: "Object folder already exists: ".$objectFolderName);
        } catch (NotFoundException) {
            // Create new folder if it doesn't exist.
            $objectFolder = $registerFolder->newFolder($objectFolderName);
            $this->logger->info(message: "Created object folder: ".$objectFolderName);
        }

        $this->logger->info(message: "Created object folder with ID: ".$objectFolder->getId());

        // Transfer ownership to OpenRegister and share with current user if needed.
        if ($this->fileService !== null) {
            // TODO: Call $this->fileService->transferFolderOwnershipIfNeeded($objectFolder) once FileOwnershipHandler is extracted.
        }

        // Share the folder with the currently active user if there is one.
        if ($currentUser !== null && $currentUser->getUID() !== $this->getUser()->getUID()) {
            if ($this->fileService !== null) {
                // TODO: Call $this->fileService->shareFolderWithUser(folder: $objectFolder, userId:
                // $currentUser->getUID()) once FileSharingHandler is extracted.
            }
        }

        return $objectFolder->getId();
    }//end createObjectFolderWithoutUpdate()

    /**
     * Create a folder path and return the Node.
     *
     * This method creates the complete folder hierarchy if it doesn't exist,
     * including the root "Open Registers" folder shared with the openregister group.
     *
     * @param string $folderPath The full path to create.
     *
     * @psalm-return   Node
     * @phpstan-return Node
     * @return         Node The folder node.
     * @throws         Exception If folder creation fails.
     */
    public function createFolderPath(string $folderPath): Node
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
                $userFolder->newFolder(self::ROOT_FOLDER);

                if ($this->groupManager->groupExists(self::APP_GROUP) === false) {
                    $this->groupManager->createGroup(self::APP_GROUP);
                }

                if ($this->fileService !== null) {
                    // TODO: Call $this->fileService->createShare() once FileSharingHandler is extracted.
                    // For now, skip share creation (will be handled during integration).
                }
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
                if ($this->fileService !== null) {
                    // TODO: Call $this->fileService->transferFolderOwnershipIfNeeded($node) once FileOwnershipHandler is extracted.
                }

                return $node;
            }
        } catch (NotPermittedException $e) {
            // End try.
            $this->logger->error(message: "Can't create folder $folderPath: ".$e->getMessage());
            throw new Exception("Can't create folder $folderPath");
        }//end try
    }//end createFolderPath()

    /**
     * Public interface to create a folder (delegates to createFolderPath).
     *
     * @param string $folderPath The folder path to create.
     *
     * @return Node The created folder node.
     * @throws Exception If folder creation fails.
     *
     * @psalm-return   Node
     * @phpstan-return Node
     */
    public function createFolder(string $folderPath): Node
    {
        return $this->createFolderPath($folderPath);
    }//end createFolder()

    /**
     * Get the register folder name.
     *
     * Ensures the register name ends with " Register" for consistency.
     *
     * @param Register $register The register to get the folder name for.
     *
     * @return string|null The folder name for the register.
     *
     * @psalm-return   string|null
     * @phpstan-return string|null
     */
    public function getRegisterFolderName(Register $register): string|null
    {
        $title = $register->getTitle();

        if (str_ends_with(haystack: strtolower(rtrim($title ?? '')), needle: 'register') === true) {
            return $title;
        }

        return "$title Register";
    }//end getRegisterFolderName()

    /**
     * Get the object folder name.
     *
     * Returns the UUID if available, otherwise the object ID.
     *
     * @param ObjectEntity|string $objectEntity The object entity or UUID string.
     *
     * @return string The folder name for the object.
     *
     * @psalm-return   string
     * @phpstan-return string
     */
    public function getObjectFolderName(ObjectEntity|string $objectEntity): string
    {
        if (is_string($objectEntity) === true) {
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
     * Get the OpenRegister user root folder.
     *
     * This method provides a consistent way to access the OpenRegister user's
     * root folder across the entire FileService.
     *
     * @return Folder The OpenRegister user's root folder.
     *
     * @throws Exception If the user folder cannot be accessed.
     *
     * @psalm-return   Folder
     * @phpstan-return Folder
     */
    public function getOpenRegisterUserFolder(): Folder
    {
        try {
            $user       = $this->getUser();
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
            return $userFolder;
        } catch (Exception $e) {
            $this->logger->error(message: "Failed to get OpenRegister user folder: ".$e->getMessage());
            throw new Exception("Cannot access OpenRegister user folder: ".$e->getMessage());
        }
    }//end getOpenRegisterUserFolder()

    /**
     * Get a Node by its ID.
     *
     * @param int $nodeId The ID of the node to retrieve.
     *
     * @return Node|null The Node if found, null otherwise.
     *
     * @psalm-return   Node|null
     * @phpstan-return Node|null
     */
    public function getNodeById(int $nodeId): ?Node
    {
        try {
            $userFolder = $this->getOpenRegisterUserFolder();
            $nodes      = $userFolder->getById($nodeId);
            if (empty($nodes) === false) {
                return $nodes[0];
            }

            return null;
        } catch (Exception $e) {
            $this->logger->error(message: "Failed to get node by ID $nodeId: ".$e->getMessage());
            return null;
        }
    }//end getNodeById()

    /**
     * Get node type from node (file or folder).
     *
     * @param Node $node The node to check.
     *
     * @return string Node type ('file' or 'folder').
     *
     * @psalm-return   'file'|'folder'|'unknown'
     * @phpstan-return 'file'|'folder'|'unknown'
     */
    public function getNodeTypeFromFolder(Node $node): string
    {
        if ($node instanceof Folder) {
            return 'folder';
        }

        if ($node instanceof \OCP\Files\File) {
            return 'file';
        }

        return 'unknown';
    }//end getNodeTypeFromFolder()

    /**
     * Get the OpenRegister user from the session.
     *
     * @return IUser The OpenRegister user.
     *
     * @throws Exception If user is not logged in.
     *
     * @psalm-return   IUser
     * @phpstan-return IUser
     */
    private function getUser(): IUser
    {
        $user = $this->userSession->getUser();

        if ($user === null) {
            throw new Exception('User not logged in');
        }

        return $user;
    }//end getUser()

    /**
     * Get the currently active user (not the OpenRegister system user).
     *
     * This method returns the user who is currently logged in and making the request,
     * which is different from the OpenRegister system user used for file operations.
     *
     * @return IUser|null The currently active user or null if no user is logged in.
     *
     * @psalm-return   IUser|null
     * @phpstan-return IUser|null
     */
    private function getCurrentUser(): ?IUser
    {
        return $this->userSession->getUser();
    }//end getCurrentUser()

    /**
     * Try to get existing folder by ID from folder property.
     *
     * @param string|null $folderProperty The folder property to check.
     *
     * @return Folder|null The existing folder or null if not found.
     *
     * @psalm-return   Folder|null
     * @phpstan-return Folder|null
     */
    private function getExistingFolderFromProperty(?string $folderProperty): ?Folder
    {
        /*
         * Check if folder ID is already set and valid (not legacy string).
         * Note: Defensive check for legacy data - getFolder() returns string|null, but legacy data might have int IDs.
         *
         * @psalm-suppress TypeDoesNotContainType - Legacy data handling
         */

        if ($folderProperty === null || $folderProperty === '' || is_string($folderProperty) === true) {
            return null;
        }

        try {
            /*
             * Type assertion: after checking it's not a string, it should be numeric (int or float).
             *
             * @var int|float $folderProperty
             */

            /*
             * @psalm-suppress TypeDoesNotContainType - Legacy numeric folder IDs
             */

            if (is_numeric($folderProperty) === false) {
                throw new Exception('Invalid folder ID type');
            }

            /*
             * @psalm-suppress InvalidCast - numeric value can be cast to int
             */

            $folderId       = (int) $folderProperty;
            $existingFolder = $this->getNodeById($folderId);
            if ($existingFolder !== null && $existingFolder instanceof Folder) {
                return $existingFolder;
            }

            return null;
        } catch (Exception $e) {
            $this->logger->warning(message: "Stored folder ID invalid: ".$e->getMessage());
            return null;
        }//end try
    }//end getExistingFolderFromProperty()

    /**
     * Share folder with current user if different from system user.
     *
     * @param Node       $folderNode  The folder to share.
     * @param IUser|null $currentUser The current user to share with.
     *
     * @return void
     *
     * @psalm-return   void
     * @phpstan-return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function shareFolderWithCurrentUser(Node $folderNode, ?IUser $currentUser): void
    {
        // Share the folder with the currently active user if there is one and different from system user.
        if ($currentUser === null) {
            return;
        }

        if ($currentUser->getUID() === $this->getUser()->getUID()) {
            return;
        }

        if ($this->fileService === null) {
            return;
        }

        // TODO: Call $this->fileService->shareFolderWithUser(folder: $folderNode, userId: $currentUser->getUID())
        // once FileSharingHandler is extracted. The $folderNode parameter will be used when the
        // FileSharingHandler integration is complete.
    }//end shareFolderWithCurrentUser()

    /**
     * Get register from object entity or register ID.
     *
     * @param ObjectEntity|string $objectEntity The object entity or UUID.
     * @param int|string|null     $registerId   Optional register ID.
     *
     * @return Register The register entity.
     *
     * @throws Exception If register cannot be found.
     *
     * @psalm-return   Register
     * @phpstan-return Register
     */
    private function getRegisterFromObjectOrId(ObjectEntity|string $objectEntity, int|string|null $registerId): Register
    {
        $register = null;

        if ($objectEntity instanceof ObjectEntity === true) {
            $register = $this->registerMapper->find($objectEntity->getRegister());
            if ($register === null) {
                $registerUuid = $objectEntity->getRegister();
                throw new Exception("Failed to create file, could not find register for objects register: {$registerUuid}");
            }

            return $register;
        }

        if ($registerId !== null) {
            $register = $this->registerMapper->find($registerId);
            if ($register === null) {
                throw new Exception("Failed to create file, could not find register with register id: $registerId");
            }

            return $register;
        }

        throw new Exception("Failed to create file because no objectEntity or registerId given");
    }//end getRegisterFromObjectOrId()

    /**
     * Create or get object folder within register folder.
     *
     * @param Folder              $registerFolder The parent register folder.
     * @param ObjectEntity|string $objectEntity   The object entity or UUID.
     *
     * @return Folder The object folder.
     *
     * @psalm-return   Folder
     * @phpstan-return Folder
     */
    private function createObjectFolderInRegister(Folder $registerFolder, ObjectEntity|string $objectEntity): Folder
    {
        $objectFolderName = $this->getObjectFolderName($objectEntity);

        try {
            // Try to get existing folder first.
            $objectFolder = $registerFolder->get($objectFolderName);
            $this->logger->info(message: "Object folder already exists: ".$objectFolderName);
            return $objectFolder;
        } catch (NotFoundException) {
            // Create new folder if it doesn't exist.
            $objectFolder = $registerFolder->newFolder($objectFolderName);
            $this->logger->info(message: "Created object folder: ".$objectFolderName);
            return $objectFolder;
        }
    }//end createObjectFolderInRegister()
}//end class
