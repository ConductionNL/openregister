<?php

/**
 * One file to write into an object's folder.
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

/**
 * A file upload: its name, its content, whether to share it, and its tags.
 *
 * Read by {@see CreateFileHandler::addFile()} and {@see CreateFileHandler::saveFile()}.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final readonly class FileUpload {
	/**
	 * Constructor.
	 *
	 * @param string $fileName The name of the file.
	 * @param mixed $content File content: a byte string, or a readable stream resource.
	 * @param bool $share Whether to create a share link for a new file.
	 * @param array<int, string> $tags Tags to attach to the file.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public string $fileName,
		public mixed $content,
		public bool $share,
		public array $tags = [],
	) {
	}//end __construct()
}//end class
