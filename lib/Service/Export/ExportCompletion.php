<?php

/**
 * ExportCompletion — what a finished export wrote, as the audit trail records it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Export;

/**
 * One completed export: the profile it ran under, how many rows left the
 * instance, the format written and the value mode, when a profile chose one.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class ExportCompletion {

	/**
	 * Describe the completed export.
	 *
	 * @param string      $profile   The profile name, or `ad-hoc` when the caller named no profile.
	 * @param int         $rowCount  How many rows left the instance.
	 * @param string      $format    The format written.
	 * @param string|null $valueMode The value mode the file was written in, when a profile chose one.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $profile,
		public readonly int $rowCount,
		public readonly string $format,
		public readonly ?string $valueMode = null,
	) {
	}//end __construct()
}//end class
