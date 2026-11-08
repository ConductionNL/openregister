<?php

/**
 * OpenRegister bulk download archive
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
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Files\Service\File;

/**
 * What one ZIP download contained, as recorded in its single audit row.
 *
 * Not strict on purpose: the values come straight from the caller's ZIP info
 * array, so the caller's own mode decides how they are checked, exactly as
 * when they were passed to logBulkDownload() one by one.
 *
 * @spec openspec/changes/retrofit-2026-05-24-audit-trail-immutable/tasks.md#task-4
 */
final class BulkDownloadArchive {
	/**
	 * Describe the archive.
	 *
	 * @param int[]    $fileIds    File IDs included in the archive.
	 * @param string[] $fileNames  File names included in the archive (parallel index to fileIds).
	 * @param string   $zipName    The generated ZIP filename.
	 * @param int|null $totalBytes Total uncompressed bytes across included files (best-effort).
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-audit-trail-immutable/tasks.md#task-4
	 */
	public function __construct(
		public readonly array $fileIds,
		public readonly array $fileNames,
		public readonly string $zipName,
		public readonly ?int $totalBytes = null,
	) {
	}//end __construct()
}//end class
