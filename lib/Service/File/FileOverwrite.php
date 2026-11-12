<?php

/**
 * One existing file to overwrite.
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
 * A file overwrite: the file, its new content, its tags, and the object whose folder is searched first.
 *
 * Read by {@see \OCA\OpenRegister\Service\FileService::overwriteFile()}.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final readonly class FileOverwrite {
	/**
	 * Constructor.
	 *
	 * @param string|int        $filePath The path (from root) or the file id.
	 * @param mixed             $content  New content (byte string or readable stream); null updates only the tags.
	 * @param array<int,string> $tags     Tags to attach, besides the object tags that are kept.
	 * @param ObjectEntity|null $object   The object whose folder is searched first.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public string|int $filePath,
		public mixed $content = null,
		public array $tags = [],
		public ?ObjectEntity $object = null,
	) {
	}//end __construct()
}//end class
