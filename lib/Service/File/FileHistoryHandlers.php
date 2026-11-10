<?php

/**
 * The handlers that keep the history of a file.
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

use OCA\OpenRegister\Files\Service\File\FileAuditHandler;

/**
 * The versioning, lock and audit handlers of the file service.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FileHistoryHandlers {
	/**
	 * Bundle the collaborators.
	 *
	 * @param FileVersioningHandler $fileVerHandler   File versioning handler
	 * @param FileLockHandler       $fileLockHandler  File lock handler
	 * @param FileAuditHandler      $fileAuditHandler File audit handler
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly FileVersioningHandler $fileVerHandler,
		public readonly FileLockHandler $fileLockHandler,
		public readonly FileAuditHandler $fileAuditHandler,
	) {
	}//end __construct()
}//end class
