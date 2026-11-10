<?php

/**
 * SchemaCascade: one cascade delete of a schema, its objects and its magic tables.
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
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Schema;

/**
 * The schema being cascade deleted, the override the caller passed and the
 * magic tables resolved once for the whole operation.
 *
 * ADR-011 Rule 2 component-local value object for
 * SchemaDeletionService::cascadeInOneTransaction().
 *
 * @internal
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */
final class SchemaCascade {

	/**
	 * Describe it.
	 *
	 * @param Schema                 $schema   The schema being deleted.
	 * @param SchemaTeardownOverride $override Which teardown guards the caller lifts.
	 * @param array                  $tables   The magic tables of the schema (registerId, register, tableName).
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly SchemaTeardownOverride $override,
		public readonly array $tables,
	) {
	}//end __construct()
}//end class
