<?php

/**
 * SolrQueryExecutor
 *
 * Handles query execution and search operations for Solr.
 * Manages query building, execution, and result parsing.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Index\Backends\Solr
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Index\Backends\Solr;

use Exception;
use Psr\Log\LoggerInterface;

/**
 * SolrQueryExecutor
 *
 * Executes search queries against Solr.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Index\Backends\Solr
 */
class SolrQueryExecutor
{

    /**
     * HTTP client.
     *
     * @var SolrHttpClient
     */
    private readonly SolrHttpClient $httpClient;

    /**
     * Collection manager.
     *
     * @var SolrCollectionManager
     */
    private readonly SolrCollectionManager $collectionManager;

    /**
     * Logger.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param SolrHttpClient        $httpClient        HTTP client
     * @param SolrCollectionManager $collectionManager Collection manager
     * @param LoggerInterface       $logger            Logger
     *
     * @return void
     */
    public function __construct(
        SolrHttpClient $httpClient,
        SolrCollectionManager $collectionManager,
        LoggerInterface $logger
    ) {
        $this->httpClient        = $httpClient;
        $this->collectionManager = $collectionManager;
        $this->logger            = $logger;
    }//end __construct()

    /**
     * Execute a search query.
     *
     * @param array $params Query parameters
     *
     * @return array Search results
     */
    public function search(array $params): array
    {
        $collection = $this->collectionManager->getActiveCollectionName();

        if ($collection === null) {
            $this->logger->warning('[SolrQueryExecutor] No active collection for search');
            return [
                'response' => [
                    'numFound' => 0,
                    'docs'     => [],
                ],
            ];
        }

        try {
            $queryString = http_build_query($params);
            $url         = $this->httpClient->getEndpointUrl($collection).'/select?'.$queryString;

            $result = $this->httpClient->get($url);

            $this->logger->debug(
                '[SolrQueryExecutor] Search executed',
                [
                    'collection' => $collection,
                    'query'      => $params['q'] ?? '*:*',
                    'numFound'   => $result['response']['numFound'] ?? 0,
                ]
            );

            return $result;
        } catch (Exception $e) {
            $this->logger->error(
                '[SolrQueryExecutor] Search failed',
                [
                    'error' => $e->getMessage(),
                ]
            );

            return [
                'response' => [
                    'numFound' => 0,
                    'docs'     => [],
                ],
                'error'    => $e->getMessage(),
            ];
        }//end try
    }//end search()

    /**
     * Search with pagination.
     *
     * @param array $query        Query parameters
     * @param bool  $rbac         Apply RBAC filters
     * @param bool  $multitenancy Apply multitenancy filters
     * @param bool  $published    Filter for published only
     * @param bool  $deleted      Include deleted items
     *
     * @return array Paginated search results
     */
    public function searchPaginated(
        array $query=[],
        bool $rbac=true,
        bool $multitenancy=true,
        bool $published=false,
        bool $deleted=false
    ): array {
        // Build Solr query from OpenRegister query format.
        $solrQuery = $this->buildSolrQuery($query);

        // Apply filters.
        if ($rbac === true || $multitenancy === true || $published === true || $deleted === false) {
            $filters = [];

            if ($published === true) {
                $filters[] = 'published:true';
            }

            if ($deleted === false) {
                $filters[] = '-deleted:true';
            }

            if (empty($filters) === false) {
                $solrQuery['fq'] = array_merge($solrQuery['fq'] ?? [], $filters);
            }
        }

        $solrQuery['wt'] = 'json';

        // Execute search.
        $result = $this->search($solrQuery);

        // Convert to OpenRegister paginated format.
        return $this->convertToPaginatedFormat($result, $query);
    }//end searchPaginated()

    /**
     * Build Solr query from OpenRegister query format.
     *
     * @param array $query OpenRegister query
     *
     * @return (int|mixed|string)[] Solr query parameters
     *
     * @psalm-return array{q: '*:*'|mixed, start: int, rows: int, sort?: string, fl?: mixed|string}
     */
    private function buildSolrQuery(array $query): array
    {
        $solrQuery = [
            'q'     => $query['_search'] ?? '*:*',
            'start' => (int) ($query['_offset'] ?? $query['_start'] ?? 0),
            'rows'  => (int) ($query['_limit'] ?? $query['_rows'] ?? 30),
        ];

        // Handle sorting.
        if (isset($query['_order']) === true) {
            $solrQuery['sort'] = $this->translateSortField($query['_order']);
        }

        // Handle field selection.
        if (isset($query['_fields']) === true) {
            if (is_array($query['_fields']) === true) {
                $solrQuery['fl'] = implode(',', $query['_fields']);
            } else {
                $solrQuery['fl'] = $query['_fields'];
            }
        }

        return $solrQuery;
    }//end buildSolrQuery()

    /**
     * Translate sort field to Solr format.
     *
     * @param array|string $order Sort specification
     *
     * @return string Solr sort string
     */
    private function translateSortField(array|string $order): string
    {
        if (is_string($order) === true) {
            return $order;
        }

        $sortParts = [];
        foreach ($order as $field => $direction) {
            if (strtolower((string) $direction) === 'desc') {
                $dir = 'desc';
            } else {
                $dir = 'asc';
            }

            $sortParts[] = "{$field} {$dir}";
        }

        return implode(', ', $sortParts);
    }//end translateSortField()

    /**
     * Convert Solr response to OpenRegister paginated format.
     *
     * @param array $solrResult Solr search result
     * @param array $query      Original query
     *
     * @return (array|int|mixed)[] Paginated result
     *
     * @psalm-return array{results: array<never, never>|mixed, total: 0|mixed, limit: int, offset: 0|mixed, page: int, pages: int}
     */
    private function convertToPaginatedFormat(array $solrResult, array $query): array
    {
        $response = $solrResult['response'] ?? [];
        $docs     = $response['docs'] ?? [];
        $numFound = $response['numFound'] ?? 0;
        $start    = $response['start'] ?? 0;

        $limit = (int) ($query['_limit'] ?? 30);
        $page  = (int) ($query['_page'] ?? 1);

        if ($limit > 0) {
            $pages = (int) ceil($numFound / $limit);
        } else {
            $pages = 0;
        }

        return [
            'results' => $docs,
            'total'   => $numFound,
            'limit'   => $limit,
            'offset'  => $start,
            'page'    => $page,
            'pages'   => $pages,
        ];
    }//end convertToPaginatedFormat()

    /**
     * Inspect index with a query.
     *
     * @param string $query  Solr query
     * @param int    $start  Start offset
     * @param int    $rows   Number of rows
     * @param string $fields Fields to return
     *
     * @return array Inspection results
     */
    public function inspectIndex(
        string $query='*:*',
        int $start=0,
        int $rows=20,
        string $fields=''
    ): array {
        $params = [
            'q'     => $query,
            'start' => $start,
            'rows'  => $rows,
            'wt'    => 'json',
        ];

        if (empty($fields) === false) {
            $params['fl'] = $fields;
        }

        return $this->search($params);
    }//end inspectIndex()

    /**
     * Get statistics about the index.
     *
     * @return (bool|int|mixed|null|string)[] Statistics
     *
     * @psalm-return array{available: bool, collection: null|string, error?: string, documents?: 0|mixed, status?: 'OK'}
     */
    public function getStats(): array
    {
        $collection = $this->collectionManager->getActiveCollectionName();

        if ($collection === null) {
            return [
                'available'  => false,
                'collection' => null,
            ];
        }

        try {
            // Get basic stats.
            $result = $this->search(['q' => '*:*', 'rows' => 0, 'wt' => 'json']);

            return [
                'available'  => true,
                'collection' => $collection,
                'documents'  => $result['response']['numFound'] ?? 0,
                'status'     => 'OK',
            ];
        } catch (Exception $e) {
            return [
                'available'  => false,
                'collection' => $collection,
                'error'      => $e->getMessage(),
            ];
        }//end try
    }//end getStats()
}//end class
