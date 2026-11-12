<?php

/**
 * One removal of a schema that keeps its objects: the schema, how many objects use it, and the force flag.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Db\Schema;

/**
 * The schema being removed, the objects that still reference it, and whether the caller forced it.
 *
 * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
 */
final class SchemaRemoval {

	/**
	 * Constructor.
	 *
	 * @param Schema $schema      The schema to delete.
	 * @param int    $objectCount Objects still referencing it, across all registers.
	 * @param bool   $force       Whether the caller passed ?force=true to orphan them.
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly int $objectCount,
		public readonly bool $force,
	) {
	}//end __construct()
}//end class
