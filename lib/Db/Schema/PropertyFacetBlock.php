<?php

/**
 * PropertyFacetBlock: the stored facet block regenerated from facetable properties.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\Schema
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\Schema;


/**
 * Builds the facet block Schema::regenerateFacetsFromProperties() stores.
 *
 * Reads property definitions only; no object value passes through it.
 *
 * No strict_types: moved out of Schema, which declares none, so every value
 * keeps the coercion it had there.
 *
 * @spec exclude schema plumbing moved out of the entity, not a capability of its own
 */
final class PropertyFacetBlock {

	/**
	 * The facet block for the facetable properties; null when the schema has no properties.
	 *
	 * @param array       $properties  The schema's properties.
	 * @param string|null $version     The schema's version.
	 * @param int         $generatedAt When the block is generated, as a Unix timestamp.
	 *
	 * @return array|null
	 */
	public function built(array $properties, ?string $version, int $generatedAt): ?array {
		if (empty($properties) === true) {
			return null;
		}

		return [
			'object_fields' => $this->objectFields(properties: $properties),
			'generated_at' => $generatedAt,
			'schema_version' => $version ?? '1.0',
		];
	}//end built()

	/**
	 * The facets of the properties marked `facetable: true`, keyed by property name.
	 *
	 * @param array $properties The schema's properties.
	 *
	 * @return array
	 */
	private function objectFields(array $properties): array {
		$fields = [];
		foreach ($properties as $propertyKey => $property) {
			// Skip properties that are not marked as facetable.
			if (isset($property['facetable']) === true && $property['facetable'] === true) {
				$fields[$propertyKey] = $this->facet(propertyKey: $propertyKey, property: $property);
			}
		}

		return $fields;
	}//end objectFields()

	/**
	 * The facet of one facetable property.
	 *
	 * @param int|string $propertyKey The property name.
	 * @param array      $property    The property configuration.
	 *
	 * @return array
	 */
	private function facet(int|string $propertyKey, array $property): array {
		// Determine appropriate facet type based on property configuration.
		$facetType = $this->determineFacetType(property: $property);

		$facet = [
			'type' => $facetType,
			'title' => $property['title'] ?? $propertyKey,
			'description' => $property['description'] ?? null,
			'data_type' => $property['type'] ?? 'string',
			'queryParameter' => $propertyKey,
		];

		return $this->withTypeOptions(facet: $facet, property: $property);
	}//end facet()

	/**
	 * The facet with its type-specific configuration added.
	 *
	 * @param array $facet    The facet so far.
	 * @param array $property The property configuration.
	 *
	 * @return array
	 */
	private function withTypeOptions(array $facet, array $property): array {
		if ($facet['type'] === 'date_histogram') {
			$facet['default_interval'] = 'month';
			$facet['supported_intervals'] = ['day', 'week', 'month', 'year'];
		} elseif ($facet['type'] === 'range') {
			$facet['supports_custom_ranges'] = true;
		} elseif ($facet['type'] === 'terms' && (($property['enum'] ?? null) !== null)) {
			$facet['predefined_values'] = $property['enum'];
		}

		return $facet;
	}//end withTypeOptions()

	/**
	 * Determine the appropriate facet type for a property
	 *
	 * Date/datetime fields use date_histogram; numeric fields can use range
	 * facets; string, boolean, array and every other type use terms.
	 *
	 * @param array $property The property configuration
	 *
	 * @phpstan-param array<string, mixed> $property
	 *
	 * @psalm-param array<string, mixed> $property
	 *
	 * @return string The facet type
	 */
	private function determineFacetType(array $property): string {
		$type = $property['type'] ?? 'string';
		if ($type === 'string' && $this->isDateFormat(format: ($property['format'] ?? null)) === true) {
			return 'date_histogram';
		}

		if ($type === 'number' || $type === 'integer') {
			return 'range';
		}

		return 'terms';
	}//end determineFacetType()

	/**
	 * Whether a string format is a date or a date-time.
	 *
	 * @param mixed $format The declared format.
	 *
	 * @return bool
	 */
	private function isDateFormat(mixed $format): bool {
		return $format === 'date' || $format === 'date-time';
	}//end isDateFormat()
}//end class
