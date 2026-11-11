<?php

/**
 * FileSharingHandler
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

namespace OCA\OpenRegister\Files\Service\File;

use Exception;
use OCP\Files\File;
use OCP\Files\Node;
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
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class FileSharingHandler implements FileSharingHandlerInterface {
	/**
	 * Constructor for FileSharingHandler.
	 *
	 * @param IManager            $shareManager Share manager for share operations.
	 * @param FileSharingAccounts $accounts     The user manager and the system-account ownership handler.
	 * @param ShareUrlBuilder     $shareUrls    Builds share links for the host the visitor used.
	 * @param LoggerInterface     $logger       Logger for logging operations.
	 */
	public function __construct(
		private readonly IManager $shareManager,
		private readonly FileSharingAccounts $accounts,
		private readonly ShareUrlBuilder $shareUrls,
		private readonly LoggerInterface $logger,
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
	 *
	 * @spec openspec/specs/file-actions/spec.md#requirement-a-file-link-points-at-the-host-the-visitor-used
	 */
	public function getShareLink(IShare $share): string {
		return $this->shareUrls->shareLink(token: $share->getToken());
	}//end getShareLink()

	/**
	 * Find shares for a given file or folder.
	 *
	 * @param Node $file The file or folder to find shares for.
	 * @param int $shareType The share type to filter by (default: public link = 3).
	 *
	 * @return IShare[] Array of shares.
	 *
	 * @psalm-return   array<IShare>
	 * @phpstan-return array<int, IShare>
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function findShares(Node $file, int $shareType = 3): array {
		// Use the OpenRegister system user instead of current user session.
		// This ensures we can find shares created by the OpenRegister system user.
		$userId = $this->accounts->fileOwnershipHandler->getUser()->getUID();

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
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function createShare(array $shareData): IShare {
		$userId = $this->sharerId(shareData: $shareData);
		$share = $this->newShareFrom(shareData: $shareData, sharedBy: $userId);

		// Actually create the share.
		return $this->persistShare(share: $share, subject: "{$shareData['path']} by user {$userId}");
	}//end createShare()

	/**
	 * Who creates the share: the file's owner when a file with an owner is given, else the OpenRegister user.
	 *
	 * @param array<string, mixed> $shareData The share data.
	 *
	 * @return string The sharer's uid.
	 */
	private function sharerId(array $shareData): string {
		// Use the file's owner as the share creator for better compatibility.
		// This avoids permission issues when the OpenRegister user doesn't own the file.
		$userId = $this->accounts->fileOwnershipHandler->getUser()->getUID();

		// If we have a file object and it has an owner, use that owner as the sharer.
		if (empty($shareData['file']) === false) {
			$fileOwner = $shareData['file']->getOwner();
			if ($fileOwner !== null) {
				$userId = $fileOwner->getUID();
			}
		}

		return $userId;
	}//end sharerId()

	/**
	 * A new share carrying the node, type, permissions, sharer and recipient of the share data.
	 *
	 * @param array<string, mixed> $shareData The share data.
	 * @param string               $sharedBy  The sharer's uid.
	 *
	 * @return IShare The share, not yet created.
	 */
	private function newShareFrom(array $shareData, string $sharedBy): IShare {
		// Create a new share.
		$share = $this->shareManager->newShare();
		$this->setShareNode(share: $share, shareData: $shareData);

		$share->setShareType(shareType: $shareData['shareType']);

		if (($shareData['permissions'] ?? null) !== null) {
			$share->setPermissions(permissions: $shareData['permissions']);
		}

		$share->setSharedBy(sharedBy: $sharedBy);

		// Add the sharedWith for user and group shares.
		if (empty($shareData['sharedWith']) === false) {
			$share->setSharedWith(sharedWith: $shareData['sharedWith']);
		}

		return $share;
	}//end newShareFrom()

	/**
	 * Point the share at its node: the file itself, or a node id and type.
	 *
	 * @param IShare               $share     The new share.
	 * @param array<string, mixed> $shareData The share data.
	 *
	 * @return void
	 */
	private function setShareNode(IShare $share, array $shareData): void {
		// Use setNode directly when file is available (more reliable than setNodeId).
		if (empty($shareData['file']) === false) {
			$share->setNode($shareData['file']);
		} elseif (empty($shareData['nodeId']) === false) {
			$share->setNodeId(fileId: $shareData['nodeId']);
			$share->setNodeType(type: $shareData['nodeType'] ?? 'file');
		}
	}//end setShareNode()

	/**
	 * Create the share; a refusal is logged and rethrown with its cause.
	 *
	 * @param IShare $share   The share to create.
	 * @param string $subject What the log lines name: "<path> by user <uid>".
	 *
	 * @return IShare The created share.
	 *
	 * @throws Exception If creating the share fails.
	 */
	private function persistShare(IShare $share, string $subject): IShare {
		try {
			$this->shareManager->createShare($share);
			$this->logger->info(
				message: "Successfully created share for {$subject}"
			);
			return $share;
		} catch (Exception $e) {
			$msg = '[FileSharingHandler] Failed to create share for ' . $subject;
			$this->logger->error(
				message: $msg . ': ' . $e->getMessage()
			);
			throw new Exception('Failed to create share: ' . $e->getMessage());
		}
	}//end persistShare()

	/**
	 * Share a file with a user unless it already is.
	 *
	 * @param UserShareGrant $grant The file, the user and the permissions.
	 *
	 * @return void
	 *
	 * @throws Exception If sharing fails.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function shareFileWithUser(UserShareGrant $grant): void {
		try {
			if ($this->alreadySharedWith(grant: $grant) === false) {
				$this->createUserShare(grant: $grant);
			}
		} catch (Exception $e) {
			$msg = '[FileSharingHandler] Failed to share file ' . $grant->node->getName() . ' with user ' . $grant->userId;
			$this->logger->error(
				message: $msg . ': ' . $e->getMessage()
			);
			throw $e;
		}
	}//end shareFileWithUser()

	/**
	 * Whether the OpenRegister user already shares the file with the user (logged when so).
	 *
	 * @param UserShareGrant $grant The file and the user.
	 *
	 * @return bool
	 */
	private function alreadySharedWith(UserShareGrant $grant): bool {
		// Check if a share already exists with this user.
		foreach ($this->systemUserShares(grant: $grant) as $share) {
			if ($share->getSharedWith() === $grant->userId) {
				$this->logger->info(
					message: '[FileSharingHandler] Share already exists for file ' . $grant->node->getName() . ' with user ' . $grant->userId
				);
				return true;
			}
		}

		return false;
	}//end alreadySharedWith()

	/**
	 * The OpenRegister user's user shares of the grant's node.
	 *
	 * @param UserShareGrant $grant The node.
	 *
	 * @return IShare[]
	 */
	private function systemUserShares(UserShareGrant $grant): array {
		return $this->shareManager->getSharesBy(
			userId: $this->accounts->fileOwnershipHandler->getUser()->getUID(),
			shareType: IShare::TYPE_USER,
			path: $grant->node
		);
	}//end systemUserShares()

	/**
	 * Share the file with the user as the OpenRegister user.
	 *
	 * @param UserShareGrant $grant The file, the user and the permissions.
	 *
	 * @return void
	 *
	 * @throws Exception If creating the share fails.
	 */
	private function createUserShare(UserShareGrant $grant): void {
		// Create new share.
		$share = $this->shareManager->newShare();
		$share->setNode($grant->node);
		$share->setShareType(IShare::TYPE_USER);
		$share->setSharedWith($grant->userId);
		$share->setSharedBy($this->accounts->fileOwnershipHandler->getUser()->getUID());
		$share->setPermissions($grant->permissions);

		$this->shareManager->createShare(share: $share);

		$this->logger->info(
			message: '[FileSharingHandler] Created share for file ' . $grant->node->getName() . ' with user ' . $grant->userId
		);
	}//end createUserShare()

	/**
	 * Share a folder with an existing user.
	 *
	 * @param UserShareGrant $grant The folder, the user and the permissions.
	 *
	 * @return IShare|null The created share, or null when the user does not exist or sharing fails.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function shareFolderWithUser(UserShareGrant $grant): ?IShare {
		try {
			// Check if user exists.
			if ($this->recipientMissing(grant: $grant) === true) {
				return null;
			}

			return $this->createFolderShare(grant: $grant);
		} catch (Exception $e) {
			$msg = "Failed to share folder '{$grant->node->getName()}' with user '$grant->userId': " . $e->getMessage();
			$this->logger->error(message: '[FileSharingHandler] ' . $msg, context: ['file' => __FILE__, 'line' => __LINE__]);
			return null;
		}
	}//end shareFolderWithUser()

	/**
	 * Whether the recipient does not exist (logged as a warning when so).
	 *
	 * @param UserShareGrant $grant The folder and the user.
	 *
	 * @return bool
	 */
	private function recipientMissing(UserShareGrant $grant): bool {
		if ($this->accounts->userManager->userExists($grant->userId) === false) {
			$this->logger->warning(
				message: '[FileSharingHandler] Cannot share folder with user ' . $grant->userId . ' - user does not exist',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return true;
		}

		return false;
	}//end recipientMissing()

	/**
	 * Share the folder with the user by node id.
	 *
	 * @param UserShareGrant $grant The folder, the user and the permissions.
	 *
	 * @return IShare The created share.
	 *
	 * @throws Exception If creating the share fails.
	 */
	private function createFolderShare(UserShareGrant $grant): IShare {
		// Create the share.
		$share = $this->createShare(shareData: $this->folderShareData(grant: $grant));

		$this->logger->info(
			message: '[FileSharingHandler] Successfully shared folder ' . $grant->node->getName() . ' with user ' . $grant->userId,
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		return $share;
	}//end createFolderShare()

	/**
	 * The share data of a user share of a folder, by node id.
	 *
	 * @param UserShareGrant $grant The folder, the user and the permissions.
	 *
	 * @return array<string, mixed>
	 */
	private function folderShareData(UserShareGrant $grant): array {
		return [
			'path' => ltrim($grant->node->getPath(), '/'),
			'nodeId' => $grant->node->getId(),
			'nodeType' => 'folder',
			'shareType' => IShare::TYPE_USER,
			'permissions' => $grant->permissions,
			'sharedWith' => $grant->userId,
		];
	}//end folderShareData()
}//end class
