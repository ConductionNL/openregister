<?php

/**
 * OpenRegister OfficeOpenRequest.
 *
 * One request to open a document in Nextcloud Office: the file, who edits
 * it, the name they are shown under and whether the caller lets them write.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\File;

use OCP\Files\File;

/**
 * One request to open a document in Nextcloud Office.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
final class OfficeOpenRequest {
	/**
	 * Constructor.
	 *
	 * @param File   $file        The document.
	 * @param string $editorUid   The user who edits it.
	 * @param string $displayName The name the editor is shown under; empty for the uid.
	 * @param bool   $canWrite    Whether the caller grants write access.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function __construct(
		public readonly File $file,
		public readonly string $editorUid,
		public readonly string $displayName,
		public readonly bool $canWrite,
	) {
	}//end __construct()
}//end class
