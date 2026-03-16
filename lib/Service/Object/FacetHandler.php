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
use OCA\OpenRegister\Db\MagicMapper;
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
     * @param MagicMapper $unifiedObjectMapper Unified object mapper with storage routing.
     * @param SchemaMapper        $schemaMapper        Schema database mapper.
     * @param ICacheFactory       $cacheFactory        Cache factory for distributed caching.
     * @param IUserSession        $userSession         User session for tenant isolation.
     * @param LoggerInterface     $logger              Logger for debugging and monitoring.
     *
     * @return void
     */
    public function __construct(
        private readonly MagicMapper $unifiedObjectMapper,
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
                $this->logger->warning(
                    message: '[FacetHandler] Facet caching unavailable',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
                );
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

        // Handle _facets as string (e.g., _facets=extend or _facets=field1,field2).
        if (is_string($facetConfig) === true) {
            if ($facetConfig === 'extend') {
                // Expand "extend" to all facetable fields from the schema.
                $schemas     = $this->getSchemasForQuery(baseQuery: $query);
                $facetConfig = $this->expandExtendToFacetConfig(schemas: $schemas);
            } else {
                // Treat as comma-separated field names.
                $fields      = array_map('trim', explode(',', $facetConfig));
                $facetConfig = [];
                foreach ($fields as $field) {
                    $facetConfig[$field] = ['type' => 'terms'];
                }
            }
        }

        // Handle _facets as numerically-indexed array (e.g., _facets[]=standaardversies).
        // PHP converts ?_facets[]=foo&_facets[]=bar into [0 => 'foo', 1 => 'bar'].
        // Ensure it stays a simple list of field names.
        if (is_array($facetConfig) === true && array_is_list($facetConfig) === true) {
            $facetConfig = array_values(array: $facetConfig);
        }

        // Write expanded facet config back to query so downstream methods receive it.
        $query['_facets'] = $facetConfig;

        // **PAGINATION INDEPENDENCE**: Remove pagination params for facet calculation.
        $facetQuery = $query;
        unset($facetQuery['_limit'], $facetQuery['_offset'], $facetQuery['_page'], $facetQuery['_facetable']);

        // **RESPONSE CACHING**: Check cache first for identical requests.
        $cacheKey = $this->generateFacetCacheKey(facetQuery: $facetQuery, facetConfig: $facetConfig);
        $cached   = $this->getCachedFacetResponse(cacheKey: $cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Discover non-aggregated facet fields from schema configurations.
        $schemas         = $this->getSchemasForQuery(baseQuery: $facetQuery);
        $facetableConfig = $this->getFacetableFieldsFromSchemas(schemas: $schemas);

        // **INTELLIGENT FACETING**: Try current filters first, then smart fallback.
            $result = $this->calculateFacetsWithFallback(
                facetQuery: $facetQuery,
                facetConfig: $facetConfig,
                facetableConfig: $facetableConfig
            );

        // **PERFORMANCE TRACKING**: Add timing metadata.
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);
        $result['performance_metadata']['total_execution_time_ms'] = $executionTime;

        // **CACHE RESULTS**: Store for future requests.
            $this->cacheFacetResponse(cacheKey: $cacheKey, result: $result);

        $this->logger->debug(
            message: '[FacetHandler] FacetHandler completed facet calculation',
            context: [
                'file'              => __FILE__,
                'line'              => __LINE__,
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
        $schemas = $this->getSchemasForQuery(baseQuery: $baseQuery);

        // **PERFORMANCE OPTIMIZATION**: Use pre-computed schema facets.
        $facetableFields = $this->getFacetableFieldsFromSchemas(schemas: $schemas);

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        $selfCount   = count($facetableFields['@self'] ?? []);
        $objectCount = count($facetableFields['object_fields'] ?? []);
        $this->logger->debug(
            message: '[FacetHandler] Facetable fields discovery completed',
            context: [
                'file'                => __FILE__,
                'line'                => __LINE__,
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
     * Implements smart fallback logic that ensures users always see meaningful facet
     * options, even when their current search/filters return zero results.
     * Also handles non-aggregated facets by making separate schema-scoped queries.
     *
     * @param array $facetQuery      Query for facet calculation (without pagination).
     * @param array $facetConfig     Facet configuration.
     * @param array $facetableConfig Facetable field configuration from schema discovery.
     *
     * @return array Facets with performance metadata including strategy and fallback status.
     */
    private function calculateFacetsWithFallback(array $facetQuery, array $facetConfig, array $facetableConfig=[]): array
    {
        // **STAGE 1**: Try facets with current filters.
        $facets = $this->unifiedObjectMapper->getSimpleFacets($facetQuery);

        // **STAGE 2**: Check if we got meaningful facets.
        $totalFacetResults = $this->countFacetResults(facets: $facets);
        $hasRestrictFilter = $this->hasRestrictiveFilters(query: $facetQuery);

        $strategy     = 'filtered';
        $fallbackUsed = false;

        // NOTE: The "intelligent fallback" that stripped object field filters and returned
        // collection-wide facet counts has been removed. It caused #453: after selecting a
        // Type filter, other facets showed full-dataset counts instead of scoped counts.
        // Showing empty/scoped facets is correct; showing unscoped counts is misleading.
        if ($totalFacetResults === 0 && $hasRestrictFilter === true) {
            $this->logger->debug(
                message: '[FacetHandler] Facets empty with restrictive filters — returning empty facets (no fallback)',
                context: [
                    'file'          => __FILE__,
                    'line'          => __LINE__,
                    'originalQuery' => array_keys($facetQuery),
                    'totalResults'  => $totalFacetResults,
                ]
            );
        }//end if

        // **NON-AGGREGATED CLEANUP**: Remove fields from aggregated results that are ONLY configured
        // as non-aggregated (not present in object_fields). getSimpleFacets returns all fields from the
        // magic table, but fields that are exclusively non-aggregated should not appear as aggregated facets.
        $nonAggregatedFields = $facetableConfig['non_aggregated_fields'] ?? [];
        $aggregatedFieldKeys = array_keys($facetableConfig['object_fields'] ?? []);
        foreach ($nonAggregatedFields as $naField) {
            $fieldName = $naField['field'];
            if (in_array($fieldName, $aggregatedFieldKeys, true) === false && isset($facets[$fieldName]) === true) {
                unset($facets[$fieldName]);
            }
        }

        // **NON-AGGREGATED FACETS**: Make separate schema-scoped queries for non-aggregated fields.
        foreach ($nonAggregatedFields as $naField) {
            $fieldName = $naField['field'];
            $schemaId  = $naField['schemaId'];
            $config    = $naField['facetConfig'];

            // Build a schema-scoped query to get facets for this field only.
            // IMPORTANT: Remove plural schema/register keys so getSimpleFacets() routes
            // to the single-schema path instead of the multi-schema UNION path.
            $scopedQuery = $facetQuery;
            $scopedQuery['@self']['schema'] = $schemaId;
            unset($scopedQuery['@self']['schemas'], $scopedQuery['_schemas']);

            try {
                $scopedFacets = $this->unifiedObjectMapper->getSimpleFacets($scopedQuery);

                // Remove metrics from scoped results.
                unset($scopedFacets['_metrics']);

                if (isset($scopedFacets[$fieldName]) === true) {
                    // Generate a unique key for this non-aggregated facet.
                    $uniqueKey = $this->generateNonAggregatedFacetKey(
                        fieldName: $fieldName,
                        schemaId: $schemaId,
                        facetConfig: $config
                    );

                    // Store the scoped facet data with metadata for the transform step.
                    $facets[$uniqueKey] = $scopedFacets[$fieldName];
                    $facets[$uniqueKey]['_nonAggregated'] = true;
                    $facets[$uniqueKey]['_schemaId']      = $schemaId;
                    $facets[$uniqueKey]['_facetConfig']   = $config;
                    $facets[$uniqueKey]['_fieldName']     = $fieldName;
                }
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[FacetHandler] Failed to get non-aggregated facet',
                    context: [
                        'file'     => __FILE__,
                        'line'     => __LINE__,
                        'field'    => $fieldName,
                        'schemaId' => $schemaId,
                        'error'    => $e->getMessage(),
                    ]
                );
            }//end try
        }//end foreach

        // Extract per-facet metrics before transformation (if available).
        $perFacetMetrics = $facets['_metrics'] ?? null;
        unset($facets['_metrics']);

        // **OUTPUT FORMAT**: Transform facets to standardized format matching external API.
        $transformedFacets = $this->transformFacetsToStandardFormat(
            facets: $facets,
            facetableConfig: $facetableConfig
        );

        $performanceMetadata = [
            'strategy'                => $strategy,
            'fallback_used'           => $fallbackUsed,
            'total_facet_results'     => $this->countFacetResults(facets: $facets),
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
     * Generate a unique key for a non-aggregated facet entry.
     *
     * @param string $fieldName   The property/field name.
     * @param int    $schemaId    The schema ID.
     * @param array  $facetConfig The facet configuration.
     *
     * @return string A unique facet key.
     */
    private function generateNonAggregatedFacetKey(string $fieldName, int $schemaId, array $facetConfig): string
    {
        // Use sanitized title if available, otherwise use field_schema pattern.
        if (empty($facetConfig['title']) === false) {
            $key = preg_replace('/([a-z])([A-Z])/', '$1_$2', $facetConfig['title']);
            $key = strtolower(str_replace(' ', '_', $key));
            $key = preg_replace('/[^a-z0-9_]/', '', $key);
            return $key;
        }

        return $fieldName.'_schema_'.$schemaId;
    }//end generateNonAggregatedFacetKey()

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
     * @param array $facets          Raw facets from mapper.
     * @param array $facetableConfig Facetable field config from schema discovery.
     *
     * @return array Transformed facets in standardized format.
     */
    private function transformFacetsToStandardFormat(array $facets, array $facetableConfig=[]): array
    {
        $transformed = [];
        $order       = 0;

        // Build a lookup of aggregated field configs for custom title/description/order.
        $aggregatedConfigs = [];
        foreach ($facetableConfig['object_fields'] ?? [] as $fieldKey => $fieldInfo) {
            if (isset($fieldInfo['facetConfig']) === true) {
                $aggregatedConfigs[$fieldKey] = $fieldInfo['facetConfig'];
            }
        }

        // Process @self metadata facets.
        if (isset($facets['@self']) === true && is_array($facets['@self']) === true) {
            $order = $this->transformMetadataFacets(
                metadataFacets: $facets['@self'],
                transformed: $transformed,
                startOrder: $order
            );
        }//end if

        // Process object field facets (non-@self).
        foreach ($facets as $field => $facetData) {
            if ($field === '@self') {
                continue;
            }

            // Check if this is a non-aggregated facet (added by calculateFacetsWithFallback).
            $isNonAggregated = $facetData['_nonAggregated'] ?? false;

            if ($isNonAggregated === true) {
                $order = $this->transformNonAggregatedFacet(
                    field: $field,
                    facetData: $facetData,
                    transformed: $transformed,
                    currentOrder: $order
                );
            } else {
                $order = $this->transformAggregatedFacet(
                    field: $field,
                    facetData: $facetData,
                    aggregatedConfigs: $aggregatedConfigs,
                    transformed: $transformed,
                    currentOrder: $order
                );
            }//end if
        }//end foreach

        return $transformed;
    }//end transformFacetsToStandardFormat()

    /**
     * Get the metadata facet definitions for @self fields.
     *
     * @return array Keyed by field name, each value contains title,
     *               description, data_type, index_field, index_type, enabled.
     */
    private function getMetadataDefinitions(): array
    {
        return [
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
    }//end getMetadataDefinitions()

    /**
     * Transform @self metadata facets into the standard format.
     *
     * @param array $metadataFacets The @self facet data keyed by field name.
     * @param array $transformed    Reference to the transformed output array.
     * @param int   $startOrder     The current order counter.
     *
     * @return int The updated order counter after processing metadata facets.
     */
    private function transformMetadataFacets(array $metadataFacets, array &$transformed, int $startOrder): int
    {
        $order = $startOrder;
        $metadataDefinitions = $this->getMetadataDefinitions();

        foreach ($metadataFacets as $field => $facetData) {
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

        return $order;
    }//end transformMetadataFacets()

    /**
     * Transform a non-aggregated object field facet into the standard format.
     *
     * Non-aggregated facets use config for title/description/order and include a schema ID.
     *
     * @param string $field        The facet field key.
     * @param array  $facetData    The raw facet data (may contain internal metadata keys).
     * @param array  $transformed  Reference to the transformed output array.
     * @param int    $currentOrder The current order counter.
     *
     * @return int The updated order counter.
     */
    private function transformNonAggregatedFacet(
        string $field,
        array $facetData,
        array &$transformed,
        int $currentOrder
    ): int {
        $order = $currentOrder;

        $naConfig    = $facetData['_facetConfig'] ?? [];
        $naSchemaId  = $facetData['_schemaId'] ?? null;
        $naFieldName = $facetData['_fieldName'] ?? $field;

        // Clean internal metadata from facet data before processing.
        unset(
            $facetData['_nonAggregated'],
            $facetData['_schemaId'],
            $facetData['_facetConfig'],
            $facetData['_fieldName']
        );

        $configOrder = $naConfig['order'] ?? null;
        if ($configOrder !== null) {
            $facetOrder = (int) $configOrder;
        } else {
            $facetOrder = ++$order;
        }

        if ($configOrder === null) {
            $order = $facetOrder;
        }

        $title       = $naConfig['title'] ?? $facetData['title'] ?? $this->formatFieldTitle(field: $naFieldName);
        $description = $naConfig['description'] ?? 'object field: '.$naFieldName;

        $definition = [
            'title'       => $title,
            'description' => $description,
            'data_type'   => $this->inferDataType(facetData: $facetData),
            'index_field' => $this->sanitizeFieldName(field: $naFieldName),
            'index_type'  => 'string',
            'enabled'     => true,
        ];

        $transformed[$field] = $this->buildFacetEntry(
            name: $naFieldName,
            facetData: $facetData,
            definition: $definition,
            source: 'object',
            queryParameter: $naFieldName,
            order: $facetOrder,
            schemaId: $naSchemaId
        );

        return $order;
    }//end transformNonAggregatedFacet()

    /**
     * Transform an aggregated object field facet into the standard format.
     *
     * Aggregated facets use config overrides from the facetable configuration if available.
     *
     * @param string $field             The facet field key.
     * @param array  $facetData         The raw facet data.
     * @param array  $aggregatedConfigs Lookup of aggregated field configs keyed by field name.
     * @param array  $transformed       Reference to the transformed output array.
     * @param int    $currentOrder      The current order counter.
     *
     * @return int The updated order counter.
     */
    private function transformAggregatedFacet(
        string $field,
        array $facetData,
        array $aggregatedConfigs,
        array &$transformed,
        int $currentOrder
    ): int {
        $order       = $currentOrder;
        $fieldConfig = $aggregatedConfigs[$field] ?? null;

        if ($fieldConfig !== null) {
            $configOrder = $fieldConfig['order'] ?? null;
        } else {
            $configOrder = null;
        }

        if ($configOrder !== null) {
            $facetOrder = (int) $configOrder;
        } else {
            $facetOrder = ++$order;
        }//end if

        if ($configOrder === null) {
            $order = $facetOrder;
        }

        // Use config title/description if available, then fall back to facet data or auto-generated.
        if ($fieldConfig !== null && ($fieldConfig['title'] ?? null) !== null) {
            $title = $fieldConfig['title'];
        } else {
            $title = $facetData['title'] ?? $this->formatFieldTitle(field: $field);
        }

        if ($fieldConfig !== null && ($fieldConfig['description'] ?? null) !== null) {
            $description = $fieldConfig['description'];
        } else {
            $description = 'object field: '.$field;
        }

        $definition = [
            'title'       => $title,
            'description' => $description,
            'data_type'   => $this->inferDataType(facetData: $facetData),
            'index_field' => $this->sanitizeFieldName(field: $field),
            'index_type'  => 'string',
            'enabled'     => true,
        ];

        $transformed[$field] = $this->buildFacetEntry(
            name: $field,
            facetData: $facetData,
            definition: $definition,
            source: 'object',
            queryParameter: $field,
            order: $facetOrder
        );

        return $order;
    }//end transformAggregatedFacet()

    /**
     * Build a single facet entry in the standardized format.
     *
     * @param string   $name           The facet name.
     * @param array    $facetData      The raw facet data with type and buckets.
     * @param array    $definition     The facet definition (title, description, etc.).
     * @param string   $source         The source type (metadata or object).
     * @param string   $queryParameter The query parameter for filtering.
     * @param int      $order          The display order.
     * @param int|null $schemaId       Optional schema ID for non-aggregated facets.
     *
     * @return array The formatted facet entry.
     */
    private function buildFacetEntry(
        string $name,
        array $facetData,
        array $definition,
        string $source,
        string $queryParameter,
        int $order,
        ?int $schemaId=null
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

        $entry = [
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

        // Add schema ID for non-aggregated facets so the frontend can scope queries.
        if ($schemaId !== null) {
            $entry['schema'] = $schemaId;
        }

        return $entry;
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
                $this->logger->debug(
                    message: '[FacetHandler] Facet response cache hit',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'cacheKey' => $cacheKey]
                );
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
                message: '[FacetHandler] Facet response cached',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
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
        // Check for plural _schemas first (multi-schema publications queries).
        // Disable RBAC/multitenancy since schemas are system-level entities needed for facet config.
        $schemasPlural = $baseQuery['@self']['schemas'] ?? $baseQuery['_schemas'] ?? null;
        if ($schemasPlural !== null && is_array($schemasPlural) === true && count($schemasPlural) > 0) {
            return $this->schemaMapper->findMultiple(
                ids: array_map('intval', $schemasPlural),
                _rbac: false,
                _multitenancy: false
            );
        }

        // Check if specific schema (singular) is filtered in the query.
        $schemaFilter = $baseQuery['@self']['schema'] ?? null;

        if ($schemaFilter !== null) {
            // Get specific schemas (bypass RBAC — schemas are system-level).
            if (is_array($schemaFilter) === true) {
                return $this->schemaMapper->findMultiple(
                    ids: $schemaFilter,
                    _rbac: false,
                    _multitenancy: false
                );
            }

            try {
                return [$this->schemaMapper->find(id: $schemaFilter, _multitenancy: false, _rbac: false)];
            } catch (\Exception $e) {
                return [];
            }
        }

        // No specific schema filter - get all schemas for collection-wide facetable discovery.
        // Null = no limit (get all). Bypass RBAC since schemas are system-level.
        return $this->schemaMapper->findAll(limit: null, _rbac: false, _multitenancy: false);
    }//end getSchemasForQuery()

    /**
     * Normalize a facetable property value to a standard config array.
     *
     * Handles both boolean (`true`) and config object formats.
     * Returns `null` if the property is not facetable.
     *
     * @param mixed $facetable The facetable value from a schema property.
     *
     * @return array|null Normalized config or null if not facetable.
     */
    private function normalizeFacetConfig(mixed $facetable): ?array
    {
        if ($facetable === false || $facetable === null) {
            return null;
        }

        if ($facetable === true) {
            return [
                'aggregated'  => true,
                'title'       => null,
                'description' => null,
                'order'       => null,
            ];
        }

        if (is_array($facetable) === true && empty($facetable) === false) {
            return [
                'aggregated'  => $facetable['aggregated'] ?? true,
                'title'       => $facetable['title'] ?? null,
                'description' => $facetable['description'] ?? null,
                'order'       => $facetable['order'] ?? null,
            ];
        }

        return null;
    }//end normalizeFacetConfig()

    /**
     * Get facetable fields from schema configurations.
     *
     * Discovers facetable fields from schema properties, supporting both
     * `facetable: true` (boolean) and `facetable: { aggregated, title, description, order }` (config object).
     * Non-aggregated fields are tracked separately with their schema context.
     *
     * @param array $schemas Array of Schema objects.
     *
     * @return array[] Facetable field configuration with non-aggregated field metadata.
     *
     * @psalm-return array{'@self': array, object_fields: array, non_aggregated_fields: array}
     */
    /**
     * Expand the "extend" facet shorthand to a full facet config array.
     *
     * Reads all schemas and returns an associative facet config for every
     * property that has facetable=true set.
     *
     * @param Schema[] $schemas Schemas to inspect.
     *
     * @return array Associative facet config, e.g. ['confidentiality' => ['type' => 'terms'], ...].
     */
    private function expandExtendToFacetConfig(array $schemas): array
    {
        $config = [];

        foreach ($schemas as $schema) {
            if (($schema instanceof Schema) === false) {
                continue;
            }

            $properties = $schema->getProperties() ?? [];
            foreach ($properties as $propertyKey => $property) {
                $facetable = $property['facetable'] ?? false;
                if ($facetable === true || (is_array($facetable) === true && empty($facetable) === false)) {
                    $facetType              = $this->determineFacetTypeFromProperty(property: $property);
                    $config[$propertyKey] = ['type' => $facetType];
                }
            }
        }

        return $config;
    }//end expandExtendToFacetConfig()

    private function getFacetableFieldsFromSchemas(array $schemas): array
    {
        $facetableFields = [
            '@self'                 => $this->getDefaultMetadataFacets(),
            'object_fields'         => [],
            'non_aggregated_fields' => [],
        ];

        foreach ($schemas as $schema) {
            // **TYPE SAFETY**: Ensure we have a Schema object.
            if (($schema instanceof Schema) === false) {
                continue;
            }

            try {
                $schemaId   = $schema->getId();
                $properties = $schema->getProperties() ?? [];
                foreach ($properties as $propertyKey => $property) {
                    $facetConfig = $this->normalizeFacetConfig(facetable: $property['facetable'] ?? false);
                    if ($facetConfig === null) {
                        continue;
                    }

                    // Determine facet type based on property type.
                    $facetType = $this->determineFacetTypeFromProperty(property: $property);

                    if ($facetConfig['aggregated'] === false) {
                        // Track non-aggregated fields separately with schema context.
                        $facetableFields['non_aggregated_fields'][] = [
                            'field'       => $propertyKey,
                            'schemaId'    => $schemaId,
                            'facetType'   => $facetType,
                            'facetConfig' => $facetConfig,
                            'title'       => $property['title'] ?? null,
                        ];
                    } else {
                        // Aggregated fields: merge across schemas (existing behavior).
                        $facetableFields['object_fields'][$propertyKey] = [
                            'type'        => $facetType,
                            'title'       => $property['title'] ?? null,
                            'facetConfig' => $facetConfig,
                        ];
                    }
                }//end foreach
            } catch (\Exception $e) {
                $schemaId = 'unknown';
                if (method_exists($schema, 'getId') === true) {
                    $schemaId = $schema->getId();
                }

                $this->logger->error(
                    message: '[FacetHandler] Failed to get facetable fields from schema properties',
                    context: [
                        'file'     => __FILE__,
                        'line'     => __LINE__,
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
