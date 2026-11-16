<?php

/**
 * ObjectFileReader: the published read of an object's attached files.
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
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File;

use OCA\OpenRegister\Contract\ObjectFileReaderInterface;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;

/**
 * Lists an object's files through the file service and keeps only the files.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
class ObjectFileReader implements ObjectFileReaderInterface {

	/**
	 * Constructor.
	 *
	 * @param FileService $fileService The file service that owns the object folders.
	 */
	public function __construct(
		private readonly FileService $fileService
	) {
	}//end __construct()

	/**
	 * The files attached to an object, as Nextcloud file nodes.
	 *
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return array<int,File> The files, in the folder's order.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function getObjectFiles(string $objectUuid): array {
		$files = [];
		foreach ($this->fileService->getFiles(object: $objectUuid) as $node) {
			if ($node instanceof File === true) {
				$files[] = $node;
			}
		}

		return $files;
	}//end getObjectFiles()
}//end class
