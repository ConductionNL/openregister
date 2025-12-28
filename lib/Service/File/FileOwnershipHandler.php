<?php

/**
 * FileOwnershipHandler
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
use OCP\Files\File;
use OCP\Files\Node;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Handles file and folder ownership operations.
 *
 * This handler is responsible for:
 * - Managing OpenRegister system user creation
 * - Transferring file ownership to system user
 * - Transferring folder ownership to system user
 * - Getting current user context
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class FileOwnershipHandler
{
    /**
     * Application user name.
     *
     * @var string
     */
    private const APP_USER = 'openregister';

    /**
     * Application group name.
     *
     * @var string
     */
    private const APP_GROUP = 'openregister';

    /**
     * Constructor for FileOwnershipHandler.
     *
     * @param IUserManager    $userManager  User manager for user operations.
     * @param IGroupManager   $groupManager Group manager for group operations.
     * @param IUserSession    $userSession  User session for user context.
     * @param LoggerInterface $logger       Logger for logging operations.
     */
    public function __construct(
        private readonly IUserManager $userManager,
        private readonly IGroupManager $groupManager,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Gets or creates the OpenRegister user for file operations.
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
        $openRegisterUser = $this->userManager->get(self::APP_USER);

        if ($openRegisterUser === null) {
            // Create OpenRegister user if it doesn't exist.
            $password         = bin2hex(random_bytes(16));
            $openRegisterUser = $this->userManager->createUser(self::APP_USER, $password);

            if ($openRegisterUser === false) {
                throw new Exception('Failed to create OpenRegister user account.');
            }

            // Add user to OpenRegister group.
            $group = $this->groupManager->get(self::APP_GROUP);
            if ($group === null) {
                $group = $this->groupManager->createGroup(self::APP_GROUP);
            }

            // Get the current user from the session.
            $currentUser = $this->userSession->getUser();

            if ($group !== null && $openRegisterUser !== null) {
                $group->addUser($openRegisterUser);
                if ($currentUser !== null) {
                    $group->addUser($currentUser);
                }
            }

            $this->logger->info(message: 'OpenRegister user created successfully');
        }//end if

        return $openRegisterUser;
    }//end getUser()

    /**
     * Get the currently active user from the session.
     *
     * This method retrieves the actual logged-in user from the session,
     * which is different from the OpenRegister system user used for file operations.
     *
     * @return IUser|null The currently active user or null if no user is logged in.
     *
     * @psalm-return   IUser|null
     * @phpstan-return IUser|null
     */
    public function getCurrentUser(): ?IUser
    {
        return $this->userSession->getUser();
    }//end getCurrentUser()

    /**
     * Transfer file ownership to OpenRegister user and share with current user.
     *
     * This method checks if the current user owns a file and if they are not the OpenRegister
     * system user. If so, it transfers ownership to the OpenRegister user and creates a share
     * with the current user to maintain access.
     *
     * NOTE: This method depends on FileSharingHandler->shareFileWithUser().
     * During integration, either inject FileSharingHandler or call through FileService facade.
     *
     * @param File                    $file               The file to potentially transfer ownership for.
     * @param FileSharingHandler|null $fileSharingHandler Optional sharing handler for creating shares.
     *
     * @return void
     *
     * @throws Exception If ownership transfer fails.
     *
     * @psalm-return   void
     * @phpstan-return void
     */
    public function transferFileOwnershipIfNeeded(File $file, ?FileSharingHandler $fileSharingHandler = null): void
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
            $openRegisterUser   = $this->getUser();
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
                $storage = $file->getStorage();
                if (method_exists($storage, 'chown') === true) {
                    $storage->chown($file->getInternalPath(), $openRegisterUserId);
                }

                // Create a share with the current user to maintain access.
                if ($fileSharingHandler !== null) {
                    $fileSharingHandler->shareFileWithUser(file: $file, userId: $currentUserId);
                }

                $this->logger->info(message: "Successfully transferred ownership and shared file {$file->getName()} with {$currentUserId}");
            }//end if
        } catch (Exception $e) {
            $this->logger->error(message: "Failed to transfer file ownership for {$file->getName()}: " . $e->getMessage());
            // Don't throw the exception to avoid breaking file operations.
            // The file operation should succeed even if ownership transfer fails.
        }//end try
    }//end transferFileOwnershipIfNeeded()

    /**
     * Transfer folder ownership to OpenRegister user and share with current user.
     *
     * This method checks if the current user owns a folder and if they are not the OpenRegister
     * system user. If so, it transfers ownership to the OpenRegister user and creates a share
     * with the current user to maintain access.
     *
     * NOTE: This method depends on FileSharingHandler->shareFolderWithUser().
     * During integration, either inject FileSharingHandler or call through FileService facade.
     *
     * @param Node                    $folder             The folder to potentially transfer ownership for.
     * @param FileSharingHandler|null $fileSharingHandler Optional sharing handler for creating shares.
     *
     * @return void
     *
     * @throws Exception If ownership transfer fails.
     *
     * @psalm-return   void
     * @phpstan-return void
     */
    public function transferFolderOwnershipIfNeeded(Node $folder, ?FileSharingHandler $fileSharingHandler = null): void
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
            $openRegisterUser   = $this->getUser();
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
                $storage = $folder->getStorage();
                if (method_exists($storage, 'chown') === true) {
                    $storage->chown($folder->getInternalPath(), $openRegisterUserId);
                }

                // Create a share with the current user to maintain access.
                if ($fileSharingHandler !== null) {
                    $fileSharingHandler->shareFolderWithUser(folder: $folder, userId: $currentUserId);
                }

                $this->logger->info(message: "Successfully transferred ownership and shared folder {$folder->getName()} with {$currentUserId}");
            }//end if
        } catch (Exception $e) {
            $this->logger->error(message: "Failed to transfer folder ownership for {$folder->getName()}: " . $e->getMessage());
            // Don't throw the exception to avoid breaking folder operations.
            // The folder operation should succeed even if ownership transfer fails.
        }//end try
    }//end transferFolderOwnershipIfNeeded()
}//end class
