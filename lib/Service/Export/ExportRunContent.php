<?php

/**
 * OpenRegister ExportRunContent
 *
 * What one export run read and wrote out.
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
 * What one export run read and wrote out.
 *
 * One of the three argument groups of {@see ExportRunRecorder::record()}.
 *
 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
 */
final class ExportRunContent {

	/**
	 * Name the content of a run.
	 *
	 * @param string      $format       csv, json and so on.
	 * @param int         $rowCount     How many rows went out.
	 * @param string|null $registerName The register it read.
	 * @param string|null $schemaName   The schema it read.
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly string $format,
		public readonly int $rowCount,
		public readonly ?string $registerName=null,
		public readonly ?string $schemaName=null,
	) {
	}//end __construct()
}//end class
