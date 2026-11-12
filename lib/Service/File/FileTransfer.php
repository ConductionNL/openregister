<?php

/**
 * One file to copy or move from one object to another.
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
 * A file transfer: the object the file is attached to, the file, and the object it goes to.
 *
 * Read by {@see \OCA\OpenRegister\Service\FileService::copyAttachedFile()} and
 * {@see \OCA\OpenRegister\Service\FileService::moveAttachedFile()}.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final readonly class FileTransfer {
	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $source The object the file is attached to.
	 * @param int          $fileId The file id.
	 * @param ObjectEntity $target The object the file goes to.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public ObjectEntity $source,
		public int $fileId,
		public ObjectEntity $target,
	) {
	}//end __construct()
}//end class
