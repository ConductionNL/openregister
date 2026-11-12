<?php

/**
 * The handlers that store files and the folders they live in.
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
 * The node and folder handlers of the file service.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FileStorageHandlers {
	/**
	 * Bundle the collaborators.
	 *
	 * @param FileNodeHandlers   $nodes   The create, read, update and delete handlers
	 * @param FileFolderHandlers $folders The validation, folder, ownership and sharing handlers
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly FileNodeHandlers $nodes,
		public readonly FileFolderHandlers $folders,
	) {
	}//end __construct()
}//end class
