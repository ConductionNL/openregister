<?php

/**
 * The handlers that create, read, update and delete file nodes.
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
 * The create, read, update and delete handlers of the file service.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FileNodeHandlers {
	/**
	 * Bundle the collaborators.
	 *
	 * @param CreateFileHandler $createFileHandler Create file handler
	 * @param ReadFileHandler   $readFileHandler   Read file handler
	 * @param UpdateFileHandler $updateFileHandler Update file handler
	 * @param DeleteFileHandler $deleteFileHandler Delete file handler
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly CreateFileHandler $createFileHandler,
		public readonly ReadFileHandler $readFileHandler,
		public readonly UpdateFileHandler $updateFileHandler,
		public readonly DeleteFileHandler $deleteFileHandler,
	) {
	}//end __construct()
}//end class
