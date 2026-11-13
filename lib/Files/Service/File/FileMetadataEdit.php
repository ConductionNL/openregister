<?php

/**
 * FileMetadataEdit
 *
 * One row of the file metadata form being applied: the object and the row,
 * the file it names, what the file says now, and what the row changed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The object, row, file id, current metadata and changes of one form row.
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */
final class FileMetadataEdit {

	/**
	 * The file id the row names.
	 *
	 * @var integer
	 */
	public readonly int $fileId;

	/**
	 * The file's metadata as it is now, once read.
	 *
	 * @var array<string, mixed>
	 */
	public array $current = [];

	/**
	 * What the row changed: field => old and new value.
	 *
	 * @var array<string, array{old: mixed, new: mixed}>
	 */
	public array $diff = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity $object The object the file belongs to.
	 * @param array        $entry  The row; its numeric 'fileId' names the file.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly array $entry,
	) {
		$this->fileId = (int)$entry['fileId'];
	}//end __construct()
}//end class
