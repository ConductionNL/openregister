<?php
/**
 * OpenRegister SearchService
 *
 * Service class for handling search operations in the OpenRegister application.
 *
 * This service provides methods for:
 * - Searching objects across multiple sources
 * - Merging search results
 * - Handling facets/aggregations
 * - Processing search parameters
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use OCP\IURLGenerator;

/**
 * Service class for handling search functionality.
 *
 * This class provides methods for searching objects across multiple sources,
 * merging results, handling facets/aggregations, and processing search parameters.
 */
class SearchService
{

    /**
     * HTTP client for making requests.
     * 
     * @var Client HTTP client for making requests.
     */
    public $client;

    /**
     * Default base object configuration for database operations.
     *
     * @var array Default base object configuration.
     */
    public const BASE_OBJECT = [
        'database'   => 'objects',
        'collection' => 'json',
    ];


    /**
     * Constructor.
     *
     * @param IURLGenerator $urlGenerator URL generator service.
     */
    public function __construct(
        private readonly IURLGenerator $urlGenerator,
    ) {
        $this->client = new Client();

    }//end __construct()


    /**
     * Merges facet counts from two aggregations.
     *
     * @param array $existingAggregation Original aggregation array.
     * @param array $newAggregation      New aggregation array to merge.
     *
     * @return array Merged facet counts.
     */
    public function mergeFacets(array $existingAggregation, array $newAggregation): array
    {
        $results = [];
        $existingAggregationMapped = [];
        $newAggregationMapped      = [];

        // Map existing aggregation counts by ID.
        foreach ($existingAggregation as $value) {
            $existingAggregationMapped[$value['_id']] = $value['count'];
        }

        // Merge new aggregation counts, adding to existing where present.
        foreach ($newAggregation as $value) {
            if (isset($existingAggregationMapped[$value['_id']]) === true) {
                $newAggregationMapped[$value['_id']] = ($existingAggregationMapped[$value['_id']] + $value['count']);
            } else {
                $newAggregationMapped[$value['_id']] = $value['count'];
            }
        }

        // Format results array with merged counts.
        foreach (
            array_merge(
                array_diff($existingAggregationMapped, $newAggregationMapped),
                array_diff($newAggregationMapped, $existingAggregationMapped)
            ) as $key => $value
        ) {
            $results[] = ['_id' => $key, 'count' => $value];
        }

        return $results;

    }//end mergeFacets()


    /**
     * Merges multiple aggregation arrays.
     *
     * @param array|null $existingAggregations Original aggregations.
     * @param array|null $newAggregations      New aggregations to merge.
     *
     * @return array Merged aggregations.
     */
    private function mergeAggregations(?array $existingAggregations, ?array $newAggregations): array
    {
        if ($newAggregations === null) {
            return [];
        }

        // Merge each aggregation key.
        foreach ($newAggregations as $key => $aggregation) {
            if (isset($existingAggregations[$key]) === false) {
                $existingAggregations[$key] = $aggregation;
            } else {
                $existingAggregations[$key] = $this->mergeFacets($existingAggregations[$key], $aggregation);
            }
        }

        return $existingAggregations;

    }//end mergeAggregations()


    /**
     * Comparison function for sorting results by score.
     *
     * @param array $a First result array.
     * @param array $b Second result array.
     *
     * @return int Comparison result (-1, 0, 1).
     */
    public function sortResultArray(array $a, array $b): int
    {
        return ($a['_score'] <=> $b['_score']);

    }//end sortResultArray()


    /**
     * Main search function that queries multiple sources and merges results.
     *
     * @param array $parameters    Search parameters and filters.
     * @param array $elasticConfig Elasticsearch configuration.
     * @param array $dbConfig      Database configuration.
     * @param array $catalogi      List of catalogs to search.
     *
     * @return array Combined search results with facets and pagination info.
     */
    public function search(array $parameters, array $elasticConfig, array $dbConfig, array $catalogi=[]): array
    {
        // Initialize results arrays.
        $localResults['results'] = [];
        $localResults['facets']  = [];

        $totalResults = 0;
        $limit        = 30;
        if (isset($parameters['.limit']) === true) {
            $limit = $parameters['.limit'];
        }
        
        $page = 1;
        if (isset($parameters['.page']) === true) {
            $page = $parameters['.page'];
        }

        // Query elastic if configured.
        if ($elasticConfig['location'] !== '') {
            $localResults = $this->elasticService->searchObject(filters: $parameters, config: $elasticConfig, totalResults: $totalResults);
        }

        $directory = $this->directoryService->listDirectory(limit: 1000);

        // Return early if no directory entries.
        if (count($directory) === 0) {
            $pages = (int) ceil($totalResults / $limit);
            return [
                'results' => $localResults['results'],
                'facets'  => $localResults['facets'],
                'count'   => count($localResults['results']),
                'limit'   => $limit,
                'page'    => $page,
                'pages'   => ($pages === 0) ? 1 : $pages,
                'total'   => $totalResults,
            ];
        }

        $results      = $localResults['results'];
        $aggregations = $localResults['facets'];

        $searchEndpoints = [];

        // Build search requests for each endpoint.
        $promises = [];
        foreach ($directory as $instance) {
            if (($instance['default'] === false) 
                || (isset($parameters['.catalogi']) === true
                && in_array($instance['catalogId'], $parameters['.catalogi']) === false)
                || ($instance['search'] === $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute(routeName:"opencatalogi.directory.index")))
            ) {
                continue;
            }

            $searchEndpoints[$instance['search']][] = $instance['catalogId'];
        }

        unset($parameters['.catalogi']);

        // Create async requests for each endpoint.
        foreach ($searchEndpoints as $searchEndpoint => $catalogi) {
            $parameters['_catalogi'] = $catalogi;
            $promises[] = $this->client->getAsync($searchEndpoint, ['query' => $parameters]);
        }

        // Wait for all requests to complete.
        $responses = Utils::settle($promises)->wait();

        // Process responses and merge results.
        foreach ($responses as $response) {
            if ($response['state'] === 'fulfilled') {
                $responseData = json_decode(
                    json: $response['value']->getBody()->getContents(),
                    associative: true
                );

                $results = array_merge(
                    $results,
                    $responseData['results']
                );

                usort($results, [$this, 'sortResultArray']);

                $aggregations = $this->mergeAggregations($aggregations, $responseData['facets']);
            }
        }

        $pages = (int) ceil($totalResults / $limit);

        // Return combined results with pagination info.
        return [
            'results' => $results,
            'facets'  => $aggregations,
            'count'   => count($results),
            'limit'   => $limit,
            'page'    => $page,
            'pages'   => ($pages === 0) ? 1 : $pages,
            'total'   => $totalResults,
        ];

    }//end search()


    /**
     * This function adds a single query param to the given $vars array. ?$name=$value
     *
     * @param array  $vars    The vars array we are going to store the query parameter in.
     * @param string $name    The full $name of the query param, like this: ?$name=$value.
     * @param string $nameKey The full $name of the query param, unless it contains [] like: ?queryParam[$nameKey]=$value.
     * @param string $value   The full $value of the query param, like this: ?$name=$value.
     * 
     * Will check if request query $name has [...] inside the parameter, like this: ?queryParam[$nameKey]=$value.
     * Works recursive, so in case we have ?queryParam[$nameKey][$anotherNameKey][etc][etc]=$value.
     * Also checks for queryParams ending on [] like: ?queryParam[$nameKey][] (or just ?queryParam[]), if this is the case
     * this function will add given value to an array of [queryParam][$nameKey][] = $value or [queryParam][] = $value.
     * If none of the above this function will just add [queryParam] = $value to $vars.
     *
     * @return void
     */
    private function recursiveRequestQueryKey(array &$vars, string $name, string $nameKey, string $value): void
    {
        $matchesCount = preg_match(pattern: '/(\[[^[\]]*])/', subject: $name, matches:$matches);
        if ($matchesCount > 0) {
            $key  = $matches[0];
            $name = str_replace(search: $key, replace:'', subject: $name);
            $key  = trim(string: $key, characters: '[]');
            if (empty($key) === false) {
                $vars[$nameKey] = ($vars[$nameKey] ?? []);
                $this->recursiveRequestQueryKey(
                    vars: $vars[$nameKey],
                    name: $name,
                    nameKey: $key,
                    value: $value
                );
            } else {
                $vars[$nameKey][] = $value;
            }
        } else {
            $vars[$nameKey] = $value;
        }

    }//end recursiveRequestQueryKey()


    /**
     * This function creates a mongodb filter array.
     *
     * Also unsets _search in filters !
     *
     * @param array $filters        Query parameters from request.
     * @param array $fieldsToSearch Database field names to filter/search on.
     *
     * @return array $filters
     */
    public function createMongoDBSearchFilter(array $filters, array $fieldsToSearch): array
    {
        if (isset($filters['_search']) === true) {
            $searchRegex    = ['$regex' => $filters['_search'], '$options' => 'i'];
            $filters['$or'] = [];

            foreach ($fieldsToSearch as $field) {
                $filters['$or'][] = [$field => $searchRegex];
            }

            unset($filters['_search']);
        }

        foreach ($filters as $field => $value) {
            if ($value === 'IS NOT NULL') {
                $filters[$field] = ['$ne' => null];
            }

            if ($value === 'IS NULL') {
                $filters[$field] = ['$eq' => null];
            }
        }

        return $filters;

    }//end createMongoDBSearchFilter()


    /**
     * This function creates mysql search conditions based on given filters from request.
     *
     * @param array $filters        Query parameters from request.
     * @param array $fieldsToSearch Fields to search on in sql.
     *
     * @return array $searchConditions
     */
    public function createMySQLSearchConditions(array $filters, array $fieldsToSearch): array
    {
        $searchConditions = [];
        if (isset($filters['_search']) === true) {
            foreach ($fieldsToSearch as $field) {
                $searchConditions[] = "LOWER($field) LIKE :search";
            }
        }

        return $searchConditions;

    }//end createMySQLSearchConditions()


    /**
     * This function unsets all keys starting with _ from filters.
     *
     * @param array $filters Query parameters from request.
     *
     * @return array $filters
     */
    public function unsetSpecialQueryParams(array $filters): array
    {
        foreach ($filters as $key => $value) {
            if (str_starts_with($key, '_')) {
                unset($filters[$key]);
            }
        }

        return $filters;

    }//end unsetSpecialQueryParams()


    /**
     * This function creates mysql search parameters based on given filters from request.
     *
     * @param array $filters Query parameters from request.
     *
     * @return array $searchParams
     */
    public function createMySQLSearchParams(array $filters): array
    {
        $searchParams = [];
        if (isset($filters['_search']) === true) {
            $searchParams['search'] = '%'.strtolower($filters['_search']).'%';
        }

        return $searchParams;

    }//end createMySQLSearchParams()


    /**
     * This function creates an sort array based on given order param from request.
     *
     * @param array $filters Query parameters from request.
     *
     * @return array $sort
     */
    public function createSortForMySQL(array $filters): array
    {
        $sort = [];
        if (isset($filters['_order']) === true && is_array($filters['_order']) === true) {
            foreach ($filters['_order'] as $field => $direction) {
                $direction    = (strtoupper($direction) === 'DESC') ? 'DESC' : 'ASC';
                $sort[$field] = $direction;
            }
        }

        return $sort;

    }//end createSortForMySQL()


    /**
     * This function creates an sort array based on given order param from request.
     *
     * @todo Not functional yet. Needs to be fixed (see PublicationsController->index).
     *
     * @param array $filters Query parameters from request.
     *
     * @return array $sort
     */
    public function createSortForMongoDB(array $filters): array
    {
        $sort = [];
        if (isset($filters['_order']) === true && is_array($filters['_order']) === true) {
            foreach ($filters['_order'] as $field => $direction) {
                $sort[$field] = (strtoupper($direction) === 'DESC') ? -1 : 1;
            }
        }

        return $sort;

    }//end createSortForMongoDB()


    /**
     * Parses the request query string and returns it as an array of queries.
     *
     * @param string $queryString The input query string from the request.
     *
     * @return array The resulting array of query parameters.
     */
    public function parseQueryString(string $queryString=''): array
    {
        $pairs = explode(separator: '&', string: $queryString);

        foreach ($pairs as $pair) {
            $kvpair = explode(separator: '=', string: $pair);

            $key   = urldecode(string: $kvpair[0]);
            $value = '';
            if (count(value: $kvpair) === 2) {
                $value = urldecode(string: $kvpair[1]);
            }

            $this->recursiveRequestQueryKey(
                vars: $vars,
                name: $key,
                nameKey: substr(
                    string: $key,
                    offset: 0,
                    length: (strpos(
                        haystack: $key,
                        needle: '['
                    ))
                ),
                value: $value
            );
        }//end foreach

        return $vars;

    }//end parseQueryString()


}//end class
