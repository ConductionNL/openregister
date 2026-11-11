<?php

/**
 * OpenRegister ExportRunFile
 *
 * The file one export run produced or served, and how often it was handed out.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

/**
 * The file one export run produced or served, and how often it was handed out.
 *
 * One of the three argument groups of {@see ExportRunRecorder::record()}.
 *
 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
 */
final class ExportRunFile {

	/**
	 * Name the file of a run.
	 *
	 * @param string|null $filename      The name it was written or served under.
	 * @param int|null    $fileId        The Nextcloud file it produced, when it produced one.
	 * @param string|null $filePath      Where that file was written.
	 * @param int         $downloadCount How often the register has already served it.
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly ?string $filename=null,
		public readonly ?int $fileId=null,
		public readonly ?string $filePath=null,
		public readonly int $downloadCount=0,
	) {
	}//end __construct()
}//end class
