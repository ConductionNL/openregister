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
use OCP\IRequest;
use Psr\Log\LoggerInterface;

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
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex query routing and optimization logic
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Query operations require many handler dependencies
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)      Boolean flags are part of established API pattern for RBAC/multitenancy filtering
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)     Complex business logic requires multiple conditional paths
 * @SuppressWarnings(PHPMD.NPathComplexity)          Query operations have inherently complex execution paths
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)    Query methods handle complex operations that benefit from cohesion
 */
class QueryHandler
{
    /**
     * Constructor for QueryHandler.
     *
     * @param ObjectEntityMapper                       $objectEntityMapper  Mapper for objects.
     * @param \OCA\OpenRegister\Db\UnifiedObjectMapper $unifiedObjectMapper Unified mapper.
     * @param GetObject                                $getHandler          Get handler.
     * @param RenderObject                             $renderHandler       Render handler.
     * @param SearchQueryHandler                       $searchQueryHandler  Search handler.
     * @param FacetHandler                             $facetHandler        Facet handler.
     * @param PerformanceOptimizationHandler           $performanceHandler  Performance handler.
     * @param IAppContainer                            $container           App container.
     * @param LoggerInterface                          $logger              Logger.
     * @param IRequest                                 $request             Request object.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI requires constructor injection
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly \OCA\OpenRegister\Db\UnifiedObjectMapper $unifiedObjectMapper,
        private readonly GetObject $getHandler,
        private readonly RenderObject $renderHandler,
        private readonly SearchQueryHandler $searchQueryHandler,
        private readonly FacetHandler $facetHandler,
        private readonly PerformanceOptimizationHandler $performanceHandler,
        private readonly IAppContainer $container,
        private readonly LoggerInterface $logger,
        private readonly IRequest $request
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
        $activeOrgUuid = null;
        if ($_multitenancy === true) {
            $activeOrgUuid = $this->performanceHandler->getActiveOrganisationForContext();
        }

        // Count uses the unified mapper's countSearchObjects for proper magic mapper routing.
        return $this->unifiedObjectMapper->countSearchObjects(
            query: $query,
            activeOrgUuid: $activeOrgUuid,
            rbac: $_rbac,
            multitenancy: $_multitenancy,
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
     * @psalm-return   int<0, max>|list<ObjectEntity>
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

        // Detect if complex rendering is needed (extend, fields, filter, unset).
        $hasComplexRendering = empty($query['_extend'] ?? []) === false
            || empty($query['_fields'] ?? null) === false
            || empty($query['_filter'] ?? null) === false
            || empty($query['_unset'] ?? null) === false;

        // Get active organization context for multi-tenancy.
        $activeOrgUuid = null;
        if ($_multitenancy === true) {
            $activeOrgUuid = $this->performanceHandler->getActiveOrganisationForContext();
        }

        // Execute database search.
        $result = $this->unifiedObjectMapper->searchObjects(
            query: $query,
            activeOrgUuid: $activeOrgUuid,
            rbac: $_rbac,
            multitenancy: $_multitenancy,
            ids: $ids,
            uses: $uses
        );

        // If _count is requested, return count instead of objects.
        if (($query['_count'] ?? false) === true || ($query['_count'] ?? false) === 'true') {
            return count($result);
        }

        // Return early if no complex rendering is needed.
        if ($hasComplexRendering === false) {
            return $result;
        }

        // Apply complex rendering (extend, fields, filter, unset).
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
        $startTime = microtime(true);

        // Set published filter if not already set.
        if (isset($query['_published']) === false) {
            $query['_published'] = $published;
        }

        // Extract pagination parameters (limit=0 is valid for count/facets-only requests).
        $limit  = max(0, (int) ($query['_limit'] ?? 20));
        $offset = $query['_offset'] ?? null;
        $page   = $query['_page'] ?? null;

        // Calculate offset from page if provided.
        if ($page !== null && $offset === null) {
            $page   = max(1, (int) $page);
            $offset = ($page - 1) * $limit;
        }

        // Calculate page from offset if not provided (avoid division by zero).
        if ($page === null && $offset !== null && $limit > 0) {
            $page = (int) floor($offset / $limit) + 1;
        }

        // Default values.
        $page   = $page ?? 1;
        $offset = $offset ?? 0;

        // Prepare paginated query (remove pagination params for count query).
        $paginatedQuery = array_merge($query, ['_limit' => $limit, '_offset' => $offset]);
        unset($paginatedQuery['_page'], $paginatedQuery['_facetable']);

        $countQuery = $query;
        unset($countQuery['_limit'], $countQuery['_offset'], $countQuery['_page'], $countQuery['_facetable']);

        // Get active organization context for multi-tenancy.
        $activeOrgUuid = null;
        if ($_multitenancy === true) {
            $activeOrgUuid = $this->performanceHandler->getActiveOrganisationForContext();
        }

        // Use optimized combined search+count that loads register/schema once.
        $searchResult = $this->unifiedObjectMapper->searchObjectsPaginated(
            searchQuery: $paginatedQuery,
            countQuery: $countQuery,
            activeOrgUuid: $activeOrgUuid,
            rbac: $_rbac,
            multitenancy: $_multitenancy,
            ids: $ids,
            uses: $uses
        );

        $results   = $searchResult['results'];
        $total     = $searchResult['total'];
        $registers = $searchResult['registers'] ?? [];
        $schemas   = $searchResult['schemas'] ?? [];

        // Detect if complex rendering is needed (extend, fields, filter, unset).
        // Skip @self.register and @self.schema from extend since we include them in response @self.
        $extend = $query['_extend'] ?? [];
        if (is_string($extend) === true) {
            $extend = array_filter(array_map('trim', explode(',', $extend)));
        }

        // Remove schema and register extensions from extend - we provide them at response level.
        // This prevents slow per-object extension; instead we batch-load once for all results.
        // Supports multiple formats: @self.schema, @self.register, _schema, _register.
        $extend = array_filter(
            $extend,
            function (string $item): bool {
                return !in_array($item, ['@self.schema', '@self.register', '_schema', '_register'], true);
            }
        );

        $hasComplexRendering = empty($extend) === false
            || empty($query['_fields'] ?? null) === false
            || empty($query['_filter'] ?? null) === false
            || empty($query['_unset'] ?? null) === false;

        // Apply complex rendering if needed.
        if ($hasComplexRendering === true && is_array($results) === true) {
            $results = $this->renderHandler->renderEntities(
                entities: $results,
                _extend: $extend,
                _filter: $query['_filter'] ?? null,
                _fields: $query['_fields'] ?? null,
                _unset: $query['_unset'] ?? null,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy
            );
        }

        // Calculate total pages (avoid division by zero when limit=0).
        $pages = 0;
        if ($limit > 0) {
            $pages = max(1, (int) ceil($total / $limit));
        }

        // Build result structure with registers/schemas indexed by ID at response @self level.
        $paginatedResults = [
            'results' => $results,
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'limit'   => $limit,
            'offset'  => $offset,
            'facets'  => [],
            '@self'   => [],
        ];

        // Add registers and schemas indexed by ID to response @self.
        // Only include when explicitly requested via _extend parameter.
        // Supports both singular (_register, _schema) and plural (_registers, _schemas) forms.
        $extend = $query['_extend'] ?? [];
        if (is_string($extend) === true) {
            $extend = explode(',', $extend);
        }

        if ((in_array('_registers', $extend, true) === true || in_array('_register', $extend, true) === true)
            && empty($registers) === false
        ) {
            $paginatedResults['@self']['registers'] = $registers;
        }

        if ((in_array('_schemas', $extend, true) === true || in_array('_schema', $extend, true) === true)
            && empty($schemas) === false
        ) {
            $paginatedResults['@self']['schemas'] = $schemas;
        }

        // Add facets if requested.
        $hasFacets    = empty($query['_facets']) === false;
        $hasFacetable = ($query['_facetable'] ?? false) === true || ($query['_facetable'] ?? false) === 'true';

        if ($hasFacets === true) {
            $facetResult = $this->facetHandler->getFacetsForObjects($countQuery);
            $paginatedResults['facets'] = $facetResult['facets'] ?? [];
        }

        if ($hasFacetable === true) {
            $paginatedResults['facetable'] = $this->facetHandler->getFacetableFields(
                baseQuery: $countQuery,
                _sampleSize: 100
            );
        }

        // Add performance metrics if requested.
        if (($query['_performance'] ?? false) === true || ($query['_performance'] ?? false) === 'true') {
            $paginatedResults['@performance'] = [
                'totalTime' => round((microtime(true) - $startTime) * 1000, 2).'ms',
            ];
        }

        return $paginatedResults;
    }//end searchObjectsPaginatedDatabase()
}//end class
