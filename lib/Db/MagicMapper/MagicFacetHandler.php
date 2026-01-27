<?php

/**
 * MagicMapper Facet Handler
 *
 * This handler provides advanced faceting and aggregation capabilities for dynamic
 * schema-based tables. It implements sophisticated faceting functionality including
 * terms facets, date histograms, range facets, and statistical aggregations
 * optimized for schema-specific table structures.
 *
 * KEY RESPONSIBILITIES:
 * - Terms faceting for categorical data in dynamic tables
 * - Date histogram faceting for temporal data analysis
 * - Range faceting for numerical data analysis
 * - Statistical aggregations (min, max, avg, sum, count)
 * - Schema-aware faceting with automatic field discovery
 * - Optimized facet queries for performance
 *
 * FACETING CAPABILITIES:
 * - Metadata facets (register, schema, owner, organization, etc.)
 * - Schema property facets based on JSON schema definitions
 * - Combined faceting with complex filtering
 * - Cardinality estimation for facet optimization
 * - Multi-level aggregations and drill-down support
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Initial implementation for MagicMapper faceting capabilities
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use DateTime;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Faceting and aggregation handler for MagicMapper dynamic tables
 *
 * This class provides comprehensive faceting functionality for dynamically created
 * schema-based tables, offering better performance than generic table faceting
 * due to schema-specific optimizations.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class MagicFacetHandler
{
    /**
     * Maximum number of buckets to return per facet.
     * Set to high value to effectively disable limit (was 50).
     */
    private const MAX_FACET_BUCKETS = 10000;

    /**
     * Metadata column prefix used in MagicMapper tables.
     */
    private const METADATA_PREFIX = '_';

    /**
     * TTL for facet label cache (24 hours).
     * Labels rarely change, so long TTL is appropriate.
     */
    private const FACET_LABEL_CACHE_TTL = 86400;

    /**
     * In-memory cache for facet results within a single request
     */
    private array $facetCache = [];

    /**
     * In-memory cache for UUID to label mappings (batch-resolved)
     */
    private array $uuidLabelCache = [];

    /**
     * In-memory cache for field-level label maps (persistent across searches).
     * Structure: ['tableName:fieldName' => ['uuid1' => 'label1', ...]]
     */
    private array $fieldLabelCache = [];

    /**
     * Tracks which fields have been warmed in the distributed cache.
     */
    private array $warmedFields = [];

    /**
     * Cache statistics for performance debugging.
     * Tracks hits/misses for facet label resolution.
     */
    private array $cacheStats = [
        'field_cache_hits' => 0,
        'distributed_cache_hits' => 0,
        'cache_handler_calls' => 0,
        'total_uuids_resolved' => 0,
    ];

    /**
     * In-memory cache for column existence checks.
     * Structure: ['tableName' => ['column1' => true, 'column2' => true, ...]]
     * This avoids repeated information_schema queries which add up quickly.
     */
    private array $columnCache = [];

    /**
     * Cache handler for UUID to name resolution
     *
     * @var \OCA\OpenRegister\Service\Object\CacheHandler|null
     */
    private ?\OCA\OpenRegister\Service\Object\CacheHandler $cacheHandler = null;

    /**
     * Distributed cache factory for persistent label caching
     *
     * @var ICacheFactory|null
     */
    private ?ICacheFactory $cacheFactory = null;

    /**
     * Distributed cache instance for facet labels
     *
     * @var ICache|null
     */
    private ?ICache $distributedLabelCache = null;

    /**
     * Search handler for building filtered queries (single source of truth for filters).
     *
     * @var MagicSearchHandler|null
     */
    private ?MagicSearchHandler $searchHandler = null;

    /**
     * Constructor for MagicFacetHandler
     *
     * @param IDBConnection   $db           Database connection for queries
     * @param LoggerInterface $logger       Logger for debugging and error reporting
     * @param \OCA\OpenRegister\Service\Object\CacheHandler|null $cacheHandler Cache handler for name resolution
     * @param ICacheFactory|null $cacheFactory Cache factory for distributed caching
     * @param MagicSearchHandler|null $searchHandler Search handler for shared query building
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
        ?\OCA\OpenRegister\Service\Object\CacheHandler $cacheHandler = null,
        ?ICacheFactory $cacheFactory = null,
        ?MagicSearchHandler $searchHandler = null
    ) {
        $this->cacheHandler = $cacheHandler;
        $this->cacheFactory = $cacheFactory;
        $this->searchHandler = $searchHandler;

        // Initialize distributed cache for facet labels.
        if ($this->cacheFactory !== null) {
            try {
                $this->distributedLabelCache = $this->cacheFactory->createDistributed('openregister_facet_labels');
            } catch (\Exception $e) {
                $this->logger->warning('Failed to create distributed facet label cache: ' . $e->getMessage());
            }
        }
    }//end __construct()

    /**
     * Get simple facets for a magic mapper table.
     *
     * This method provides faceting capabilities for dynamically created
     * schema-based tables, similar to the blob storage faceting but optimized
     * for column-based storage.
     *
     * @param string   $tableName The magic mapper table name (without oc_ prefix).
     * @param array    $query     The search query array containing filters and facet configuration.
     * @param Register $register  The register context.
     * @param Schema   $schema    The schema context.
     *
     * @return array Facet results with buckets.
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     */
    public function getSimpleFacets(
        string $tableName,
        array $query,
        Register $register,
        Schema $schema
    ): array {
        $startTime = microtime(true);
        
        // Extract facet configuration.
        $facetConfig = $query['_facets'] ?? [];
        if (empty($facetConfig) === true) {
            return [];
        }

        // Handle _facets as string (e.g., _facets=extend) by converting to array.
        if (is_string($facetConfig) === true) {
            $facetConfig = $this->expandFacetConfig(facetConfig: $facetConfig, schema: $schema);
        }

        // Extract base query (without facet config).
        $baseQuery = $query;
        unset($baseQuery['_facets']);

        $facets = [];
        $facetTimes = []; // Track time per facet for optimization

        // Process metadata facets (@self).
        if (($facetConfig['@self'] ?? null) !== null && is_array($facetConfig['@self']) === true) {
            $facets['@self'] = [];
            foreach ($facetConfig['@self'] as $field => $config) {
                $facetStart = microtime(true);
                $type = $config['type'] ?? 'terms';

                if ($type === 'terms') {
                    $facets['@self'][$field] = $this->getTermsFacet(
                        tableName: $tableName,
                        field: self::METADATA_PREFIX.$field,
                        baseQuery: $baseQuery,
                        isMetadata: true,
                        register: $register,
                        schema: $schema
                    );
                } else if ($type === 'date_histogram') {
                    $interval = $config['interval'] ?? 'month';
                    $facets['@self'][$field] = $this->getDateHistogramFacet(
                        tableName: $tableName,
                        field: self::METADATA_PREFIX.$field,
                        interval: $interval,
                        baseQuery: $baseQuery,
                        schema: $schema
                    );
                }
                
                $facetTimes['@self.'.$field] = round((microtime(true) - $facetStart) * 1000, 2);
            }//end foreach
        }//end if

        // Process object field facets (schema properties).
        $objectFacetConfig = array_filter(
            $facetConfig,
            function ($key) {
                return $key !== '@self';
            },
            ARRAY_FILTER_USE_KEY
        );

        foreach ($objectFacetConfig as $field => $config) {
            $facetStart = microtime(true);
            $type = $config['type'] ?? 'terms';
            // Sanitize field name to match database column (camelCase -> snake_case).
            $columnName = $this->sanitizeColumnName($field);

            if ($type === 'terms') {
                $facets[$field] = $this->getTermsFacet(
                    tableName: $tableName,
                    field: $columnName,
                    baseQuery: $baseQuery,
                    isMetadata: false,
                    register: $register,
                    schema: $schema
                );
            } else if ($type === 'date_histogram') {
                $interval       = $config['interval'] ?? 'month';
                $facets[$field] = $this->getDateHistogramFacet(
                    tableName: $tableName,
                    field: $columnName,
                    interval: $interval,
                    baseQuery: $baseQuery,
                    schema: $schema
                );
            }

            // Add schema property title if available.
            if (isset($config['title']) === true && $config['title'] !== null) {
                $facets[$field]['title'] = $config['title'];
            }

            $facetTimes[$field] = round((microtime(true) - $facetStart) * 1000, 2);
        }//end foreach

        $totalTime = round((microtime(true) - $startTime) * 1000, 2);

        // Add timing metadata to facets for performance debugging.
        $facets['_metrics'] = [
            'total_ms' => $totalTime,
            'per_facet_ms' => $facetTimes,
            'label_cache' => $this->cacheStats,
        ];

        return $facets;
    }//end getSimpleFacets()

    /**
     * Get facets using UNION ALL across multiple tables for better performance.
     *
     * This method executes ONE query per facet field using UNION ALL to combine
     * results from multiple tables, instead of running separate queries per table
     * sequentially. Benchmarks show 2-2.5x speedup for large datasets.
     *
     * @param array $tableConfigs Array of ['tableName' => string, 'register' => Register, 'schema' => Schema].
     * @param array $query        The search query with filters and facet config.
     *
     * @return array Merged facet results across all tables.
     */
    public function getSimpleFacetsUnion(array $tableConfigs, array $query): array
    {
        $startTime = microtime(true);

        if (empty($tableConfigs) === true) {
            return [];
        }

        // Extract facet configuration.
        $facetConfig = $query['_facets'] ?? [];
        if (empty($facetConfig) === true) {
            return [];
        }

        // Handle _facets as string (e.g., _facets=extend).
        // IMPORTANT: Merge facet configs from ALL schemas, not just the first one.
        // Each schema may have different facetable fields, and we want the union of all.
        if (is_string($facetConfig) === true) {
            $facetConfig = $this->expandFacetConfigFromAllSchemas(
                facetConfigString: $facetConfig,
                tableConfigs: $tableConfigs
            );
        }

        // Extract base query (without facet config).
        $baseQuery = $query;
        unset($baseQuery['_facets']);

        $facets = [];
        $facetTimes = [];

        // Get all table names.
        $allTables = array_map(fn($c) => $c['tableName'], $tableConfigs);

        // Process object field facets using UNION.
        $objectFacetConfig = array_filter(
            $facetConfig,
            fn($key) => $key !== '@self',
            ARRAY_FILTER_USE_KEY
        );

        foreach ($objectFacetConfig as $field => $config) {
            $facetStart = microtime(true);
            $type = $config['type'] ?? 'terms';
            $columnName = $this->sanitizeColumnName($field);

            if ($type === 'terms') {
                // Find which tables have this column.
                $tablesWithColumn = [];
                foreach ($tableConfigs as $tc) {
                    if ($this->columnExists(tableName: $tc['tableName'], columnName: $columnName) === true) {
                        $tablesWithColumn[] = $tc;
                    }
                }

                if (empty($tablesWithColumn) === false) {
                    $facets[$field] = $this->getTermsFacetUnion(
                        tableConfigs: $tablesWithColumn,
                        field: $columnName,
                        baseQuery: $baseQuery,
                        schema: $schema
                    );
                } else {
                    $facets[$field] = ['type' => 'terms', 'buckets' => []];
                }
            }

            // Add schema property title if available.
            if (isset($config['title']) === true && $config['title'] !== null) {
                $facets[$field]['title'] = $config['title'];
            }

            $facetTimes[$field] = round((microtime(true) - $facetStart) * 1000, 2);
        }

        // Process @self metadata facets using UNION.
        if (($facetConfig['@self'] ?? null) !== null && is_array($facetConfig['@self']) === true) {
            $facets['@self'] = [];
            foreach ($facetConfig['@self'] as $field => $config) {
                $facetStart = microtime(true);
                $type = $config['type'] ?? 'terms';
                $columnName = self::METADATA_PREFIX . $field;

                if ($type === 'terms') {
                    $facets['@self'][$field] = $this->getTermsFacetUnion(
                        tableConfigs: $tableConfigs,
                        field: $columnName,
                        baseQuery: $baseQuery,
                        schema: $schema,
                        isMetadata: true
                    );
                } else if ($type === 'date_histogram') {
                    // Date histograms still use single-table approach (less common).
                    $interval = $config['interval'] ?? 'month';
                    $facets['@self'][$field] = $this->getDateHistogramFacetUnion(
                        tableConfigs: $tableConfigs,
                        field: $columnName,
                        interval: $interval,
                        baseQuery: $baseQuery
                    );
                }

                $facetTimes['@self.' . $field] = round((microtime(true) - $facetStart) * 1000, 2);
            }
        }

        $totalTime = round((microtime(true) - $startTime) * 1000, 2);

        // Add timing metadata to facets for performance debugging.
        $facets['_metrics'] = [
            'total_ms' => $totalTime,
            'table_count' => count($tableConfigs),
            'per_facet_ms' => $facetTimes,
            'label_cache' => $this->cacheStats,
        ];

        return $facets;
    }//end getSimpleFacetsUnion()

    /**
     * Get terms facet using UNION ALL across multiple tables.
     *
     * This method uses a simple GROUP BY approach and then post-processes
     * array values in PHP. This is more reliable than trying to detect
     * array fields at SQL level and use jsonb_array_elements_text().
     *
     * @param array  $tableConfigs Array of table configurations.
     * @param string $field        The field/column name.
     * @param array  $baseQuery    Base query filters.
     * @param Schema $schema       Schema for type checking.
     * @param bool   $isMetadata   Whether this is a metadata field.
     *
     * @return array Facet result with merged buckets.
     */
    private function getTermsFacetUnion(
        array $tableConfigs,
        string $field,
        array $baseQuery,
        Schema $schema,
        bool $isMetadata = false
    ): array {
        if (empty($tableConfigs) === true) {
            return ['type' => 'terms', 'buckets' => []];
        }

        // Build UNION ALL query with simple GROUP BY.
        // Array values will come as JSON strings like '["uuid1", "uuid2"]'
        // and will be post-processed in PHP.
        $unionParts = [];
        $prefix = 'oc_';

        foreach ($tableConfigs as $tc) {
            $tableName = $tc['tableName'];
            $fullTableName = $prefix . $tableName;
            $tcSchema = $tc['schema'];

            // Simple SELECT with GROUP BY - no jsonb_array_elements_text complexity.
            $subSql = "SELECT {$field} as facet_value, COUNT(*) as cnt FROM {$fullTableName} WHERE {$field} IS NOT NULL";

            // Use shared method for all filter conditions (single source of truth).
            if ($this->searchHandler !== null) {
                $whereConditions = $this->searchHandler->buildWhereConditionsSql(
                    query: $baseQuery,
                    schema: $tcSchema
                );
                foreach ($whereConditions as $condition) {
                    // Skip '1=0' conditions - they mean filter column doesn't exist on this schema.
                    if ($condition === '1=0') {
                        // Skip this table entirely.
                        continue 2;
                    }
                    $subSql .= " AND {$condition}";
                }
            }

            $subSql .= " GROUP BY {$field}";
            $unionParts[] = $subSql;
        }

        if (empty($unionParts) === true) {
            return ['type' => 'terms', 'buckets' => []];
        }

        // Combine with UNION ALL and aggregate.
        $sql = "SELECT facet_value, SUM(cnt) as doc_count FROM (\n"
            . implode("\nUNION ALL\n", $unionParts)
            . "\n) combined GROUP BY facet_value ORDER BY doc_count DESC LIMIT " . self::MAX_FACET_BUCKETS;

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute();

            // Collect raw buckets from database.
            $rawBuckets = [];
            while (($row = $stmt->fetch()) !== false) {
                $rawBuckets[] = [
                    'key' => $row['facet_value'],
                    'count' => (int) $row['doc_count'],
                ];
            }

            // PHP POST-PROCESSING: Normalize array values.
            // This splits JSON array values like '["uuid1", "uuid2"]' into individual values
            // and merges their counts. Much more reliable than SQL-based array detection.
            $normalizedBuckets = $this->normalizeArrayFacetBuckets($rawBuckets);

            // Collect UUIDs for label resolution.
            $uuidsToResolve = [];
            foreach ($normalizedBuckets as $bucket) {
                $key = $bucket['key'];
                if (is_string($key) === true
                    && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key) === 1
                ) {
                    $uuidsToResolve[] = $key;
                }
            }

            // Batch resolve labels AFTER normalization (so we only resolve individual UUIDs).
            $labelMap = [];
            if (empty($uuidsToResolve) === false && $isMetadata === false) {
                $firstConfig = reset($tableConfigs);
                $labelMap = $this->batchResolveUuidLabels(
                    uuids: $uuidsToResolve,
                    field: $field,
                    schema: $firstConfig['schema'],
                    register: $firstConfig['register']
                );
            }

            // Build final buckets with labels.
            $buckets = [];
            foreach ($normalizedBuckets as $bucket) {
                $key = $bucket['key'];
                $label = $labelMap[$key] ?? (string) $key;

                $buckets[] = [
                    'key' => $key,
                    'results' => $bucket['count'],
                    'label' => $label,
                ];
            }

            return ['type' => 'terms', 'buckets' => $buckets];
        } catch (\Exception $e) {
            $this->logger->warning(
                'MagicFacetHandler: UNION facet query failed',
                ['field' => $field, 'error' => $e->getMessage(), 'sql' => $sql]
            );
            return ['type' => 'terms', 'buckets' => []];
        }
    }//end getTermsFacetUnion()

    /**
     * Normalize facet buckets by splitting JSON array values into individual values.
     *
     * This is the PHP-based approach to handling array facets. Instead of complex
     * SQL with jsonb_array_elements_text(), we:
     * 1. Get raw facet values (arrays come as JSON strings like '["uuid1", "uuid2"]')
     * 2. Detect array values by checking if they start with '['
     * 3. Decode arrays and distribute counts to individual values
     * 4. Merge counts for values that appear both individually and in arrays
     *
     * This approach is more reliable because:
     * - No need for complex array field detection at SQL level
     * - Works regardless of how the schema defines the field
     * - Clear, testable PHP logic
     *
     * @param array $rawBuckets Array of ['key' => value, 'count' => int] from database.
     *
     * @return array Normalized buckets with array values split into individuals.
     */
    private function normalizeArrayFacetBuckets(array $rawBuckets): array
    {
        // Map to accumulate counts: value => count
        $valueCounts = [];

        foreach ($rawBuckets as $bucket) {
            $key = $bucket['key'];
            $count = $bucket['count'];

            // Skip null/empty values.
            if ($key === null || $key === '' || $key === 'null') {
                continue;
            }

            // Check if this looks like a JSON array (starts with '[').
            if (is_string($key) === true && str_starts_with(trim($key), '[') === true) {
                // Try to decode as JSON array.
                $decoded = json_decode($key, true);

                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) === true) {
                    // It's a valid JSON array - distribute count to each element.
                    foreach ($decoded as $element) {
                        // Skip null/empty elements.
                        if ($element === null || $element === '') {
                            continue;
                        }

                        $elementKey = (string) $element;
                        if (isset($valueCounts[$elementKey]) === false) {
                            $valueCounts[$elementKey] = 0;
                        }
                        $valueCounts[$elementKey] += $count;
                    }
                    continue;
                }
            }

            // Not an array - use value as-is.
            // Clean up JSON-encoded single values (e.g., "\"value\"" -> "value").
            $cleanKey = $this->cleanJsonValue($key);
            $cleanKeyStr = (string) $cleanKey;

            if (isset($valueCounts[$cleanKeyStr]) === false) {
                $valueCounts[$cleanKeyStr] = 0;
            }
            $valueCounts[$cleanKeyStr] += $count;
        }

        // Convert back to bucket format, sorted by count descending.
        $normalizedBuckets = [];
        foreach ($valueCounts as $key => $count) {
            $normalizedBuckets[] = [
                'key' => $key,
                'count' => $count,
            ];
        }

        // Sort by count descending (highest first).
        usort($normalizedBuckets, fn($a, $b) => $b['count'] <=> $a['count']);

        // Apply limit.
        return array_slice($normalizedBuckets, 0, self::MAX_FACET_BUCKETS);
    }//end normalizeArrayFacetBuckets()

    /**
     * Get date histogram facet using UNION ALL across multiple tables.
     *
     * @param array  $tableConfigs Array of table configurations.
     * @param string $field        The field/column name.
     * @param string $interval     Histogram interval (day, week, month, year).
     * @param array  $baseQuery    Base query filters.
     *
     * @return array Facet result with merged buckets.
     */
    private function getDateHistogramFacetUnion(
        array $tableConfigs,
        string $field,
        string $interval,
        array $baseQuery
    ): array {
        if (empty($tableConfigs) === true) {
            return ['type' => 'date_histogram', 'interval' => $interval, 'buckets' => []];
        }

        $dateFormat = $this->getDateFormatForInterval($interval);
        $unionParts = [];
        $prefix = 'oc_';

        foreach ($tableConfigs as $tc) {
            $tableName = $tc['tableName'];
            $fullTableName = $prefix . $tableName;
            $tcSchema = $tc['schema'] ?? null;

            if ($this->columnExists(tableName: $tableName, columnName: $field) === false) {
                continue;
            }

            $subSql = "SELECT TO_CHAR({$field}, '{$dateFormat}') as date_key, COUNT(*) as cnt "
                . "FROM {$fullTableName} WHERE {$field} IS NOT NULL";

            // Use shared method for all filter conditions (single source of truth).
            if ($this->searchHandler !== null && $tcSchema !== null) {
                $whereConditions = $this->searchHandler->buildWhereConditionsSql(
                    query: $baseQuery,
                    schema: $tcSchema
                );
                foreach ($whereConditions as $condition) {
                    if ($condition === '1=0') {
                        continue 2;
                    }
                    $subSql .= " AND {$condition}";
                }
            }

            $subSql .= " GROUP BY date_key";
            $unionParts[] = $subSql;
        }

        if (empty($unionParts) === true) {
            return ['type' => 'date_histogram', 'interval' => $interval, 'buckets' => []];
        }

        $sql = "SELECT date_key, SUM(cnt) as doc_count FROM (\n"
            . implode("\nUNION ALL\n", $unionParts)
            . "\n) combined GROUP BY date_key ORDER BY date_key ASC";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute();

            $buckets = [];
            while (($row = $stmt->fetch()) !== false) {
                $buckets[] = [
                    'key' => $row['date_key'],
                    'results' => (int) $row['doc_count'],
                ];
            }

            return ['type' => 'date_histogram', 'interval' => $interval, 'buckets' => $buckets];
        } catch (\Exception $e) {
            $this->logger->warning(
                'MagicFacetHandler: UNION date histogram failed',
                ['field' => $field, 'error' => $e->getMessage()]
            );
            return ['type' => 'date_histogram', 'interval' => $interval, 'buckets' => []];
        }
    }//end getDateHistogramFacetUnion()

    /**
     * Expand facet config string to full configuration.
     *
     * Handles special values like "extend" which should return all facetable fields.
     *
     * @param string $facetConfig The facet config string (e.g., "extend").
     * @param Schema $schema      The schema for field discovery.
     *
     * @return array Expanded facet configuration.
     */
    private function expandFacetConfig(string $facetConfig, Schema $schema): array
    {
        if ($facetConfig === 'extend') {
            // Return all facetable metadata fields.
            // PERFORMANCE: Metadata facets are disabled by default for performance reasons.
            // Date histograms (@self.created, @self.updated) are particularly slow (~170-200ms each)
            // because they require grouping and date formatting across all tables.
            // The @self.register facet requires label resolution (~140ms).
            // These can be explicitly requested via _facets=@self.created,@self.updated,@self.register
            // if needed for specific use cases.
            $config = [
                '@self' => [
                    // Disabled for performance - uncomment if needed:
                    // 'register'     => ['type' => 'terms'],
                    // 'schema'       => ['type' => 'terms'],
                    // 'organisation' => ['type' => 'terms'],
                    // 'created'      => ['type' => 'date_histogram', 'interval' => 'month'],
                    // 'updated'      => ['type' => 'date_histogram', 'interval' => 'month'],
                ],
            ];

            // Add schema property facets - try pre-computed facets first.
            // But always get titles from schema properties since pre-computed facets may not have them.
            $schemaFacets = $schema->getFacets();
            $properties   = $schema->getProperties() ?? [];

            if (($schemaFacets['object_fields'] ?? null) !== null) {
                foreach ($schemaFacets['object_fields'] as $field => $fieldConfig) {
                    $facetType = $fieldConfig['type'] ?? 'terms';
                    // Get title from pre-computed facets first, then from schema property, then null.
                    $title     = $fieldConfig['title'] ?? $properties[$field]['title'] ?? null;
                    $config[$field] = [
                        'type'  => $facetType,
                        'title' => $title,
                    ];
                }

                return $config;
            }

            // Fall back to analyzing schema properties directly for facetable fields.
            $properties = $schema->getProperties();
            if (empty($properties) === false) {
                foreach ($properties as $propertyKey => $property) {
                    // Check if property is marked as facetable.
                    if (isset($property['facetable']) === true && $property['facetable'] === true) {
                        // Determine facet type based on property type.
                        $facetType            = $this->determineFacetTypeFromProperty($property);
                        $config[$propertyKey] = [
                            'type'  => $facetType,
                            'title' => $property['title'] ?? null,
                        ];
                    }
                }
            }

            return $config;
        }//end if

        // Treat as comma-separated field names.
        $fields = array_map('trim', explode(',', $facetConfig));
        $config = ['@self' => []];

        foreach ($fields as $field) {
            if (in_array($field, ['register', 'schema', 'organisation', 'owner'], true) === true) {
                $config['@self'][$field] = ['type' => 'terms'];
                continue;
            }

            if (in_array($field, ['created', 'updated', 'published'], true) === true) {
                $config['@self'][$field] = ['type' => 'date_histogram', 'interval' => 'month'];
                continue;
            }

            $config[$field] = ['type' => 'terms'];
        }

        return $config;
    }//end expandFacetConfig()

    /**
     * Expand facet config from ALL schemas in a multi-schema search.
     *
     * This method merges facet configurations from all schemas to ensure
     * that facetable fields from every schema are included in the result.
     * This fixes the bug where only the first schema's facets were used.
     *
     * @param string $facetConfigString The facet config string (e.g., "extend").
     * @param array  $tableConfigs      Array of table configurations with schemas.
     *
     * @return array Merged facet configuration from all schemas.
     */
    private function expandFacetConfigFromAllSchemas(string $facetConfigString, array $tableConfigs): array
    {
        $mergedConfig = [
            '@self' => [],
        ];

        foreach ($tableConfigs as $tc) {
            $schema = $tc['schema'] ?? null;
            if ($schema === null) {
                continue;
            }

            // Get facet config for this schema.
            $schemaConfig = $this->expandFacetConfig(facetConfig: $facetConfigString, schema: $schema);

            // Merge @self metadata facets (typically same across schemas).
            if (isset($schemaConfig['@self']) === true && is_array($schemaConfig['@self']) === true) {
                foreach ($schemaConfig['@self'] as $field => $config) {
                    if (isset($mergedConfig['@self'][$field]) === false) {
                        $mergedConfig['@self'][$field] = $config;
                    }
                }
            }

            // Merge object field facets (may differ per schema).
            foreach ($schemaConfig as $field => $config) {
                if ($field === '@self') {
                    continue;
                }

                // Add field if not already present, or merge if title is missing.
                if (isset($mergedConfig[$field]) === false) {
                    $mergedConfig[$field] = $config;
                } else if (
                    isset($config['title']) === true
                    && isset($mergedConfig[$field]['title']) === false
                ) {
                    // Use the title from a schema that has it.
                    $mergedConfig[$field]['title'] = $config['title'];
                }
            }
        }

        return $mergedConfig;
    }//end expandFacetConfigFromAllSchemas()

    /**
     * Sanitize column name for database compatibility.
     *
     * Converts camelCase to snake_case for PostgreSQL compatibility.
     * This matches MagicMapper::sanitizeColumnName().
     *
     * @param string $name The property name to sanitize.
     *
     * @return string The sanitized column name.
     */
    private function sanitizeColumnName(string $name): string
    {
        // Convert camelCase to snake_case.
        $name = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
        $name = strtolower($name);

        // Replace any remaining invalid characters with underscore.
        $name = preg_replace('/[^a-z0-9_]/', '_', $name);

        // Ensure it starts with a letter or underscore.
        if (preg_match('/^[a-z_]/', $name) === 0) {
            $name = 'col_'.$name;
        }

        // Remove consecutive underscores.
        $name = preg_replace('/_+/', '_', $name);

        // Remove trailing underscores.
        return rtrim($name, '_');
    }//end sanitizeColumnName()

    /**
     * Determine facet type based on property definition.
     *
     * @param array $property The property definition from the schema.
     *
     * @return string The appropriate facet type (terms, date_histogram, range).
     */
    private function determineFacetTypeFromProperty(array $property): string
    {
        $type   = $property['type'] ?? 'string';
        $format = $property['format'] ?? '';

        // Date/datetime fields use date histogram.
        if ($format === 'date' || $format === 'date-time' || $format === 'datetime') {
            return 'date_histogram';
        }

        // Numeric fields could use range, but terms is more common for faceting.
        // Only use range if specifically configured.
        if (($type === 'integer' || $type === 'number') && isset($property['facet_type']) === true) {
            return $property['facet_type'];
        }

        // Default to terms facet for categorical data.
        return 'terms';
    }//end determineFacetTypeFromProperty()

    /**
     * Get terms facet for a field in a magic mapper table.
     *
     * Returns unique values and their counts for categorical fields.
     * Uses simple GROUP BY and PHP post-processing for array values.
     *
     * @param string   $tableName  The table name.
     * @param string   $field      The field/column name.
     * @param array    $baseQuery  Base query filters to apply.
     * @param bool     $isMetadata Whether this is a metadata field.
     * @param Register $register   The register context.
     * @param Schema   $schema     The schema context.
     *
     * @return array Facet result with type and buckets.
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     */
    private function getTermsFacet(
        string $tableName,
        string $field,
        array $baseQuery,
        bool $isMetadata,
        Register $register,
        Schema $schema
    ): array {
        // Create cache key
        $cacheKey = md5(json_encode([$tableName, $field, $baseQuery, $isMetadata]));
        if (isset($this->facetCache[$cacheKey])) {
            return $this->facetCache[$cacheKey];
        }

        // Check if column exists in table before querying.
        if ($this->columnExists(tableName: $tableName, columnName: $field) === false) {
            $this->logger->debug(
                'MagicFacetHandler: Column does not exist for facet',
                ['tableName' => $tableName, 'field' => $field]
            );
            $result = [
                'type'    => 'terms',
                'buckets' => [],
            ];
            $this->facetCache[$cacheKey] = $result;
            return $result;
        }

        // Use shared query builder from MagicSearchHandler (single source of truth for filters).
        // Simple GROUP BY - array values will be post-processed in PHP.
        if ($this->searchHandler !== null) {
            $queryBuilder = $this->searchHandler->buildFilteredQuery(
                query: $baseQuery,
                schema: $schema,
                tableName: $tableName
            );

            // Add facet-specific SELECT and GROUP BY.
            $queryBuilder->selectAlias("t.{$field}", 'facet_value')
                ->addSelect($queryBuilder->createFunction('COUNT(*) as doc_count'))
                ->andWhere($queryBuilder->expr()->isNotNull("t.{$field}"))
                ->groupBy("t.{$field}")
                ->orderBy('doc_count', 'DESC')
                ->setMaxResults(self::MAX_FACET_BUCKETS);
        } else {
            // Fallback: Build query manually (legacy behavior).
            $queryBuilder = $this->db->getQueryBuilder();
            $queryBuilder->selectAlias($field, 'facet_value')
                ->addSelect($queryBuilder->createFunction('COUNT(*) as doc_count'))
                ->from($tableName)
                ->where($queryBuilder->expr()->isNotNull($field))
                ->groupBy($field)
                ->orderBy('doc_count', 'DESC')
                ->setMaxResults(self::MAX_FACET_BUCKETS);

            // Apply base filters.
            $this->applyBaseFilters(
                queryBuilder: $queryBuilder,
                baseQuery: $baseQuery,
                tableName: $tableName,
                schema: $schema
            );
        }

        $result = $queryBuilder->executeQuery();

        // Collect raw buckets from database.
        $rawBuckets = [];
        while (($row = $result->fetch()) !== false) {
            $rawBuckets[] = [
                'key' => $row['facet_value'],
                'count' => (int) $row['doc_count'],
            ];
        }

        // PHP POST-PROCESSING: Normalize array values.
        // This splits JSON array values into individual values and merges counts.
        $normalizedBuckets = $this->normalizeArrayFacetBuckets($rawBuckets);

        // Collect UUIDs for label resolution.
        $uuidsToResolve = [];
        foreach ($normalizedBuckets as $bucket) {
            $key = $bucket['key'];
            if (is_string($key) === true
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key) === 1
            ) {
                $uuidsToResolve[] = $key;
            }
        }

        // Batch resolve all UUID labels at once (after normalization).
        $labelMap = [];
        if (empty($uuidsToResolve) === false && $isMetadata === false) {
            $labelMap = $this->batchResolveUuidLabels(
                uuids: $uuidsToResolve,
                field: $field,
                schema: $schema,
                register: $register
            );
        }

        // Build final buckets with labels.
        $buckets = [];
        foreach ($normalizedBuckets as $bucket) {
            $key = $bucket['key'];

            // Use batch-resolved label if available, otherwise fall back to individual lookup.
            if (isset($labelMap[$key]) === true) {
                $label = $labelMap[$key];
            } else {
                $label = $this->getFieldLabel(
                    field: $field,
                    value: $key,
                    isMetadata: $isMetadata,
                    register: $register,
                    schema: $schema
                );
            }

            $buckets[] = [
                'key'     => $key,
                'results' => $bucket['count'],
                'label'   => $label,
            ];
        }

        $result = [
            'type'    => 'terms',
            'buckets' => $buckets,
        ];

        // Cache the result.
        $this->facetCache[$cacheKey] = $result;

        return $result;
    }//end getTermsFacet()

    /**
     * Clean up JSON-encoded values.
     *
     * Removes JSON encoding artifacts from single values.
     *
     * @param mixed $value The value to clean.
     *
     * @return mixed The cleaned value.
     */
    private function cleanJsonValue(mixed $value): mixed
    {
        if (is_string($value) === false) {
            return $value;
        }

        // Try to decode JSON strings.
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // If it decoded to a scalar, return that.
            if (is_scalar($decoded) === true) {
                return $decoded;
            }

            // If it's an array with one element, return that element.
            if (is_array($decoded) === true && count($decoded) === 1) {
                return reset($decoded);
            }
        }

        return $value;
    }//end cleanJsonValue()

    /**
     * Get date histogram facet for a field.
     *
     * Returns time-based buckets with counts for date fields.
     *
     * @param string      $tableName The table name.
     * @param string      $field     The field/column name.
     * @param string      $interval  The histogram interval (day, week, month, year).
     * @param array       $baseQuery Base query filters to apply.
     * @param Schema|null $schema    The schema for property type checking.
     *
     * @return array Facet result with type, interval, and buckets.
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     */
    private function getDateHistogramFacet(
        string $tableName,
        string $field,
        string $interval,
        array $baseQuery,
        ?Schema $schema=null
    ): array {
        // Check if column exists.
        if ($this->columnExists(tableName: $tableName, columnName: $field) === false) {
            return [
                'type'     => 'date_histogram',
                'interval' => $interval,
                'buckets'  => [],
            ];
        }

        // Build date histogram query based on interval using PostgreSQL-compatible syntax.
        $dateFormat = $this->getDateFormatForInterval($interval);

        // Use shared query builder from MagicSearchHandler (single source of truth for filters).
        if ($this->searchHandler !== null && $schema !== null) {
            $queryBuilder = $this->searchHandler->buildFilteredQuery(
                query: $baseQuery,
                schema: $schema,
                tableName: $tableName
            );

            // Add date histogram-specific SELECT and GROUP BY.
            // Note: buildFilteredQuery uses alias 't' for table.
            $queryBuilder->selectAlias(
                $queryBuilder->createFunction("TO_CHAR(t.{$field}, '{$dateFormat}')"),
                'date_key'
            )
                ->addSelect($queryBuilder->createFunction('COUNT(*) as doc_count'))
                ->andWhere($queryBuilder->expr()->isNotNull("t.{$field}"))
                ->groupBy('date_key')
                ->orderBy('date_key', 'ASC');
        } else {
            // Fallback: Build query manually (legacy behavior).
            $queryBuilder = $this->db->getQueryBuilder();

            // Use TO_CHAR for PostgreSQL (Nextcloud default) instead of DATE_FORMAT (MySQL).
            $queryBuilder->selectAlias(
                $queryBuilder->createFunction("TO_CHAR($field, '$dateFormat')"),
                'date_key'
            )
                ->selectAlias($queryBuilder->createFunction('COUNT(*)'), 'doc_count')
                ->from($tableName)
                ->where($queryBuilder->expr()->isNotNull($field))
                ->groupBy('date_key')
                ->orderBy('date_key', 'ASC');

            // Apply base filters (including object field filters for facet filtering).
            $this->applyBaseFilters(
                queryBuilder: $queryBuilder,
                baseQuery: $baseQuery,
                tableName: $tableName,
                schema: $schema
            );
        }

        $result  = $queryBuilder->executeQuery();
        $buckets = [];

        while (($row = $result->fetch()) !== false) {
            $buckets[] = [
                'key'     => $row['date_key'],
                'results' => (int) $row['doc_count'],
            ];
        }

        return [
            'type'     => 'date_histogram',
            'interval' => $interval,
            'buckets'  => $buckets,
        ];
    }//end getDateHistogramFacet()

    /**
     * Check if a column exists in the table.
     *
     * PERFORMANCE OPTIMIZATION: Uses in-memory cache to avoid repeated
     * information_schema queries. Loads ALL columns for a table on first
     * access (one query), then subsequent checks are instant array lookups.
     *
     * @param string $tableName  The table name.
     * @param string $columnName The column name.
     *
     * @return bool True if the column exists.
     */
    private function columnExists(string $tableName, string $columnName): bool
    {
        try {
            // The table name passed may or may not include the prefix.
            // Normalize to always have the 'oc_' prefix for information_schema lookup.
            $prefix        = 'oc_';
            $fullTableName = $prefix.$tableName;
            if (str_starts_with($tableName, $prefix) === true) {
                $fullTableName = $tableName;
            }

            // PostgreSQL stores unquoted identifiers in lowercase.
            $fullTableNameLower = strtolower($fullTableName);
            $columnNameLower    = strtolower($columnName);

            // OPTIMIZATION: Check in-memory cache first.
            if (isset($this->columnCache[$fullTableNameLower]) === true) {
                return isset($this->columnCache[$fullTableNameLower][$columnNameLower]);
            }

            // Load ALL columns for this table in one query (instead of one query per column).
            $sql = "SELECT LOWER(column_name) as col FROM information_schema.columns
                    WHERE LOWER(table_name) = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$fullTableNameLower]);

            // Cache all columns for this table.
            $this->columnCache[$fullTableNameLower] = [];
            while (($row = $stmt->fetch()) !== false) {
                $this->columnCache[$fullTableNameLower][$row['col']] = true;
            }

            return isset($this->columnCache[$fullTableNameLower][$columnNameLower]);
        } catch (\Exception $e) {
            $this->logger->warning(
                'MagicFacetHandler: Failed to check column existence',
                ['tableName' => $tableName, 'column' => $columnName, 'error' => $e->getMessage()]
            );
            return false;
        }//end try
    }//end columnExists()

    /**
     * Apply base query filters to the query builder.
     *
     * @param IQueryBuilder $queryBuilder The query builder to modify.
     * @param array         $baseQuery    The base query filters.
     * @param string        $tableName    The table name.
     * @param Schema|null   $schema       The schema for property type checking.
     *
     * @return void
     */
    private function applyBaseFilters(
        IQueryBuilder $queryBuilder,
        array $baseQuery,
        string $tableName,
        ?Schema $schema=null
    ): void {
        // Exclude deleted objects by default.
        $includeDeleted = $baseQuery['_includeDeleted'] ?? false;
        if ($includeDeleted === false) {
            $queryBuilder->andWhere($queryBuilder->expr()->isNull(self::METADATA_PREFIX.'deleted'));
        }

        // NOTE: The _published filter is intentionally NOT applied here.
        // The main search in MagicMapper::applySearchFilters() also skips _published
        // (it's in the reservedParams list), so facets should match the main search
        // behavior and include all non-deleted objects regardless of published status.
        // This allows facets to show the full distribution of data visible to users.
        // Apply metadata filters from @self.
        if (($baseQuery['@self'] ?? null) !== null && is_array($baseQuery['@self']) === true) {
            foreach ($baseQuery['@self'] as $field => $value) {
                $columnName = self::METADATA_PREFIX.$field;

                if ($this->columnExists(tableName: $tableName, columnName: $columnName) === false) {
                    continue;
                }

                if (is_array($value) === true) {
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()->in(
                            $columnName,
                            $queryBuilder->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
                        )
                    );
                    continue;
                }

                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq($columnName, $queryBuilder->createNamedParameter($value))
                );
            }//end foreach
        }//end if

        // Apply object field filters (schema property filters).
        // These are filters like licentietype[]=Open source that filter on object fields.
        $this->applyObjectFieldFilters(
            queryBuilder: $queryBuilder,
            baseQuery: $baseQuery,
            tableName: $tableName,
            schema: $schema
        );

        // Apply search filter if provided.
        $search = $baseQuery['_search'] ?? null;
        if ($search !== null && trim($search) !== '') {
            $this->applySearchFilter(
                queryBuilder: $queryBuilder,
                searchTerm: trim($search),
                tableName: $tableName,
                schema: $schema
            );
        }
    }//end applyBaseFilters()

    /**
     * Apply object field filters to the query builder.
     *
     * Handles filters on schema properties like licentietype[]=Open source.
     * Properly handles both regular string fields and JSON array fields.
     *
     * @param IQueryBuilder $queryBuilder The query builder.
     * @param array         $baseQuery    The base query with filters.
     * @param string        $tableName    The table name.
     * @param Schema|null   $schema       The schema for property type checking.
     *
     * @return void
     */
    private function applyObjectFieldFilters(
        IQueryBuilder $queryBuilder,
        array $baseQuery,
        string $tableName,
        ?Schema $schema=null
    ): void {
        // List of reserved query parameters that should not be used as filters.
        $reservedParams = [
            '_limit',
            '_offset',
            '_page',
            '_order',
            '_sort',
            '_search',
            '_extend',
            '_fields',
            '_filter',
            '_unset',
            '_facets',
            '_facetable',
            '_aggregations',
            '_debug',
            '_source',
            '_published',
            '_rbac',
            '_multitenancy',
            '_validation',
            '_events',
            '_register',
            '_schema',
            '_schemas',
            '_includeDeleted',
            '@self',
        ];

        // Get schema properties for type checking.
        $properties = [];
        if ($schema !== null) {
            $properties = ($schema->getProperties() ?? []);
        }

        foreach ($baseQuery as $key => $value) {
            // Skip reserved parameters.
            if (in_array($key, $reservedParams, true) === true) {
                continue;
            }

            // Skip system parameters starting with underscore.
            if (str_starts_with($key, '_') === true) {
                continue;
            }

            // This is an object field filter.
            $columnName = $this->sanitizeColumnName(name: $key);

            // Check if column exists.
            // If filter column doesn't exist, this schema can't match the filter - return 0 results.
            if ($this->columnExists(tableName: $tableName, columnName: $columnName) === false) {
                // Add an impossible condition to return 0 results.
                // This ensures facets don't count items from schemas that don't have the filtered property.
                $queryBuilder->andWhere('1 = 0');
                return;
                // No need to process further filters.
            }

            // Determine if this is an array-type property.
            $propertyType = $properties[$key]['type'] ?? 'string';

            if ($propertyType === 'array') {
                // Handle JSON array field filtering using containment operator.
                $this->applyJsonArrayFilter(
                    queryBuilder: $queryBuilder,
                    columnName: $columnName,
                    value: $value
                );
                continue;
            }

            // Handle regular field filtering.
            if (is_array($value) === true) {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->in(
                        $columnName,
                        $queryBuilder->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
                    )
                );
                continue;
            }

            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq($columnName, $queryBuilder->createNamedParameter($value))
            );
        }//end foreach
    }//end applyObjectFieldFilters()

    /**
     * Apply JSON array containment filter.
     *
     * Uses PostgreSQL's @> operator to check if JSON array contains value(s).
     *
     * @param IQueryBuilder $queryBuilder The query builder.
     * @param string        $columnName   The column name.
     * @param mixed         $value        The filter value (string or array).
     *
     * @return void
     */
    private function applyJsonArrayFilter(IQueryBuilder $queryBuilder, string $columnName, mixed $value): void
    {
        // Normalize value to array.
        $values = [$value];
        if (is_array($value) === true) {
            $values = $value;
        }

        // Use AND logic: JSON array must contain ALL specified values.
        $columnCast = $queryBuilder->createFunction("{$columnName}::jsonb");
        foreach ($values as $v) {
            $jsonValue = json_encode([$v]);
            $paramName = $queryBuilder->createNamedParameter($jsonValue);
            $queryBuilder->andWhere("{$columnCast} @> {$paramName}");
        }
    }//end applyJsonArrayFilter()

    /**
     * Apply search filter to query builder.
     *
     * @param IQueryBuilder $queryBuilder The query builder.
     * @param string        $searchTerm   The search term.
     * @param string        $tableName    The table name.
     *
     * @return void
     */
    /**
     * Apply search filter to query builder using same logic as MagicMapper.
     *
     * This ensures facet counts match the main search results by using identical
     * search logic: searches all string properties in the schema using ILIKE and
     * trigram similarity (for PostgreSQL).
     *
     * @param IQueryBuilder $queryBuilder The query builder.
     * @param string        $searchTerm   The search term.
     * @param string        $tableName    The table name.
     * @param Schema|null   $schema       The schema for determining searchable columns.
     *
     * @return void
     */
    private function applySearchFilter(
        IQueryBuilder $queryBuilder,
        string $searchTerm,
        string $tableName,
        ?Schema $schema = null
    ): void {
        $orConditions = $queryBuilder->expr()->orX();

        // Get all text-based properties from the schema (matching MagicMapper logic).
        $searchableColumns = [];
        
        if ($schema !== null) {
            $properties = $schema->getProperties() ?? [];
            if (is_array($properties) === true) {
                foreach ($properties as $propertyName => $propertyConfig) {
                    $type = $propertyConfig['type'] ?? 'string';
                    // Only search in string fields (same as MagicMapper).
                    if ($type === 'string') {
                        $columnName = $this->sanitizeColumnName($propertyName);
                        if ($this->columnExists(tableName: $tableName, columnName: $columnName) === true) {
                            $searchableColumns[] = $columnName;
                        }
                    }
                }
            }
        }

        // If no schema properties, fall back to metadata columns.
        if (empty($searchableColumns) === true) {
            $searchableColumns = [
                self::METADATA_PREFIX.'name',
                self::METADATA_PREFIX.'summary',
                self::METADATA_PREFIX.'uuid',
            ];
        }

        // Build search conditions (matching MagicMapper's ACTUAL behavior, not intended).
        // NOTE: Even though MagicMapper's applyFuzzySearch() includes trigram % operator,
        // in practice it seems to only use ILIKE. We match the actual behavior for consistency.
        $platform = $this->db->getDatabasePlatform();
        $searchPattern = '%'.$searchTerm.'%';

        foreach ($searchableColumns as $column) {
            if ($this->columnExists(tableName: $tableName, columnName: $column) === true) {
                // Use ILIKE only (matching actual behavior, not the % operator).
                if ($platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform === true) {
                    $orConditions->add(
                        $queryBuilder->createFunction(
                            "LOWER($column) ILIKE LOWER(".$queryBuilder->createNamedParameter($searchPattern).')'
                        )
                    );
                } else {
                    // MariaDB/MySQL: Use LIKE for case-insensitive substring match.
                    $orConditions->add(
                        $queryBuilder->expr()->like(
                            $queryBuilder->createFunction("LOWER($column)"),
                            $queryBuilder->createNamedParameter(strtolower($searchPattern))
                        )
                    );
                }
            }
        }

        if ($orConditions->count() > 0) {
            $queryBuilder->andWhere($orConditions);
        }
    }//end applySearchFilter()

    /**
     * Get date format string for histogram interval.
     *
     * Uses PostgreSQL TO_CHAR format patterns.
     *
     * @param string $interval The interval (day, week, month, year).
     *
     * @return string PostgreSQL TO_CHAR date format string.
     */
    private function getDateFormatForInterval(string $interval): string
    {
        switch ($interval) {
            case 'day':
                return 'YYYY-MM-DD';
            case 'week':
                return 'IYYY-IW';
            case 'month':
                return 'YYYY-MM';
            case 'year':
                return 'YYYY';
            default:
                return 'YYYY-MM';
        }
    }//end getDateFormatForInterval()

    /**
     * Batch resolve UUID labels with field-level caching optimization.
     *
     * PERFORMANCE OPTIMIZATION:
     * Instead of resolving labels per search query, we cache ALL labels for a field
     * in distributed cache with a long TTL. This means:
     * - First request for a field: loads all labels (may be slow)
     * - Subsequent requests: instant lookup from cache
     * - Labels rarely change, so long TTL (24h) is safe
     *
     * @param array    $uuids    Array of UUIDs to resolve.
     * @param string   $field    The field name for cache key.
     * @param Schema   $schema   The current schema context.
     * @param Register $register The current register context.
     * @param string   $tableName The magic mapper table name (optional, for cache key).
     *
     * @return array<string, string> Map of UUID to label.
     */
    private function batchResolveUuidLabels(
        array $uuids,
        string $field,
        Schema $schema,
        Register $register,
        string $tableName = ''
    ): array {
        if (empty($uuids) === true) {
            return [];
        }

        $startTime = microtime(true);

        // Generate field-level cache key.
        $fieldCacheKey = 'facet_labels_' . $register->getId() . '_' . $schema->getId() . '_' . $field;

        // STEP 1: Check in-memory field-level cache (fastest).
        if (isset($this->fieldLabelCache[$fieldCacheKey]) === true) {
            $cachedLabels = $this->fieldLabelCache[$fieldCacheKey];
            $result = [];
            $uncachedUuids = [];

            foreach ($uuids as $uuid) {
                if (isset($cachedLabels[$uuid]) === true) {
                    $result[$uuid] = $cachedLabels[$uuid];
                } else {
                    $uncachedUuids[] = $uuid;
                }
            }

            // If all UUIDs found in cache, return immediately.
            if (empty($uncachedUuids) === true) {
                $this->cacheStats['field_cache_hits']++;
                $this->cacheStats['total_uuids_resolved'] += count($result);
                $this->logger->debug('batchResolveUuidLabels: All labels from in-memory field cache', [
                    'field' => $field,
                    'count' => count($result),
                    'time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                ]);
                return $result;
            }
        }

        // STEP 2: Check distributed cache for field-level labels.
        if ($this->distributedLabelCache !== null && isset($this->warmedFields[$fieldCacheKey]) === false) {
            try {
                $distributedLabels = $this->distributedLabelCache->get($fieldCacheKey);
                if ($distributedLabels !== null && is_array($distributedLabels) === true) {
                    // Store in in-memory cache for this request.
                    $this->fieldLabelCache[$fieldCacheKey] = $distributedLabels;
                    $this->warmedFields[$fieldCacheKey] = true;

                    // Try again with the loaded cache.
                    $result = [];
                    $uncachedUuids = [];
                    foreach ($uuids as $uuid) {
                        if (isset($distributedLabels[$uuid]) === true) {
                            $result[$uuid] = $distributedLabels[$uuid];
                        } else {
                            $uncachedUuids[] = $uuid;
                        }
                    }

                    if (empty($uncachedUuids) === true) {
                        $this->cacheStats['distributed_cache_hits']++;
                        $this->cacheStats['total_uuids_resolved'] += count($result);
                        $this->logger->debug('batchResolveUuidLabels: All labels from distributed cache', [
                            'field' => $field,
                            'count' => count($result),
                            'time_ms' => round((microtime(true) - $startTime) * 1000, 2),
                        ]);
                        return $result;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to get facet labels from distributed cache: ' . $e->getMessage());
            }
        }

        // STEP 3: Resolve remaining UUIDs via CacheHandler.
        $result = $result ?? [];
        $uncachedUuids = $uncachedUuids ?? $uuids;

        if ($this->cacheHandler !== null && empty($uncachedUuids) === false) {
            $this->cacheStats['cache_handler_calls']++;
            $batchedLabels = $this->cacheHandler->getMultipleObjectNames($uncachedUuids);
            $this->cacheStats['total_uuids_resolved'] += count($batchedLabels);

            // Merge results.
            foreach ($batchedLabels as $uuid => $label) {
                $this->uuidLabelCache[$uuid] = $label;
                $result[$uuid] = $label;
            }

            // Update field-level cache with new labels.
            if (isset($this->fieldLabelCache[$fieldCacheKey]) === false) {
                $this->fieldLabelCache[$fieldCacheKey] = [];
            }
            $this->fieldLabelCache[$fieldCacheKey] = array_merge(
                $this->fieldLabelCache[$fieldCacheKey],
                $batchedLabels
            );

            // Persist to distributed cache for future requests.
            if ($this->distributedLabelCache !== null) {
                try {
                    $this->distributedLabelCache->set(
                        $fieldCacheKey,
                        $this->fieldLabelCache[$fieldCacheKey],
                        self::FACET_LABEL_CACHE_TTL
                    );
                    $this->warmedFields[$fieldCacheKey] = true;
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to persist facet labels to distributed cache: ' . $e->getMessage());
                }
            }

            $this->logger->debug('batchResolveUuidLabels: Resolved via CacheHandler and cached', [
                'field' => $field,
                'requested' => count($uuids),
                'resolved' => count($batchedLabels),
                'time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            ]);
        }

        return $result;
    }//end batchResolveUuidLabels()

    /**
     * Get human-readable label for a field value.
     *
     * @param string   $field      The field name.
     * @param mixed    $value      The field value.
     * @param bool     $isMetadata Whether this is a metadata field.
     * @param Register $register   The register context.
     * @param Schema   $schema     The schema context.
     *
     * @return string Human-readable label.
     *
     * @psalm-suppress UnusedParam Parameters reserved for future label lookup from related entities.
     */
    private function getFieldLabel(
        string $field,
        mixed $value,
        bool $isMetadata,
        Register $register,
        Schema $schema
    ): string {
        // For register field, try to get the register title.
        if ($field === self::METADATA_PREFIX.'register' && is_numeric($value) === true) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->select('title')
                    ->from('openregister_registers')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter((int) $value)));
                $result = $qb->executeQuery();
                $title  = $result->fetchOne();
                if ($title !== false) {
                    return (string) $title;
                }
            } catch (\Exception $e) {
                // Fall through to default.
            }

            return "Register $value";
        }

        // For schema field, try to get the schema title.
        if ($field === self::METADATA_PREFIX.'schema' && is_numeric($value) === true) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->select('title')
                    ->from('openregister_schemas')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter((int) $value)));
                $result = $qb->executeQuery();
                $title  = $result->fetchOne();
                if ($title !== false) {
                    return (string) $title;
                }
            } catch (\Exception $e) {
                // Fall through to default.
            }

            return "Schema $value";
        }

        // For organisation field, try to get the organisation name.
        if ($field === self::METADATA_PREFIX.'organisation' && is_string($value) === true && $value !== '') {
            // First try system organisations table.
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->select('name')
                    ->from('openregister_organisations')
                    ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($value)));
                $result = $qb->executeQuery();
                $name   = $result->fetchOne();
                if ($name !== false && $name !== null) {
                    return (string) $name;
                }
            } catch (\Exception $e) {
                // Fall through to CacheHandler lookup.
            }

            // Try CacheHandler for dynamic object name lookup.
            if ($this->cacheHandler !== null) {
                $names = $this->cacheHandler->getMultipleObjectNames([$value]);
                if (isset($names[$value]) === true) {
                    return $names[$value];
                }
            }

            // Return shortened UUID if name not found.
            return substr($value, 0, 8).'...';
        }

        // For object fields containing UUIDs, try to resolve to object names.
        // Note: Using relaxed UUID pattern to match non-standard UUIDs (e.g., version 1 time-based).
        if (is_string($value) === true
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1
        ) {
            // Use CacheHandler for dynamic object name lookup.
            if ($this->cacheHandler !== null) {
                $names = $this->cacheHandler->getMultipleObjectNames([$value]);
                if (isset($names[$value]) === true) {
                    return $names[$value];
                }
            }

            // Return shortened UUID if name not found.
            return substr($value, 0, 8).'...';
        }

        return (string) $value;
    }//end getFieldLabel()
}//end class
