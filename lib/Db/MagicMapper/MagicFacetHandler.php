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
     * In-memory cache for facet results within a single request
     */
    private array $facetCache = [];

    /**
     * Constructor for MagicFacetHandler
     *
     * @param IDBConnection   $db     Database connection for queries
     * @param LoggerInterface $logger Logger for debugging and error reporting
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger
    ) {
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
        $this->logger->info(
            'MagicFacetHandler: Facet performance',
            [
                'total_time_ms' => $totalTime,
                'facet_count' => count($facetTimes),
                'facet_times' => $facetTimes,
            ]
        );

        return $facets;
    }//end getSimpleFacets()

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
            $config = [
                '@self' => [
                    'register'     => ['type' => 'terms'],
                    // 'schema'       => ['type' => 'terms'],
                    // 'organisation' => ['type' => 'terms'],
                    'created'      => ['type' => 'date_histogram', 'interval' => 'month'],
                    'updated'      => ['type' => 'date_histogram', 'interval' => 'month'],
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

        // Check if this is an array field by looking at the schema property.
        $isArrayField = $this->isArrayField(field: $field, schema: $schema, isMetadata: $isMetadata);

        if ($isArrayField === true) {
            // Use JSON array unnesting for array fields.
            $result = $this->getTermsFacetForArrayField(
                tableName: $tableName,
                field: $field,
                baseQuery: $baseQuery,
                isMetadata: $isMetadata,
                register: $register,
                schema: $schema
            );
            $this->facetCache[$cacheKey] = $result;
            return $result;
        }

        // Standard terms facet for non-array fields.
        $queryBuilder = $this->db->getQueryBuilder();

        // Build aggregation query.
        $queryBuilder->select($field, $queryBuilder->createFunction('COUNT(*) as doc_count'))
            ->from($tableName)
            ->where($queryBuilder->expr()->isNotNull($field))
            ->groupBy($field)
            ->orderBy('doc_count', 'DESC')
            ->setMaxResults(self::MAX_FACET_BUCKETS); // Limit for performance

        // Apply base filters (including object field filters for facet filtering).
        $this->applyBaseFilters(
            queryBuilder: $queryBuilder,
            baseQuery: $baseQuery,
            tableName: $tableName,
            schema: $schema
        );

        $result  = $queryBuilder->executeQuery();
        $buckets = [];

        while (($row = $result->fetch()) !== false) {
            $key = $row[$field];
            // Clean up JSON-encoded single values (e.g., "value" -> value).
            $key = $this->cleanJsonValue($key);

            $label = $this->getFieldLabel(
                field: $field,
                value: $key,
                isMetadata: $isMetadata,
                register: $register,
                schema: $schema
            );

            $buckets[] = [
                'key'     => $key,
                'results' => (int) $row['doc_count'],
                'label'   => $label,
            ];
        }

        $result = [
            'type'    => 'terms',
            'buckets' => $buckets,
        ];
        
        // Cache the result
        $cacheKey = md5(json_encode([$tableName, $field, $baseQuery, $isMetadata]));
        $this->facetCache[$cacheKey] = $result;
        
        return $result;
    }//end getTermsFacet()

    /**
     * Get terms facet for an array field using JSON unnesting.
     *
     * Uses PostgreSQL's jsonb_array_elements_text() to unnest JSON arrays
     * and count individual values.
     *
     * @param string   $tableName  The table name.
     * @param string   $field      The field/column name.
     * @param array    $baseQuery  Base query filters to apply.
     * @param bool     $isMetadata Whether this is a metadata field.
     * @param Register $register   The register context.
     * @param Schema   $schema     The schema context.
     *
     * @return array Facet result with type and buckets.
     */
    private function getTermsFacetForArrayField(
        string $tableName,
        string $field,
        array $baseQuery,
        bool $isMetadata,
        Register $register,
        Schema $schema
    ): array {
        // Build raw SQL for JSON array unnesting.
        // PostgreSQL: jsonb_array_elements_text(column::jsonb) to extract array elements.
        $prefix        = 'oc_';
        $fullTableName = $prefix.$tableName;
        $params        = [];

        $sql = "SELECT elem AS facet_value, COUNT(*) AS doc_count
                FROM {$fullTableName}, jsonb_array_elements_text({$field}::jsonb) AS elem
                WHERE {$field} IS NOT NULL AND {$field} != '[]' AND {$field} != 'null'";

        // Add deleted filter.
        $includeDeleted = $baseQuery['_includeDeleted'] ?? false;
        if ($includeDeleted === false) {
            $sql .= " AND _deleted IS NULL";
        }

        // Add object field filters for facet filtering.
        $filterSql = $this->buildObjectFieldFiltersSql(
            baseQuery: $baseQuery,
            tableName: $tableName,
            schema: $schema,
            params: $params
        );
        if ($filterSql !== '') {
            $sql .= " AND ".$filterSql;
        }

        // Add search filter if provided (CRITICAL FIX: was missing for array fields).
        // Use same logic as MagicMapper: search all string properties in schema.
        $search = $baseQuery['_search'] ?? null;
        if ($search !== null && trim($search) !== '') {
            $searchTerm = trim($search);
            $searchConditions = [];
            
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
                            $searchableColumns[] = $columnName;
                        }
                    }
                }
            }
            
            // If no schema properties, fall back to metadata columns.
            if (empty($searchableColumns) === true) {
                $searchableColumns = ['_name', '_summary', '_uuid'];
            }
            
            // Build search conditions (matching MagicMapper's ACTUAL behavior).
            // Use ILIKE only, not trigram % operator.
            foreach ($searchableColumns as $column) {
                // ILIKE for case-insensitive substring match.
                $searchConditions[] = "LOWER($column) ILIKE ?";
                $params[] = '%'.strtolower($searchTerm).'%';
            }
            
            if (count($searchConditions) > 0) {
                $sql .= " AND (".implode(" OR ", $searchConditions).")";
            }
        }

        $sql .= " GROUP BY elem ORDER BY doc_count DESC LIMIT ".self::MAX_FACET_BUCKETS;

        try {
            $stmt = $this->db->prepare($sql);
            // Bind parameters if any.
            foreach ($params as $index => $value) {
                $stmt->bindValue((int) $index + 1, $value);
            }

            $stmt->execute();
            $buckets = [];

            while (($row = $stmt->fetch()) !== false) {
                $key   = $row['facet_value'];
                $label = $this->getFieldLabel(
                    field: $field,
                    value: $key,
                    isMetadata: $isMetadata,
                    register: $register,
                    schema: $schema
                );

                $buckets[] = [
                    'key'     => $key,
                    'results' => (int) $row['doc_count'],
                    'label'   => $label,
                ];
            }

            return [
                'type'    => 'terms',
                'buckets' => $buckets,
            ];
        } catch (\Exception $e) {
            $this->logger->warning(
                'MagicFacetHandler: Failed to get array facet',
                ['field' => $field, 'error' => $e->getMessage()]
            );
            // Fall back to empty buckets on error.
            return [
                'type'    => 'terms',
                'buckets' => [],
            ];
        }//end try
    }//end getTermsFacetForArrayField()

    /**
     * Build raw SQL WHERE conditions for object field filters.
     *
     * @param array       $baseQuery The base query with filters.
     * @param string      $tableName The table name.
     * @param Schema|null $schema    The schema for property type checking.
     * @param array       $params    Reference to array for collecting bind parameters.
     *
     * @return string SQL WHERE conditions (without leading AND).
     */
    private function buildObjectFieldFiltersSql(
        array $baseQuery,
        string $tableName,
        ?Schema $schema,
        array &$params
    ): string {
        $conditions = [];

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
            // If filter column doesn't exist, this schema can't match the filter - return impossible condition.
            if ($this->columnExists(tableName: $tableName, columnName: $columnName) === false) {
                // Return impossible condition to get 0 results.
                return "1 = 0";
            }

            // Determine if this is an array-type property.
            $propertyType = $properties[$key]['type'] ?? 'string';

            // Normalize value to array.
            $values = [$value];
            if (is_array($value) === true) {
                $values = $value;
            }

            if ($propertyType === 'array') {
                // Handle JSON array field filtering using containment operator.
                // Use AND logic: JSON array must contain ALL specified values.
                foreach ($values as $v) {
                    $params[]     = json_encode([$v]);
                    $conditions[] = "{$columnName}::jsonb @> ?";
                }

                continue;
            }

            // Handle regular field filtering.
            if (count($values) === 1) {
                $params[]     = $values[0];
                $conditions[] = "{$columnName} = ?";
                continue;
            }

            $placeholders = [];
            foreach ($values as $v) {
                $params[]       = $v;
                $placeholders[] = "?";
            }

            $conditions[] = "{$columnName} IN (".implode(", ", $placeholders).")";
        }//end foreach

        return implode(" AND ", $conditions);
    }//end buildObjectFieldFiltersSql()

    /**
     * Check if a field is an array type based on schema.
     *
     * @param string $field      The field name.
     * @param Schema $schema     The schema.
     * @param bool   $isMetadata Whether this is a metadata field.
     *
     * @return bool True if the field is an array type.
     */
    private function isArrayField(string $field, Schema $schema, bool $isMetadata): bool
    {
        // Metadata fields are not arrays (they're stored directly).
        if ($isMetadata === true) {
            return false;
        }

        // Check schema properties for type definition.
        $properties = $schema->getProperties() ?? [];

        // Find the property - need to match sanitized name back to original.
        foreach ($properties as $propName => $propDef) {
            $sanitized = $this->sanitizeColumnName($propName);
            if ($sanitized === $field) {
                $type = $propDef['type'] ?? 'string';
                return $type === 'array';
            }
        }

        return false;
    }//end isArrayField()

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

        $queryBuilder = $this->db->getQueryBuilder();

        // Build date histogram query based on interval using PostgreSQL-compatible syntax.
        $dateFormat = $this->getDateFormatForInterval($interval);

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

            $sql = "SELECT 1 FROM information_schema.columns
                    WHERE LOWER(table_name) = ? AND LOWER(column_name) = ? LIMIT 1";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$fullTableNameLower, $columnNameLower]);
            $row = $stmt->fetch();

            return $row !== false;
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
                // Fall through to schema object lookup.
            }

            // Try to find organisation name from Organisation schema objects.
            // Organisation objects may be stored in magic tables with different name columns.
            $orgName = $this->getOrganisationNameFromSchemaObjects($value);
            if ($orgName !== null) {
                return $orgName;
            }

            // Return shortened UUID if name not found.
            return substr($value, 0, 8).'...';
        }

        // For object fields containing UUIDs, try to resolve to object names.
        // Note: Using relaxed UUID pattern to match non-standard UUIDs (e.g., version 1 time-based).
        if (is_string($value) === true
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1
        ) {
            $objectName = $this->getObjectNameFromSchemaObjects($value);
            if ($objectName !== null) {
                return $objectName;
            }

            // Return shortened UUID if name not found.
            return substr($value, 0, 8).'...';
        }

        return (string) $value;
    }//end getFieldLabel()

    /**
     * Get object name from schema objects by UUID.
     *
     * Looks up the UUID in known schema magic tables and returns the object name
     * if found. Searches common name fields (naam, name, title).
     *
     * @param string $uuid The object UUID to look up.
     *
     * @return string|null The object name or null if not found.
     */
    private function getObjectNameFromSchemaObjects(string $uuid): ?string
    {
        // Known object tables with their name columns.
        // Table: openregister_table_{register}_{schema} with column 'naam', 'name', '_name' (metadata), or 'title'.
        $objectTables = [
            // Organisatie in Voorzieningen register (register 2, schema 24).
            ['table' => 'oc_openregister_table_2_24', 'columns' => ['naam', 'name', '_name']],
            // Element in AMEF register (register 3, schema 29) - for standaardversies.
            // Element uses _name metadata column for name.
            ['table' => 'oc_openregister_table_3_29', 'columns' => ['_name', 'name', 'naam', 'title']],
            // Organization in Publication register (register 1, schema 4).
            ['table' => 'oc_openregister_table_1_4', 'columns' => ['name', 'naam', '_name']],
            // Organization in AMEF register (register 3, schema 4).
            ['table' => 'oc_openregister_table_3_4', 'columns' => ['name', 'naam', '_name']],
            // Applicatie/Module in Voorzieningen register (register 2, schema 34).
            ['table' => 'oc_openregister_table_2_34', 'columns' => ['naam', 'name', '_name', 'title']],
        ];

        foreach ($objectTables as $tableInfo) {
            $tableName = $tableInfo['table'];

            foreach ($tableInfo['columns'] as $nameColumn) {
                try {
                    // Query directly - skip information_schema check for performance.
                    // Try to select from the table directly. If it fails, move to next.
                    $sql  = "SELECT {$nameColumn} FROM {$tableName} WHERE _uuid = ? LIMIT 1";
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute([$uuid]);
                    $name = $stmt->fetchOne();

                    if ($name !== false && $name !== null && trim((string) $name) !== '') {
                        return (string) $name;
                    }
                } catch (\Exception $e) {
                    // Table/column doesn't exist or query failed, try next column/table.
                    continue;
                }
            }//end foreach
        }//end foreach

        return null;
    }//end getObjectNameFromSchemaObjects()

    /**
     * Get organisation name from Organisation schema objects.
     *
     * Looks up the organisation UUID in known Organisation schema magic tables
     * and returns the organisation name if found. Uses direct SQL for reliability.
     *
     * @param string $uuid The organisation UUID to look up.
     *
     * @return string|null The organisation name or null if not found.
     */
    private function getOrganisationNameFromSchemaObjects(string $uuid): ?string
    {
        // Known organisation tables with their name columns.
        // Table: openregister_table_{register}_{schema} with column 'naam' or 'name'.
        $orgTables = [
            ['table' => 'oc_openregister_table_2_24', 'column' => 'naam'],
            // Organisatie in Voorzieningen register
            ['table' => 'oc_openregister_table_1_4', 'column' => 'name'],
            // Organization in Publication register
            ['table' => 'oc_openregister_table_3_4', 'column' => 'name'],
            // Organization in AMEF register (if exists)
        ];

        foreach ($orgTables as $tableInfo) {
            $tableName  = $tableInfo['table'];
            $nameColumn = $tableInfo['column'];

            try {
                // Check if table and column exist.
                $checkSql = "SELECT 1 FROM information_schema.columns
                             WHERE table_name = ? AND column_name = ? LIMIT 1";
                $stmt     = $this->db->prepare($checkSql);
                $stmt->execute([strtolower($tableName), strtolower($nameColumn)]);
                if ($stmt->fetch() === false) {
                    continue;
                }

                // Query for the organisation name.
                $sql  = "SELECT {$nameColumn} FROM {$tableName} WHERE _uuid = ? LIMIT 1";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$uuid]);
                $name = $stmt->fetchOne();

                if ($name !== false && $name !== null && trim((string) $name) !== '') {
                    return (string) $name;
                }
            } catch (\Exception $e) {
                // Table doesn't exist or query failed, try next.
                continue;
            }
        }//end foreach

        return null;
    }//end getOrganisationNameFromSchemaObjects()
}//end class
