<?php

/**
 * FileOwnershipHandler
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
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class FileOwnershipHandler implements FileOwnershipHandlerInterface {
	/**
	 * Application group name.
	 *
	 * @var string
	 */
	private const APP_GROUP = 'openregister';

	/**
	 * Constructor for FileOwnershipHandler.
	 *
	 * @param IUserManager $userManager User manager for user operations.
	 * @param IGroupManager $groupManager Group manager for group operations.
	 * @param IUserSession $userSession User session for user context.
	 * @param LoggerInterface $logger Logger for logging operations.
	 */
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
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
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getUser(): IUser {
		$openRegisterUser = $this->userManager->get(self::APP_USER);

		if ($openRegisterUser === null) {
			$openRegisterUser = $this->createSystemAccount();
		}

		return $openRegisterUser;
	}//end getUser()

	/**
	 * Create the OpenRegister system account, unlimited and in the app group.
	 *
	 * @return IUser The new account.
	 *
	 * @throws Exception If the account cannot be created.
	 */
	private function createSystemAccount(): IUser {
		// Create OpenRegister user if it doesn't exist.
		$password = bin2hex(random_bytes(16));
		$openRegisterUser = $this->userManager->createUser(self::APP_USER, $password);

		if ($openRegisterUser === false) {
			throw new Exception('Failed to create OpenRegister user account.');
		}

		// Every object's file counts against this account, so it starts
		// unlimited. An admin can still set a limit afterwards.
		$openRegisterUser->setQuota('none');

		$this->joinAppGroup(account: $openRegisterUser);

		$this->logger->info(
			message: '[FileOwnershipHandler] OpenRegister user created successfully',
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return $openRegisterUser;
	}//end createSystemAccount()

	/**
	 * Add the new system account, and the signed-in user if any, to the OpenRegister group.
	 *
	 * @param IUser $account The new system account.
	 *
	 * @return void
	 */
	private function joinAppGroup(IUser $account): void {
		// Add user to OpenRegister group.
		$group = $this->groupManager->get(self::APP_GROUP);
		if ($group === null) {
			$group = $this->groupManager->createGroup(self::APP_GROUP);
		}

		// Get the current user from the session.
		$currentUser = $this->userSession->getUser();

		if ($group !== null) {
			$group->addUser($account);
			if ($currentUser !== null) {
				$group->addUser($currentUser);
			}
		}
	}//end joinAppGroup()

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
	public function getCurrentUser(): ?IUser {
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
	 * @param File $file The file to potentially transfer ownership for.
	 * @param FileSharingHandlerInterface|null $fileSharingHandler Optional sharing handler for creating shares.
	 *
	 * @return void
	 *
	 * @throws Exception If ownership transfer fails.
	 *
	 * @psalm-return   void
	 * @phpstan-return void
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function transferFileOwnershipIfNeeded(File $file, ?FileSharingHandlerInterface $fileSharingHandler = null): void {
		$transfer = new OwnershipTransferRun(node: $file, noun: 'file');
		$transfer->sharing = $fileSharingHandler;
		try {
			// If the current user already has write rights on the file, leave ownership
			// as-is. Files uploaded into the OpenRegister folder are already owned by the
			// openregister system user (the folder's mount owner), and files linked from
			// folders outside the OpenRegister folder must keep their original owner — in
			// neither case do we re-own. Re-owning remains only as a fallback for when the
			// session cannot otherwise write the file.
			if ($file->isUpdateable() === false) {
				$this->transferIfCallerOwns(transfer: $transfer);
			}
		} catch (Exception $e) {
			// Don't throw the exception to avoid breaking file operations.
			// The file operation should succeed even if ownership transfer fails.
			$this->logTransferFailure(transfer: $transfer, error: $e);
		}
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
	 * @param Node $folder The folder to potentially transfer ownership for.
	 * @param FileSharingHandlerInterface|null $fileSharingHandler Optional sharing handler for creating shares.
	 *
	 * @return void
	 *
	 * @throws Exception If ownership transfer fails.
	 *
	 * @psalm-return   void
	 * @phpstan-return void
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function transferFolderOwnershipIfNeeded(Node $folder, ?FileSharingHandlerInterface $fileSharingHandler = null): void {
		$transfer = new OwnershipTransferRun(node: $folder, noun: 'folder');
		$transfer->sharing = $fileSharingHandler;
		try {
			$this->transferIfCallerOwns(transfer: $transfer);
		} catch (Exception $e) {
			// Don't throw the exception to avoid breaking folder operations.
			// The folder operation should succeed even if ownership transfer fails.
			$this->logTransferFailure(transfer: $transfer, error: $e);
		}
	}//end transferFolderOwnershipIfNeeded()

	/**
	 * Re-own the node to the OpenRegister user when the signed-in caller (not the
	 * OpenRegister user itself) owns it.
	 *
	 * @param OwnershipTransferRun $transfer The transfer; receives the two accounts.
	 *
	 * @return void
	 */
	private function transferIfCallerOwns(OwnershipTransferRun $transfer): void {
		if ($this->resolveAccounts(transfer: $transfer) === false) {
			return;
		}

		// Re-own only when the current user owns the node. They are already known
		// not to be the OpenRegister user (the equality case returned above).
		if ($this->ownerId(transfer: $transfer) === $transfer->currentUserId) {
			$this->transferToOpenRegister(transfer: $transfer);
		}
	}//end transferIfCallerOwns()

	/**
	 * Record the caller's and the OpenRegister user's uids.
	 *
	 * @param OwnershipTransferRun $transfer The transfer; receives the two accounts.
	 *
	 * @return bool False when nobody is signed in or the caller is the OpenRegister user.
	 */
	private function resolveAccounts(OwnershipTransferRun $transfer): bool {
		$currentUser = $this->getCurrentUser();
		if ($currentUser === null) {
			// No user logged in, nothing to do.
			return false;
		}

		$transfer->currentUserId = $currentUser->getUID();
		$transfer->openRegisterUserId = $this->getUser()->getUID();

		// If current user is already the OpenRegister user, nothing to do.
		return $transfer->currentUserId !== $transfer->openRegisterUserId;
	}//end resolveAccounts()

	/**
	 * The node owner's uid; null (with a warning) when the node has no owner.
	 *
	 * @param OwnershipTransferRun $transfer The transfer.
	 *
	 * @return string|null
	 */
	private function ownerId(OwnershipTransferRun $transfer): ?string {
		$owner = $transfer->node->getOwner();
		if ($owner === null) {
			$this->logger->warning(
				message: '[FileOwnershipHandler] ' . ucfirst($transfer->noun) . ' ' . $transfer->node->getName() . ' has no owner, skipping ownership transfer'
			);
			return null;
		}

		return $owner->getUID();
	}//end ownerId()

	/**
	 * Chown the node to the OpenRegister user and share it back to the caller.
	 *
	 * @param OwnershipTransferRun $transfer The transfer with both accounts known.
	 *
	 * @return void
	 */
	private function transferToOpenRegister(OwnershipTransferRun $transfer): void {
		$name = $transfer->node->getName();
		$msg = '[FileOwnershipHandler] Transferring ' . $transfer->noun . ' ' . $name . ' from ' . $transfer->currentUserId;
		$this->logger->info(
			message: $msg . ' to ' . $transfer->openRegisterUserId
		);

		// Change ownership to OpenRegister user.
		$storage = $transfer->node->getStorage();
		if (method_exists($storage, 'chown') === true) {
			$storage->chown($transfer->node->getInternalPath(), $transfer->openRegisterUserId);
		}

		// Create a share with the current user to maintain access.
		$this->shareBack(transfer: $transfer);

		$this->logger->info(
			message: "[FileOwnershipHandler] Successfully transferred and shared {$transfer->noun} {$name}"
		);
	}//end transferToOpenRegister()

	/**
	 * Share the node back to the caller, when a sharing handler was given.
	 *
	 * @param OwnershipTransferRun $transfer The transfer.
	 *
	 * @return void
	 */
	private function shareBack(OwnershipTransferRun $transfer): void {
		if ($transfer->sharing === null) {
			return;
		}

		$grant = new UserShareGrant(node: $transfer->node, userId: $transfer->currentUserId);
		if ($transfer->isFile() === true) {
			$transfer->sharing->shareFileWithUser(grant: $grant);
			return;
		}

		$transfer->sharing->shareFolderWithUser(grant: $grant);
	}//end shareBack()

	/**
	 * Log a failed transfer as an error.
	 *
	 * @param OwnershipTransferRun $transfer The transfer.
	 * @param Exception            $error    The failure.
	 *
	 * @return void
	 */
	private function logTransferFailure(OwnershipTransferRun $transfer, Exception $error): void {
		$errMsg = '[FileOwnershipHandler] Failed to transfer ' . $transfer->noun . ' ownership for ' . $transfer->node->getName();
		$this->logger->error(
			message: $errMsg . ': ' . $error->getMessage()
		);
	}//end logTransferFailure()
}//end class
