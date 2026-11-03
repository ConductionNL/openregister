<?php

/**
 * A saved view's stored query, as the object search reads it.
 *
 * 🔴 A VIEW'S NUMBER MUST BE THE VIEW'S ROWS. The count alert once handed the
 * stored query to ObjectService::count(), which reads neither `registers` nor
 * `schemas`, so a view over three records alerted at five: the count of whatever
 * register and schema the service last pointed at. This class turns the stored
 * query into the search query the object list understands, so a caller counts
 * exactly the rows the view names.
 *
 * A stored view query carries `registers`, `schemas`, `searchTerms` and
 * `facetFilters` (the shape the edit screen saves), `filters` (the shape the
 * board and calendar read), or the API's single `_register`/`_schema`. The rest
 * (`source`, `enabledFacets`, pagination, sorting, columns) is presentation and
 * bounds nothing.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\View
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-alert-fires-once-per-crossing-and-re-arms
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\View;

/**
 * Translate a stored view query into an object search query.
 *
 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-alert-fires-once-per-crossing-and-re-arms
 */
final class ViewObjectQuery {

	/**
	 * The search query for a view's stored query.
	 *
	 * The facet filters are read the way a view-backed schema reads them
	 * (ViewObjectSourceProvider::combine()): an empty selection is no filter, a
	 * `@self.<field>` key filters metadata, and a field named by both `filters`
	 * and `facetFilters` keeps only the values both allow.
	 *
	 * @param array<string, mixed> $viewQuery The view's stored query.
	 *
	 * @return array<string, mixed>|null The search query; null when the view's filters exclude each other.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-alert-fires-once-per-crossing-and-re-arms
	 */
	public function forView(array $viewQuery): ?array {
		$query = $this->bounds(viewQuery: $viewQuery);

		foreach ((array) ($viewQuery['filters'] ?? []) as $field => $value) {
			$field = (string) $field;
			if ($field !== '' && $field[0] !== '_' && $field[0] !== '@') {
				$query[$field] = $value;
			}
		}

		$query = $this->withFacets(query: $query, facetFilters: (array) ($viewQuery['facetFilters'] ?? []));
		if ($query === null) {
			return null;
		}

		$search = $this->searchOf(viewQuery: $viewQuery);
		if ($search !== '') {
			$query['_search'] = $search;
		}

		return $query;
	}//end forView()

	/**
	 * The register and schema bounds, as `@self` filters.
	 *
	 * @param array<string, mixed> $viewQuery The view's stored query.
	 *
	 * @return array<string, mixed>
	 */
	private function bounds(array $viewQuery): array {
		$query = [];

		$registers = $this->ids(list: ($viewQuery['registers'] ?? null), single: ($viewQuery['_register'] ?? null));
		if ($registers !== []) {
			$query['@self']['register'] = $registers;
		}

		$schemas = $this->ids(list: ($viewQuery['schemas'] ?? null), single: ($viewQuery['_schema'] ?? null));
		if ($schemas !== []) {
			$query['@self']['schema'] = $schemas;
		}

		return $query;
	}//end bounds()

	/**
	 * Add the view's facet filters; null when one excludes a `filters` value.
	 *
	 * @param array<string, mixed> $query        The query so far.
	 * @param array<mixed>         $facetFilters The view's facet filters.
	 *
	 * @return array<string, mixed>|null
	 */
	private function withFacets(array $query, array $facetFilters): ?array {
		foreach ($facetFilters as $field => $values) {
			$values = array_values((array) $values);
			if ($values === []) {
				continue;
			}

			$field = (string) $field;
			if (str_starts_with($field, '@self.') === true) {
				$query['@self'][substr($field, 6)] = $values;
				continue;
			}

			if (array_key_exists($field, $query) === true) {
				$values = array_values(
					array_intersect(array_map('strval', (array) $query[$field]), array_map('strval', $values))
				);
				if ($values === []) {
					return null;
				}
			}

			$query[$field] = $values;
		}//end foreach

		return $query;
	}//end withFacets()

	/**
	 * The view's search terms as one search string.
	 *
	 * @param array<string, mixed> $viewQuery The view's stored query.
	 *
	 * @return string
	 */
	private function searchOf(array $viewQuery): string {
		$terms = ($viewQuery['searchTerms'] ?? ($viewQuery['_search'] ?? ''));
		if (is_array($terms) === true) {
			$terms = implode(' ', array_map('strval', $terms));
		}

		return trim((string) $terms);
	}//end searchOf()

	/**
	 * The id list a view names, from its list form or its single form.
	 *
	 * @param mixed $list   The `registers`/`schemas` list.
	 * @param mixed $single The `_register`/`_schema` value.
	 *
	 * @return array<int, int|string> The ids, list-indexed, without empties.
	 */
	private function ids(mixed $list, mixed $single): array {
		$ids = (array) ($list ?? []);
		if ($ids === [] && $single !== null && $single !== '') {
			$ids = [$single];
		}

		return array_values(array_filter($ids, static fn ($id): bool => $id !== null && $id !== ''));
	}//end ids()
}//end class
