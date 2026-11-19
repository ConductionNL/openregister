<?php

/**
 * FileOwnershipHandlerInterface: what callers use of FileOwnershipHandler.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use OCA\OpenRegister\Kernel\Contracts\Core\FileAccountInterface;
use OCP\Files\File;
use OCP\Files\Node;

/**
 * Gives openregister's own account and moves files and folders into it.
 *
 * The account and the file move are the Core port FileAccountInterface, which the file handlers
 * ask; this interface adds the folder move and the optional sharing handler.
 */
interface FileOwnershipHandlerInterface extends FileAccountInterface {

	/**
	 * Application user name: the account openregister owns its files under.
	 *
	 * @var string
	 */
	public const APP_USER = 'openregister';

	/**
	 * Transfer file ownership to OpenRegister user and share with current user.
	 *
	 * @param File                    $file               The file to potentially transfer ownership for.
	 * @param FileSharingHandlerInterface|null $fileSharingHandler Optional sharing handler for creating shares.
	 *
	 * @return void
	 *
	 * @throws \Exception If ownership transfer fails.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function transferFileOwnershipIfNeeded(File $file, ?FileSharingHandlerInterface $fileSharingHandler=null): void;

	/**
	 * Transfer folder ownership to OpenRegister user and share with current user.
	 *
	 * @param Node                    $folder             The folder to potentially transfer ownership for.
	 * @param FileSharingHandlerInterface|null $fileSharingHandler Optional sharing handler for creating shares.
	 *
	 * @return void
	 *
	 * @throws \Exception If ownership transfer fails.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function transferFolderOwnershipIfNeeded(Node $folder, ?FileSharingHandlerInterface $fileSharingHandler=null): void;
}//end interface
