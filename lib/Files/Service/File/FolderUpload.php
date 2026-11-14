<?php

/**
 * An upload of one file into a folder of an object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

/**
 * Where the file goes, its name and its bytes.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class FolderUpload {
	/**
	 * Constructor.
	 *
	 * @param ObjectFolderPath $folder  The target folder of the object.
	 * @param string           $name    The file's name: one segment.
	 * @param resource|string  $content The file's bytes, or a readable stream.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly ObjectFolderPath $folder,
		public readonly string $name,
		public readonly mixed $content,
	) {
	}//end __construct()
}//end class
