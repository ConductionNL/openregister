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

namespace OCA\OpenRegister\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\OpenRegister\Service\ObjectService;

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
class SearchController extends Controller
{
    /**
     * Constructor for the SearchController
     *
     * Initializes controller with object service for database search operations.
     * Calls parent constructor to set up base controller functionality.
     *
     * @param string        $appName       The name of the app
     * @param IRequest      $request       The HTTP request object
     * @param ObjectService $objectService The object service instance
     *
     * @return void
     *
     * @spec openspec/specs/zoeken-filteren/spec.md
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ObjectService $objectService
    ) {
        // Call parent constructor to initialize base controller.
        parent::__construct(appName: $appName, request: $request);
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
    public function search(): JSONResponse
    {
        // Step 1: Get the search query from request parameters (default to empty string).
        $query = $this->request->getParam('query', '');

        // Step 2: Process the search query to handle multiple search words.
        // This handles comma-separated values, arrays, and case-insensitive matching.
        $processedQuery = $this->processSearchQuery(query: $query);

        // Step 3: Build search parameters for the database search path.
        // Note: This is a simplified search endpoint. For full Nextcloud search integration,
        // use the ObjectsProvider which implements IFilteringProvider.
        $searchParams = [
            '_search' => $processedQuery,
            '_page'   => (int) floor(((int) $this->request->getParam('offset', 0)) / max(1, (int) $this->request->getParam('limit', 25))) + 1,
            '_limit'  => (int) ($this->request->getParam('limit', 25)),
        ];

        // Step 4: Perform search using ObjectService database path.
        // Returns: ['results' => ObjectEntity[], 'total' => int, '@self' => [...]].
        $paginatedResult = $this->objectService->searchObjectsPaginated(query: $searchParams);

        // Step 5: Format search results for JSON response.
        // Extract relevant fields from each ObjectEntity and standardize format.
        $formattedResults = array_map(
            // phpcs:ignore Squiz.Commenting.BlockComment.NoEmptyLineBefore -- Empty line conflicts with "first argument must be on line after opening parenthesis" rule
            /*
             * Format search result item.
             *
             * @param \OCA\OpenRegister\Db\ObjectEntity|array $object
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

            function ($object): array {
                // Probe the PROPERTY, not the method. searchObjectsPaginated()
                // returns ObjectEntity rows, and ObjectEntity declares getUuid() and
                // getName() only as `@method` — Nextcloud's Entity serves them through
                // __call(). method_exists() is FALSE for both, so every row fell past
                // this branch, and the array branch below cannot read an object either:
                // $objectArr stayed [] and EVERY search hit was returned as
                // `id: null, name: 'Unknown'`.
                //
                // There is no fallback to recover it here: unlike the getUuid probes
                // elsewhere in this app, nothing on this path routes through
                // getObject(), which is the concrete method that injects the uuid
                // under 'id'. property_exists() is the same test Entity::getter() runs
                // before returning the value, so it cannot throw.
                if ($object instanceof Entity && property_exists($object, 'uuid') === true) {
                    $name = null;
                    if (property_exists($object, 'name') === true) {
                        // @phpstan-ignore-next-line Entity::getName() is dispatched via __call.
                        $name = $object->getName();
                    }

                    return [
                        // @phpstan-ignore-next-line Entity::getUuid() is dispatched via __call.
                        'id'     => $object->getUuid(),
                        'name'   => $name ?? 'Unknown',
                        'type'   => 'object',
                        'url'    => null,
                        'source' => 'openregister',
                    ];
                }

                $objectArr = [];
                if (is_array($object) === true) {
                    $objectArr = $object;
                }

                return [
                    'id'     => $objectArr['uuid'] ?? $objectArr['id'] ?? null,
                    'name'   => $objectArr['name'] ?? $objectArr['@self']['name'] ?? 'Unknown',
                    'type'   => 'object',
                    'url'    => $objectArr['url'] ?? null,
                    'source' => 'openregister',
                ];
            },
            $paginatedResult['results'] ?? []
        );

        // Step 6: Return formatted search results with metadata.
        return new JSONResponse(
            data: [
                'results' => $formattedResults,
                'total'   => $paginatedResult['total'] ?? 0,
                'facets'  => [],
            ]
        );
    }//end search()

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
     * @return string The processed search query ready for the SOLR search service
     *
     * @suppressWarnings(PHPMD.CyclomaticComplexity)
     *
     * @spec openspec/specs/zoeken-filteren/spec.md#requirement-dutch-language-search-support-i18n
     */
    private function processSearchQuery(string $query): string
    {
        // Handle array parameters (_search[]).
        $searchArray = $this->request->getParam('_search', []);
        if (is_array($searchArray) === true && empty($searchArray) === false) {
            // Combine array values with the main query.
            $searchTerms = array_merge(
                [$query],
                $searchArray
            );
        }

        if (is_array($searchArray) === false || empty($searchArray) === true) {
            // Handle comma-separated values in the main query.
            $searchTerms = array_filter(
                array_map('trim', explode(',', $query)),
                function ($term) {
                    return empty($term) === false;
                }
            );
        }

        // If no search terms found, return the original query.
        if (empty($searchTerms) === true) {
            return $query;
        }

        // Process each search term to make them case-insensitive and support partial matches.
        $processedTerms = [];
        foreach ($searchTerms as $term) {
            // Convert to lowercase for case-insensitive matching.
            $lowerTerm = strtolower(trim($term));

            // Add wildcards for partial matching if not already present.
            if (str_starts_with($lowerTerm, '*') === false && str_starts_with($lowerTerm, '%') === false) {
                $lowerTerm = '*'.$lowerTerm;
            }

            if (str_ends_with($lowerTerm, '*') === false && str_ends_with($lowerTerm, '%') === false) {
                $lowerTerm = $lowerTerm.'*';
            }

            $processedTerms[] = $lowerTerm;
        }

        // Join multiple terms with OR logic (any term can match).
        return implode(' OR ', $processedTerms);
    }//end processSearchQuery()
}//end class
