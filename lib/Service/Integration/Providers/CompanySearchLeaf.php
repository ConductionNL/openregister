<?php

/**
 * CompanySearchLeaf: the shared search plumbing of the company-register leaves.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-10-05-integration-kvk-opencorporates/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCA\OpenRegister\Exception\ProviderUnavailableException;
use Throwable;

/**
 * GET the source's search endpoint through the external router and shape
 * the rows into an envelope.
 *
 * The using class carries the router (`$router`) and logger (`$logger`),
 * a `SEARCH_PATH` constant, a `LIST_PARAMS` constant mapping `search`,
 * `limit` and `page` to the source's query names, and extractRows().
 *
 * @spec openspec/changes/archive/2026-10-05-integration-kvk-opencorporates/tasks.md
 */
trait CompanySearchLeaf {

	/**
	 * The rows of a decoded search response.
	 *
	 * @param array<string, mixed> $response The decoded response.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	abstract protected function extractRows(array $response): array;

	/**
	 * GET the search endpoint with a query.
	 *
	 * @param array<string, mixed> $query The query parameters.
	 *
	 * @return array<string, mixed> The decoded response.
	 */
	private function searchCall(array $query): array {
		return $this->router->call(
			provider: $this,
			method: 'GET',
			path: self::SEARCH_PATH,
			options: ['query' => $query, 'headers' => ['Accept' => 'application/json']]
		);
	}//end searchCall()

	/**
	 * Run a search and answer with the found or degraded envelope; never throws.
	 *
	 * @param array<string, mixed> $params The query parameters.
	 * @param CompanyLookup $lookup The operation and paging.
	 *
	 * @return array<string, mixed> The envelope.
	 */
	private function lookup(array $params, CompanyLookup $lookup): array {
		try {
			$response = $this->searchCall(query: $params);
		} catch (ProviderUnavailableException $e) {
			return $lookup->degraded(cause: $e->getCause());
		} catch (Throwable $e) {
			$this->logger->warning($lookup->operation . ' failed: ' . $e->getMessage());
			return $lookup->degraded(cause: ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN);
		}

		return $lookup->found(rows: $this->extractRows(response: $response));
	}//end lookup()

	/**
	 * The search query of the IntegrationProvider read-path filters:
	 * `_search`, `_limit` (capped at 100) and `_page`, each only when set.
	 *
	 * @param array<string, mixed> $filters The list() filters.
	 *
	 * @return array<string, string> The query parameters.
	 */
	private function listQuery(array $filters): array {
		$query = [];
		$search = (string)($filters['_search'] ?? '');
		if ($search !== '') {
			$query[self::LIST_PARAMS['search']] = $search;
		}

		$limit = (int)($filters['_limit'] ?? 0);
		if ($limit > 0) {
			$query[self::LIST_PARAMS['limit']] = (string)min(100, $limit);
		}

		return $this->withListPage(query: $query, filters: $filters);
	}//end listQuery()

	/**
	 * Add the `_page` filter to a list query when it is positive.
	 *
	 * @param array<string, string> $query The query so far.
	 * @param array<string, mixed> $filters The list() filters.
	 *
	 * @return array<string, string> The query parameters.
	 */
	private function withListPage(array $query, array $filters): array {
		$page = (int)($filters['_page'] ?? 0);
		if ($page > 0) {
			$query[self::LIST_PARAMS['page']] = (string)$page;
		}

		return $query;
	}//end withListPage()

	/**
	 * Keep only the array entries of a row list.
	 *
	 * @param array<mixed> $rows The candidate rows.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	protected function arrayRows(array $rows): array {
		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end arrayRows()
}//end trait
