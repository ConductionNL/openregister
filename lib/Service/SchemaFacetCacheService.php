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
     *
     * Database table used for persistent facet caching.
     *
     * @var string Facet cache table name
     */
    private const FACET_CACHE_TABLE = 'openregister_schema_facet_cache';

    /**
     * Default cache TTL in seconds
     *
     * Default time-to-live for cached facet data (30 minutes).
     * Facets are cached for shorter periods than schemas due to data volatility.
     *
     * @var int Default facet cache TTL in seconds (1800 = 30 minutes)
     */
    private const DEFAULT_FACET_TTL = 1800;

    /**
     * Maximum cache TTL for office environments
     *
     * Maximum time-to-live for cached facet data (8 hours in seconds).
     * This prevents indefinite cache buildup while maintaining performance
     * during business hours.
     *
     * @var int Maximum cache TTL in seconds (28800 = 8 hours)
     */
    private const MAX_CACHE_TTL = 28800;

    /**
     * Facet type constant for terms facets
     *
     * Used for categorical/string field faceting.
     *
     * @var string Facet type identifier
     */
    private const FACET_TYPE_TERMS = 'terms';

    /**
     * Facet type constant for date histogram facets
     *
     * Used for date/time field faceting with time buckets.
     *
     * @var string Facet type identifier
     */
    private const FACET_TYPE_DATE_HISTOGRAM = 'date_histogram';

    /**
     * Facet type constant for range facets
     *
     * Used for numeric range faceting.
     *
     * @var string Facet type identifier
     */
    private const FACET_TYPE_RANGE = 'range';

    /**
     * In-memory cache for facet configurations
     *
     * Static array cache for ultra-fast access to facet configurations.
     * Shared across all instances of this service.
     *
     * @var array<string, mixed> In-memory facet configuration cache
     */
    private static array $facetConfigCache = [];

    /**
     * Database connection
     *
     * Used for persistent facet cache storage and retrieval.
     *
     * @var IDBConnection Database connection instance
     */
    private readonly IDBConnection $db;

    /**
     * Schema mapper for database operations
     *
     * Used to load schemas when computing facet configurations.
     *
     * @var SchemaMapper Schema mapper instance
     */
    private readonly SchemaMapper $schemaMapper;

    /**
     * Logger for performance monitoring
     *
     * Used for logging cache operations and performance metrics.
     *
     * @var LoggerInterface Logger instance
     */
    private readonly LoggerInterface $logger;


    /**
     * Constructor
     *
     * Initializes service with database connection, schema mapper, and logger
     * for facet caching operations.
     *
     * @param IDBConnection   $db           Database connection for persistent facet cache
     * @param SchemaMapper    $schemaMapper Schema mapper for loading schemas
     * @param LoggerInterface $logger       Logger for performance monitoring and debugging
     *
     * @return void
     */
    public function __construct(
        IDBConnection $db,
        SchemaMapper $schemaMapper,
        LoggerInterface $logger
    ) {
        // Store dependencies for use in service methods.
        $this->db           = $db;
        $this->schemaMapper = $schemaMapper;
        $this->logger       = $logger;

    }//end __construct()


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
        $this->setCachedFacetData(schemaId: $schemaId, cacheKey: 'facetable_fields', facetType: 'config', fieldName: 'facetable_fields', facetConfig: [], data: $facetableFields, ttl: $ttl);

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
            if (str_contains($key, "_{$schemaId}") === true) {
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
     * Clean expired facet cache entries
     *
     * @return int Number of expired entries removed
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @psalm-return int<min, max>
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
     * Get comprehensive facet cache statistics
     *
     * @return (int|int[]|string)[] Cache statistics including performance metrics
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @psalm-return array{
     *     total_entries: int,
     *     by_type: array<int>,
     *     memory_cache_size: int<0, max>,
     *     cache_table: 'openregister_schema_facet_cache',
     *     query_time: string,
     *     timestamp: int<1, max>
     * }
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
     * Generate facetable fields configuration from schema properties
     *
     * @param int $schemaId The schema ID
     *
     * @return (array|mixed)[][] Facetable fields configuration
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @psalm-return array{'@self'?: array<string, mixed>, object_fields?: array<array>}
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
        if (is_array($properties) === true) {
            foreach ($properties as $propertyName => $property) {
                if ($this->isPropertyFacetable($property) === true) {
                    $fieldConfig = $this->generateFieldConfigFromProperty(propertyKey: $propertyName, property: $property);
                    $facetableFields['object_fields'][$propertyName] = $fieldConfig;
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
     * @return (string|string[])[][] Metadata facetable fields
     *
     * @psalm-return array{
     *     register: array{
     *         type: 'integer',
     *         facet_types: list{'terms'},
     *         description: 'Register ID'
     *     },
     *     schema: array{
     *         type: 'integer',
     *         facet_types: list{'terms'},
     *         description: 'Schema ID'
     *     },
     *     organisation: array{
     *         type: 'string',
     *         facet_types: list{'terms'},
     *         description: 'Organisation UUID'
     *     },
     *     owner: array{
     *         type: 'string',
     *         facet_types: list{'terms'},
     *         description: 'Owner user ID'
     *     },
     *     created: array{
     *         type: 'datetime',
     *         facet_types: list{'date_histogram', 'range'},
     *         description: 'Creation date'
     *     },
     *     updated: array{
     *         type: 'datetime',
     *         facet_types: list{'date_histogram', 'range'},
     *         description: 'Last update date'
     *     },
     *     published: array{
     *         type: 'datetime',
     *         facet_types: list{'date_histogram', 'range'},
     *         description: 'Publication date'
     *     },
     *     depublished: array{
     *         type: 'datetime',
     *         facet_types: list{'date_histogram', 'range'},
     *         description: 'Depublication date'
     *     }
     * }
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
        if (($property['facetable'] ?? null) !== null && ($property['facetable'] === true) === true) {
            return true;
        }

        // Auto-detect facetable properties based on type.
        $type = $property['type'] ?? '';
        $property['format'] ?? '';

        // Facetable types.
        $facetableTypes = ['string', 'integer', 'number', 'boolean', 'date', 'datetime'];

        if (in_array($type, $facetableTypes) === true) {
            return true;
        }

        // Check for enum properties (always facetable).
        if (($property['enum'] ?? null) !== null && is_array($property['enum']) === true) {
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
     * @return ((mixed|string)[]|mixed|string)[] Field configuration or null if not facetable
     *
     * @psalm-return array{
     *     type: 'string'|mixed,
     *     facet_types: array<string>,
     *     description: mixed|string,
     *     enum_values?: array,
     *     minimum?: mixed,
     *     maximum?: mixed
     * }
     */
    private function generateFieldConfigFromProperty(string $propertyKey, array $property): array
    {
        $type   = $property['type'] ?? 'string';
        $format = $property['format'] ?? '';

        $config = [
            'type'        => $type,
            'facet_types' => $this->determineFacetTypesFromProperty(type: $type, _format: $format),
            'description' => $property['description'] ?? "Facet for {$propertyKey}",
        ];

        // Add enum values if available.
        if (($property['enum'] ?? null) !== null && is_array($property['enum']) === true) {
            $config['enum_values'] = $property['enum'];
        }

        // Add range information for numeric types.
        if (in_array($type, ['integer', 'number']) === true) {
            if (($property['minimum'] ?? null) !== null) {
                $config['minimum'] = $property['minimum'];
            }

            if (($property['maximum'] ?? null) !== null) {
                $config['maximum'] = $property['maximum'];
            }
        }

        return $config;

    }//end generateFieldConfigFromProperty()


    /**
     * Determine appropriate facet types for a property
     *
     * @param string $type    Property type
     * @param string $_format Property format (unused, kept for API compatibility)
     *
     * @return string[] Array of supported facet types
     *
     * @psalm-return list{0: 'date_histogram'|'terms', 1?: 'range'}
     */
    private function determineFacetTypesFromProperty(string $type, string $_format): array
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
                $this->removeCachedFacetData(schemaId: $schemaId, cacheKey: $cacheKey);
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

        $now = new \DateTime();
        if ($ttl > 0) {
            $expires = (clone $now)->add(new \DateInterval("PT{$ttl}S"));
        } else {
            $expires = null;
        }

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
                ->values(values: [
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
