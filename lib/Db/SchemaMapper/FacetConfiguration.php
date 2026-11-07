<?php

/**
 * FacetConfiguration: the facet block stored with a schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db\SchemaMapper;

use OCA\OpenRegister\Db\Schema;

/**
 * Derives the stored facet block from a schema's properties.
 *
 * Facets are computed at runtime from property-level `facetable: true`; this block
 * is kept for backward compatibility. Not strict_types: an integer property name
 * coerces to a string, as it did inside SchemaMapper.
 *
 * @spec exclude schema save plumbing moved out of SchemaMapper, not a capability of its own
 */
final class FacetConfiguration {

	private const SELF_FACETS = [
		'register' => ['type' => 'terms'],
		'schema' => ['type' => 'terms'],
		'created' => ['type' => 'date_histogram', 'interval' => 'month'],
		'updated' => ['type' => 'date_histogram', 'interval' => 'month'],
		'published' => ['type' => 'date_histogram', 'interval' => 'month'],
		'owner' => ['type' => 'terms'],
	];

	private const COMMON_FACET_FIELDS = [
		'type',
		'status',
		'category',
		'tags',
		'label',
		'group',
		'department',
		'location',
		'priority',
		'state',
		'classification',
		'genre',
		'brand',
		'model',
		'version',
		'license',
		'language',
	];

	private const DATE_TYPES = ['date', 'datetime', 'date-time'];

	private const DATE_FIELD_WORDS = ['created', 'updated', 'modified', 'date', 'time'];

	/**
	 * Generate the facet configuration from the schema properties.
	 *
	 * @param Schema $schema The schema to generate facets for.
	 *
	 * @return void
	 *
	 * @spec exclude moved unchanged from SchemaMapper, which named no spec for it
	 */
	public function generate(Schema $schema): void {
		$objectFields = [];
		foreach ($schema->getProperties() as $fieldName => $property) {
			if (is_array($property) === false) {
				continue;
			}

			$facetType = $this->typeOf(property: $property, fieldName: $fieldName);
			if ($facetType !== null) {
				$objectFields[$fieldName] = $this->facet(type: $facetType);
			}
		}

		$schema->setFacets(['@self' => self::SELF_FACETS, 'object_fields' => $objectFields]);
	}//end generate()

	/**
	 * One facet entry; date histograms group by month.
	 *
	 * @param string $type The facet type.
	 *
	 * @return array<string, string>
	 */
	private function facet(string $type): array {
		if ($type === 'date_histogram') {
			return ['type' => $type, 'interval' => 'month'];
		}

		return ['type' => $type];
	}//end facet()

	/**
	 * Determine the facet type of a property.
	 *
	 * An explicit `facetable` setting wins over every detection below it.
	 *
	 * @param array<string, mixed> $property  The property definition.
	 * @param string               $fieldName The field name.
	 *
	 * @return string|null The facet type, or null if not facetable.
	 *
	 * @psalm-return 'date_histogram'|'terms'|null
	 */
	private function typeOf(array $property, string $fieldName): ?string {
		$facetable = $this->explicitFlag(facetable: ($property['facetable'] ?? null));
		if ($facetable === false) {
			return null;
		}

		$lowerFieldName = strtolower($fieldName);
		if ($facetable === true || in_array($lowerFieldName, self::COMMON_FACET_FIELDS) === true) {
			return $this->declaredType(property: $property);
		}

		return $this->detectedType(property: $property, lowerFieldName: $lowerFieldName);
	}//end typeOf()

	/**
	 * What a `facetable` setting says, or null when it says nothing.
	 *
	 * @param mixed $facetable The setting.
	 *
	 * @return bool|null
	 */
	private function explicitFlag(mixed $facetable): ?bool {
		if (is_bool($facetable) === true) {
			return $facetable;
		}

		if (is_string($facetable) === false) {
			return null;
		}

		$words = ['false' => false, 'true' => true];

		return ($words[strtolower(trim($facetable))] ?? null);
	}//end explicitFlag()

	/**
	 * The type for a facet that is on: dates get a histogram, everything else terms.
	 *
	 * Checks both 'type' (e.g. type: date) and 'format' (e.g. type: string, format:
	 * date) because JSON Schema represents dates as type: "string" with format: "date".
	 *
	 * @param array<string, mixed> $property The property definition.
	 *
	 * @return string
	 *
	 * @psalm-return 'date_histogram'|'terms'
	 */
	private function declaredType(array $property): string {
		if (in_array(($property['type'] ?? 'string'), self::DATE_TYPES) === true
			|| in_array(($property['format'] ?? ''), self::DATE_TYPES) === true
		) {
			return 'date_histogram';
		}

		return 'terms';
	}//end declaredType()

	/**
	 * Detect a facet from the property's enum, its date type or a date-like name.
	 *
	 * @param array<string, mixed> $property       The property definition.
	 * @param string               $lowerFieldName The lowercased field name.
	 *
	 * @return string|null
	 *
	 * @psalm-return 'date_histogram'|'terms'|null
	 */
	private function detectedType(array $property, string $lowerFieldName): ?string {
		if ($this->hasEnum(property: $property) === true) {
			return 'terms';
		}

		if (in_array(($property['type'] ?? ''), self::DATE_TYPES) === true) {
			return 'date_histogram';
		}

		if ($this->hasDateName(lowerFieldName: $lowerFieldName) === true) {
			return 'date_histogram';
		}

		return null;
	}//end detectedType()

	/**
	 * Whether a property lists its values.
	 *
	 * @param array<string, mixed> $property The property definition.
	 *
	 * @return bool
	 */
	private function hasEnum(array $property): bool {
		$enum = ($property['enum'] ?? null);

		return is_array($enum) === true && count($enum) > 0;
	}//end hasEnum()

	/**
	 * Whether a field name reads as a date.
	 *
	 * @param string $lowerFieldName The lowercased field name.
	 *
	 * @return bool
	 */
	private function hasDateName(string $lowerFieldName): bool {
		$matches = array_filter(
			self::DATE_FIELD_WORDS,
			static fn (string $word): bool => str_contains($lowerFieldName, $word)
		);

		return $matches !== [];
	}//end hasDateName()
}//end class
