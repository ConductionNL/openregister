<?php

/**
 * SearchResponseReports: what a paged search says about itself under `@self`.
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
use OCA\OpenRegister\Service\Search\DictionaryExpansion;

/**
 * Builds the reports a search answers with beside its results.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class SearchResponseReports {
	/**
	 * The `_extend` names that ask for the registers and schemas of a search.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const INCLUDE_SPELLINGS = [
		'registers' => ['_registers', '_register', '@self.registers', '@self.register'],
		'schemas'   => ['_schemas', '_schema', '@self.schemas', '@self.schema'],
	];

	/**
	 * What the history filter, the dictionary and the `_recent` lens did, for `@self`.
	 *
	 * A filter that did not read says so instead of quietly doing nothing, and an
	 * expansion is reported because it returns rows the searcher did not ask for.
	 *
	 * @param array                                                     $query            The search query
	 * @param HistoryPredicate                                          $historyPredicate The parsed history predicate
	 * @param DictionaryExpansion|null $expansion        The expansion, if any
	 *
	 * @return array
	 *
	 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
	 */
	public function searchReports(
		array $query,
		HistoryPredicate $historyPredicate,
		?DictionaryExpansion $expansion
	): array {
		$reports = $this->historyReport(historyPredicate: $historyPredicate);
		if ($expansion !== null && $expansion->isReportable() === true) {
			$reports['dictionary'] = $expansion->jsonSerialize();
		}

		if (is_array($query['_recentLens'] ?? null) === true) {
			$reports['lenses']['recent'] = $query['_recentLens'];
		}

		return $reports;
	}//end searchReports()

	/**
	 * The report of a history predicate that narrowed or could not be read.
	 *
	 * @param HistoryPredicate $historyPredicate The predicate.
	 *
	 * @return array<string, mixed> The report, empty when it has nothing to say.
	 */
	private function historyReport(HistoryPredicate $historyPredicate): array {
		if ($historyPredicate->narrows() === true || $historyPredicate->unparsed() !== []) {
			return ['history' => $historyPredicate->jsonSerialize()];
		}

		return [];
	}//end historyReport()

	/**
	 * The registers and schemas indexed by id, when `_extend` asks for them in any spelling.
	 *
	 * @param array $query        The search query
	 * @param array $searchResult The search result
	 *
	 * @return array
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	public function requestedRegistersAndSchemas(array $query, array $searchResult): array {
		$extend = $this->extendList(query: $query);

		$included = [];
		foreach (self::INCLUDE_SPELLINGS as $key => $names) {
			$asked = array_filter($names, static fn (string $name): bool => in_array($name, $extend, true));
			if ($asked !== [] && empty($searchResult[$key] ?? []) === false) {
				$included[$key] = $searchResult[$key];
			}
		}

		return $included;
	}//end requestedRegistersAndSchemas()

	/**
	 * What a query asks to extend, as a list.
	 *
	 * @param array $query The query.
	 *
	 * @return array The asked names.
	 */
	private function extendList(array $query): array {
		$extend = $query['_extend'] ?? [];
		if (is_string($extend) === true) {
			return explode(',', $extend);
		}

		return $extend;
	}//end extendList()

	/**
	 * The ignored filters, with a hint when one looks like a control parameter missing its `_`.
	 *
	 * @param array $ignoredFilters The filters the search ignored
	 *
	 * @return array
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
	 */
	public function ignoredFilterReport(array $ignoredFilters): array {
		if (empty($ignoredFilters) === true) {
			return [];
		}

		$report         = ['ignoredFilters' => $ignoredFilters];
		$controlParams  = ['limit', 'offset', 'page', 'order', 'sort', 'search', 'extend', 'fields', 'filter', 'unset'];
		$mistakenParams = array_intersect($ignoredFilters, $controlParams);
		if (empty($mistakenParams) === false) {
			$suggestions    = array_map(fn ($param) => "_{$param}", $mistakenParams);
			$report['hint'] = 'Query returned 0 results because '.implode(', ', $mistakenParams)
				.' was treated as a property filter. Did you mean '.implode(', ', $suggestions)
				.'? Control params require underscore prefix.';
		}

		return $report;
	}//end ignoredFilterReport()
}//end class
