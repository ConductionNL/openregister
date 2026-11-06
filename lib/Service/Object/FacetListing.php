<?php

/**
 * OpenRegister FacetListing
 *
 * The facets of one response in the standard format.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/faceting-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;


use OCA\OpenRegister\Db\MagicMapper\MagicColumnNameTrait;

/**
 * Turns computed facets into the standard response: metadata facets first, then object fields,
 * each numbered in turn unless its configuration sets an order.
 *
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-response-standardized-format
 */
final class FacetListing {
	use MagicColumnNameTrait;

	/**
	 * The metadata fields a facet can be built on, with their index field and type.
	 *
	 * @var array<string, array{title: string, description: string, data_type: string, index_field: string, index_type: string, enabled: bool}>
	 */
	private const METADATA_DEFINITIONS = [
		'register' => [
			'title' => 'Register',
			'description' => 'metadata field: Register',
			'data_type' => 'integer',
			'index_field' => 'self_register',
			'index_type' => 'pint',
			'enabled' => false,
		],
		'schema' => [
			'title' => 'Type',
			'description' => 'metadata field: Schema',
			'data_type' => 'integer',
			'index_field' => 'self_schema',
			'index_type' => 'pint',
			'enabled' => true,
		],
		'organisation' => [
			'title' => 'Leverancier',
			'description' => 'metadata field: Organisation',
			'data_type' => 'string',
			'index_field' => 'self_organisation',
			'index_type' => 'string',
			'enabled' => true,
		],
		'owner' => [
			'title' => 'Owner',
			'description' => 'metadata field: Owner',
			'data_type' => 'string',
			'index_field' => 'self_owner',
			'index_type' => 'string',
			'enabled' => true,
		],
		'created' => [
			'title' => 'Created',
			'description' => 'metadata field: Created',
			'data_type' => 'datetime',
			'index_field' => 'self_created',
			'index_type' => 'pdate',
			'enabled' => false,
		],
		'updated' => [
			'title' => 'Updated',
			'description' => 'metadata field: Updated',
			'data_type' => 'datetime',
			'index_field' => 'self_updated',
			'index_type' => 'pdate',
			'enabled' => false,
		],
	];

	/**
	 * The entries so far, by response key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $entries = [];

	/**
	 * How many facets have been numbered.
	 *
	 * @var integer
	 */
	private int $order = 0;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $facetableConfig The discovered facetable fields.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-response-standardized-format
	 */
	public function __construct(
		private readonly array $facetableConfig,
	) {
	}//end __construct()

	/**
	 * The standard form of the computed facets.
	 *
	 * @param array<int|string, mixed> $facets The computed facets; `@self` holds the metadata facets.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-response-standardized-format
	 */
	public function format(array $facets): array {
		if (isset($facets['@self']) === true && is_array($facets['@self']) === true) {
			$this->addMetadataFacets(metadataFacets: $facets['@self']);
		}

		foreach ($facets as $field => $facetData) {
			if ($field !== '@self') {
				$this->addObjectFacet(field: $field, facetData: $facetData);
			}
		}

		return $this->entries;
	}//end format()

	/**
	 * List the metadata facets.
	 *
	 * @param array<int|string, mixed> $metadataFacets The facets under `@self`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-metadata-facets-via-self-namespace
	 */
	private function addMetadataFacets(array $metadataFacets): void {
		foreach ($metadataFacets as $field => $facetData) {
			$name = '_' . $field;
			$entry = (new FacetEntry(name: $name, definition: $this->metadataDefinition(field: $field)))
				->from(source: 'metadata', queryParameter: '@self[' . $field . ']')
				->placed(order: $this->nextOrder(configOrder: null));
			$this->entries[$name] = $entry->build(facetData: $facetData);
		}
	}//end addMetadataFacets()

	/**
	 * The definition of a metadata field; a field without one is an enabled string facet.
	 *
	 * @param int|string $field The metadata field.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-metadata-facets-via-self-namespace
	 */
	private function metadataDefinition(int|string $field): array {
		return self::METADATA_DEFINITIONS[$field] ?? [
			'title' => ucfirst($field),
			'description' => 'metadata field: ' . ucfirst($field),
			'data_type' => 'string',
			'index_field' => 'self_' . $field,
			'index_type' => 'string',
			'enabled' => true,
		];
	}//end metadataDefinition()

	/**
	 * List one object field facet.
	 *
	 * @param string $field     The response key.
	 * @param mixed  $facetData The computed facet.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-response-standardized-format
	 */
	private function addObjectFacet(string $field, mixed $facetData): void {
		if (($facetData['_nonAggregated'] ?? false) === true) {
			$this->addNonAggregatedFacet(field: $field, facetData: $facetData);
			return;
		}

		$this->addAggregatedFacet(field: $field, facetData: $facetData);
	}//end addObjectFacet()

	/**
	 * List a facet computed for one schema; its internal markers do not reach the response.
	 *
	 * @param string               $field     The response key.
	 * @param array<string, mixed> $facetData The computed facet with its markers.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-non-aggregated-facet-isolation
	 */
	private function addNonAggregatedFacet(string $field, array $facetData): void {
		$config = $facetData['_facetConfig'] ?? [];
		$schemaId = $facetData['_schemaId'] ?? null;
		$fieldName = $facetData['_fieldName'] ?? $field;
		unset($facetData['_nonAggregated'], $facetData['_schemaId'], $facetData['_facetConfig'], $facetData['_fieldName']);

		$definition = $this->configured(definition: $this->objectDefinition(field: $fieldName, facetData: $facetData), config: $config);
		$entry = (new FacetEntry(name: $fieldName, definition: $definition))
			->from(source: 'object', queryParameter: $fieldName)
			->placed(order: $this->nextOrder(configOrder: ($config['order'] ?? null)), schemaId: $schemaId);
		$this->entries[$field] = $entry->build(facetData: $facetData);
	}//end addNonAggregatedFacet()

	/**
	 * List a facet computed across schemas.
	 *
	 * @param string               $field     The field and response key.
	 * @param array<string, mixed> $facetData The computed facet.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-response-standardized-format
	 */
	private function addAggregatedFacet(string $field, array $facetData): void {
		$config = $this->facetableConfig['object_fields'][$field]['facetConfig'] ?? null;
		$definition = $this->configured(definition: $this->objectDefinition(field: $field, facetData: $facetData), config: $config);
		$entry = (new FacetEntry(name: $field, definition: $definition))
			->from(source: 'object', queryParameter: $field)
			->placed(order: $this->nextOrder(configOrder: ($config['order'] ?? null)));
		$this->entries[$field] = $entry->build(facetData: $facetData);
	}//end addAggregatedFacet()

	/**
	 * The next position: every facet advances the count, and a configured order takes the place of the count.
	 *
	 * @param mixed $configOrder The configured order, or null.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-response-standardized-format
	 */
	private function nextOrder(mixed $configOrder): int {
		$this->order++;
		if ($configOrder !== null) {
			return (int)$configOrder;
		}

		return $this->order;
	}//end nextOrder()

	/**
	 * The definition of an object field facet before its configuration applies.
	 *
	 * @param string               $field     The field.
	 * @param array<string, mixed> $facetData The computed facet.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-response-standardized-format
	 */
	private function objectDefinition(string $field, array $facetData): array {
		return [
			'title' => $facetData['title'] ?? ucfirst((string)preg_replace('/([a-z])([A-Z])/', '$1 $2', $field)),
			'description' => 'object field: ' . $field,
			'data_type' => $this->inferDataType(facetData: $facetData),
			'index_field' => $this->sanitizeColumnName(name: $field),
			'index_type' => 'string',
			'enabled' => true,
		];
	}//end objectDefinition()

	/**
	 * The definition with the configured title and description, where set.
	 *
	 * @param array<string, mixed> $definition The definition.
	 * @param mixed                $config     The facet configuration, or null.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-response-standardized-format
	 */
	private function configured(array $definition, mixed $config): array {
		$definition['title'] = $config['title'] ?? $definition['title'];
		$definition['description'] = $config['description'] ?? $definition['description'];
		return $definition;
	}//end configured()

	/**
	 * The data type a facet's type or first bucket shows.
	 *
	 * @param array<string, mixed> $facetData The computed facet.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-type-auto-detection-from-property-definitions
	 */
	private function inferDataType(array $facetData): string {
		$type = $facetData['type'] ?? 'terms';
		if ($type === 'date_histogram') {
			return 'datetime';
		}

		if ($type === 'range') {
			return 'number';
		}

		return $this->bucketDataType(firstValue: ($facetData['buckets'][0]['key'] ?? null));
	}//end inferDataType()

	/**
	 * The data type of a facet's first bucket key.
	 *
	 * @param mixed $firstValue The key, or null.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-type-auto-detection-from-property-definitions
	 */
	private function bucketDataType(mixed $firstValue): string {
		if (is_int($firstValue) === true) {
			return 'integer';
		}

		if (is_float($firstValue) === true) {
			return 'number';
		}

		return 'string';
	}//end bucketDataType()
}//end class
