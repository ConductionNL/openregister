<?php

/**
 * OpenRegister SearchTrailQueryConfig
 *
 * Turns the raw request parameters of a search trail listing into the paging,
 * sorting, search, date and filter arguments the search trail mapper takes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
 */

// No strict_types on purpose: the date parsing keeps the scalar coercion that
// SearchTrailService has always applied to request parameters.

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;

/**
 * Processes search trail listing parameters (plain and `_`-prefixed spellings).
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
 */
final class SearchTrailQueryConfig {

	/**
	 * Parameters that steer the listing itself and are never passed on as filters.
	 */
	private const NON_FILTER_KEYS = [
		'limit', '_limit', 'offset', '_offset', 'page', '_page',
		'search', '_search', 'sort', '_sort', 'order', '_order',
		'from', 'to', '_route', 'id',
	];

	/**
	 * Process configuration parameters for search trail operations
	 *
	 * @param array $config Raw configuration parameters
	 *
	 * @return array Processed configuration: limit, offset, page, filters, sort, search, from, to
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-saved-searches-and-search-trails
	 */
	public function process(array $config): array {
		return $this->paging(config: $config) + [
			'filters' => $this->filters(config: $config),
			'sort' => $this->sort(config: $config),
			'search' => $config['search'] ?? $config['_search'] ?? null,
			'from' => $this->date(value: $config['from'] ?? null),
			'to' => $this->date(value: $config['to'] ?? null),
		];
	}//end process()

	/**
	 * Resolve limit, offset and page; a page derives the offset and an offset derives the page.
	 *
	 * @param array $config Raw configuration parameters
	 *
	 * @return array{limit: int, offset: int, page: int|float}
	 */
	private function paging(array $config): array {
		$limit = $this->intOrNull(value: $config['limit'] ?? $config['_limit'] ?? null, minimum: 1) ?? 20;
		$offset = $this->intOrNull(value: $config['offset'] ?? $config['_offset'] ?? null);
		$page = $this->intOrNull(value: $config['page'] ?? $config['_page'] ?? null, minimum: 1);

		if ($page !== null && $offset === null) {
			$offset = ($page - 1) * $limit;
		}

		if ($page === null && $offset !== null) {
			$page = floor($offset / $limit) + 1;
		}

		return ['limit' => $limit, 'offset' => $offset ?? 0, 'page' => $page ?? 1];
	}//end paging()

	/**
	 * Cast a given value to an integer of at least the minimum; null stays null.
	 *
	 * @param mixed $value Raw value, null when absent
	 * @param int $minimum Smallest result (no clamp by default)
	 *
	 * @return int|null
	 */
	private function intOrNull(mixed $value, int $minimum = PHP_INT_MIN): ?int {
		$int = null;
		if ($value !== null) {
			$int = max($minimum, (int)$value);
		}

		return $int;
	}//end intOrNull()

	/**
	 * Resolve the sort; an order alone does not change it.
	 *
	 * @param array $config Raw configuration parameters
	 *
	 * @return array Sort as field => direction
	 */
	private function sort(array $config): array {
		$field = $config['sort'] ?? $config['_sort'] ?? null;
		if ($field === null) {
			return ['created' => 'DESC'];
		}

		return [$field => $config['order'] ?? $config['_order'] ?? 'DESC'];
	}//end sort()

	/**
	 * Parse a date filter; an unparsable date is ignored.
	 *
	 * @param mixed $value Raw date, null when absent
	 *
	 * @return DateTime|null
	 */
	private function date(mixed $value): ?DateTime {
		$date = null;
		if ($value !== null) {
			try {
				$date = new DateTime($value);
			} catch (Exception $e) {
				// Invalid date format, ignore.
			}
		}

		return $date;
	}//end date()

	/**
	 * Keep every parameter that is not a listing parameter and not system (`_`-prefixed).
	 *
	 * @param array $config Raw configuration parameters
	 *
	 * @return array Filters as key => value
	 */
	private function filters(array $config): array {
		$filters = [];
		foreach ($config as $key => $value) {
			if (in_array($key, self::NON_FILTER_KEYS, true) === false && str_starts_with((string)$key, '_') === false) {
				$filters[$key] = $value;
			}
		}

		return $filters;
	}//end filters()
}//end class
