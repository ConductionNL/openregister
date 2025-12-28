<?php

/**
 * Class SearchController
 *
 * Controller for handling search operations in the OpenRegister app.
 *
 * @category Controller
 * @package  OCA\OpenRegister\AppInfo
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\OpenRegister\Service\IndexService;

/**
 * SearchController handles search operations
 *
 * Controller for handling search operations in the application.
 * Provides functionality to search across objects using SOLR search service.
 * Supports query processing, pagination, and result formatting.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
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
     * The SOLR search service
     *
     * Handles SOLR-based search operations for objects.
     *
     * @var IndexService Index search service instance
     */
    private readonly IndexService $indexService;

    /**
     * Constructor for the SearchController
     *
     * Initializes controller with SOLR search service for object search operations.
     * Calls parent constructor to set up base controller functionality.
     *
     * @param string       $appName      The name of the app
     * @param IRequest     $request      The HTTP request object
     * @param IndexService $indexService The index search service instance
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        IndexService $indexService
    ) {
        // Call parent constructor to initialize base controller.
        parent::__construct(appName: $appName, request: $request);

        // Store index service for search operations.
        $this->indexService = $indexService;
    }//end __construct()

    /**
     * Handles search requests and forwards them to the SOLR search service
     *
     * Processes search query, performs SOLR search, and formats results for JSON response.
     * Supports pagination via offset and limit parameters.
     * Returns formatted search results with facets and total count.
     *
     * @return JSONResponse Search results with facets and total count.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200, array{results: array<never, array{id: mixed|null, name: 'Unknown'|mixed,
     *                                type: 'object', url: mixed|null, source: 'openregister'}>, total: 0|mixed,
     *                                facets: array<never, never>|mixed}, array<never, never>>
     */
    public function search(): JSONResponse
    {
        // Step 1: Get the search query from request parameters (default to empty string).
        $query = $this->request->getParam('query', '');

        // Step 2: Process the search query to handle multiple search words.
        // This handles comma-separated values, arrays, and case-insensitive matching.
        $processedQuery = $this->processSearchQuery($query);

        // Step 3: Build search parameters for SOLR query.
        // Note: This is a simplified search endpoint. For full Nextcloud search integration,
        // Use the ObjectsProvider which implements IFilteringProvider.
        $searchParams = [
            'q'     => $processedQuery,
            'start' => (int) ($this->request->getParam('offset', 0)),
            'rows'  => (int) ($this->request->getParam('limit', 25)),
        ];

        // Step 4: Perform search using SOLR service.
        // Returns: ['objects' => [], 'facets' => [], 'total' => int, 'execution_time_ms' => float].
        $results = $this->indexService->searchObjects($searchParams);

        // Step 5: Format search results for JSON response.
        // Extract relevant fields from each object and standardize format.
        $formattedResults = array_map(
            /*
             * @return (mixed|null|string)[]
             *
             * @psalm-return array{id: mixed|null, name: 'Unknown'|mixed, type: 'object', url: mixed|null, source: 'openregister'}
             */
            function (array $object): array {
                return [
                    'id'     => $object['uuid'] ?? $object['id'] ?? null,
                    'name'   => $object['name'] ?? $object['@self']['name'] ?? 'Unknown',
                    'type'   => 'object',
                    'url'    => $object['url'] ?? null,
                    'source' => 'openregister',
                ];
            },
            $results['objects'] ?? []
        );

        // Step 6: Return formatted search results with metadata.
        return new JSONResponse(
            data: [
                'results' => $formattedResults,
                'total'   => $results['total'] ?? 0,
                'facets'  => $results['facets'] ?? [],
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
        } else {
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
                $lowerTerm = '*' . $lowerTerm;
            }

            if (str_ends_with($lowerTerm, '*') === false && str_ends_with($lowerTerm, '%') === false) {
                $lowerTerm = $lowerTerm . '*';
            }

            $processedTerms[] = $lowerTerm;
        }

        // Join multiple terms with OR logic (any term can match).
        return implode(' OR ', $processedTerms);
    }//end processSearchQuery()
}//end class
