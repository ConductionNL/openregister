<?php

/**
 * One change to a file's OpenRegister-side metadata.
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
 * A file metadata change: the file, and the description, category and labels to write.
 *
 * A null field is left as it is; an empty string or empty list clears it.
 * Read by {@see UpdateFileHandler::updateFileMetadata()}.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final readonly class FileMetadataChange {
	/**
	 * Constructor.
	 *
	 * @param int                $fileId      The Nextcloud filecache fileid.
	 * @param string|null        $description New description; '' clears, null skips.
	 * @param string|null        $category    New category; '' clears, null skips.
	 * @param array<string>|null $labels      New labels; [] clears, null skips.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public int $fileId,
		public ?string $description = null,
		public ?string $category = null,
		public ?array $labels = null,
	) {
	}//end __construct()

	/**
	 * The fields to write, by name, null for a field to skip.
	 *
	 * @return array{description: string|null, category: string|null, labels: array<string>|null} The fields.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function values(): array {
		return ['description' => $this->description, 'category' => $this->category, 'labels' => $this->labels];
	}//end values()
}//end class
