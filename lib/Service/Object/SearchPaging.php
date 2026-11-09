<?php

/**
 * SearchPaging: the window, queries and envelope of one page of a search.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Service\Search\HistoryPredicate;

/**
 * Turns a search query into a page window and a result into its paged answer.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class SearchPaging {
	/**
	 * The limit, offset and page of a paginated search.
	 *
	 * `_limit=0` is valid for count- or facets-only requests; the limit is
	 * clamped to MAX_PAGE_SIZE so an oversized `_limit` cannot force an
	 * unbounded load. A page gives the offset and an offset gives the page.
	 *
	 * @param array $query The search query
	 *
	 * @return array{limit: int, offset: mixed, page: mixed}
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	public function window(array $query): array {
		$limit  = min(max(0, (int) ($query['_limit'] ?? QueryHandler::DEFAULT_PAGE_SIZE)), QueryHandler::MAX_PAGE_SIZE);
		$offset = $query['_offset'] ?? null;
		$page   = $query['_page'] ?? null;

		if ($page === null) {
			return ['limit' => $limit, 'offset' => $offset ?? 0, 'page' => $this->pageAtOffset(offset: $offset, limit: $limit)];
		}

		$page = $this->askedPage(page: $page, offset: $offset);

		return ['limit' => $limit, 'offset' => $offset ?? (($page - 1) * $limit), 'page' => $page];
	}//end window()

	/**
	 * The page an asked page stands for.
	 *
	 * @param mixed $page   The asked page.
	 * @param mixed $offset The asked offset; when there is one the page stays as asked.
	 *
	 * @return mixed The page.
	 */
	private function askedPage(mixed $page, mixed $offset): mixed {
		if ($offset === null) {
			return max(1, (int) $page);
		}

		return $page;
	}//end askedPage()

	/**
	 * The page an offset falls on.
	 *
	 * @param mixed $offset The asked offset.
	 * @param int   $limit  The page size.
	 *
	 * @return int The page.
	 */
	private function pageAtOffset(mixed $offset, int $limit): int {
		if ($offset === null || $limit <= 0) {
			return 1;
		}

		return ((int) floor($offset / $limit) + 1);
	}//end pageAtOffset()

	/**
	 * The page query and the count query handed to the mapper.
	 *
	 * Control keys the mapper does not read are removed, and so are the two
	 * history filter keys: left in, the search path would read them as
	 * property filters that match nothing. History is applied as a narrowing
	 * of the id set instead (see narrowByHistory()).
	 *
	 * @param array                                       $query  The search query
	 * @param array{limit: int, offset: mixed, page: mixed} $window The pagination window
	 *
	 * @return array{search: array, count: array}
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	public function queries(array $query, array $window): array {
		$paginatedQuery = array_merge($query, ['_limit' => $window['limit'], '_offset' => $window['offset']]);
		unset($paginatedQuery['_page'], $paginatedQuery['_facetable'], $paginatedQuery['_extend']);

		$countQuery = $query;
		unset($countQuery['_limit'], $countQuery['_offset'], $countQuery['_page'], $countQuery['_facetable'], $countQuery['_extend']);

		unset(
			$paginatedQuery[HistoryPredicate::WAS_EVER],
			$paginatedQuery[HistoryPredicate::CHANGED_BETWEEN],
			$countQuery[HistoryPredicate::WAS_EVER],
			$countQuery[HistoryPredicate::CHANGED_BETWEEN]
		);

		return ['search' => $paginatedQuery, 'count' => $countQuery];
	}//end queries()

	/**
	 * The response skeleton: rows, totals and the pagination window.
	 *
	 * @param mixed $results      The rows
	 * @param array $searchResult The search result
	 * @param array $window       The pagination window
	 *
	 * @return array
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	public function response(mixed $results, array $searchResult, array $window): array {
		$total = $searchResult['total'];
		$pages = 0;
		if ($window['limit'] > 0) {
			$pages = max(1, (int) ceil($total / $window['limit']));
		}

		return [
			'results' => $results,
			'total'   => $total,
			'page'    => $window['page'],
			'pages'   => $pages,
			'limit'   => $window['limit'],
			'offset'  => $window['offset'],
			'facets'  => [],
			'@self'   => ['source' => $searchResult['source'] ?? 'database'],
		];
	}//end response()
}//end class
