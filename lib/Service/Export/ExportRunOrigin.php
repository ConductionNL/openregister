<?php

/**
 * OpenRegister ExportRunOrigin
 *
 * Who and what produced one export run, and the status to record it under.
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
 * Who and what produced one export run, and the status to record it under.
 *
 * One of the three argument groups of {@see ExportRunRecorder::record()}.
 *
 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
 */
final class ExportRunOrigin {

	/**
	 * Name the origin of a run.
	 *
	 * @param string      $source  What produced it, for example `scheduled-report`.
	 * @param string      $actor   Who asked for it.
	 * @param string|null $profile The profile or report it came from.
	 * @param string|null $status  The status to write, defaulting to available.
	 *
	 * @spec openspec/changes/an-export-is-a-file-with-a-life/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly string $source,
		public readonly string $actor,
		public readonly ?string $profile=null,
		public readonly ?string $status=null,
	) {
	}//end __construct()
}//end class
