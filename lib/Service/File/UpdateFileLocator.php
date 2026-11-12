<?php

/**
 * OpenRegister Update File Locator
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

/**
 * Finds the file an update is about: by id through the read handler, or in the object and user folders.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-file-update-guards-locks-preserves-object-tags-and-persists-or-side-metadata-separately
 */
final readonly class UpdateFileLocator {

	/**
	 * Bundle the collaborators.
	 *
	 * @param FolderManagementHandler $folderMgmtHandler Folder management handler.
	 * @param ReadFileHandler $readFileHandler Read file handler.
	 */
	public function __construct(
		public FolderManagementHandler $folderMgmtHandler,
		public ReadFileHandler $readFileHandler,
	) {
	}//end __construct()
}//end class
