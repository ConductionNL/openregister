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
use OCP\Search\ISearch;
use OCP\Search\Result;
use OCA\OpenRegister\Service\SolrService;

/**
 * Class SearchController
 *
 * Controller for handling search operations in the application.
 * Provides functionality to search across the application using the Nextcloud search service.
 */
class SearchController extends Controller
{

    /**
     * The Nextcloud search service
     *
     * @var            ISearch
     * @psalm-suppress UndefinedClass
     */
    private readonly ISearch $searchService;

    /**
     * The SOLR search service
     *
     * @var SolrService
     */
    private readonly SolrService $solrService;


    /**
     * Constructor for the SearchController
     *
     * @param string      $appName       The name of the app
     * @param IRequest    $request       The request object
     * @param ISearch     $searchService The search service
     * @param SolrService $solrService   The Solr search service
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        ISearch $searchService,
        SolrService $solrService
    ) {
        parent::__construct($appName, $request);
        $this->searchService = $searchService;
        $this->solrService   = $solrService;

    }//end __construct()


    /**
     * Handles search requests and forwards them to the Nextcloud search service
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse A JSON response containing the search results
     *
     * @psalm-return JSONResponse<200, array<array{id: mixed, name: mixed, type: mixed, url: mixed, source: mixed}>, array<never, never>>
     */
    public function search(): JSONResponse
    {
        // Get the search query from the request parameters.
        $query = $this->request->getParam('query', '');

        // Process the search query to handle multiple search words.
        $processedQuery = $this->processSearchQuery($query);

        // Perform the search using the search service.
        $results = $this->searchService->search($processedQuery);

        // Format the search results for the JSON response.
        $formattedResults = array_map(
            function (Result $result) {

                /*
                 * @psalm-suppress UndefinedMethod
                 */

                $id = $result->getId();

                /*
                 * @psalm-suppress UndefinedMethod
                 */

                $name = $result->getName();

                /*
                 * @psalm-suppress UndefinedMethod
                 */

                $type = $result->getType();

                /*
                 * @psalm-suppress UndefinedMethod
                 */

                $url = $result->getUrl();

                /*
                 * @psalm-suppress UndefinedMethod
                 */

                $source = $result->getSource();

                return [
                    'id'     => $id,
                    'name'   => $name,
                    'type'   => $type,
                    'url'    => $url,
                    'source' => $source,
                ];
            },
            $results
        );

        return new JSONResponse($formattedResults);

    }//end search()


    /**
     * Process search query to support multiple search words and case-insensitive partial matches
     *
     * This method handles multiple search words by:
     * 1. Supporting comma-separated values in the query parameter
     * 2. Supporting array parameters (_search[])
     * 3. Making searches case-insensitive
     * 4. Enabling partial matches (e.g., 'tes' matches 'test')
     *
     * @param string $query The raw search query from the request
     *
     * @return string The processed search query ready for the search service
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
