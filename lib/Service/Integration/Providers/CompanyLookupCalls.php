<?php

/**
 * The calls KvK and OpenCorporates share.
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
 * @spec openspec/specs/integration-company-lookup/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCA\OpenRegister\Exception\ProviderUnavailableException;
use Throwable;

/**
 * Asks a company register's search path and reads the answer.
 *
 * The using provider declares `SOURCE_ID`, `SEARCH_PATH` and `LIST_PARAMS` and provides
 * `$this->router`, `$this->logger` and `extractRows()`.
 */
trait CompanyLookupCalls {

	/**
	 * Auth requirements descriptor. `type: 'external'`: the API key is configured
	 * on the OpenConnector source, not here. OpenRegister's admin UI surfaces the
	 * source's auth status and links out to OpenConnector to configure it.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/integration-company-lookup/spec.md
	 */
	public function authRequirements(): array {
		return [
			'type' => 'external',
			'configuredVia' => 'openconnector',
			'source' => self::SOURCE_ID,
			'supports' => ['apikey'],
		];
	}//end authRequirements()

	/**
	 * Ask the register's search path.
	 *
	 * @param array<string,mixed> $query The query params.
	 *
	 * @return array<string,mixed> The decoded response.
	 *
	 * @throws ProviderUnavailableException When the source is missing or down.
	 */
	private function askRegister(array $query): array {
		return $this->router->call(
			provider: $this,
			method: 'GET',
			path: self::SEARCH_PATH,
			options: ['query' => $query, 'headers' => ['Accept' => 'application/json']]
		);
	}//end askRegister()

	/**
	 * Ask the register and answer without throwing, so a consuming app never
	 * fatals on a missing or down source (AD-23).
	 *
	 * @param array<string,mixed> $query The query params.
	 * @param CompanyLookupRun    $run   The lookup.
	 *
	 * @return array<string,mixed> The answer.
	 */
	private function lookup(array $query, CompanyLookupRun $run): array {
		try {
			$response = $this->askRegister(query: $query);
		} catch (ProviderUnavailableException $e) {
			return $run->degraded(cause: $e->getCause());
		} catch (Throwable $e) {
			$this->logger->warning($run->operation . ' failed: ' . $e->getMessage());
			return $run->degraded(cause: ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN);
		}

		return $run->found(rows: $this->extractRows(response: $response));
	}//end lookup()

	/**
	 * The registry filters as the register's query params.
	 *
	 * @param array<string,mixed> $filters `_search`, `_limit`, `_page`.
	 *
	 * @return array<string,string> The query params.
	 */
	private function listQuery(array $filters): array {
		$query = [];
		$search = (string)($filters['_search'] ?? '');
		if ($search !== '') {
			$query[self::LIST_PARAMS['_search']] = $search;
		}

		return $query + $this->listPaging(filters: $filters);
	}//end listQuery()

	/**
	 * The paging filters as the register's query params.
	 *
	 * @param array<string,mixed> $filters `_limit`, `_page`.
	 *
	 * @return array<string,string> The query params.
	 */
	private function listPaging(array $filters): array {
		$query = [];
		$limit = (int)($filters['_limit'] ?? 0);
		if ($limit > 0) {
			$query[self::LIST_PARAMS['_limit']] = (string)min(100, $limit);
		}

		$page = (int)($filters['_page'] ?? 0);
		if ($page > 0) {
			$query[self::LIST_PARAMS['_page']] = (string)$page;
		}

		return $query;
	}//end listPaging()

	/**
	 * The entries that are rows, renumbered.
	 *
	 * @param array<mixed> $entries The entries of a response.
	 *
	 * @return array<int,array<string,mixed>> The rows.
	 */
	private function rowsOf(array $entries): array {
		return array_values(array_filter($entries, 'is_array'));
	}//end rowsOf()
}//end trait
