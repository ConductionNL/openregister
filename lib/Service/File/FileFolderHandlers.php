<?php

/**
 * The handlers that manage folders, ownership and sharing of files.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Files\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Files\Service\File\FileSharingHandler;

/**
 * The validation, folder, ownership and sharing handlers of the file service.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FileFolderHandlers {
	/**
	 * Bundle the collaborators.
	 *
	 * @param FileValidationHandler   $fileValidHandler     File validation handler
	 * @param FolderManagementHandler $folderMgmtHandler    Folder management handler
	 * @param FileOwnershipHandler    $fileOwnershipHandler File ownership handler
	 * @param FileSharingHandler      $fileSharingHandler   File sharing handler
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly FileValidationHandler $fileValidHandler,
		public readonly FolderManagementHandler $folderMgmtHandler,
		public readonly FileOwnershipHandler $fileOwnershipHandler,
		public readonly FileSharingHandler $fileSharingHandler,
	) {
	}//end __construct()
}//end class
