<?php

/**
 * OpenRegister RowImportTarget
 *
 * The table one source row is transformed for, and who imports it.
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

use OCA\OpenRegister\Db\RegisterSchema;
use OCP\IUser;

/**
 * The register and schema a row becomes an object of, and the importing user
 * whose admin role decides whether `@self.*` columns are honoured (ADR-011 Rule 2).
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
final class RowImportTarget {
	/**
	 * Build the target.
	 *
	 * @param RegisterSchema $table    The register and schema.
	 * @param IUser|null     $importer The user performing the import; null imports as a non-admin.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public function __construct(
		public readonly RegisterSchema $table,
		public readonly ?IUser $importer = null,
	) {
	}//end __construct()
}//end class
