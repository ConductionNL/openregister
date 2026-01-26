<?php

/**
 * FileSharingHandler
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
use OCP\Files\File;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Handles file and folder sharing operations.
 *
 * This handler is responsible for:
 * - Creating share links (public links)
 * - Creating shares (user, group, public)
 * - Sharing files with specific users
 * - Sharing folders with specific users
 * - Finding existing shares
 * - Getting share links
 * - Publishing/unpublishing files
 *
 * NOTE: This is Phase 1B implementation with core structure.
 * Full method extraction from FileService to be completed in Phase 2.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class FileSharingHandler
{
    /**
     * Constructor for FileSharingHandler.
     *
     * @param IManager             $shareManager         Share manager for share operations.
     * @param IUserManager         $userManager          User manager for user operations.
     * @param IURLGenerator        $urlGenerator         URL generator for creating share links.
     * @param IConfig              $config               Configuration service.
     * @param LoggerInterface      $logger               Logger for logging operations.
     * @param FileOwnershipHandler $fileOwnershipHandler Ownership handler for user operations.
     */
    public function __construct(
        private readonly IManager $shareManager,
        private readonly IUserManager $userManager,
        private readonly IURLGenerator $urlGenerator,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
        private readonly FileOwnershipHandler $fileOwnershipHandler
    ) {
    }//end __construct()

    /**
     * Get the share link URL for a given share.
     *
     * @param IShare $share The share to get the link for.
     *
     * @return string
     *
     * @psalm-return   string
     * @phpstan-return string
     */
    public function getShareLink(IShare $share): string
    {
        return $this->getCurrentDomain().'/index.php/s/'.$share->getToken();
    }//end getShareLink()

    /**
     * Find shares for a given file or folder.
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
        // Use the OpenRegister system user instead of current user session.
        // This ensures we can find shares created by the OpenRegister system user.
        $userId = $this->fileOwnershipHandler->getUser()->getUID();

        return $this->shareManager->getSharesBy(userId: $userId, shareType: $shareType, path: $file, reshares: true);
    }//end findShares()

    /**
     * Create a share with the given share data.
     *
     * @param array<string, mixed> $shareData The data to create a share with
     *
     * @throws Exception If creating the share fails
     *
     * @return IShare The created share object
     *
     * @psalm-param array{
     *     path: string,
     *     file?: File,
     *     nodeId?: int,
     *     nodeType?: string,
     *     shareType: int,
     *     permissions?: int,
     *     sharedWith?: string
     * } $shareData
     *
     * @psalm-return   IShare
     * @phpstan-return IShare
     */
    public function createShare(array $shareData): IShare
    {
        // Use the file's owner as the share creator for better compatibility.
        // This avoids permission issues when the OpenRegister user doesn't own the file.
        $userId = $this->fileOwnershipHandler->getUser()->getUID();

        // If we have a file object and it has an owner, use that owner as the sharer.
        if (empty($shareData['file']) === false) {
            $fileOwner = $shareData['file']->getOwner();
            if ($fileOwner !== null) {
                $userId = $fileOwner->getUID();
            }
        }

        // Create a new share.
        $share = $this->shareManager->newShare();

        // Use setNode directly when file is available (more reliable than setNodeId).
        if (empty($shareData['file']) === false) {
            $share->setNode($shareData['file']);
        } else if (empty($shareData['nodeId']) === false) {
            $share->setNodeId(fileId: $shareData['nodeId']);
            $share->setNodeType(type: $shareData['nodeType'] ?? 'file');
        }

        $share->setShareType(shareType: $shareData['shareType']);

        if (($shareData['permissions'] ?? null) !== null) {
            $share->setPermissions(permissions: $shareData['permissions']);
        }

        $share->setSharedBy(sharedBy: $userId);

        // Add the sharedWith for user and group shares.
        if (empty($shareData['sharedWith']) === false) {
            $share->setSharedWith(sharedWith: $shareData['sharedWith']);
        }

        // Actually create the share.
        try {
            $this->shareManager->createShare($share);
            $this->logger->info(
                message: "Successfully created share for {$shareData['path']} by user {$userId}"
            );
            return $share;
        } catch (Exception $e) {
            $this->logger->error(message: "Failed to create share for {$shareData['path']} by user {$userId}: ".$e->getMessage());
            throw new Exception("Failed to create share: ".$e->getMessage());
        }
    }//end createShare()

    /**
     * Share a file with a specific user.
     *
     * @param File   $file        The file to share.
     * @param string $userId      The user ID to share with.
     * @param int    $permissions The permissions to grant (default: 31 = all).
     *
     * @return void
     *
     * @throws Exception If sharing fails.
     *
     * @psalm-return   void
     * @phpstan-return void
     */
    public function shareFileWithUser(File $file, string $userId, int $permissions=31): void
    {
        try {
            // Check if a share already exists with this user.
            $existingShares = $this->shareManager->getSharesBy(
                userId: $this->fileOwnershipHandler->getUser()->getUID(),
                shareType: IShare::TYPE_USER,
                path: $file
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
            $share->setShareType(IShare::TYPE_USER);
            $share->setSharedWith($userId);
            $share->setSharedBy($this->fileOwnershipHandler->getUser()->getUID());
            $share->setPermissions($permissions);

            $this->shareManager->createShare(share: $share);

            $this->logger->info(message: "Created share for file {$file->getName()} with user {$userId}");
        } catch (Exception $e) {
            $this->logger->error(message: "Failed to share file {$file->getName()} with user {$userId}: ".$e->getMessage());
            throw $e;
        }//end try
    }//end shareFileWithUser()

    /**
     * Share a folder with a specific user.
     *
     * @param Node   $folder      The folder to share.
     * @param string $userId      The user ID to share with.
     * @param int    $permissions The permissions to grant (default: 31 = all).
     *
     * @return IShare|null The created share or null if user doesn't exist.
     *
     * @psalm-return   IShare|null
     * @phpstan-return IShare|null
     */
    public function shareFolderWithUser(Node $folder, string $userId, int $permissions=31): ?IShare
    {
        try {
            // Check if user exists.
            if ($this->userManager->userExists($userId) === false) {
                $this->logger->warning(message: "Cannot share folder with user '$userId' - user does not exist");
                return null;
            }

            // Create the share.
            $share = $this->createShare(
                shareData: [
                    'path'        => ltrim($folder->getPath(), '/'),
                    'nodeId'      => $folder->getId(),
                    'nodeType'    => 'folder',
                    'shareType'   => IShare::TYPE_USER,
                    'permissions' => $permissions,
                    'sharedWith'  => $userId,
                ]
            );

            $this->logger->info(message: "Successfully shared folder '{$folder->getName()}' with user '$userId'");
            return $share;
        } catch (Exception $e) {
            $msg = "Failed to share folder '{$folder->getName()}' with user '$userId': ".$e->getMessage();
            $this->logger->error(message: $msg);
            return null;
        }//end try
    }//end shareFolderWithUser()

    /**
     * Get the current domain with correct protocol.
     *
     * @return string The current http/https domain URL.
     *
     * @psalm-return   string
     * @phpstan-return string
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
}//end class
