<?php

/**
 * QueryHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\RenderObject;
use OCA\OpenRegister\Service\Object\SearchQueryHandler;
use OCA\OpenRegister\Service\Object\FacetHandler;
use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCP\AppFramework\IAppContainer;
use Psr\Log\LoggerInterface;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Promise\PromiseInterface;

use function React\Promise\all;

use React\Async;

/**
 * Handles all query and search operations for ObjectService.
 *
 * This is the LARGEST handler responsible for:
 * - find(), findSilent(), findAll(), count()
 * - searchObjects(), searchObjectsPaginated()
 * - Async and sync pagination
 * - Solr vs Database routing
 * - Performance optimizations
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class QueryHandler
{
    /**
     * Constructor for QueryHandler.
     *
     * @param ObjectEntityMapper             $objectEntityMapper Mapper for object entities.
     * @param GetObject                      $getHandler         Handler for get operations.
     * @param RenderObject                   $renderHandler      Handler for render operations.
     * @param SearchQueryHandler             $searchQueryHandler Handler for search query operations.
     * @param FacetHandler                   $facetHandler       Handler for facet operations.
     * @param PerformanceOptimizationHandler $performanceHandler Handler for performance optimizations.
     * @param IAppContainer                  $container          Application container for service access.
     * @param LoggerInterface                $logger             Logger for logging operations.
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly GetObject $getHandler,
        private readonly RenderObject $renderHandler,
        private readonly SearchQueryHandler $searchQueryHandler,
        private readonly FacetHandler $facetHandler,
        private readonly PerformanceOptimizationHandler $performanceHandler,
        private readonly IAppContainer $container,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Count search objects matching the query.
     *
     * @param array       $query         The search query.
     * @param bool        $_rbac         Whether to apply RBAC checks.
     * @param bool        $_multitenancy Whether to apply multitenancy filtering.
     * @param array|null  $ids           Optional array of IDs to filter by.
     * @param string|null $uses          Optional uses parameter.
     *
     * @psalm-param   array<string, mixed> $query
     * @psalm-param   array<int, string>|null $ids
     * @phpstan-param array<string, mixed> $query
     * @phpstan-param array<int, string>|null $ids
     *
     * @return int The count of matching objects.
     *
     * @psalm-return   int
     * @phpstan-return int
     */
    public function countSearchObjects(
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true,
        ?array $ids=null,
        ?string $uses=null
    ): int {
        $activeOrganisationUuid = null;
        if ($_multitenancy === true) {
            $activeOrganisationUuid = $this->performanceHandler->getActiveOrganisationForContext();
        }

        // Count uses the mapper's countSearchObjects.
        return $this->objectEntityMapper->countSearchObjects(
            query: $query,
            _activeOrganisationUuid: $activeOrganisationUuid,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            ids: $ids,
            uses: $uses
        );
    }//end countSearchObjects()

    /**
     * Search objects using clean query structure.
     *
     * @param array       $query         The search query.
     * @param bool        $_rbac         Whether to apply RBAC checks.
     * @param bool        $_multitenancy Whether to apply multitenancy filtering.
     * @param array|null  $ids           Optional array of IDs to filter by.
     * @param string|null $uses          Optional uses parameter.
     * @param array|null  $views         Optional view IDs to apply.
     *
     * @psalm-param array<string, mixed> $query
     * @psalm-param array<int, string>|null $ids
     * @psalm-param array<int, string>|null $views
     *
     * @phpstan-param array<string, mixed> $query
     * @phpstan-param array<int, string>|null $ids
     * @phpstan-param array<int, string>|null $views
     *
     * @return ObjectEntity[]|int
     *
     * @psalm-return int<0, max>|list<ObjectEntity>
     * @phpstan-return array<int, ObjectEntity>|int
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     */
    public function searchObjects(
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true,
        ?array $ids=null,
        ?string $uses=null,
        ?array $views=null
    ): array|int {
        // Apply view filters if provided.
        if ($views !== null && empty($views) === false) {
            $query = $this->searchQueryHandler->applyViewsToQuery(query: $query, viewIds: $views);
        }

        // **CRITICAL PERFORMANCE OPTIMIZATION**: Detect simple vs complex rendering needs.
        $hasExtend           = empty($query['_extend'] ?? []) === false;
        $hasFields           = empty($query['_fields'] ?? null) === false;
        $hasFilter           = empty($query['_filter'] ?? null) === false;
        $hasUnset            = empty($query['_unset'] ?? null) === false;
        $hasComplexRendering = $hasExtend || $hasFields || $hasFilter || $hasUnset;

        // Get active organization context for multi-tenancy (only if multi is enabled).
        $activeOrganisationUuid = null;
        if ($_multitenancy === true) {
            $activeOrganisationUuid = $this->performanceHandler->getActiveOrganisationForContext();
        }

        // **MAPPER CALL**: Execute database search.
        $dbStart = microtime(true);
        $limit   = $query['_limit'] ?? 20;

        $this->logger->info(
            message: '🔍 MAPPER CALL - Starting database search',
            context: [
                'queryKeys'  => array_keys($query),
                'rbac'       => $_rbac,
                'multi'      => $_multitenancy,
                'limit'      => $limit,
                'requestUri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            ]
        );

        // **MAPPER CALL TIMING**: Track how long the mapper takes.
        $mapperStart = microtime(true);
        $result      = $this->objectEntityMapper->searchObjects(
            query: $query,
            _activeOrganisationUuid: $activeOrganisationUuid,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            ids: $ids,
            uses: $uses
        );

        $resultCount = 'non-array';
        if (is_array($result) === true) {
            $resultCount = count($result);
        }

        $this->logger->info(
            message: '✅ MAPPER CALL - Database search completed',
            context: [
                'resultCount' => $resultCount,
                'mapperTime'  => round((microtime(true) - $mapperStart) * 1000, 2).'ms',
                'totalTime'   => round((microtime(true) - $dbStart) * 1000, 2).'ms',
            ]
        );

        // If _count is requested, return count instead of objects.
        if (($query['_count'] ?? false) === true || ($query['_count'] ?? false) === 'true') {
            return count($result);
        }

        // Return early if no complex rendering is needed.
        if ($hasComplexRendering === false) {
            $this->logger->debug(
                message: '⚡ FAST PATH - No rendering needed, returning raw results',
                context: [
                    'resultCount' => count($result),
                    'reason'      => 'no_extend_fields_filter_unset',
                ]
            );

            return $result;
        }

        // **RENDERING**: Apply complex rendering if needed.
        $this->logger->debug(
            message: '🎨 RENDERING - Complex rendering required',
            context: [
                'hasExtend' => $hasExtend,
                'hasFields' => $hasFields,
                'hasFilter' => $hasFilter,
                'hasUnset'  => $hasUnset,
            ]
        );

        return $this->renderHandler->renderEntities(
            entities: $result,
            _extend: $query['_extend'] ?? [],
            _filter: $query['_filter'] ?? null,
            _fields: $query['_fields'] ?? null,
            _unset: $query['_unset'] ?? null,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy
        );
    }//end searchObjects()

    /**
     * Search objects with pagination (main entry point).
     *
     * @param array       $query         The search query.
     * @param bool        $_rbac         Whether to apply RBAC checks.
     * @param bool        $_multitenancy Whether to apply multitenancy filtering.
     * @param bool        $published     Whether to filter by published status.
     * @param bool        $deleted       Whether to include deleted objects.
     * @param array|null  $ids           Optional array of IDs to filter by.
     * @param string|null $uses          Optional uses parameter.
     * @param array|null  $views         Optional view IDs to apply.
     *
     * @psalm-param   array<string, mixed> $query
     * @psalm-param   array<int, string>|null $ids
     * @psalm-param   array<int, string>|null $views
     * @phpstan-param array<string, mixed> $query
     * @phpstan-param array<int, string>|null $ids
     * @phpstan-param array<int, string>|null $views
     *
     * @return array Paginated search results.
     *
     * @psalm-return   array<string, mixed>
     * @phpstan-return array<string, mixed>
     */
    public function searchObjectsPaginated(
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $published=false,
        bool $deleted=false,
        ?array $ids=null,
        ?string $uses=null,
        ?array $views=null
    ): array {
        // Apply view filters if provided.
        if ($views !== null && empty($views) === false) {
            $query = $this->searchQueryHandler->applyViewsToQuery(query: $query, viewIds: $views);
        }

        $requestedSource = $query['_source'] ?? null;

        // Simple switch: Use SOLR if explicitly requested OR if SOLR is enabled in config.
        // BUT force database when ids or uses parameters are provided (relation-based searches).
        $hasIds          = isset($query['_ids']) === true;
        $hasUses         = isset($query['_uses']) === true;
        $hasIdsParam     = $ids !== null;
        $hasUsesParam    = $uses !== null;
        $isSolrRequested = ($requestedSource === 'index' || $requestedSource === 'solr');
        $isSolrEnabled   = $this->searchQueryHandler->isSolrAvailable();
        $isNotDatabase   = $requestedSource !== 'database';

        if ((            $isSolrRequested === true
            && $hasIdsParam === false && $hasUsesParam === false
            && $hasIds === false && $hasUses === false)
            || (            $requestedSource === null
            && $isSolrEnabled === true
            && $isNotDatabase === true
            && $hasIdsParam === false && $hasUsesParam === false
            && $hasIds === false && $hasUses === false)
        ) {
            // Forward to Index service - let it handle availability checks and error handling.
            $indexService = $this->container->get(IndexService::class);
            $result       = $indexService->searchObjects(
                query: $query,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy,
                published: $published,
                deleted: $deleted
            );
            $result['@self']['source']    = 'index';
            $result['@self']['query']     = $query;
            $result['@self']['rbac']      = $_rbac;
            $result['@self']['multi']     = $_multitenancy;
            $result['@self']['published'] = $published;
            $result['@self']['deleted']   = $deleted;
            return $result;
        }

        // Use database search.
        $result = $this->searchObjectsPaginatedDatabase(
            query: $query,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            published: $published,
            deleted: $deleted,
            ids: $ids,
            uses: $uses
        );
        $result['@self']['source']    = 'database';
        $result['@self']['query']     = $query;
        $result['@self']['rbac']      = $_rbac;
        $result['@self']['multi']     = $_multitenancy;
        $result['@self']['published'] = $published;
        $result['@self']['deleted']   = $deleted;

        return $result;
    }//end searchObjectsPaginated()

    /**
     * Database-based paginated search (extracted from main method).
     *
     * @param array       $query         The search query.
     * @param bool        $_rbac         Whether to apply RBAC checks.
     * @param bool        $_multitenancy Whether to apply multitenancy filtering.
     * @param bool        $published     Whether to filter by published status.
     * @param bool        $deleted       Whether to include deleted objects.
     * @param array|null  $ids           Optional array of IDs to filter by.
     * @param string|null $uses          Optional uses parameter.
     *
     * @psalm-param   array<string, mixed> $query
     * @psalm-param   array<int, string>|null $ids
     * @phpstan-param array<string, mixed> $query
     * @phpstan-param array<int, string>|null $ids
     *
     * @return array Paginated search results.
     *
     * @psalm-return   array<string, mixed>
     * @phpstan-return array<string, mixed>
     */
    public function searchObjectsPaginatedDatabase(
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $published=false,
        bool $deleted=false,
        ?array $ids=null,
        ?string $uses=null
    ): array {
        // **PERFORMANCE MONITORING**: Check for _performance=true parameter.
        $includePerformance = ($query['_performance'] ?? false) === true || ($query['_performance'] ?? false) === 'true';

        if ($includePerformance === true) {
            $this->logger->info(
                message: '📊 PERFORMANCE MONITORING: _performance=true parameter detected',
                context: [
                    'requestUri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                    'purpose'    => 'performance_analysis',
                    'note'       => 'Response will include detailed performance metrics',
                ]
            );
        }

        // **PERFORMANCE OPTIMIZATION**: Start timing execution and detect request complexity.
        $startTime = microtime(true);

        // **PERFORMANCE DETECTION**: Determine if this is a complex request requiring async processing.
        $hasFacets        = empty($query['_facets']) === false;
        $hasFacetable     = ($query['_facetable'] ?? false) === true || ($query['_facetable'] ?? false) === 'true';
        $isComplexRequest = $hasFacets || $hasFacetable;

        if (isset($query['_published']) === false) {
            $query['_published'] = $published;
        }

        // **PERFORMANCE OPTIMIZATION**: For complex requests, use async version for better performance.
        if ($isComplexRequest === true) {
            if ($hasFacets === true) {
                $facetCount = count($query['_facets'] ?? []);
            } else {
                $facetCount = 0;
            }

            $this->logger->debug(
                message: 'Complex request detected, using async processing',
                context: [
                    'hasFacets'    => $hasFacets,
                    'hasFacetable' => $hasFacetable,
                    'facetCount'   => $facetCount,
                ]
            );

            // Use async version and return synchronous result.
            return $this->searchObjectsPaginatedSync(
                query: $query,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy,
                published: $published,
                deleted: $deleted
            );
        }//end if

        // **PERFORMANCE OPTIMIZATION**: Simple requests - minimal operations for sub-500ms performance.
        $this->logger->debug(
            message: 'Simple request detected, using optimized path',
            context: [
                'limit'     => $query['_limit'] ?? 20,
                'hasExtend' => empty($query['_extend']) === false,
                'hasSearch' => empty($query['_search']) === false,
            ]
        );

        // Extract pagination parameters.
        $limit  = $query['_limit'] ?? 20;
        $offset = $query['_offset'] ?? null;
        $page   = $query['_page'] ?? null;

        // Calculate offset from page if provided.
        if ($page !== null && $offset === null) {
            $page = max(1, (int) $page);
            // Ensure page is at least 1.
            $offset = ($page - 1) * $limit;
        }

        // Calculate page from offset if not provided.
        if ($page === null && $offset !== null && $limit > 0) {
            $page = floor($offset / $limit) + 1;
        }

        // Default values.
        $page   = $page ?? 1;
        $offset = $offset ?? 0;
        $limit  = max(1, (int) $limit);

        // **PERFORMANCE OPTIMIZATION**: Prepare optimized queries.
        $paginatedQuery = array_merge(
            $query,
            [
                '_limit'  => $limit,
                '_offset' => $offset,
            ]
        );

        // Remove page parameter from the query as we use offset internally.
        unset($paginatedQuery['_page'], $paginatedQuery['_facetable']);

        // **CRITICAL OPTIMIZATION**: Get search results and count in a single optimized call.
        $searchStartTime = microtime(true);
        $results         = $this->searchObjects(
            query: $paginatedQuery,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            ids: $ids,
            uses: $uses
        );
        $searchTime      = round((microtime(true) - $searchStartTime) * 1000, 2);

        $countStartTime = microtime(true);
        $total          = $this->countSearchObjects(
            query: $query,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            ids: $ids,
            uses: $uses
        );
        $countTime      = round((microtime(true) - $countStartTime) * 1000, 2);

        // Calculate total pages.
        $pages = max(1, ceil($total / $limit));

        // Build the paginated results structure.
        $paginatedResults = [
            'results' => $results,
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'limit'   => $limit,
            'offset'  => $offset,
            'facets'  => [],
        ];

        // Add performance metrics if requested.
        if ($includePerformance === true) {
            $totalTime = round((microtime(true) - $startTime) * 1000, 2);
            $paginatedResults['@performance'] = [
                'totalTime'  => $totalTime.'ms',
                'searchTime' => $searchTime.'ms',
                'countTime'  => $countTime.'ms',
                'mode'       => 'simple_sync',
            ];
        }

        return $paginatedResults;
    }//end searchObjectsPaginatedDatabase()

    /**
     * Synchronous wrapper for async paginated search (used for complex requests).
     *
     * @param array $query         The search query.
     * @param bool  $_rbac         Whether to apply RBAC checks.
     * @param bool  $_multitenancy Whether to apply multitenancy filtering.
     * @param bool  $published     Whether to filter by published status.
     * @param bool  $deleted       Whether to include deleted objects.
     *
     * @return array Paginated search results.
     *
     * @psalm-param    array<string, mixed> $query
     * @phpstan-param  array<string, mixed> $query
     * @psalm-return   array<string, mixed>
     * @phpstan-return array<string, mixed>
     */
    public function searchObjectsPaginatedSync(
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $published=false,
        bool $deleted=false
    ): array {
        // Execute async version and wait for result.
        $promise = $this->searchObjectsPaginatedAsync(
            query: $query,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy,
            _published: $published,
            _deleted: $deleted
        );

        // Note: React\Async\await requires react/async package which is optional.
        // For now, we fall back to synchronous resolution of the promise.
        // If react/async is installed, uncomment: return Async\await($promise);
        $result = null;
        $promise->then(
            function ($value) use (&$result) {
                $result = $value;
            },
            function ($error) {
                throw $error;
            }
        );

        // Return result (synchronous fallback).
        return $result;
    }//end searchObjectsPaginatedSync()

    /**
     * Async paginated search with concurrent promise execution.
     *
     * @param array $query         The search query.
     * @param bool  $_rbac         Whether to apply RBAC checks.
     * @param bool  $_multitenancy Whether to apply multitenancy filtering.
     * @param bool  $_published    Whether to filter by published status.
     * @param bool  $_deleted      Whether to include deleted objects.
     *
     * @psalm-param array<string, mixed> $query
     *
     * @phpstan-param array<string, mixed> $query
     *
     * @return PromiseInterface
     *
     * @psalm-return   PromiseInterface<array{results: mixed, total: mixed,
     *     page: float|int<1, max>|mixed, pages: 1|float, limit: int<1, max>,
     *     offset: 0|mixed, facets: mixed, facetable?: mixed}>
     * @phpstan-return PromiseInterface
     */
    public function searchObjectsPaginatedAsync(
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $_published=false,
        bool $_deleted=false
    ): PromiseInterface {
        // Start timing execution.
        $startTime  = microtime(true);
        $queryLimit = $query['_limit'] ?? 20;
        $this->logger->debug(
            message: 'Starting searchObjectsPaginatedAsync',
            context: ['query_limit' => $queryLimit]
        );

        // Extract pagination parameters (same as synchronous version).
        $limit     = $query['_limit'] ?? 20;
        $offset    = $query['_offset'] ?? null;
        $page      = $query['_page'] ?? null;
        $facetable = $query['_facetable'] ?? false;

        // Calculate offset from page if provided.
        if ($page !== null && $offset === null) {
            $page   = max(1, (int) $page);
            $offset = ($page - 1) * $limit;
        }

        // Calculate page from offset if not provided.
        if ($page === null && $offset !== null && $limit > 0) {
            $page = floor($offset / $limit) + 1;
        }

        // Default values.
        $page   = $page ?? 1;
        $offset = $offset ?? 0;
        $limit  = max(1, (int) $limit);

        // Prepare queries for different operations.
        $paginatedQuery = array_merge(
            $query,
            [
                '_limit'  => $limit,
                '_offset' => $offset,
            ]
        );
        unset($paginatedQuery['_page']);

        $countQuery = $query;
        // Use original query without pagination.
        unset($countQuery['_limit'], $countQuery['_offset'], $countQuery['_page'], $countQuery['_facetable']);

        // Create promises for each operation in order of expected duration (longest first).
        $promises = [];

        // 1. Facetable discovery (~25ms) - Only if requested.
        if ($facetable === true || $facetable === 'true') {
            $baseQuery  = $countQuery;
            $sampleSize = (int) ($query['_sample_size'] ?? 100);

            $promises['facetable'] = new Promise(
                /**
                 * @param callable(mixed): void $resolve
                 * @param callable(\Throwable): void $reject
                 */
                function (callable $resolve, callable $reject) use ($baseQuery, $sampleSize) {
                    try {
                        $result = $this->facetHandler->getFacetableFields(baseQuery: $baseQuery, sampleSize: $sampleSize);
                        $resolve($result);
                    } catch (\Throwable $e) {
                        $this->logger->error(
                            message: '❌ FACETABLE PROMISE ERROR',
                            context: [
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString(),
                            ]
                        );
                        $reject($e);
                    }
                }
            );
        }//end if

        // 2. Search results (~10ms).
        $promises['search'] = new Promise(
            /**
             * @param callable(mixed): void $resolve
             * @param callable(\Throwable): void $reject
             */
            function (callable $resolve, callable $reject) use ($paginatedQuery, $_rbac, $_multitenancy) {
                try {
                    $searchStart = microtime(true);
                    $result      = $this->searchObjects(
                        query: $paginatedQuery,
                        _rbac: $_rbac,
                        _multitenancy: $_multitenancy,
                        ids: null,
                        uses: null
                    );
                    $searchTime  = round((microtime(true) - $searchStart) * 1000, 2);
                    $this->logger->debug(
                        message: 'Search objects completed',
                        context: [
                            'searchTime'  => $searchTime.'ms',
                            'resultCount' => count($result),
                            'limit'       => $paginatedQuery['_limit'] ?? 20,
                        ]
                    );
                    $resolve($result);
                } catch (\Throwable $e) {
                    $reject($e);
                }//end try
            }
        );

        // 3. Facets (~10ms).
        $promises['facets'] = new Promise(
            /**
             * @param callable(mixed): void $resolve
             * @param callable(\Throwable): void $reject
             */
            function (callable $resolve, callable $reject) use ($countQuery) {
                try {
                    $result = $this->facetHandler->getFacetsForObjects($countQuery);
                    $resolve($result);
                } catch (\Throwable $e) {
                    $reject($e);
                }
            }
        );

        // 4. Count (~5ms).
        $promises['count'] = new Promise(
            /**
             * @param callable(mixed): void $resolve
             * @param callable(\Throwable): void $reject
             */
            function (callable $resolve, callable $reject) use ($countQuery, $_rbac, $_multitenancy) {
                try {
                    $result = $this->countSearchObjects(
                        query: $countQuery,
                        _rbac: $_rbac,
                        _multitenancy: $_multitenancy
                    );
                    $resolve($result);
                } catch (\Throwable $e) {
                    $reject($e);
                }
            }
        );

        // Execute all promises concurrently and combine results.
        return \React\Promise\all($promises)->then(
            function ($results) use ($page, $limit, $offset, $query, $startTime) {
                // Extract results from promises.
                $searchResults   = $results['search'];
                $total           = $results['count'];
                $facets          = $results['facets'];
                $facetableFields = $results['facetable'] ?? null;

                // Calculate total pages.
                $pages = max(1, ceil($total / $limit));

                // Build the paginated results structure.
                $paginatedResults = [
                    'results' => $searchResults,
                    'total'   => $total,
                    'page'    => $page,
                    'pages'   => $pages,
                    'limit'   => $limit,
                    'offset'  => $offset,
                    'facets'  => $facets,
                ];

                // Add facetable field discovery if it was requested.
                if ($facetableFields !== null) {
                    $paginatedResults['facetable'] = $facetableFields;
                }

                $totalTime = round((microtime(true) - $startTime) * 1000, 2);
                $this->logger->debug(
                    message: 'Async search completed',
                    context: [
                        'totalTime'   => $totalTime.'ms',
                        'resultCount' => count($searchResults),
                        'total'       => $total,
                    ]
                );

                return $paginatedResults;
            }
        );
    }//end searchObjectsPaginatedAsync()
}//end class
