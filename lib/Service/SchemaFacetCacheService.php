<?php

/**
 * OpenRegister Schema Facet Cache Service
 *
 * Service class for caching schema-based facets to improve performance.
 *
 * This service provides predictable facet caching based on schema definitions.
 * Since facets are determined by schema properties, they can be cached and
 * invalidated when schemas change, providing significant performance benefits.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Schema-based Facet Cache Service
 *
 * ARCHITECTURE OVERVIEW:
 * This service implements predictable facet caching based on the principle that
 * facets are determined by schema structure. Since schema properties define what
 * fields can be faceted and how, we can cache facet configurations and results
 * based on schema definitions.
 *
 * CACHING STRATEGY:
 * - Facet configurations are cached per schema
 * - Facet results are cached with configurable TTL
 * - Cache invalidation occurs when schemas are updated
 * - Supports different facet types: terms, date_histogram, range
 *
 * PERFORMANCE BENEFITS:
 * - Eliminates repeated facet computation for the same schema
 * - Reduces database queries for faceting operations
 * - Enables fast facet response times for frequently accessed schemas
 * - Supports predictable facet discovery based on schema properties
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */
class SchemaFacetCacheService
{

    /**
     * Cache table name for facet data
     */
    private const FACET_CACHE_TABLE = 'openregister_schema_facet_cache';

    /**
     * Default cache TTL in seconds (30 minutes for facets)
     */
    private const DEFAULT_FACET_TTL = 1800;

    /**
     * Maximum cache TTL for office environments (8 hours in seconds)
     *
     * This prevents indefinite cache buildup while maintaining performance
     * during business hours.
     */
    private const MAX_CACHE_TTL = 28800;

    /**
     * Supported facet types
     */
    private const FACET_TYPE_TERMS          = 'terms';
    private const FACET_TYPE_DATE_HISTOGRAM = 'date_histogram';
    private const FACET_TYPE_RANGE          = 'range';

    /**
     * In-memory cache for facet configurations
     *
     * @var array<string, mixed>
     */
    private static array $facetConfigCache = [];

    /**
     * Database connection
     *
     * @var IDBConnection
     */
    private IDBConnection $db;

    /**
     * Schema mapper for database operations
     *
     * @var SchemaMapper
     */
    private SchemaMapper $schemaMapper;

    /**
     * Logger for performance monitoring
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * Constructor
     *
     * @param IDBConnection   $db           Database connection
     * @param SchemaMapper    $schemaMapper Schema mapper for database operations
     * @param LoggerInterface $logger       Logger for performance monitoring
     */
    public function __construct(
        IDBConnection $db,
        SchemaMapper $schemaMapper,
        LoggerInterface $logger
    ) {
        $this->db           = $db;
        $this->schemaMapper = $schemaMapper;
        $this->logger       = $logger;

    }//end __construct()


    /**
     * Get facetable fields configuration for a schema
     *
     * This method returns the cached facetable field configuration based on
     * schema properties. It analyzes schema properties to determine which
     * fields can be faceted and what facet types are appropriate.
     *
     * @param int $schemaId The schema ID
     *
     * @return array<string, mixed> Facetable fields configuration
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function getFacetableFields(int $schemaId): array
    {
        $cacheKey = "facetable_fields_{$schemaId}";

        // Check in-memory cache.
        if (isset(self::$facetConfigCache[$cacheKey])) {
            $this->logger->debug('Facetable fields cache hit (memory)', ['schemaId' => $schemaId]);
            return self::$facetConfigCache[$cacheKey];
        }

        // Check if we have cached facet configuration.
        $cachedConfig = $this->getCachedFacetData($schemaId, 'facetable_fields');
        if ($cachedConfig !== null) {
            self::$facetConfigCache[$cacheKey] = $cachedConfig;
            $this->logger->debug('Facetable fields cache hit (database)', ['schemaId' => $schemaId]);
            return $cachedConfig;
        }

        // Generate facetable fields from schema and cache.
        $facetableFields = $this->generateFacetableFieldsFromSchema($schemaId);
        $this->cacheFacetableFields($schemaId, $facetableFields);

        return $facetableFields;

    }//end getFacetableFields()


    /**
     * Get cached facet results for specific facet configuration
     *
     * @param int    $schemaId    The schema ID
     * @param string $facetType   The facet type (terms, date_histogram, range)
     * @param string $fieldName   The field name for the facet
     * @param array  $facetConfig Optional facet configuration (intervals, ranges, etc.)
     *
     * @return array|null Cached facet results or null if not cached
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function getCachedFacetResults(int $schemaId, string $facetType, string $fieldName, array $facetConfig=[]): ?array
    {
        $cacheKey = $this->buildFacetCacheKey($facetType, $fieldName, $facetConfig);
        return $this->getCachedFacetData($schemaId, $cacheKey);

    }//end getCachedFacetResults()


    /**
     * Cache facet results for a specific configuration
     *
     * @param int    $schemaId     The schema ID
     * @param string $facetType    The facet type
     * @param string $fieldName    The field name
     * @param array  $facetConfig  Facet configuration
     * @param array  $facetResults The facet results to cache
     * @param int    $ttl          Cache TTL in seconds
     *
     * @return void
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function cacheFacetResults(
        int $schemaId,
        string $facetType,
        string $fieldName,
        array $facetConfig,
        array $facetResults,
        int $ttl=self::DEFAULT_FACET_TTL
    ): void {
        $cacheKey = $this->buildFacetCacheKey($facetType, $fieldName, $facetConfig);
        $this->setCachedFacetData($schemaId, $cacheKey, $facetType, $fieldName, $facetConfig, $facetResults, $ttl);

        $this->logger->debug(
                'Cached facet results',
                [
                    'schemaId'    => $schemaId,
                    'facetType'   => $facetType,
                    'fieldName'   => $fieldName,
                    'resultCount' => count($facetResults),
                    'ttl'         => $ttl,
                ]
                );

    }//end cacheFacetResults()


    /**
     * Cache facetable fields configuration for a schema
     *
     * @param int   $schemaId        The schema ID
     * @param array $facetableFields The facetable fields configuration
     * @param int   $ttl             Cache TTL in seconds (longer for config)
     *
     * @return void
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function cacheFacetableFields(int $schemaId, array $facetableFields, int $ttl=7200): void
    {
        $this->setCachedFacetData($schemaId, 'facetable_fields', 'config', 'facetable_fields', [], $facetableFields, $ttl);

        // Store in memory cache.
        $cacheKey = "facetable_fields_{$schemaId}";
        self::$facetConfigCache[$cacheKey] = $facetableFields;

        $this->logger->debug(
                'Cached facetable fields configuration',
                [
                    'schemaId'   => $schemaId,
                    'fieldCount' => count($facetableFields),
                    'ttl'        => $ttl,
                ]
                );

    }//end cacheFacetableFields()


    /**
     * Invalidate all cached facets for a schema
     *
     * **SCHEMA FACET INVALIDATION**: Called when schemas are created, updated,
     * or deleted to ensure facet cache consistency.
     *
     * @param int    $schemaId  The schema ID to invalidate
     * @param string $operation The operation performed (create/update/delete)
     *
     * @return void
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function invalidateForSchemaChange(int $schemaId, string $operation='update'): void
    {
        $startTime    = microtime(true);
        $deletedCount = 0;

        // Remove from database cache (if table exists).
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete(self::FACET_CACHE_TABLE)
                ->where($qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId)));
            $deletedCount = $qb->executeStatement();
        } catch (\Exception $e) {
            // If the cache table doesn't exist yet, just log a debug message and continue.
            // This allows the app to work even if the migration hasn't been run yet.
            $this->logger->debug(
                    'Schema facet cache table does not exist yet, skipping database cache invalidation',
                    [
                        'schemaId' => $schemaId,
                        'error'    => $e->getMessage(),
                    ]
                    );
        }

        // Remove from memory cache (always safe to do).
        $memoryClearedCount = 0;
        $cacheKeys          = array_keys(self::$facetConfigCache);
        foreach ($cacheKeys as $key) {
            if (str_contains($key, "_{$schemaId}")) {
                unset(self::$facetConfigCache[$key]);
                $memoryClearedCount++;
            }
        }

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        $this->logger->info(
                'Schema facet cache invalidated',
                [
                    'schemaId'             => $schemaId,
                    'operation'            => $operation,
                    'deletedDbEntries'     => $deletedCount,
                    'clearedMemoryEntries' => $memoryClearedCount,
                    'executionTime'        => $executionTime.'ms',
                ]
                );

    }//end invalidateForSchemaChange()


    /**
     * Invalidate all cached facets for a schema (legacy method)
     *
     * @deprecated Use invalidateForSchemaChange() instead
     * @param      int $schemaId The schema ID to invalidate
     * @return     void
     * @throws     \OCP\DB\Exception If a database error occurs
     */
    public function invalidateSchemaFacets(int $schemaId): void
    {
        $this->invalidateForSchemaChange($schemaId, 'update');

    }//end invalidateSchemaFacets()


    /**
     * Clear all facet caches (Administrative Operation)
     *
     * **NUCLEAR OPTION**: Clears all facet caches for all schemas.
     * Use with caution as it will impact performance until caches are rebuilt.
     *
     * @return void
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function clearAllCaches(): void
    {
        $startTime = microtime(true);

        // Clear database cache.
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::FACET_CACHE_TABLE);
        $deletedCount = $qb->executeStatement();

        // Clear memory cache.
        $memoryCacheSize        = count(self::$facetConfigCache);
        self::$facetConfigCache = [];

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        $this->logger->info(
                'All facet caches cleared',
                [
                    'deletedDbEntries'     => $deletedCount,
                    'clearedMemoryEntries' => $memoryCacheSize,
                    'executionTime'        => $executionTime.'ms',
                ]
                );

    }//end clearAllCaches()


    /**
     * Clear all facet cache (legacy method)
     *
     * @deprecated Use clearAllCaches() instead
     * @return     void
     * @throws     \OCP\DB\Exception If a database error occurs
     */
    public function clearAll(): void
    {
        $this->clearAllCaches();

    }//end clearAll()


    /**
     * Clean expired facet cache entries
     *
     * @return int Number of expired entries removed
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function cleanExpiredEntries(): int
    {
        $startTime = microtime(true);

        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::FACET_CACHE_TABLE)
            ->where($qb->expr()->isNotNull('expires'))
            ->andWhere($qb->expr()->lt('expires', $qb->createNamedParameter(new \DateTime(), 'datetime')));

        $deletedCount = $qb->executeStatement();

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        if ($deletedCount > 0) {
            $this->logger->info(
                    'Cleaned expired facet cache entries',
                    [
                        'count'         => $deletedCount,
                        'executionTime' => $executionTime.'ms',
                    ]
                    );
        }

        return $deletedCount;

    }//end cleanExpiredEntries()


    /**
     * Clean expired facet cache entries (legacy method)
     *
     * @deprecated Use cleanExpiredEntries() instead
     * @return     int Number of expired entries removed
     * @throws     \OCP\DB\Exception If a database error occurs
     */
    public function cleanExpired(): int
    {
        return $this->cleanExpiredEntries();

    }//end cleanExpired()


    /**
     * Get comprehensive facet cache statistics
     *
     * @return array<string, mixed> Cache statistics including performance metrics
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function getCacheStatistics(): array
    {
        $startTime = microtime(true);

        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('id', 'total_entries'))
            ->addSelect('facet_type')
            ->addSelect($qb->func()->count('id', 'count'))
            ->from(self::FACET_CACHE_TABLE)
            ->groupBy('facet_type');

        $results = $qb->executeQuery()->fetchAll();

        $stats = [
            'total_entries'     => 0,
            'by_type'           => [],
            'memory_cache_size' => count(self::$facetConfigCache),
            'cache_table'       => self::FACET_CACHE_TABLE,
        ];

        foreach ($results as $result) {
            $stats['total_entries'] += (int) $result['count'];
            $stats['by_type'][$result['facet_type']] = (int) $result['count'];
        }

        $executionTime       = round((microtime(true) - $startTime) * 1000, 2);
        $stats['query_time'] = $executionTime.'ms';
        $stats['timestamp']  = time();

        return $stats;

    }//end getCacheStatistics()


    /**
     * Get facet cache statistics (legacy method)
     *
     * @deprecated Use getCacheStatistics() instead
     * @return     array<string, mixed> Cache statistics
     * @throws     \OCP\DB\Exception If a database error occurs
     */
    public function getStatistics(): array
    {
        return $this->getCacheStatistics();

    }//end getStatistics()


    /**
     * Generate facetable fields configuration from schema properties
     *
     * @param int $schemaId The schema ID
     *
     * @return array<string, mixed> Facetable fields configuration
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    private function generateFacetableFieldsFromSchema(int $schemaId): array
    {
        try {
            $schema = $this->schemaMapper->find($schemaId);
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to load schema for facetable fields generation',
                    [
                        'schemaId' => $schemaId,
                        'error'    => $e->getMessage(),
                    ]
                    );
            return [];
        }

        $facetableFields = [
            '@self'         => $this->getMetadataFacetableFields(),
            'object_fields' => [],
        ];

        // Analyze schema properties for facetable fields.
        $properties = $schema->getProperties();
        if (is_array($properties)) {
            foreach ($properties as $propertyName => $property) {
                if ($this->isPropertyFacetable($property)) {
                    $fieldConfig = $this->generateFieldConfigFromProperty($propertyName, $property);
                    if ($fieldConfig !== null) {
                        $facetableFields['object_fields'][$propertyName] = $fieldConfig;
                    }
                }
            }
        }

        $this->logger->debug(
                'Generated facetable fields from schema',
                [
                    'schemaId'       => $schemaId,
                    'metadataFields' => count($facetableFields['@self']),
                    'objectFields'   => count($facetableFields['object_fields']),
                ]
                );

        return $facetableFields;

    }//end generateFacetableFieldsFromSchema()


    /**
     * Get metadata facetable fields (always available)
     *
     * @return array<string, mixed> Metadata facetable fields
     */
    private function getMetadataFacetableFields(): array
    {
        return [
            'register'     => [
                'type'        => 'integer',
                'facet_types' => [self::FACET_TYPE_TERMS],
                'description' => 'Register ID',
            ],
            'schema'       => [
                'type'        => 'integer',
                'facet_types' => [self::FACET_TYPE_TERMS],
                'description' => 'Schema ID',
            ],
            'organisation' => [
                'type'        => 'string',
                'facet_types' => [self::FACET_TYPE_TERMS],
                'description' => 'Organisation UUID',
            ],
            'owner'        => [
                'type'        => 'string',
                'facet_types' => [self::FACET_TYPE_TERMS],
                'description' => 'Owner user ID',
            ],
            'created'      => [
                'type'        => 'datetime',
                'facet_types' => [self::FACET_TYPE_DATE_HISTOGRAM, self::FACET_TYPE_RANGE],
                'description' => 'Creation date',
            ],
            'updated'      => [
                'type'        => 'datetime',
                'facet_types' => [self::FACET_TYPE_DATE_HISTOGRAM, self::FACET_TYPE_RANGE],
                'description' => 'Last update date',
            ],
            'published'    => [
                'type'        => 'datetime',
                'facet_types' => [self::FACET_TYPE_DATE_HISTOGRAM, self::FACET_TYPE_RANGE],
                'description' => 'Publication date',
            ],
            'depublished'  => [
                'type'        => 'datetime',
                'facet_types' => [self::FACET_TYPE_DATE_HISTOGRAM, self::FACET_TYPE_RANGE],
                'description' => 'Depublication date',
            ],
        ];

    }//end getMetadataFacetableFields()


    /**
     * Check if a property is facetable based on its definition
     *
     * @param array $property Property definition
     *
     * @return bool True if property is facetable
     */
    private function isPropertyFacetable(array $property): bool
    {
        // Check for explicit facetable flag.
        if (isset($property['facetable']) && $property['facetable'] === true) {
            return true;
        }

        // Auto-detect facetable properties based on type.
        $type   = $property['type'] ?? '';
        $format = $property['format'] ?? '';

        // Facetable types.
        $facetableTypes = ['string', 'integer', 'number', 'boolean', 'date', 'datetime'];

        if (in_array($type, $facetableTypes)) {
            return true;
        }

        // Check for enum properties (always facetable).
        if (isset($property['enum']) && is_array($property['enum'])) {
            return true;
        }

        return false;

    }//end isPropertyFacetable()


    /**
     * Generate field configuration from property definition
     *
     * @param string $propertyKey Property name
     * @param array  $property    Property definition
     *
     * @return array|null Field configuration or null if not facetable
     */
    private function generateFieldConfigFromProperty(string $propertyKey, array $property): ?array
    {
        $type   = $property['type'] ?? 'string';
        $format = $property['format'] ?? '';

        $config = [
            'type'        => $type,
            'facet_types' => $this->determineFacetTypesFromProperty($type, $format),
            'description' => $property['description'] ?? "Facet for {$propertyKey}",
        ];

        // Add enum values if available.
        if (isset($property['enum']) && is_array($property['enum'])) {
            $config['enum_values'] = $property['enum'];
        }

        // Add range information for numeric types.
        if (in_array($type, ['integer', 'number'])) {
            if (isset($property['minimum'])) {
                $config['minimum'] = $property['minimum'];
            }

            if (isset($property['maximum'])) {
                $config['maximum'] = $property['maximum'];
            }
        }

        return $config;

    }//end generateFieldConfigFromProperty()


    /**
     * Determine appropriate facet types for a property
     *
     * @param string $type   Property type
     * @param string $format Property format
     *
     * @return array<string> Array of supported facet types
     */
    private function determineFacetTypesFromProperty(string $type, string $format): array
    {
        switch ($type) {
            case 'string':
                return [self::FACET_TYPE_TERMS];

            case 'integer':
            case 'number':
                return [self::FACET_TYPE_TERMS, self::FACET_TYPE_RANGE];

            case 'boolean':
                return [self::FACET_TYPE_TERMS];

            case 'date':
            case 'datetime':
                return [self::FACET_TYPE_DATE_HISTOGRAM, self::FACET_TYPE_RANGE];

            default:
                return [self::FACET_TYPE_TERMS];
        }

    }//end determineFacetTypesFromProperty()


    /**
     * Build cache key for facet results
     *
     * @param string $facetType   Facet type
     * @param string $fieldName   Field name
     * @param array  $facetConfig Facet configuration
     *
     * @return string Cache key
     */
    private function buildFacetCacheKey(string $facetType, string $fieldName, array $facetConfig): string
    {
        $configHash = md5(json_encode($facetConfig));
        return "facet_{$facetType}_{$fieldName}_{$configHash}";

    }//end buildFacetCacheKey()


    /**
     * Get cached facet data from database
     *
     * @param int    $schemaId Schema ID
     * @param string $cacheKey Cache key
     *
     * @return mixed|null Cached data or null if not found/expired
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    private function getCachedFacetData(int $schemaId, string $cacheKey): mixed
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('cache_data', 'expires')
            ->from(self::FACET_CACHE_TABLE)
            ->where($qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId)))
            ->andWhere($qb->expr()->eq('field_name', $qb->createNamedParameter($cacheKey)));

        $result = $qb->executeQuery()->fetch();
        if ($result === false || $result === null) {
            return null;
        }

        // Check if expired.
        if ($result['expires'] !== null) {
            $expires = new \DateTime($result['expires']);
            if ($expires <= new \DateTime()) {
                // Cache expired, remove it.
                $this->removeCachedFacetData($schemaId, $cacheKey);
                return null;
            }
        }

        return json_decode($result['cache_data'], true);

    }//end getCachedFacetData()


    /**
     * Set cached facet data in database
     *
     * @param int    $schemaId    Schema ID
     * @param string $cacheKey    Cache key
     * @param string $facetType   Facet type
     * @param string $fieldName   Field name
     * @param array  $facetConfig Facet configuration
     * @param mixed  $data        Data to cache
     * @param int    $ttl         Cache TTL in seconds
     *
     * @return void
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    private function setCachedFacetData(
        int $schemaId,
        string $cacheKey,
        string $facetType,
        string $fieldName,
        array $facetConfig,
        mixed $data,
        int $ttl
    ): void {
        // Enforce maximum cache TTL for office environments.
        $ttl = min($ttl, self::MAX_CACHE_TTL);

        $now     = new \DateTime();
        $expires = $ttl > 0 ? (clone $now)->add(new \DateInterval("PT{$ttl}S")) : null;

        // Use INSERT ... ON DUPLICATE KEY UPDATE pattern.
        $qb = $this->db->getQueryBuilder();

        // Try update first.
        $qb->update(self::FACET_CACHE_TABLE)
            ->set('cache_data', $qb->createNamedParameter(json_encode($data)))
            ->set('updated', $qb->createNamedParameter($now, 'datetime'))
            ->set('expires', $qb->createNamedParameter($expires, 'datetime'))
            ->where($qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId)))
            ->andWhere($qb->expr()->eq('field_name', $qb->createNamedParameter($cacheKey)));

        $updated = $qb->executeStatement();

        // If no rows updated, insert new record.
        if ($updated === 0) {
            $qb = $this->db->getQueryBuilder();
            $qb->insert(self::FACET_CACHE_TABLE)
                ->values(
                       [
                           'schema_id'    => $qb->createNamedParameter($schemaId),
                           'facet_type'   => $qb->createNamedParameter($facetType),
                           'field_name'   => $qb->createNamedParameter($cacheKey),
                           'facet_config' => $qb->createNamedParameter(json_encode($facetConfig)),
                           'cache_data'   => $qb->createNamedParameter(json_encode($data)),
                           'created'      => $qb->createNamedParameter($now, 'datetime'),
                           'updated'      => $qb->createNamedParameter($now, 'datetime'),
                           'expires'      => $qb->createNamedParameter($expires, 'datetime'),
                       ]
                       );
            $qb->executeStatement();
        }

    }//end setCachedFacetData()


    /**
     * Remove cached facet data from database
     *
     * @param int    $schemaId Schema ID
     * @param string $cacheKey Cache key
     *
     * @return void
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    private function removeCachedFacetData(int $schemaId, string $cacheKey): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::FACET_CACHE_TABLE)
            ->where($qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId)))
            ->andWhere($qb->expr()->eq('field_name', $qb->createNamedParameter($cacheKey)));
        $qb->executeStatement();

    }//end removeCachedFacetData()


}//end class
