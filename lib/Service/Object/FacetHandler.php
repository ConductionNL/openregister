<?php

/**
 * OpenRegister Facet Handler
 *
 * **CENTRALIZED FACETING SYSTEM**: This handler manages all faceting operations
 * with intelligent fallback strategies, response caching, and performance optimization.
 * Solves the fundamental pagination vs faceting architectural conflict.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Service\Object
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\UnifiedObjectMapper;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Facet Handler - Centralized Faceting Operations
 *
 * **ARCHITECTURAL BREAKTHROUGH**: Handles faceting concerns with intelligent fallback strategies
 * that solve the pagination vs faceting conflict.
 *
 * **KEY FEATURES**:
 * - Smart Fallback: Collection-wide facets when filters return empty.
 * - Response Caching: Lightning-fast repeated requests.
 * - Clean Architecture: Integrated handler pattern.
 * - Performance Optimized: Multiple optimization strategies.
 * - Backwards Compatible: Drop-in replacement for existing faceting.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://OpenRegister.app
 * @version  2.0.0
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex faceting logic with multiple strategies
 */
class FacetHandler
{
    /**
     * Cache TTL for facet responses (1 hour).
     *
     * Facet counts don't need real-time accuracy - slight staleness is acceptable.
     * Cache is invalidated when schemas change.
     *
     * @var int
     */
    private const FACET_CACHE_TTL = 3600;

    /**
     * Cache TTL for collection-wide facets (1 hour).
     *
     * Collection-wide facets change even less frequently.
     *
     * @var int
     */
    private const COLLECTION_FACET_TTL = 3600;

    /**
     * Distributed cache for facet responses.
     *
     * @var IMemcache|null
     */
    private ?IMemcache $facetCache = null;

    /**
     * Constructor for FacetHandler.
     *
     * @param UnifiedObjectMapper $unifiedObjectMapper Unified object mapper with storage routing.
     * @param SchemaMapper        $schemaMapper        Schema database mapper.
     * @param ICacheFactory       $cacheFactory        Cache factory for distributed caching.
     * @param IUserSession        $userSession         User session for tenant isolation.
     * @param LoggerInterface     $logger              Logger for debugging and monitoring.
     *
     * @return void
     */
    public function __construct(
        private readonly UnifiedObjectMapper $unifiedObjectMapper,
        private readonly SchemaMapper $schemaMapper,
        /**
         * Logger for facet operations
         *
         * @psalm-suppress UnusedProperty
         */
        private readonly ICacheFactory $cacheFactory,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger
    ) {
        // Initialize facet response caching.
        try {
            $this->facetCache = $this->cacheFactory->createDistributed('openregister_facets');
        } catch (\Exception $e) {
            // Fallback to local cache if distributed cache unavailable.
            try {
                $this->facetCache = $this->cacheFactory->createLocal('openregister_facets');
            } catch (\Exception $e) {
                // No caching available - will skip cache operations.
                $this->facetCache = null;
                $this->logger->warning(message: 'Facet caching unavailable', context: ['error' => $e->getMessage()]);
            }
        }
    }//end __construct()

    /**
     * Get facets for objects based on query.
     *
     * **BREAKTHROUGH SOLUTION**: Solves the pagination vs faceting conflict by implementing
     * intelligent fallback strategies that ensure facets are always meaningful and relevant.
     *
     * **STRATEGY**:
     * 1. Check response cache first (lightning-fast repeated requests).
     * 2. Try facets on current filtered dataset.
     * 3. If empty + restrictive filters → fall back to collection-wide facets.
     * 4. Cache results for future requests.
     * 5. Include performance metadata.
     *
     * **PAGINATION INDEPENDENCE**: Facets are calculated on the complete filtered dataset,
     * ignoring pagination parameters (_limit, _offset, _page) to ensure users always see
     * relevant navigation options regardless of current page or limit.
     *
     * @param array $query The search query array.
     *
     * @throws \OCP\DB\Exception If database error occurs.
     *
     * @psalm-param   array<string, mixed> $query
     * @phpstan-param array<string, mixed> $query
     *
     * @return array Facet results with intelligent fallback and performance metadata.
     *
     * @psalm-return   array<string, mixed>
     * @phpstan-return array<string, mixed>
     */
    public function getFacetsForObjects(array $query=[]): array
    {
        $startTime = microtime(true);

        // Extract facet configuration.
        $facetConfig = $query['_facets'] ?? [];
        if (empty($facetConfig) === true) {
            return ['facets' => []];
        }

        // **BUGFIX**: Handle _facets as string (e.g., _facets=extend) by converting to array.
        if (is_string($facetConfig) === true) {
            // Handle special string values like "extend" or comma-separated field names.
            $facetConfig = [$facetConfig];
        }

        // **PAGINATION INDEPENDENCE**: Remove pagination params for facet calculation.
        $facetQuery = $query;
        unset($facetQuery['_limit'], $facetQuery['_offset'], $facetQuery['_page'], $facetQuery['_facetable']);

        // **RESPONSE CACHING**: Check cache first for identical requests.
        $cacheKey = $this->generateFacetCacheKey(facetQuery: $facetQuery, facetConfig: $facetConfig);
        $cached   = $this->getCachedFacetResponse($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // **INTELLIGENT FACETING**: Try current filters first, then smart fallback.
            $result = $this->calculateFacetsWithFallback(facetQuery: $facetQuery, facetConfig: $facetConfig);

        // **PERFORMANCE TRACKING**: Add timing metadata.
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);
        $result['performance_metadata']['total_execution_time_ms'] = $executionTime;

        // **CACHE RESULTS**: Store for future requests.
            $this->cacheFacetResponse(cacheKey: $cacheKey, result: $result);

        $this->logger->debug(
            message: 'FacetHandler completed facet calculation',
            context: [
                'executionTime'     => $executionTime.'ms',
                'strategy'          => $result['performance_metadata']['strategy'] ?? 'unknown',
                'cacheUsed'         => false,
                'totalFacetResults' => $result['performance_metadata']['total_facet_results'] ?? 0,
            ]
        );

        return $result;
    }//end getFacetsForObjects()

    /**
     * Get facetable fields for discovery.
     *
     * PERFORMANCE OPTIMIZED**: Uses pre-computed schema facets stored in database
     * instead of runtime analysis for lightning-fast _facetable=true requests.
     *
     * @param array $baseQuery   Base query filters to apply for context.
     * @param int   $_sampleSize Sample size (kept for backward compatibility).
     *
     * @psalm-param array<string, mixed> $baseQuery
     * @psalm-param int $_sampleSize
     *
     * @phpstan-param array<string, mixed> $baseQuery
     * @phpstan-param int $_sampleSize
     *
     * @return array[]
     *
     * @psalm-return   array{'@self': array, object_fields: array}
     * @phpstan-return array<string, mixed>
     */
    public function getFacetableFields(array $baseQuery=[], int $_sampleSize=100): array
    {
        $startTime = microtime(true);

        // Get schemas relevant to this query (cached for performance).
        $schemas = $this->getSchemasForQuery($baseQuery);

        // **PERFORMANCE OPTIMIZATION**: Use pre-computed schema facets.
        $facetableFields = $this->getFacetableFieldsFromSchemas($schemas);

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        $selfCount   = count($facetableFields['@self'] ?? []);
        $objectCount = count($facetableFields['object_fields'] ?? []);
        $this->logger->debug(
            message: 'Facetable fields discovery completed',
            context: [
                'executionTime'       => $executionTime.'ms',
                'schemaCount'         => count($schemas),
                'facetableFieldCount' => $selfCount + $objectCount,
            ]
        );

        return $facetableFields;
    }//end getFacetableFields()

    /**
     * Get metadata facetable fields.
     *
     * @return string[]
     *
     * @psalm-return   list{'register', 'schema', 'owner', 'organisation', 'created', 'updated'}
     * @phpstan-return array<int, string>
     */
    public function getMetadataFacetableFields(): array
    {
        return [
            'register',
            'schema',
            'owner',
            'organisation',
            'created',
            'updated',
        ];
    }//end getMetadataFacetableFields()

    /**
     * Calculate facet count for performance metrics.
     *
     * @param bool  $hasFacets Whether facets were requested.
     * @param array $query     The query array.
     *
     * @psalm-param bool $hasFacets
     * @psalm-param array<string, mixed> $query
     *
     * @phpstan-param bool $hasFacets
     * @phpstan-param array<string, mixed> $query
     *
     * @return         int The facet count.
     * @psalm-return   int<0, max>
     * @phpstan-return int
     */
    public function getFacetCount(bool $hasFacets, array $query): int
    {
        if ($hasFacets === false) {
            return 0;
        }

        $facets = $query['_facets'] ?? [];
        if (is_array($facets) === true) {
            return count($facets);
        }

        return 0;
    }//end getFacetCount()

    /**
     * Calculate Facets with Intelligent Fallback Strategy.
     *
     * CORE BREAKTHROUGH**: Implements the smart fallback logic that ensures users
     * always see meaningful facet options, even when their current search/filters
     * return zero results.
     *
     * @param array $facetQuery  Query for facet calculation (without pagination).
     * @param array $facetConfig Facet configuration.
     *
     * @return array Facets with performance metadata including strategy and fallback status.
     */
    private function calculateFacetsWithFallback(array $facetQuery, array $facetConfig): array
    {
        // **STAGE 1**: Try facets with current filters.
        $facets = $this->unifiedObjectMapper->getSimpleFacets($facetQuery);

        // **STAGE 2**: Check if we got meaningful facets.
        $totalFacetResults = $this->countFacetResults($facets);
        $hasRestrictFilter = $this->hasRestrictiveFilters($facetQuery);

        $strategy     = 'filtered';
        $fallbackUsed = false;

        // **INTELLIGENT FALLBACK**: If no facets and we have restrictive filters, try broader query.
        if ($totalFacetResults === 0 && $hasRestrictFilter === true) {
            $this->logger->debug(
                message: 'Facets empty with restrictive filters, trying collection-wide fallback',
                context: [
                    'originalQuery' => array_keys($facetQuery),
                    'totalResults'  => $totalFacetResults,
                ]
            );

            // Create collection-wide query: keep register/schema context but remove restrictive filters.
            $collectionQuery = [
                '@self'           => $facetQuery['@self'] ?? [],
                '_facets'         => $facetConfig,
                '_published'      => $facetQuery['_published'] ?? false,
                '_includeDeleted' => $facetQuery['_includeDeleted'] ?? false,
            ];

            // Calculate collection-wide facets.
            $fallbackFacets  = $this->unifiedObjectMapper->getSimpleFacets($collectionQuery);
            $fallbackResults = $this->countFacetResults($fallbackFacets);

            if ($fallbackResults > 0) {
                $facets       = $fallbackFacets;
                $strategy     = 'collection_fallback';
                $fallbackUsed = true;

                $this->logger->info(
                    message: 'Smart faceting fallback successful',
                    context: [
                        'fallbackResults' => $fallbackResults,
                        'originalResults' => $totalFacetResults,
                        'collectionQuery' => array_keys($collectionQuery),
                    ]
                );
            }
        }//end if

        // Extract per-facet metrics before transformation (if available).
        $perFacetMetrics = $facets['_metrics'] ?? null;
        unset($facets['_metrics']);

        // **OUTPUT FORMAT**: Transform facets to standardized format matching external API.
        $transformedFacets = $this->transformFacetsToStandardFormat($facets);

        $performanceMetadata = [
            'strategy'                => $strategy,
            'fallback_used'           => $fallbackUsed,
            'total_facet_results'     => $this->countFacetResults($facets),
            'has_restrictive_filters' => $hasRestrictFilter,
        ];

        // Include per-facet timing if available.
        if ($perFacetMetrics !== null) {
            $performanceMetadata['facet_db_ms'] = $perFacetMetrics;
        }

        return [
            'facets'               => $transformedFacets,
            'performance_metadata' => $performanceMetadata,
        ];
    }//end calculateFacetsWithFallback()

    /**
     * Transform facets from internal format to standardized external API format.
     *
     * Converts the internal structure:
     * ```
     * { "@self": { "register": { "type": "terms", "buckets": [{key, results, label}] } } }
     * ```
     *
     * To the external API format:
     * ```
     * { "_register": { "name": "_register", "type": "terms", "title": "Register", ... ,
     *                  "data": { "type": "terms", "total_count": N, "buckets": [{value, count, label}] } } }
     * ```
     *
     * @param array $facets Raw facets from mapper.
     *
     * @return array Transformed facets in standardized format.
     */
    private function transformFacetsToStandardFormat(array $facets): array
    {
        $transformed = [];
        $order       = 0;

        // Metadata facet definitions for @self fields.
        $metadataDefinitions = [
            'register'     => [
                'title'       => 'Register',
                'description' => 'metadata field: Register',
                'data_type'   => 'integer',
                'index_field' => 'self_register',
                'index_type'  => 'pint',
                'enabled'     => false,
            ],
            'schema'       => [
                'title'       => 'Type',
                'description' => 'metadata field: Schema',
                'data_type'   => 'integer',
                'index_field' => 'self_schema',
                'index_type'  => 'pint',
                'enabled'     => true,
            ],
            'organisation' => [
                'title'       => 'Leverancier',
                'description' => 'metadata field: Organisation',
                'data_type'   => 'string',
                'index_field' => 'self_organisation',
                'index_type'  => 'string',
                'enabled'     => true,
            ],
            'owner'        => [
                'title'       => 'Owner',
                'description' => 'metadata field: Owner',
                'data_type'   => 'string',
                'index_field' => 'self_owner',
                'index_type'  => 'string',
                'enabled'     => true,
            ],
            'created'      => [
                'title'       => 'Created',
                'description' => 'metadata field: Created',
                'data_type'   => 'datetime',
                'index_field' => 'self_created',
                'index_type'  => 'pdate',
                'enabled'     => false,
            ],
            'updated'      => [
                'title'       => 'Updated',
                'description' => 'metadata field: Updated',
                'data_type'   => 'datetime',
                'index_field' => 'self_updated',
                'index_type'  => 'pdate',
                'enabled'     => false,
            ],
        ];

        // Process @self metadata facets.
        if (isset($facets['@self']) === true && is_array($facets['@self']) === true) {
            foreach ($facets['@self'] as $field => $facetData) {
                $order++;
                $name       = '_'.$field;
                $definition = $metadataDefinitions[$field] ?? [
                    'title'       => ucfirst($field),
                    'description' => 'metadata field: '.ucfirst($field),
                    'data_type'   => 'string',
                    'index_field' => 'self_'.$field,
                    'index_type'  => 'string',
                    'enabled'     => true,
                ];

                $transformed[$name] = $this->buildFacetEntry(
                    name: $name,
                    facetData: $facetData,
                    definition: $definition,
                    source: 'metadata',
                    queryParameter: '@self['.$field.']',
                    order: $order
                );
            }//end foreach
        }//end if

        // Process object field facets (non-@self).
        foreach ($facets as $field => $facetData) {
            if ($field === '@self') {
                continue;
            }

            $order++;

            // Use schema property title if available, otherwise auto-generate from field name.
            $title = $facetData['title'] ?? $this->formatFieldTitle($field);

            // Create definition for object field.
            $definition = [
                'title'       => $title,
                'description' => 'object field: '.$field,
                'data_type'   => $this->inferDataType($facetData),
                'index_field' => $this->sanitizeFieldName($field),
                'index_type'  => 'string',
                'enabled'     => true,
            ];

            $transformed[$field] = $this->buildFacetEntry(
                name: $field,
                facetData: $facetData,
                definition: $definition,
                source: 'object',
                queryParameter: $field,
                order: $order
            );
        }//end foreach

        return $transformed;
    }//end transformFacetsToStandardFormat()

    /**
     * Build a single facet entry in the standardized format.
     *
     * @param string $name           The facet name.
     * @param array  $facetData      The raw facet data with type and buckets.
     * @param array  $definition     The facet definition (title, description, etc.).
     * @param string $source         The source type (metadata or object).
     * @param string $queryParameter The query parameter for filtering.
     * @param int    $order          The display order.
     *
     * @return array The formatted facet entry.
     */
    private function buildFacetEntry(
        string $name,
        array $facetData,
        array $definition,
        string $source,
        string $queryParameter,
        int $order
    ): array {
        $type    = $facetData['type'] ?? 'terms';
        $buckets = $facetData['buckets'] ?? [];

        // Transform buckets to use value/count instead of key/results.
        $transformedBuckets = [];
        foreach ($buckets as $bucket) {
            $transformedBuckets[] = [
                'value' => $bucket['key'] ?? $bucket['value'] ?? '',
                'count' => (int) ($bucket['results'] ?? $bucket['count'] ?? 0),
                'label' => $bucket['label'] ?? (string) ($bucket['key'] ?? $bucket['value'] ?? ''),
            ];
        }

        return [
            'name'           => $name,
            'type'           => $type,
            'title'          => $definition['title'],
            'description'    => $definition['description'],
            'data_type'      => $definition['data_type'],
            'index_field'    => $definition['index_field'],
            'index_type'     => $definition['index_type'],
            'queryParameter' => $queryParameter,
            'source'         => $source,
            'show_count'     => true,
            'enabled'        => $definition['enabled'],
            'order'          => $order,
            'data'           => [
                'type'        => $type,
                'total_count' => count($transformedBuckets),
                'buckets'     => $transformedBuckets,
            ],
        ];
    }//end buildFacetEntry()

    /**
     * Format a field name as a human-readable title.
     *
     * @param string $field The field name (e.g., cloudDienstverleningsmodel).
     *
     * @return string The formatted title (e.g., Cloud Dienstverleningsmodel).
     */
    private function formatFieldTitle(string $field): string
    {
        // Convert camelCase to Title Case.
        $spaced = preg_replace('/([a-z])([A-Z])/', '$1 $2', $field);
        return ucfirst($spaced);
    }//end formatFieldTitle()

    /**
     * Sanitize a field name for use as an index field.
     *
     * @param string $field The field name.
     *
     * @return string The sanitized field name.
     */
    private function sanitizeFieldName(string $field): string
    {
        // Convert camelCase to snake_case.
        $name = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $field);
        $name = strtolower($name);
        // Remove any non-alphanumeric characters except underscore.
        return preg_replace('/[^a-z0-9_]/', '_', $name);
    }//end sanitizeFieldName()

    /**
     * Infer the data type from facet data.
     *
     * @param array $facetData The facet data with type and buckets.
     *
     * @return string The inferred data type.
     */
    private function inferDataType(array $facetData): string
    {
        $type = $facetData['type'] ?? 'terms';

        if ($type === 'date_histogram') {
            return 'datetime';
        }

        if ($type === 'range') {
            return 'number';
        }

        // Check bucket values to infer type.
        $buckets = $facetData['buckets'] ?? [];
        if (empty($buckets) === false) {
            $firstValue = $buckets[0]['key'] ?? null;
            if (is_int($firstValue) === true) {
                return 'integer';
            }

            if (is_float($firstValue) === true) {
                return 'number';
            }
        }

        return 'string';
    }//end inferDataType()

    /**
     * Generate cache key for facet responses.
     *
     * @param array $facetQuery  Query for faceting (without pagination).
     * @param array $facetConfig Facet configuration.
     *
     * @return string Cache key.
     */
    private function generateFacetCacheKey(array $facetQuery, array $facetConfig): string
    {
        // **RBAC COMPLIANCE**: Include user context for role-based access control.
        $user   = $this->userSession->getUser();
        $userId = 'anonymous';
        if ($user !== null) {
            $userId = $user->getUID();
        }

        // Get organization context if available.
        $orgId = null;
        if (($facetQuery['@self']['organisation'] ?? null) !== null) {
            $orgId = $facetQuery['@self']['organisation'];
        }

        // Create RBAC-aware cache key.
        $cacheData = [
            'facets'  => $facetConfig,
            'filters' => array_diff_key($facetQuery, ['_facets' => true]),
            'user'    => $userId,
            'org'     => $orgId,
            'version' => '2.0',
        // Increment to invalidate when RBAC logic changes.
        ];

        return 'facet_rbac_'.md5(json_encode($cacheData));
    }//end generateFacetCacheKey()

    /**
     * Get cached facet response.
     *
     * @param string $cacheKey Cache key to lookup.
     *
     * @return array|null Cached response or null if not found.
     */
    private function getCachedFacetResponse(string $cacheKey): ?array
    {
        if ($this->facetCache === null) {
            return null;
        }

        try {
            $cached = $this->facetCache->get($cacheKey);
            if ($cached !== null) {
                $this->logger->debug(message: 'Facet response cache hit', context: ['cacheKey' => $cacheKey]);
                // Add cache metadata.
                $cached['performance_metadata']['cache_hit'] = true;
                return $cached;
            }
        } catch (\Exception $e) {
            // Cache get failed, continue without cache.
        }

        return null;
    }//end getCachedFacetResponse()

    /**
     * Cache facet response for future requests.
     *
     * @param string $cacheKey Cache key.
     * @param array  $result   Facet result to cache.
     *
     * @return void
     */
    private function cacheFacetResponse(string $cacheKey, array $result): void
    {
        if ($this->facetCache === null) {
            return;
        }

        try {
            // Use different TTL based on strategy.
            $fallbackUsed = $result['performance_metadata']['fallback_used'] ?? false;
            $ttl          = self::FACET_CACHE_TTL;
            if ($fallbackUsed === true) {
                $ttl = self::COLLECTION_FACET_TTL;
            }

            $this->facetCache->set($cacheKey, $result, $ttl);

            $this->logger->debug(
                message: 'Facet response cached',
                context: [
                    'cacheKey' => $cacheKey,
                    'ttl'      => $ttl,
                    'strategy' => $result['performance_metadata']['strategy'] ?? 'unknown',
                ]
            );
        } catch (\Exception $e) {
            // Cache set failed, continue without caching.
        }//end try
    }//end cacheFacetResponse()

    /**
     * Count total results across all facet buckets.
     *
     * @param array $facets Facet data structure.
     *
     * @return int Total number of facet results.
     */
    private function countFacetResults(array $facets): int
    {
        $total = 0;

        foreach ($facets as $facetGroup) {
            if (is_array($facetGroup) === true) {
                foreach ($facetGroup as $facet) {
                    if (($facet['buckets'] ?? null) !== null && is_array($facet['buckets']) === true) {
                        foreach ($facet['buckets'] as $bucket) {
                            $total += (int) ($bucket['results'] ?? 0);
                        }
                    }
                }
            }
        }

        return $total;
    }//end countFacetResults()

    /**
     * Check if query has restrictive filters that might eliminate all results.
     *
     * @param array $query Query parameters.
     *
     * @return bool True if query has restrictive filters.
     */
    private function hasRestrictiveFilters(array $query): bool
    {
        // Check for search terms.
        if (empty($query['_search']) === false) {
            return true;
        }

        // Check for object field filters (anything not starting with _ or @self).
        foreach ($query as $key => $value) {
            if (str_starts_with($key, '_') === false && $key !== '@self' && empty($value) === false) {
                return true;
            }
        }

        return false;
    }//end hasRestrictiveFilters()

    /**
     * Get schemas relevant to the current query (cached for performance).
     *
     * @param array $baseQuery Base query with register/schema filters.
     *
     * @return Schema[] Array of Schema objects.
     *
     * @psalm-return array<Schema>
     */
    private function getSchemasForQuery(array $baseQuery): array
    {
        // Check if specific schemas are filtered in the query.
        $schemaFilter = $baseQuery['@self']['schema'] ?? null;

        if ($schemaFilter !== null) {
            // Get specific schemas.
            if (is_array($schemaFilter) === true) {
                return $this->schemaMapper->findMultiple($schemaFilter);
            }

            try {
                return [$this->schemaMapper->find($schemaFilter)];
            } catch (\Exception $e) {
                return [];
            }
        }

        // No specific schema filter - get all schemas for collection-wide facetable discovery.
        // Null = no limit (get all).
        return $this->schemaMapper->findAll(limit: null);
    }//end getSchemasForQuery()

    /**
     * Get facetable fields from schema configurations.
     *
     * **PERFORMANCE OPTIMIZED**: Uses pre-computed schema facets instead of runtime analysis.
     *
     * @param array $schemas Array of Schema objects.
     *
     * @return array[] Facetable field configuration.
     *
     * @psalm-return array{'@self': array, object_fields: array}
     */
    private function getFacetableFieldsFromSchemas(array $schemas): array
    {
        $facetableFields = [
            '@self'         => $this->getDefaultMetadataFacets(),
            'object_fields' => [],
        ];

        foreach ($schemas as $schema) {
            // **TYPE SAFETY**: Ensure we have a Schema object.
            if (($schema instanceof Schema) === false) {
                continue;
            }

            try {
                // RUNTIME COMPUTATION: Get facetable fields from schema properties.
                // This is the single source of truth - no pre-computed facets needed.
                $properties = $schema->getProperties() ?? [];
                foreach ($properties as $propertyKey => $property) {
                    // Check if property is marked as facetable.
                    if (isset($property['facetable']) === true && $property['facetable'] === true) {
                        // Determine facet type based on property type.
                        $facetType = $this->determineFacetTypeFromProperty($property);
                        $facetableFields['object_fields'][$propertyKey] = [
                            'type'  => $facetType,
                            'title' => $property['title'] ?? null,
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Get schema ID if method exists, otherwise use 'unknown'.
                $schemaId = 'unknown';
                if (method_exists($schema, 'getId') === true) {
                    $schemaId = $schema->getId();
                }

                $this->logger->error(
                    message: 'Failed to get facetable fields from schema properties',
                    context: [
                        'error'    => $e->getMessage(),
                        'schemaId' => $schemaId,
                    ]
                );
                continue;
            }//end try
        }//end foreach

        return $facetableFields;
    }//end getFacetableFieldsFromSchemas()

    /**
     * Get default metadata facets for @self fields.
     *
     * @return array Default metadata facet configuration.
     */
    private function getDefaultMetadataFacets(): array
    {
        return [
            'register' => ['type' => 'terms'],
            'schema'   => ['type' => 'terms'],
            'created'  => ['type' => 'date_histogram', 'interval' => 'month'],
            'updated'  => ['type' => 'date_histogram', 'interval' => 'month'],
            'owner'    => ['type' => 'terms'],
        ];
    }//end getDefaultMetadataFacets()

    /**
     * Determine facet type from property configuration.
     *
     * @param array $property The property configuration.
     *
     * @return string The facet type ('terms' or 'date_histogram').
     */
    private function determineFacetTypeFromProperty(array $property): string
    {
        $type   = $property['type'] ?? 'string';
        $format = $property['format'] ?? null;

        // Date/datetime fields use date_histogram.
        if ($format === 'date' || $format === 'date-time' || $type === 'date') {
            return 'date_histogram';
        }

        // All other types use terms aggregation.
        return 'terms';
    }//end determineFacetTypeFromProperty()
}//end class
