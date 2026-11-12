<?php

/**
 * What a GraphQL list query asks for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\GraphQL
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/graphql-api/spec.md
 */

namespace OCA\OpenRegister\Service\GraphQL;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * A list query's schema, register and GraphQL arguments, read as search and aggregation input.
 *
 * @spec openspec/specs/graphql-api/spec.md
 */
final class ListRequest {

	/**
	 * Constructor.
	 *
	 * @param Schema               $schema   The schema listed.
	 * @param Register|null        $register The register holding it, if any.
	 * @param array<string, mixed> $args     The GraphQL arguments.
	 */
	public function __construct(
		public readonly Schema $schema,
		public readonly ?Register $register,
		private readonly array $args,
	) {
	}//end __construct()

	/**
	 * The id of the register, if there is one.
	 *
	 * @return int|null The register id.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function registerId(): ?int {
		return $this->register?->getId();
	}//end registerId()

	/**
	 * The page size asked for.
	 *
	 * @return mixed The `first` argument, or the default.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function limit(): mixed {
		return ($this->args['first'] ?? 20);
	}//end limit()

	/**
	 * The offset asked for.
	 *
	 * @return mixed The `offset` argument, or the default.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function offset(): mixed {
		return ($this->args['offset'] ?? 0);
	}//end offset()

	/**
	 * The arguments as HTTP request params for ObjectService::buildSearchQuery().
	 *
	 * @return array<string, mixed> The request params.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function requestParams(): array {
		$params = array_replace(
			['_limit' => $this->limit(), '_offset' => $this->offset()],
			$this->searchParams(),
			$this->orderParam(),
			$this->facetsParam(),
			$this->filterParams()
		);

		return $this->withSelfFilter(params: $params);
	}//end requestParams()

	/**
	 * The free-text search params.
	 *
	 * @return array<string, mixed> The params.
	 */
	private function searchParams(): array {
		$params = [];
		if (isset($this->args['search']) === true) {
			$params['_search'] = $this->args['search'];
		}

		if (($this->args['fuzzy'] ?? null) === true) {
			$params['_fuzzy'] = 'true';
		}

		return $params;
	}//end searchParams()

	/**
	 * The sort param.
	 *
	 * @return array<string, string> The param, or nothing.
	 */
	private function orderParam(): array {
		if (isset($this->args['sort']) === false) {
			return [];
		}

		$sort = $this->args['sort'];
		$direction = strtoupper(string: ($sort['order'] ?? 'ASC'));

		return ['_order' => json_encode(value: [['field' => $sort['field'], 'direction' => $direction]])];
	}//end orderParam()

	/**
	 * The facets param.
	 *
	 * @return array<string, string> The param, or nothing.
	 */
	private function facetsParam(): array {
		if (empty($this->args['facets']) === true) {
			return [];
		}

		return ['_facets' => implode(separator: ',', array: $this->args['facets'])];
	}//end facetsParam()

	/**
	 * The property values filtered on, as given.
	 *
	 * @return array<int|string, mixed> The params.
	 */
	private function filterParams(): array {
		$filter = ($this->args['filter'] ?? null);
		if (is_array(value: $filter) === false) {
			return [];
		}

		return $filter;
	}//end filterParams()

	/**
	 * Add the metadata columns filtered on, under `@self`.
	 *
	 * @param array<string, mixed> $params The params so far.
	 *
	 * @return array<string, mixed> The params with the metadata filter.
	 */
	private function withSelfFilter(array $params): array {
		$selfFilter = ($this->args['selfFilter'] ?? null);
		if (is_array(value: $selfFilter) === false) {
			return $params;
		}

		foreach ($selfFilter as $field => $value) {
			if ($value !== null) {
				$params['@self'][$field] = $value;
			}
		}

		return $params;
	}//end withSelfFilter()

	/**
	 * The `groupBy` argument, when it names a field.
	 *
	 * @return array<string, mixed>|null The argument, or null when there is nothing to group.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function groupBy(): ?array {
		$groupBy = ($this->args['groupBy'] ?? null);
		if (is_array(value: $groupBy) === false || ($groupBy['field'] ?? '') === '') {
			return null;
		}

		return $groupBy;
	}//end groupBy()

	/**
	 * The name of the declared aggregation asked for.
	 *
	 * @return string|null The name, or null when none was asked for.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function aggregationName(): ?string {
		$name = ($this->args['aggregation'] ?? null);
		if (is_string(value: $name) === false || $name === '') {
			return null;
		}

		return $name;
	}//end aggregationName()

	/**
	 * The PROPERTY filter of the query, for reuse by its aggregation.
	 *
	 * Only `filter`, the property-value map:
	 *
	 *  - paging must not reach the aggregation: a total over "the first 20 rows"
	 *    is not a total, and it would change as the user paged;
	 *  - `search` is a relevance query the aggregation engine does not implement:
	 *    it would filter on a property named `_search` and match nothing;
	 *  - `selfFilter` addresses `@self` metadata columns, a different namespace
	 *    from the schema properties the aggregation filters on.
	 *
	 * @return array<string, mixed> Property filters, or an empty array.
	 *
	 * @spec openspec/specs/graphql-api/spec.md
	 */
	public function propertyFilter(): array {
		return array_filter(
			$this->filterParams(),
			static fn (int|string $field): bool => is_string(value: $field) === true && $field !== '',
			ARRAY_FILTER_USE_KEY
		);
	}//end propertyFilter()
}//end class
