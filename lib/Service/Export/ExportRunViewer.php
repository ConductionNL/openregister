<?php

/**
 * OpenRegister ExportRunViewer
 *
 * Who is looking at the export runs, and whether they see every run.
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
 * Who is looking at the export runs, and whether they see every run.
 *
 * The scope half of {@see ExportRunRecorder::listVisible()}; the page and
 * filters travel in a ListQuery beside it.
 *
 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
 */
final class ExportRunViewer {

	/**
	 * Name the viewer.
	 *
	 * @param string|null $actor        The caller, null when anonymous.
	 * @param bool        $seesEveryRun Whether the caller is an instance administrator.
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly ?string $actor,
		public readonly bool $seesEveryRun=false,
	) {
	}//end __construct()
}//end class
