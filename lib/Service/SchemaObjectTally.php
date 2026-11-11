<?php

/**
 * OpenRegister Schema Object Tally
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
 * @spec openspec/specs/runtime-schema-api/spec.md
 */

namespace OCA\OpenRegister\Service;

/**
 * One count of the objects per schema of a register: the register counted,
 * and the per-schema counts gathered so far. Built once per call by
 * RegisterService::getSchemaObjectCounts(), which returns what it holds
 * even when counting stops part-way.
 *
 * Not strict, like RegisterService, whose schema ids reach it untyped.
 *
 * @spec openspec/specs/runtime-schema-api/spec.md
 */
final class SchemaObjectTally {

	/**
	 * Schema id to counts, filled in as the count proceeds.
	 *
	 * @var array<array-key, array<string, int>>
	 */
	public array $result = [];

	/**
	 * Start a count for a register.
	 *
	 * @param int $registerId The register id.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function __construct(
		public readonly int $registerId,
	) {
	}//end __construct()

	/**
	 * The unprefixed magic table of one of the register's schemas.
	 *
	 * @param mixed $schemaId The schema id, as the schema carried it.
	 *
	 * @return string The table name.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function tableName(mixed $schemaId): string {
		return 'openregister_table_' . $this->registerId . '_' . $schemaId;
	}//end tableName()
}//end class
