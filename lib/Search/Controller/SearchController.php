<?php

/**
 * Class SearchController
 *
 * Controller for handling search operations in the OpenRegister app.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-dutch-language-search-support-i18n
 */

namespace OCA\OpenRegister\Search\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * SearchController handles search operations
 *
 * Controller for handling search operations in the application.
 * Provides functionality to search across objects using the database search path.
 * Supports query processing, pagination, and result formatting.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @psalm-suppress UnusedClass
 */
class SearchController extends Controller {
	/**
	 * Constructor for the SearchController
	 *
	 * Initializes controller with object service for database search operations.
	 * Calls parent constructor to set up base controller functionality.
	 *
	 * @param IRequest $request The HTTP request object
	 * @param ObjectOperationsInterface $objectService The object service instance
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		IRequest $request,
		private readonly ObjectOperationsInterface $objectService,
	) {
		// Call parent constructor to initialize base controller.
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Handles search requests and delegates to the database search path
	 *
	 * Processes search query, performs database object search, and formats results for JSON response.
	 * Supports pagination via offset and limit parameters.
	 * Returns formatted search results with total count.
	 *
	 * @return JSONResponse Search results with total count.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200, array{results: array<int, array{id: mixed|null, name: 'Unknown'|mixed,
	 *                                type: 'object', url: mixed|null, source: 'openregister'}>, total: 0|int,
	 *                                facets: array<never, never>}, array<never, never>>
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function search(): JSONResponse {
		// Step 1: Get the search query from request parameters (default to empty string).
		$query = $this->request->getParam('query', '');

		// Step 2: Process the search query to handle multiple search words.
		// This handles comma-separated values, arrays, and case-insensitive matching.
		$processedQuery = $this->processSearchQuery(query: $query);

		// Step 3: Build search parameters for the database search path.
		$searchParams = $this->searchParams(processedQuery: $processedQuery);

		// Step 4: Perform search using ObjectService database path.
		// Returns: ['results' => ObjectEntity[], 'total' => int, '@self' => [...]].
		$paginatedResult = $this->objectService->searchObjectsPaginated(query: $searchParams);

		// Steps 5 and 6: Format the results and return them with metadata.
		return $this->searchResponse(paginatedResult: $paginatedResult);
	}//end search()

	/**
	 * The search response: every result row formatted, with the total count.
	 *
	 * @param array<string, mixed> $paginatedResult The paginated database search result.
	 *
	 * @return JSONResponse
	 */
	private function searchResponse(array $paginatedResult): JSONResponse {
		// Step 5: Format search results for JSON response.
		// Extract relevant fields from each ObjectEntity and standardize format.
		$formattedResults = array_map(
			fn ($object): array => $this->formatResult(object: $object),
			$paginatedResult['results'] ?? []
		);

		// Step 6: Return formatted search results with metadata.
		return new JSONResponse(
			data: [
				'results' => $formattedResults,
				'total' => $paginatedResult['total'] ?? 0,
				'facets' => [],
			]
		);
	}//end searchResponse()

	/**
	 * The database search parameters: the processed query and the page the offset falls on.
	 *
	 * Note: This is a simplified search endpoint. For full Nextcloud search integration,
	 * use the ObjectsProvider which implements IFilteringProvider.
	 *
	 * @param string $processedQuery The processed search query.
	 *
	 * @return array{_search: string, _page: int, _limit: int}
	 */
	private function searchParams(string $processedQuery): array {
		return [
			'_search' => $processedQuery,
			'_page' => (int)floor(((int)$this->request->getParam('offset', 0)) / max(1, (int)$this->request->getParam('limit', 25))) + 1,
			'_limit' => (int)($this->request->getParam('limit', 25)),
		];
	}//end searchParams()

	/**
	 * Format one search result row.
	 *
	 * The searchObjectsPaginated() call returns ObjectEntity rows, and
	 * ObjectEntity declares getUuid() / getName() only as `@method`
	 * — Nextcloud's Entity serves them through __call(). An earlier
	 * version of this closure probed with method_exists(), which is
	 * FALSE for both, so every row fell past this branch and came
	 * back as `id: null, name: 'Unknown'`.
	 *
	 * Narrowing on ObjectEntity rather than the OCP Entity base is
	 * what makes the accessors resolvable: the `@method` tags live on
	 * ObjectEntity. Against the base they produced an error type that
	 * propagated out through this array and left the whole
	 * JSONResponse payload unverifiable. It also makes the
	 * property_exists('uuid') / ('name') probes redundant — both are
	 * declared properties of ObjectEntity — so they are gone.
	 *
	 * @param mixed $object An ObjectEntity, an array row, or anything else.
	 *
	 * @return (mixed|null|string)[]
	 *
	 * @psalm-return array{
	 *     id: mixed|null,
	 *     name: 'Unknown'|mixed,
	 *     type: 'object',
	 *     url: mixed|null,
	 *     source: 'openregister'
	 * }
	 */
	private function formatResult(mixed $object): array {
		if ($object instanceof ObjectEntity) {
			return $this->formatEntityResult(object: $object);
		}

		$objectArr = [];
		if (is_array($object) === true) {
			$objectArr = $object;
		}

		return $this->formatArrayResult(objectArr: $objectArr);
	}//end formatResult()

	/**
	 * Format one ObjectEntity search result row.
	 *
	 * @param ObjectEntity $object The row.
	 *
	 * @return array{id: mixed|null, name: 'Unknown'|mixed, type: 'object', url: null, source: 'openregister'}
	 */
	private function formatEntityResult(ObjectEntity $object): array {
		return [
			'id' => $object->getUuid(),
			'name' => $object->getName() ?? 'Unknown',
			'type' => 'object',
			'url' => null,
			'source' => 'openregister',
		];
	}//end formatEntityResult()

	/**
	 * Format one array search result row; any key may be missing.
	 *
	 * @param array<string, mixed> $objectArr The row.
	 *
	 * @return array{id: mixed|null, name: 'Unknown'|mixed, type: 'object', url: mixed|null, source: 'openregister'}
	 */
	private function formatArrayResult(array $objectArr): array {
		return [
			'id' => $objectArr['uuid'] ?? $objectArr['id'] ?? null,
			'name' => $objectArr['name'] ?? $objectArr['@self']['name'] ?? 'Unknown',
			'type' => 'object',
			'url' => $objectArr['url'] ?? null,
			'source' => 'openregister',
		];
	}//end formatArrayResult()

	/**
	 * Process search query to support multiple search words and case-insensitive partial matches
	 *
	 * Processes raw search query to handle various input formats and search requirements:
	 * 1. Supporting comma-separated values in the query parameter
	 * 2. Supporting array parameters (_search[])
	 * 3. Making searches case-insensitive
	 * 4. Enabling partial matches (e.g., 'tes' matches 'test')
	 *
	 * @param string $query The raw search query from the request
	 *
	 * @return string The processed search query ready for the database search
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-dutch-language-search-support-i18n
	 */
	private function processSearchQuery(string $query): string {
		$searchTerms = $this->searchTerms(query: $query);

		// If no search terms found, return the original query.
		if (empty($searchTerms) === true) {
			return $query;
		}

		// Process each search term to make them case-insensitive and support partial matches.
		$processedTerms = [];
		foreach ($searchTerms as $term) {
			$processedTerms[] = $this->wildcardTerm(term: $term);
		}

		// Join multiple terms with OR logic (any term can match).
		return implode(' OR ', $processedTerms);
	}//end processSearchQuery()

	/**
	 * The search terms: the query plus the `_search[]` values, or the query split on commas.
	 *
	 * @param string $query The raw search query from the request
	 *
	 * @return array<int|string, mixed> The terms; empty ones dropped when splitting.
	 */
	private function searchTerms(string $query): array {
		// Handle array parameters (_search[]).
		$searchArray = $this->request->getParam('_search', []);
		if (is_array($searchArray) === true && empty($searchArray) === false) {
			// Combine array values with the main query.
			return array_merge(
				[$query],
				$searchArray
			);
		}

		// Handle comma-separated values in the main query.
		return array_filter(
			array_map('trim', explode(',', $query)),
			function ($term) {
				return empty($term) === false;
			}
		);
	}//end searchTerms()

	/**
	 * One term, lowercased and wrapped in `*` wildcards on each side that has no `*` or `%` yet.
	 *
	 * @param mixed $term The search term.
	 *
	 * @return string The processed term.
	 */
	private function wildcardTerm(mixed $term): string {
		// Convert to lowercase for case-insensitive matching.
		$lowerTerm = strtolower(trim($term));

		// Add wildcards for partial matching if not already present.
		if (str_starts_with($lowerTerm, '*') === false && str_starts_with($lowerTerm, '%') === false) {
			$lowerTerm = '*' . $lowerTerm;
		}

		if (str_ends_with($lowerTerm, '*') === false && str_ends_with($lowerTerm, '%') === false) {
			$lowerTerm = $lowerTerm . '*';
		}

		return $lowerTerm;
	}//end wildcardTerm()
}//end class
