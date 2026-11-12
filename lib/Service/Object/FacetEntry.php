<?php

/**
 * OpenRegister FacetEntry
 *
 * One facet of the standardised facet response.
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

/**
 * Builds a facet's response entry from its definition, where it comes from and its place in the list.
 *
 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-response-standardized-format
 */
final class FacetEntry {

	/**
	 * Where the facet comes from: `metadata` or `object`.
	 *
	 * @var string
	 */
	private string $source = 'object';

	/**
	 * The query parameter that filters on this facet.
	 *
	 * @var string
	 */
	private string $queryParameter = '';

	/**
	 * The facet's position.
	 *
	 * @var integer
	 */
	private int $order = 0;

	/**
	 * The schema a non-aggregated facet belongs to, so the frontend can scope queries.
	 *
	 * @var integer|null
	 */
	private ?int $schemaId = null;

	/**
	 * Constructor.
	 *
	 * @param string               $name       The facet name.
	 * @param array<string, mixed> $definition Title, description, data_type, index_field, index_type and enabled.
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-response-standardized-format
	 */
	public function __construct(
		private readonly string $name,
		private readonly array $definition,
	) {
	}//end __construct()

	/**
	 * A copy with the given origin.
	 *
	 * @param string $source         `metadata` or `object`.
	 * @param string $queryParameter The query parameter that filters on this facet.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-response-standardized-format
	 */
	public function from(string $source, string $queryParameter): self {
		$copy = clone $this;
		$copy->source = $source;
		$copy->queryParameter = $queryParameter;
		return $copy;
	}//end from()

	/**
	 * A copy at the given position.
	 *
	 * @param int      $order    The position.
	 * @param int|null $schemaId The schema of a non-aggregated facet.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-response-standardized-format
	 */
	public function placed(int $order, ?int $schemaId = null): self {
		$copy = clone $this;
		$copy->order = $order;
		$copy->schemaId = $schemaId;
		return $copy;
	}//end placed()

	/**
	 * The response entry for the computed facet; buckets use value/count/label rather than key/results.
	 *
	 * @param array<string, mixed> $facetData The computed facet.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-response-standardized-format
	 */
	public function build(array $facetData): array {
		$type = $facetData['type'] ?? 'terms';
		$buckets = [];
		foreach ($facetData['buckets'] ?? [] as $bucket) {
			$buckets[] = self::bucket(bucket: $bucket);
		}

		$entry = $this->head(type: $type);
		$entry['data'] = ['type' => $type, 'total_count' => count($buckets), 'buckets' => $buckets];
		if ($this->schemaId !== null) {
			$entry['schema'] = $this->schemaId;
		}

		return $entry;
	}//end build()

	/**
	 * Everything of the entry but its data.
	 *
	 * @param mixed $type The facet type.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-response-standardized-format
	 */
	private function head(mixed $type): array {
		$def = $this->definition;
		$described = ['title' => $def['title'], 'description' => $def['description'], 'data_type' => $def['data_type']];
		$indexed = ['index_field' => $def['index_field'], 'index_type' => $def['index_type']];
		return ['name' => $this->name, 'type' => $type] + $described + $indexed + [
			'queryParameter' => $this->queryParameter,
			'source' => $this->source,
			'show_count' => true,
			'enabled' => $def['enabled'],
			'order' => $this->order,
		];
	}//end head()

	/**
	 * One bucket in response form.
	 *
	 * @param mixed $bucket The computed bucket.
	 *
	 * @return array{value: mixed, count: int, label: mixed}
	 *
	 * @spec openspec/specs/faceting-configuration/spec.md#requirement-facet-response-standardized-format
	 */
	private static function bucket(mixed $bucket): array {
		return [
			'value' => $bucket['key'] ?? $bucket['value'] ?? '',
			'count' => (int)($bucket['results'] ?? $bucket['count'] ?? 0),
			'label' => $bucket['label'] ?? (string)($bucket['key'] ?? $bucket['value'] ?? ''),
		];
	}//end bucket()
}//end class
