<?php

/**
 * OpenRegister file guard services
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
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

namespace OCA\OpenRegister\Files\Controller\Support;

use OCA\OpenRegister\Files\Service\File\FileAuditHandler;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuard;
use OCA\OpenRegister\Files\Service\File\ObjectFileAccess;
use OCA\OpenRegister\Service\Object\FileWriteGuardInterface;

/**
 * Access, write and restriction checks plus the file audit; each is null when absent.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FileGuards {
	/**
	 * Bundle the services.
	 *
	 * @param ?ObjectFileAccess     $objectFileAccess The object file access check.
	 * @param ?FileWriteGuardInterface       $fileWriteGuard   The file write guard.
	 * @param ?FileRestrictionGuard $fileRestrictions The file restriction guard.
	 * @param ?FileAuditHandler     $fileAuditHandler The file audit handler.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly ?ObjectFileAccess $objectFileAccess = null,
		public readonly ?FileWriteGuardInterface $fileWriteGuard = null,
		public readonly ?FileRestrictionGuard $fileRestrictions = null,
		public readonly ?FileAuditHandler $fileAuditHandler = null,
	) {
	}//end __construct()
}//end class
