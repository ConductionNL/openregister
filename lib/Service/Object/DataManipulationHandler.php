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
 * Handles data manipulation operations including:
 * - Nested value extraction via path notation
 * - Slug generation for URLs and identifiers
 * - Property mapping between data structures
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects
 */
class DataManipulationHandler {
	/**
	 * Get a value from nested array using dot notation path
	 *
	 * Traverses a nested array structure using a dot-separated path string.
	 * Returns null if the path doesn't exist at any level.
	 *
	 * Example:
	 * ```php
	 * $data = ['user' => ['profile' => ['name' => 'John']]];
	 * getValueFromPath($data, 'user.profile.name'); // Returns 'John'
	 * ```
	 *
	 * @param array<string, mixed> $data The data array to search.
	 * @param string $path The dot-separated path (e.g., 'user.profile.name').
	 *
	 * @return mixed The value at the path, or null if path doesn't exist
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getValueFromPath(array $data, string $path): mixed {
		$keys = explode('.', $path);
		$current = $data;

		foreach ($keys as $key) {
			if (is_array($current) === false || array_key_exists($key, $current) === false) {
				return null;
			}

			$current = $current[$key];
		}

		return $current;
	}//end getValueFromPath()

	/*
	 * SLUG GENERATION DOES NOT LIVE HERE.
	 *
	 * `generateSlugFromValue()` + `createSlugHelper()` were removed: they had
	 * no callers, and a byte-identical copy of the same pair sat on
	 * `MetadataHandler`. Object slugs are produced by
	 * `SaveObject\MetadataHydrationHandler::generateSlug(array $data, Schema
	 * $schema)` (invoked from that class and from
	 * `SaveObject::generateSlug()`), which is schema-aware — it reads the
	 * schema's configured slug source instead of slugifying whatever string
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
