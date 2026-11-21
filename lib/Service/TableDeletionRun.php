<?php

/**
 * TableDeletionRun: one whole-table delete of a register's objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-schema-wide-object-deletion-is-refused-on-an-archival-schema
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Register;

/**
 * The register whose magic tables are emptied, whether the rows are hard
 * deleted, and what triggered it: the state every helper of one whole-table
 * delete in SchemaDeletionService shares.
 *
 * ADR-011 Rule 3 run object; it never leaves SchemaDeletionService.
 *
 * @internal
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md#requirement-schema-wide-object-deletion-is-refused-on-an-archival-schema
 */
final class TableDeletionRun {

	/**
	 * Describe it.
	 *
	 * @param Register $register    The register whose tables are emptied.
	 * @param bool     $hardDelete  Whether the rows are hard deleted.
	 * @param string   $triggeredBy What triggered the delete, recorded on every audit row.
	 */
	public function __construct(
		public readonly Register $register,
		public readonly bool $hardDelete,
		public readonly string $triggeredBy,
	) {
	}//end __construct()
}//end class
