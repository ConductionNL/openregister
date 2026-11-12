<?php

/**
 * The selection, writing and validation steps an export profile is run and saved through.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

use OCA\OpenRegister\Service\ExportService;

/**
 * A dependency bundle of {@see ExportProfileService}.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ExportProfileSteps {
	/**
	 * Bundle the services.
	 *
	 * @param ExportService          $exportService The selection every export shares.
	 * @param ExportProfileWriter    $writer        Projection and file writing.
	 * @param ExportProfileValidator $validator     Judges a submitted profile.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly ExportService $exportService,
		public readonly ExportProfileWriter $writer,
		public readonly ExportProfileValidator $validator,
	) {
	}//end __construct()
}//end class
