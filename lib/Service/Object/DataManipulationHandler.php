<?php

/**
 * DataManipulationHandler - Data Transformation and Manipulation Handler
 *
 * Handles data transformation, property mapping, slug generation, and path-based access.
 * This handler consolidates utility functions for manipulating object data,
 * making these operations more testable and maintainable.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * DataManipulationHandler class
 *
 * Maps properties between data structures.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects
 */
class DataManipulationHandler {

	/*
	 * SLUG GENERATION DOES NOT LIVE HERE.
	 *
	 * `generateSlugFromValue()` + `createSlugHelper()` were removed: they had
	 * no callers, and a byte-identical copy of the same pair sat on
	 * `MetadataHandler`. Object slugs are decided by
	 * `SaveObject\ObjectSlugHandler` (#4037), which is schema-aware — it reads
	 * the schema's configured slug source, keeps a slug unique per register
	 * and schema and frozen once set — instead of slugifying whatever string
	 * a caller happened to pass. Nothing should reintroduce a
	 * schema-unaware slug helper here.
	 */

	/**
	 * Map properties from source data to target structure
	 *
	 * Performs simple key-based property mapping. Only maps properties that exist
	 * in the source data - missing properties are not included in the result.
	 *
	 * Example:
	 * ```php
	 * $source = ['firstName' => 'John', 'lastName' => 'Doe'];
	 * $mapping = ['name' => 'firstName', 'surname' => 'lastName'];
	 * mapObjectProperties($source, $mapping);
	 * // Returns: ['name' => 'John', 'surname' => 'Doe']
	 * ```
	 *
	 * @param array<string, mixed> $sourceData The source data array.
	 * @param array<string, string> $mapping Mapping array (target => source property names).
	 *
	 * @return array<string, mixed> The mapped data
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function mapObjectProperties(array $sourceData, array $mapping): array {
		$mappedData = [];

		// Simple mapping: keys are target properties, values are source properties.
		foreach ($mapping as $targetProperty => $sourceProperty) {
			// Only map if the source property exists in the source data.
			if (array_key_exists($sourceProperty, $sourceData) === true) {
				$mappedData[$targetProperty] = $sourceData[$sourceProperty];
			}
		}

		return $mappedData;
	}//end mapObjectProperties()
}//end class
