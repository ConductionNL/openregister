<?php

/**
 * SolrQueryExecutor
 *
 * Handles query execution and search operations for Solr.
 * Manages query building, execution, and result parsing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
use OCA\OpenRegister\Service\OrganisationService;
use OCP\IUserSession;
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
     * Organisation service (multitenancy resolution).
     *
     * @var OrganisationService
     */
    private readonly OrganisationService $organisationService;

    /**
     * User session (RBAC owner resolution).
     *
     * @var IUserSession
     */
    private readonly IUserSession $userSession;

    /**
     * Constructor
     *
     * @param SolrHttpClient        $httpClient          HTTP client
     * @param SolrCollectionManager $collectionManager   Collection manager
     * @param LoggerInterface       $logger              Logger
     * @param OrganisationService   $organisationService Organisation service
     * @param IUserSession          $userSession         User session
     *
     * @return void
     *
     * @spec openspec/changes/retrofit-2026-05-24-search-index/tasks.md#task-1
     */
    public function __construct(
        SolrHttpClient $httpClient,
        SolrCollectionManager $collectionManager,
        LoggerInterface $logger,
        OrganisationService $organisationService,
        IUserSession $userSession
    ) {
        $this->httpClient          = $httpClient;
        $this->collectionManager   = $collectionManager;
        $this->logger              = $logger;
        $this->organisationService = $organisationService;
        $this->userSession         = $userSession;
    }//end __construct()

    /**
     * Execute a search query.
     *
     * @param array $params Query parameters
     *
     * @return array Search results
     *
     * @spec openspec/changes/retrofit-2026-05-24-search-index/tasks.md#task-1
     */
    public function search(array $params): array
    {
        $collection = $this->collectionManager->getActiveCollectionName();

        if ($collection === null) {
            $this->logger->warning(
                message: '[SolrQueryExecutor] No active collection for search',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
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
                message: '[SolrQueryExecutor] Search executed',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'collection' => $collection,
                    'query'      => $params['q'] ?? '*:*',
                    'numFound'   => $result['response']['numFound'] ?? 0,
                ]
            );

            return $result;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[SolrQueryExecutor] Search failed',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
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
     * @param array $query         Query parameters
     * @param bool  $_rbac         Apply RBAC filters
     * @param bool  $_multitenancy Apply multitenancy filters
     * @param bool  $deleted       Include deleted items
     *
     * @return array Paginated search results with pagination info.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Paginated search requires handling multiple filter conditions
     * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple filter combinations create many execution paths
     *
     * @spec openspec/changes/retrofit-2026-05-24-newcap-search-index-backend/tasks.md#task-10
     */
    public function searchPaginated(
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $deleted=false
    ): array {
        // Build Solr query from OpenRegister query format.
        $solrQuery = $this->buildSolrQuery(query: $query);

        // Apply filters.
        if ($_rbac === true || $_multitenancy === true || $deleted === false) {
            $filters = [];

            if ($deleted === false) {
                $filters[] = '-deleted:true';
            }

            // BUG-SVC-3: multitenancy — restrict to the caller's active
            // organisation. Fail closed: with no active organisation, match a
            // sentinel that never exists so no cross-tenant docs leak.
            if ($_multitenancy === true) {
                $activeOrg = $this->organisationService->getActiveOrganisation();
                $orgUuid   = $activeOrg?->getUuid();
                if (empty($orgUuid) === true) {
                    $orgUuid = '__no_active_org__';
                }

                $filters[] = 'organisation:'.$this->escapeSolrQuery(value: (string) $orgUuid);
            }

            // BUG-SVC-3: RBAC — non-admin callers may only see their own
            // documents. Mirror MagicRbacHandler's owner predicate. Fail
            // closed when there is no authenticated user.
            if ($_rbac === true) {
                $userId = $this->userSession->getUser()?->getUID();
                if (empty($userId) === true) {
                    $userId = '__no_authenticated_user__';
                }

                $filters[] = 'owner:'.$this->escapeSolrQuery(value: (string) $userId);
            }

            if (empty($filters) === false) {
                $solrQuery['fq'] = array_merge($solrQuery['fq'] ?? [], $filters);
            }
        }//end if

        $solrQuery['wt'] = 'json';

        // Execute search.
        $result = $this->search(params: $solrQuery);

        // Convert to OpenRegister paginated format.
        return $this->convertToPaginatedFormat(solrResult: $result, query: $query);
    }//end searchPaginated()

    /**
     * Build Solr query from OpenRegister query format.
     *
     * @param array $query OpenRegister query
     *
     * @return (int|mixed|string)[] Solr query parameters
     *
     * @psalm-return array{q: '*:*'|mixed, start: int, rows: int, sort?: string, fl?: mixed|string}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Query building requires handling multiple parameter types
     *
     * @spec openspec/changes/retrofit-2026-05-24-search-index/tasks.md#task-1
     */
    private function buildSolrQuery(array $query): array
    {
        // BUG-SVC-10: Lucene-escape the user-supplied search term so special
        // characters (e.g. `:`, `(`, `&&`) can't alter query semantics or
        // trigger Solr parse errors. A literal `*:*` (no _search) is left as-is.
        $searchTerm = '*:*';
        if (isset($query['_search']) === true && $query['_search'] !== '' && $query['_search'] !== '*:*') {
            $searchTerm = $this->escapeSolrQuery(value: (string) $query['_search']);
        }

        $solrQuery = [
            'q'     => $searchTerm,
            'start' => (int) ($query['_offset'] ?? $query['_start'] ?? 0),
            'rows'  => (int) ($query['_limit'] ?? $query['_rows'] ?? 30),
        ];

        // Handle sorting.
        if (isset($query['_order']) === true) {
            $solrQuery['sort'] = $this->translateSortField(order: $query['_order']);
        }

        // Handle field selection.
        if (isset($query['_fields']) === true) {
            $solrQuery['fl'] = $query['_fields'];
            if (is_array($query['_fields']) === true) {
                $solrQuery['fl'] = implode(',', $query['_fields']);
            }
        }

        return $solrQuery;
    }//end buildSolrQuery()

    /**
     * Escape Lucene/Solr query special characters.
     *
     * Backslash-escapes every character in the Lucene special set so a
     * user-supplied value can be used as a literal term or filter value
     * without altering query semantics or causing a Solr parse error.
     *
     * @param string $value Raw user-supplied value.
     *
     * @return string The escaped value.
     *
     * @spec exclude Internal query-string escaping helper; no business rule.
     */
    private function escapeSolrQuery(string $value): string
    {
        // Lucene special characters: + - && || ! ( ) { } [ ] ^ " ~ * ? : \ /
        $special = [
            '\\',
            '+',
            '-',
            '&',
            '|',
            '!',
            '(',
            ')',
            '{',
            '}',
            '[',
            ']',
            '^',
            '"',
            '~',
            '*',
            '?',
            ':',
            '/',
        ];

        foreach ($special as $char) {
            $value = str_replace($char, '\\'.$char, $value);
        }

        return $value;
    }//end escapeSolrQuery()

    /**
     * Translate sort field to Solr format.
     *
     * @param array|string $order Sort specification
     *
     * @return string Solr sort string
     *
     * @spec openspec/changes/retrofit-2026-05-24-newcap-search-index-backend/tasks.md#task-10
     * @spec openspec/changes/retrofit-2026-05-24-search-index/tasks.md#task-1
     */
    private function translateSortField(array|string $order): string
    {
        if (is_string($order) === true) {
            return $order;
        }

        $sortParts = [];
        foreach ($order as $field => $direction) {
            $dir = 'asc';
            if (strtolower((string) $direction) === 'desc') {
                $dir = 'desc';
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
     * @return array Paginated format with results and pagination info.
     *
     * @spec openspec/changes/retrofit-2026-05-24-newcap-search-index-backend/tasks.md#task-10
     * @spec openspec/changes/retrofit-2026-05-24-search-index/tasks.md#task-1
     */
    private function convertToPaginatedFormat(array $solrResult, array $query): array
    {
        $response = $solrResult['response'] ?? [];
        $docs     = $response['docs'] ?? [];
        $numFound = $response['numFound'] ?? 0;
        $start    = $response['start'] ?? 0;

        $limit = (int) ($query['_limit'] ?? 30);
        $page  = (int) ($query['_page'] ?? 1);

        $pages = 0;
        if ($limit > 0) {
            $pages = (int) ceil($numFound / $limit);
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
     *
     * @spec openspec/changes/retrofit-2026-05-24-newcap-search-index-backend/tasks.md#task-10
     * @spec openspec/changes/retrofit-2026-05-24-search-index/tasks.md#task-1
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

        return $this->search(params: $params);
    }//end inspectIndex()

    /**
     * Get statistics about the index.
     *
     * @return (bool|int|mixed|null|string)[] Statistics
     *
     * @psalm-return array{available: bool, collection: null|string, error?: string, documents?: 0|mixed, status?: 'OK'}
     *
     * @spec exclude thin stats wrapper — runs a rows=0 search and reports numFound
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
            $result = $this->search(params: ['q' => '*:*', 'rows' => 0, 'wt' => 'json']);

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
