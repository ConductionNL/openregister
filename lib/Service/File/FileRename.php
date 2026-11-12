<?php

/**
 * One file attached to an object to give a new name.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * A file rename: the object the file is attached to, the file, and its new name.
 *
 * Read by {@see \OCA\OpenRegister\Service\FileService::renameAttachedFile()}.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final readonly class FileRename {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object  The object the file is attached to.
	 * @param int          $fileId  The file id.
	 * @param string       $newName The new file name.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public ObjectEntity $object,
		public int $fileId,
		public string $newName,
	) {
	}//end __construct()
}//end class
