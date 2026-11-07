<?php

/**
 * OpenRegister Update File Guards
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

/**
 * Checks a file before its content is replaced and repairs its ownership after: executable screening, the ownership check and the ownership transfer.
 *
 * @spec openspec/specs/file-actions/spec.md#requirement-file-update-guards-locks-preserves-object-tags-and-persists-or-side-metadata-separately
 */
final readonly class UpdateFileGuards {

	/**
	 * Bundle the collaborators.
	 *
	 * @param FileValidationHandler $fileValidHandler File validation handler.
	 * @param FileOwnershipHandler $fileOwnershipHandler File ownership handler.
	 */
	public function __construct(
		public FileValidationHandler $fileValidHandler,
		public FileOwnershipHandler $fileOwnershipHandler,
	) {
	}//end __construct()
}//end class
