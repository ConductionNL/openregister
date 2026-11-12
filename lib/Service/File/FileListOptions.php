<?php

/**
 * How to narrow the files listed for an object.
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
 * File list options: whether to list only shared files, and the category to filter on.
 *
 * Read by {@see ReadFileHandler::getFiles()}.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final readonly class FileListOptions {
	/**
	 * Constructor.
	 *
	 * @param bool|null   $sharedFilesOnly Whether to return only shared files.
	 * @param string|null $category        FileMapper category to filter on; null lists every category.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public ?bool $sharedFilesOnly = false,
		public ?string $category = null,
	) {
	}//end __construct()
}//end class
