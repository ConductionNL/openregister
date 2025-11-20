<?php

/**
 * OpenRegister Schema Cache Service
 *
 * Service class for caching schema data to improve performance.
 *
 * This service provides high-performance caching for schema objects and their
 * computed properties like facetable fields, validation rules, and configuration.
 * It automatically invalidates cache when schemas are updated.
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
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * Schema Cache Service for improved performance
 *
 * This service provides comprehensive caching for schema-related data:
 * - Schema objects and their properties
 * - Computed facetable fields
 * - Validation configurations
 * - Schema relationships and dependencies
 *
 * CACHE INVALIDATION STRATEGY:
 * - Automatic invalidation when schemas are updated
 * - TTL-based expiration for stale data prevention
 * - Manual cache clearing for administrative operations
 *
 * PERFORMANCE BENEFITS:
 * - Reduces database queries for frequently accessed schemas
 * - Eliminates repeated computation of facetable fields
 * - Improves search and faceting response times
 * - Enables predictable facet caching based on schema structure
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
class SchemaCacheService
{

    /**
     * Cache table name for schema data
     */
    private const CACHE_TABLE = 'openregister_schema_cache';

    /**
     * Default cache TTL in seconds (1 hour)
     */
    private const DEFAULT_TTL = 3600;

    /**
     * Maximum cache TTL for office environments (8 hours in seconds)
     *
     * This prevents indefinite cache buildup while maintaining performance
     * during business hours.
     */
    private const MAX_CACHE_TTL = 28800;

    /**
     * Cache keys for different types of cached data
     */
    private const CACHE_KEY_SCHEMA           = 'schema_object';
    private const CACHE_KEY_FACETABLE_FIELDS = 'facetable_fields';
    private const CACHE_KEY_CONFIGURATION    = 'configuration';
    private const CACHE_KEY_PROPERTIES       = 'properties';

    /**
     * In-memory cache for frequently accessed data
     *
     * @var array<string, mixed>
     */
    private static array $memoryCache = [];

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
     * Get cached schema object by ID
     *
     * This method provides high-performance schema loading with automatic caching.
     * It first checks the in-memory cache, then the database cache, and finally
     * loads from the database if not cached.
     *
     * @param int $schemaId The schema ID to retrieve
     *
     * @return Schema|null The cached schema object or null if not found
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function getSchema(int $schemaId): ?Schema
    {
        $cacheKey = $this->buildCacheKey($schemaId, self::CACHE_KEY_SCHEMA);

        // Check in-memory cache first.
        if (isset(self::$memoryCache[$cacheKey])) {
            $this->logger->debug('Schema cache hit (memory)', ['schemaId' => $schemaId]);
            return self::$memoryCache[$cacheKey];
        }

        // Check database cache.
        $cachedData = $this->getCachedData($schemaId, self::CACHE_KEY_SCHEMA);
        if ($cachedData !== null) {
            // Reconstruct schema object from cached data.
            $schema = $this->reconstructSchemaFromCache($cachedData);
            if ($schema !== null) {
                // Store in memory cache for future requests.
                self::$memoryCache[$cacheKey] = $schema;
                $this->logger->debug('Schema cache hit (database)', ['schemaId' => $schemaId]);
                return $schema;
            }
        }

        // Load from database and cache.
        try {
            $schema = $this->schemaMapper->find($schemaId);
            $this->cacheSchema($schema);
            $this->logger->debug('Schema loaded from database and cached', ['schemaId' => $schemaId]);
            return $schema;
        } catch (DoesNotExistException $e) {
            return null;
        }

    }//end getSchema()


    /**
     * Clear cache for a specific schema
     *
     * Removes cached data for a schema from both in-memory and database cache.
     * This is useful when schemas are updated and cache needs to be invalidated.
     *
     * @param int $schemaId The schema ID to remove from cache
     *
     * @return void
     */
    public function clearSchemaCache(int $schemaId): void
    {
        // Clear from in-memory cache.
        foreach (self::$memoryCache as $key => $value) {
            if (strpos($key, 'schema_'.$schemaId) !== false) {
                unset(self::$memoryCache[$key]);
            }
        }

        // Clear from database cache.
        $sql = 'DELETE FROM '.self::CACHE_TABLE.' WHERE schema_id = ?';
        try {
            $this->db->executeQuery($sql, [$schemaId]);
            $this->logger->debug('Cleared schema cache', ['schemaId' => $schemaId]);
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to clear schema cache',
                    [
                        'schemaId' => $schemaId,
                        'error'    => $e->getMessage(),
                    ]
                    );
        }

    }//end clearSchemaCache()


    /**
     * Get multiple schemas with batch caching optimization
     *
     * @param array<int> $schemaIds Array of schema IDs to retrieve
     *
     * @return array<int, Schema> Array of schemas indexed by ID
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function getSchemas(array $schemaIds): array
    {
        if (empty($schemaIds)) {
            return [];
        }

        $schemas     = [];
        $uncachedIds = [];

        // Check cache for each schema.
        foreach ($schemaIds as $schemaId) {
            $schema = $this->getSchema($schemaId);
            if ($schema !== null) {
                $schemas[$schemaId] = $schema;
            } else {
                $uncachedIds[] = $schemaId;
            }
        }

        // Batch load uncached schemas.
        if (!empty($uncachedIds)) {
            $loadedSchemas = $this->schemaMapper->findMultiple($uncachedIds);
            foreach ($loadedSchemas as $schema) {
                $schemas[$schema->getId()] = $schema;
                $this->cacheSchema($schema);
            }
        }

        return $schemas;

    }//end getSchemas()


    /**
     * Get cached facetable fields for a schema
     *
     * This method returns the cached facetable field configuration for a schema,
     * which is computed from the schema properties and cached for performance.
     *
     * @param int $schemaId The schema ID
     *
     * @return array|null The cached facetable fields or null if not cached
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function getFacetableFields(int $schemaId): ?array
    {
        $cacheKey = $this->buildCacheKey($schemaId, self::CACHE_KEY_FACETABLE_FIELDS);

        // Check in-memory cache first.
        if (isset(self::$memoryCache[$cacheKey])) {
            return self::$memoryCache[$cacheKey];
        }

        // Check database cache.
        $cachedData = $this->getCachedData($schemaId, self::CACHE_KEY_FACETABLE_FIELDS);
        if ($cachedData !== null) {
            // Store in memory cache.
            self::$memoryCache[$cacheKey] = $cachedData;
            return $cachedData;
        }

        return null;

    }//end getFacetableFields()


    /**
     * Cache facetable fields for a schema
     *
     * @param int   $schemaId        The schema ID
     * @param array $facetableFields The facetable fields to cache
     * @param int   $ttl             Cache TTL in seconds
     *
     * @return void
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function cacheFacetableFields(int $schemaId, array $facetableFields, int $ttl=self::DEFAULT_TTL): void
    {
        $this->setCachedData($schemaId, self::CACHE_KEY_FACETABLE_FIELDS, $facetableFields, $ttl);

        // Also store in memory cache.
        $cacheKey = $this->buildCacheKey($schemaId, self::CACHE_KEY_FACETABLE_FIELDS);
        self::$memoryCache[$cacheKey] = $facetableFields;

    }//end cacheFacetableFields()


    /**
     * Cache a schema object
     *
     * @param Schema $schema The schema to cache
     * @param int    $ttl    Cache TTL in seconds
     *
     * @return void
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function cacheSchema(Schema $schema, int $ttl=self::DEFAULT_TTL): void
    {
        $schemaId   = $schema->getId();
        $schemaData = $this->serializeSchemaForCache($schema);

        $this->setCachedData($schemaId, self::CACHE_KEY_SCHEMA, $schemaData, $ttl);

        // Store in memory cache.
        $cacheKey = $this->buildCacheKey($schemaId, self::CACHE_KEY_SCHEMA);
        self::$memoryCache[$cacheKey] = $schema;

        // Also cache computed properties.
        $this->cacheSchemaConfiguration($schema, $ttl);
        $this->cacheSchemaProperties($schema, $ttl);

    }//end cacheSchema()


    /**
     * Cache schema configuration
     *
     * @param Schema $schema The schema object
     * @param int    $ttl    Cache TTL in seconds
     *
     * @return void
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function cacheSchemaConfiguration(Schema $schema, int $ttl=self::DEFAULT_TTL): void
    {
        $configuration = $schema->getConfiguration();
        $this->setCachedData($schema->getId(), self::CACHE_KEY_CONFIGURATION, $configuration, $ttl);

    }//end cacheSchemaConfiguration()


    /**
     * Cache schema properties
     *
     * @param Schema $schema The schema object
     * @param int    $ttl    Cache TTL in seconds
     *
     * @return void
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function cacheSchemaProperties(Schema $schema, int $ttl=self::DEFAULT_TTL): void
    {
        $properties = $schema->getProperties();
        $this->setCachedData($schema->getId(), self::CACHE_KEY_PROPERTIES, $properties, $ttl);

    }//end cacheSchemaProperties()


    /**
     * Invalidate cache for a specific schema
     *
     * **SCHEMA CACHE INVALIDATION**: Called when schemas are created, updated,
     * or deleted to ensure cache consistency.
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
        $startTime      = microtime(true);
        $deletedEntries = 0;

        // Remove from database cache (if table exists).
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete(self::CACHE_TABLE)
                ->where($qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId)));
            $deletedEntries = $qb->executeStatement();
        } catch (\Exception $e) {
            // If the cache table doesn't exist yet, just log a debug message and continue.
            // This allows the app to work even if the migration hasn't been run yet.
            $this->logger->debug(
                    'Schema cache table does not exist yet, skipping database cache invalidation',
                    [
                        'schemaId' => $schemaId,
                        'error'    => $e->getMessage(),
                    ]
                    );
        }

        // Remove from memory cache (always safe to do).
        $cacheKeys = [
            $this->buildCacheKey($schemaId, self::CACHE_KEY_SCHEMA),
            $this->buildCacheKey($schemaId, self::CACHE_KEY_FACETABLE_FIELDS),
            $this->buildCacheKey($schemaId, self::CACHE_KEY_CONFIGURATION),
            $this->buildCacheKey($schemaId, self::CACHE_KEY_PROPERTIES),
        ];

        foreach ($cacheKeys as $key) {
            unset(self::$memoryCache[$key]);
        }

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        $this->logger->info(
                'Schema cache invalidated',
                [
                    'schemaId'       => $schemaId,
                    'operation'      => $operation,
                    'deletedEntries' => $deletedEntries,
                    'executionTime'  => $executionTime.'ms',
                ]
                );

    }//end invalidateForSchemaChange()


    /**
     * Invalidate cache for a specific schema (legacy method)
     *
     * @deprecated Use invalidateForSchemaChange() instead
     * @param      int $schemaId The schema ID to invalidate
     * @return     void
     * @throws     \OCP\DB\Exception If a database error occurs
     */
    public function invalidateSchema(int $schemaId): void
    {
        $this->invalidateForSchemaChange($schemaId, 'update');

    }//end invalidateSchema()


    /**
     * Clear all schema caches (Administrative Operation)
     *
     * **NUCLEAR OPTION**: This method clears both database and memory caches for all schemas.
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
        $qb->delete(self::CACHE_TABLE);
        $deletedEntries = $qb->executeStatement();

        // Clear memory cache.
        $memoryCacheSize   = count(self::$memoryCache);
        self::$memoryCache = [];

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        $this->logger->info(
                'All schema caches cleared',
                [
                    'deletedDbEntries'     => $deletedEntries,
                    'clearedMemoryEntries' => $memoryCacheSize,
                    'executionTime'        => $executionTime.'ms',
                ]
                );

    }//end clearAllCaches()


    /**
     * Clear all schema cache (legacy method)
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
     * Clean expired cache entries
     *
     * This method removes expired cache entries from the database.
     * Should be called periodically via cron job.
     *
     * @return int Number of expired entries removed
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    public function cleanExpiredEntries(): int
    {
        $startTime = microtime(true);

        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::CACHE_TABLE)
            ->where($qb->expr()->isNotNull('expires'))
            ->andWhere($qb->expr()->lt('expires', $qb->createNamedParameter(new \DateTime(), 'datetime')));

        $deletedCount = $qb->executeStatement();

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        if ($deletedCount > 0) {
            $this->logger->info(
                    'Cleaned expired schema cache entries',
                    [
                        'count'         => $deletedCount,
                        'executionTime' => $executionTime.'ms',
                    ]
                    );
        }

        return $deletedCount;

    }//end cleanExpiredEntries()


    /**
     * Clean expired cache entries (legacy method)
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
     * Get comprehensive cache statistics
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
            ->addSelect($qb->func()->count('expires', 'entries_with_ttl'))
            ->from(self::CACHE_TABLE);

        $result = $qb->executeQuery()->fetch();

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        return [
            'total_entries'     => (int) $result['total_entries'],
            'entries_with_ttl'  => (int) $result['entries_with_ttl'],
            'memory_cache_size' => count(self::$memoryCache),
            'cache_table'       => self::CACHE_TABLE,
            'query_time'        => $executionTime.'ms',
            'timestamp'         => time(),
        ];

    }//end getCacheStatistics()


    /**
     * Get cache statistics (legacy method)
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
     * Build cache key for a schema and cache type
     *
     * @param int    $schemaId The schema ID
     * @param string $cacheKey The cache key type
     *
     * @return string The full cache key
     */
    private function buildCacheKey(int $schemaId, string $cacheKey): string
    {
        return "schema_{$schemaId}_{$cacheKey}";

    }//end buildCacheKey()


    /**
     * Get cached data from database
     *
     * @param int    $schemaId The schema ID
     * @param string $cacheKey The cache key type
     *
     * @return mixed|null The cached data or null if not found/expired
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    private function getCachedData(int $schemaId, string $cacheKey): mixed
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('cache_data', 'expires')
            ->from(self::CACHE_TABLE)
            ->where($qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId)))
            ->andWhere($qb->expr()->eq('cache_key', $qb->createNamedParameter($cacheKey)));

        $result = $qb->executeQuery()->fetch();
        if ($result === false || $result === null) {
            return null;
        }

        // Check if expired.
        if ($result['expires'] !== null) {
            $expires = new \DateTime($result['expires']);
            if ($expires <= new \DateTime()) {
                // Cache expired, remove it.
                $this->removeCachedData($schemaId, $cacheKey);
                return null;
            }
        }

        return json_decode($result['cache_data'], true);

    }//end getCachedData()


    /**
     * Set cached data in database
     *
     * @param int    $schemaId The schema ID
     * @param string $cacheKey The cache key type
     * @param mixed  $data     The data to cache
     * @param int    $ttl      Cache TTL in seconds
     *
     * @return void
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    private function setCachedData(int $schemaId, string $cacheKey, mixed $data, int $ttl): void
    {
        // Enforce maximum cache TTL for office environments.
        $ttl = min($ttl, self::MAX_CACHE_TTL);

        $now     = new \DateTime();
        $expires = $ttl > 0 ? (clone $now)->add(new \DateInterval("PT{$ttl}S")) : null;

        // Use INSERT ... ON DUPLICATE KEY UPDATE for MySQL/MariaDB compatibility.
        $qb = $this->db->getQueryBuilder();

        // First, try to update existing record.
        $qb->update(self::CACHE_TABLE)
            ->set('cache_data', $qb->createNamedParameter(json_encode($data)))
            ->set('updated', $qb->createNamedParameter($now, 'datetime'))
            ->set('expires', $qb->createNamedParameter($expires, 'datetime'))
            ->where($qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId)))
            ->andWhere($qb->expr()->eq('cache_key', $qb->createNamedParameter($cacheKey)));

        $updated = $qb->executeStatement();

        // If no rows updated, insert new record.
        if ($updated === 0) {
            $qb = $this->db->getQueryBuilder();
            $qb->insert(self::CACHE_TABLE)
                ->values(
                       [
                           'schema_id'  => $qb->createNamedParameter($schemaId),
                           'cache_key'  => $qb->createNamedParameter($cacheKey),
                           'cache_data' => $qb->createNamedParameter(json_encode($data)),
                           'created'    => $qb->createNamedParameter($now, 'datetime'),
                           'updated'    => $qb->createNamedParameter($now, 'datetime'),
                           'expires'    => $qb->createNamedParameter($expires, 'datetime'),
                       ]
                       );
            $qb->executeStatement();
        }

    }//end setCachedData()


    /**
     * Remove cached data from database
     *
     * @param int    $schemaId The schema ID
     * @param string $cacheKey The cache key type
     *
     * @return void
     *
     * @throws \OCP\DB\Exception If a database error occurs
     */
    private function removeCachedData(int $schemaId, string $cacheKey): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::CACHE_TABLE)
            ->where($qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId)))
            ->andWhere($qb->expr()->eq('cache_key', $qb->createNamedParameter($cacheKey)));
        $qb->executeStatement();

    }//end removeCachedData()


    /**
     * Serialize schema object for caching
     *
     * @param Schema $schema The schema to serialize
     *
     * @return array<string, mixed> Serialized schema data
     */
    private function serializeSchemaForCache(Schema $schema): array
    {
        return [
            'id'            => $schema->getId(),
            'uuid'          => $schema->getUuid(),
            'title'         => $schema->getTitle(),
            'version'       => $schema->getVersion(),
            'description'   => $schema->getDescription(),
            'summary'       => $schema->getSummary(),
            'tags'          => $schema->getTags(),
            'required'      => $schema->getRequired(),
            'properties'    => $schema->getProperties(),
            'archive'       => $schema->getArchive(),
            'configuration' => $schema->getConfiguration(),
            'source'        => $schema->getSource(),
            'register'      => $schema->getRegister(),
            'organisation'  => $schema->getOrganisation(),
            'owner'         => $schema->getOwner(),
            'created'       => $schema->getCreated()?->format('Y-m-d H:i:s'),
            'updated'       => $schema->getUpdated()?->format('Y-m-d H:i:s'),
        ];

    }//end serializeSchemaForCache()


    /**
     * Reconstruct schema object from cached data
     *
     * @param array<string, mixed> $cachedData The cached schema data
     *
     * @return Schema|null The reconstructed schema or null if reconstruction fails
     */
    private function reconstructSchemaFromCache(array $cachedData): ?Schema
    {
        try {
            $schema = new Schema();
            $schema->setId($cachedData['id']);
            $schema->setUuid($cachedData['uuid']);
            $schema->setTitle($cachedData['title']);
            $schema->setVersion($cachedData['version']);
            $schema->setDescription($cachedData['description']);
            $schema->setSummary($cachedData['summary']);
            $schema->setTags($cachedData['tags']);
            $schema->setRequired($cachedData['required']);
            $schema->setProperties($cachedData['properties']);
            $schema->setArchive($cachedData['archive']);
            $schema->setConfiguration($cachedData['configuration']);
            $schema->setSource($cachedData['source']);
            $schema->setRegister($cachedData['register']);
            $schema->setOrganisation($cachedData['organisation']);
            $schema->setOwner($cachedData['owner']);

            if ($cachedData['created']) {
                $schema->setCreated(new \DateTime($cachedData['created']));
            }

            if ($cachedData['updated']) {
                $schema->setUpdated(new \DateTime($cachedData['updated']));
            }

            return $schema;
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to reconstruct schema from cache',
                    [
                        'schemaId' => $cachedData['id'] ?? 'unknown',
                        'error'    => $e->getMessage(),
                    ]
                    );
            return null;
        }//end try

    }//end reconstructSchemaFromCache()


}//end class
