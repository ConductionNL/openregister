<?php

/**
 * OpenRegister ImportDestination
 *
 * The register and schema one import preview reads into and writes to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The register and schema one import preview reads into and writes to.
 *
 * The two travel together through every row decision and every write of
 * {@see ImportPreviewService}, so they are passed as one.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
final class ImportDestination {

	/**
	 * Name the destination.
	 *
	 * @param Register $register The target register.
	 * @param Schema   $schema   The target schema.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
	) {
	}//end __construct()
}//end class
