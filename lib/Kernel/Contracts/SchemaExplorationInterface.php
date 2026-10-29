<?php

/**
 * SchemaExplorationInterface: explore a schema's stored objects and apply what was found.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use OCA\OpenRegister\Db\Schema;

/**
 * Reads the properties a schema's stored objects actually carry, and writes chosen ones back to the schema. Implemented by SchemaService.
 */
interface SchemaExplorationInterface {

	/**
	 * Explore objects and discover new properties for a schema
	 *
	 * This method analyzes all objects belonging to a specific schema and identifies
	 * properties that exist in the object data but are not defined in the schema.
	 *
	 * PROCESS:
	 * 1. Retrieves all objects for the specified schema
	 * 2. Analyzes the 'object' JSON field of each object
	 * 3. Creates a summary of property usage and types
	 * 4. Compares discovered properties against existing schema properties
	 * 5. Returns suggestions for schema updates
	 *
	 * @param int $schemaId The ID of the schema to explore
	 *
	 * @return array Exploration results with discovered properties
	 *
	 * @throws \Exception If schema not found or analysis fails
	 */
	public function exploreSchemaProperties(int $schemaId): array;

	/**
	 * Update schema properties based on exploration suggestions
	 *
	 * Applies user-confirmed property updates to a schema. This method validates
	 * the updates and applies them to the schema definition.
	 *
	 * @param int $schemaId The schema ID to update
	 * @param array $propertyUpdates Array of properties to add/update
	 *
	 * @throws \Exception If schema update fails
	 *
	 * @return Schema Updated schema entity
	 */
	public function updateSchemaFromExploration(int $schemaId, array $propertyUpdates): Schema;
}//end interface
