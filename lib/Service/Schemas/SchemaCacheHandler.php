<?php

/**
 * OpenRegister Schema Cache Handler
 *
 * Handler class for caching schema data to improve performance.
 *
 * This handler provides high-performance caching for schema objects and their
 * computed properties like facetable fields, validation rules, and configuration.
 * It automatically invalidates cache when schemas are updated.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\Schemas;

use DateInterval;
use DateTime;
use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Schema Cache Handler for improved performance
 *
 * This handler provides comprehensive caching for schema-related data:
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
 * @package  OCA\OpenRegister\Service\Schemas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */
class SchemaCacheHandler implements SchemaCacheHandlerInterface {
	/**
	 * Cache table name for schema data
	 *
	 * Database table used for persistent schema caching.
	 *
	 * @var string Cache table name
	 */
	private const CACHE_TABLE = 'openregister_schema_cache';

	/**
	 * Default cache TTL in seconds
	 *
	 * Default time-to-live for cached schema data (1 hour).
	 *
	 * @var int Default cache TTL in seconds (3600 = 1 hour)
	 */
	private const DEFAULT_TTL = 3600;

	/**
	 * Maximum cache TTL for office environments
	 *
	 * Maximum time-to-live for cached schema data (8 hours in seconds).
	 * This prevents indefinite cache buildup while maintaining performance
	 * during business hours.
	 *
	 * @var int Maximum cache TTL in seconds (28800 = 8 hours)
	 */
	private const MAX_CACHE_TTL = 28800;

	/**
	 * Cache key for schema object data
	 *
	 * @var string Cache key identifier
	 */
	private const CACHE_KEY_SCHEMA = 'schema_object';

	/**
	 * Cache key for facetable fields configuration
	 *
	 * @var string Cache key identifier
	 */
	private const CACHE_KEY_FACETABLE_FIELDS = 'facetable_fields';

	/**
	 * Cache key for schema configuration
	 *
	 * @var string Cache key identifier
	 */
	private const CACHE_KEY_CONFIGURATION = 'configuration';

	/**
	 * Cache key for schema properties
	 *
	 * @var string Cache key identifier
	 */
	private const CACHE_KEY_PROPERTIES = 'properties';

	/**
	 * In-memory cache for frequently accessed data
	 *
	 * Static array cache for ultra-fast access to frequently used schema data.
	 * Shared across all instances of this handler.
	 *
	 * @var array<string, mixed> In-memory cache array
	 */
	private static array $memoryCache = [];

	/**
	 * Constructor
	 *
	 * Initializes handler with database connection, schema mapper, and logger
	 * for schema caching operations.
	 *
	 * @param IDBConnection         $db           Database connection for persistent cache storage and retrieval
	 * @param SchemaMapperInterface $schemaMapper Schema mapper for loading schemas on cache miss
	 * @param LoggerInterface       $logger       Logger for cache hits, misses and performance metrics
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
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
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getSchema(int $schemaId): ?Schema {
		$cacheKey = $this->buildCacheKey(schemaId: $schemaId, cacheKey: self::CACHE_KEY_SCHEMA);

		// Check in-memory cache first.
		if ((self::$memoryCache[$cacheKey] ?? null) !== null) {
			$context = $this->schemaContext(line: __LINE__, schemaId: $schemaId);
			$this->logger->debug(message: '[SchemaCacheHandler] Schema cache hit (memory)', context: $context);
			return self::$memoryCache[$cacheKey];
		}

		return ($this->schemaFromDatabaseCache(schemaId: $schemaId, cacheKey: $cacheKey) ?? $this->loadAndCacheSchema(schemaId: $schemaId));
	}//end getSchema()

	/**
	 * The schema the database cache holds, kept in memory once read.
	 *
	 * @param int    $schemaId The schema ID.
	 * @param string $cacheKey The memory cache key of the schema.
	 *
	 * @return Schema|null The cached schema, or null on a miss or an unreadable row.
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 */
	private function schemaFromDatabaseCache(int $schemaId, string $cacheKey): ?Schema {
		$cachedData = $this->getCachedData(schemaId: $schemaId, cacheKey: self::CACHE_KEY_SCHEMA);
		if ($cachedData === null) {
			return null;
		}

		$schema = $this->reconstructSchemaFromCache(cachedData: $cachedData);
		if ($schema === null) {
			return null;
		}

		// Store in memory cache for future requests.
		self::$memoryCache[$cacheKey] = $schema;
		$context = $this->schemaContext(line: __LINE__, schemaId: $schemaId);
		$this->logger->debug(message: '[SchemaCacheHandler] Schema cache hit (database)', context: $context);
		return $schema;
	}//end schemaFromDatabaseCache()

	/**
	 * Load a schema from its table and cache it.
	 *
	 * @param int $schemaId The schema ID.
	 *
	 * @return Schema|null The schema, or null when it does not exist.
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 */
	private function loadAndCacheSchema(int $schemaId): ?Schema {
		try {
			$schema = $this->schemaMapper->find($schemaId);
			$this->cacheSchema(schema: $schema);
			$context = $this->schemaContext(line: __LINE__, schemaId: $schemaId);
			$this->logger->debug(message: '[SchemaCacheHandler] Schema loaded from database and cached', context: $context);
			return $schema;
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end loadAndCacheSchema()

	/**
	 * Clear cache for a specific schema
	 *
	 * Removes cached data for a schema from both in-memory and database cache.
	 * This is useful when schemas are updated and cache needs to be invalidated.
	 *
	 * @param int $schemaId The schema ID to remove from cache
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function clearSchemaCache(int $schemaId): void {
		// Clear from in-memory cache.
		foreach (array_keys(self::$memoryCache) as $key) {
			if (strpos($key, 'schema_' . $schemaId) !== false) {
				unset(self::$memoryCache[$key]);
			}
		}

		$this->deleteSchemaRows(schemaId: $schemaId);
	}//end clearSchemaCache()

	/**
	 * Delete the database cache rows of one schema; a failure is logged, not thrown.
	 *
	 * @param int $schemaId The schema ID.
	 *
	 * @return void
	 */
	private function deleteSchemaRows(int $schemaId): void {
		// Clear from database cache.
		//
		// Built through the query builder, not raw SQL. The raw statement this
		// replaces read `DELETE FROM openregister_schema_cache ...` — an
		// UNPREFIXED table name. Nextcloud rewrites `*PREFIX*` and nothing
		// else, so that statement addressed a table called
		// `openregister_schema_cache` while the installer creates
		// `oc_openregister_schema_cache`. It could therefore never have
		// matched a row, even once the table existed. The query builder
		// prefixes for us and parameterises `schema_id` as the integer it is,
		// instead of binding it as a string.
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::CACHE_TABLE)
				->where($qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
			$context = $this->schemaContext(line: __LINE__, schemaId: $schemaId);
			$this->logger->debug(message: '[SchemaCacheHandler] Cleared schema cache', context: $context);
		} catch (Exception $e) {
			// `warning`, not `error`: this is the same cause as the catch in
			// invalidateForSchemaChange() and the two used to disagree about
			// severity for an identical failure. Neither fails the caller, so
			// neither is an error; both are a degraded cache.
			$context = $this->context(line: __LINE__, values: ['schemaId' => $schemaId, 'error' => $e->getMessage()]);
			$this->logger->warning(message: '[SchemaCacheHandler] Failed to clear schema cache', context: $context);
		}
	}//end deleteSchemaRows()

	/**
	 * Cache a schema object
	 *
	 * @param Schema $schema The schema to cache
	 * @param int $ttl Cache TTL in seconds
	 *
	 * @return void
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function cacheSchema(Schema $schema, int $ttl = self::DEFAULT_TTL): void {
		$schemaId = $schema->getId();
		$schemaData = $this->serializeSchemaForCache(schema: $schema);

		$slot = new SchemaCacheSlot(schemaId: $schemaId, cacheKey: self::CACHE_KEY_SCHEMA);
		$this->setCachedData(row: $this->cacheRow(slot: $slot, data: $schemaData), ttl: $ttl);

		// Store in memory cache.
		$cacheKey = $this->buildCacheKey(schemaId: $schemaId, cacheKey: self::CACHE_KEY_SCHEMA);
		self::$memoryCache[$cacheKey] = $schema;

		// Also cache computed properties.
		$this->cacheSchemaConfiguration(schema: $schema, ttl: $ttl);
		$this->cacheSchemaProperties(schema: $schema, ttl: $ttl);
	}//end cacheSchema()

	/**
	 * Cache schema configuration
	 *
	 * @param Schema $schema The schema object
	 * @param int $ttl Cache TTL in seconds
	 *
	 * @return void
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function cacheSchemaConfiguration(Schema $schema, int $ttl = self::DEFAULT_TTL): void {
		$configuration = $schema->getConfiguration();
		$slot          = new SchemaCacheSlot(schemaId: $schema->getId(), cacheKey: self::CACHE_KEY_CONFIGURATION);
		$this->setCachedData(row: $this->cacheRow(slot: $slot, data: $configuration), ttl: $ttl);
	}//end cacheSchemaConfiguration()

	/**
	 * Cache schema properties
	 *
	 * @param Schema $schema The schema object
	 * @param int $ttl Cache TTL in seconds
	 *
	 * @return void
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function cacheSchemaProperties(Schema $schema, int $ttl = self::DEFAULT_TTL): void {
		$properties = $schema->getProperties();
		$slot       = new SchemaCacheSlot(schemaId: $schema->getId(), cacheKey: self::CACHE_KEY_PROPERTIES);
		$this->setCachedData(row: $this->cacheRow(slot: $slot, data: $properties), ttl: $ttl);
	}//end cacheSchemaProperties()

	/**
	 * Invalidate cache for a specific schema (runtime-schema-api contract)
	 *
	 * Public, canonical entry point used by the runtime schema CRUD path
	 * (SchemasController create/update/delete). After this call returns, the
	 * next read in the same PHP worker MUST observe a fresh load from the
	 * database — both the in-memory cache and the persistent cache row for
	 * the given ID are dropped.
	 *
	 * Internally delegates to {@see self::invalidateForSchemaChange()} with
	 * a generic 'mutate' operation tag so audit logs stay informative.
	 *
	 * @param int $schemaId The schema ID to invalidate.
	 *
	 * @return void
	 *
	 * @throws \OCP\DB\Exception If a database error occurs.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function invalidate(int $schemaId): void {
		// Delegate to the canonical invalidator used by every controller path.
		$this->invalidateForSchemaChange(schemaId: $schemaId, operation: 'mutate');

		// Also drop the request-scoped find cache on the SchemaMapper itself
		// so the next find() reads fresh state from the database within the
		// same PHP worker. The persistent cache table is already invalidated
		// above; this call closes the in-mapper micro-cache window.
		$this->schemaMapper->clearFindCache(schemaId: $schemaId);
	}//end invalidate()

	/**
	 * Invalidate cache for a specific schema
	 *
	 * **SCHEMA CACHE INVALIDATION**: Called when schemas are created, updated,
	 * or deleted to ensure cache consistency.
	 *
	 * @param int $schemaId The schema ID to invalidate
	 * @param string $operation The operation performed (create/update/delete)
	 *
	 * @return void
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Operation parameter with default is not a boolean
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function invalidateForSchemaChange(int $schemaId, string $operation = 'update'): void {
		$startTime      = microtime(true);
		$deletedEntries = $this->deleteRowsForInvalidation(schemaId: $schemaId);

		// Remove from memory cache (always safe to do).
		foreach ($this->memoryKeysOf(schemaId: $schemaId) as $key) {
			unset(self::$memoryCache[$key]);
		}

		$values = ['schemaId' => $schemaId, 'operation' => $operation, 'deletedEntries' => $deletedEntries];
		$values['executionTime'] = $this->elapsedSince(startTime: $startTime);
		$this->logger->info(message: '[SchemaCacheHandler] Schema cache invalidated', context: $this->context(line: __LINE__, values: $values));
	}//end invalidateForSchemaChange()

	/**
	 * Delete the database cache rows of one schema for an invalidation.
	 *
	 * @param int $schemaId The schema ID.
	 *
	 * @return int The number of rows deleted, 0 when the delete failed.
	 */
	private function deleteRowsForInvalidation(int $schemaId): int {
		$deletedEntries = 0;

		// Remove from database cache (if table exists).
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::CACHE_TABLE)
				->where($qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId)));
			$deletedEntries = $qb->executeStatement();
		} catch (Exception $e) {
			// The cache is an optimisation, so a database failure here must not
			// fail the schema change that triggered it — but it is NOT expected
			// and is not logged as though it were.
			//
			// This block used to say the table "doesn't exist yet" and that the
			// tolerance let the app work "even if the migration hasn't been run
			// yet". No migration created this table, so there was nothing for
			// that state to be transitional to: the condition was permanent,
			// the message was wrong, and at default log level `debug` emitted
			// nothing at all. The only trace was 33 `relation ... does not
			// exist` errors per run in the Postgres server log. The table is
			// now created by Version1Date20260809000000, so reaching this
			// branch means something real went wrong; `warning` matches the
			// sibling path in clearSchemaCache(), which logged the identical
			// cause at `error`.
			$this->logger->warning(
				message: '[SchemaCacheHandler] Failed to invalidate the database schema cache; continuing on the memory cache only',
				context: $this->context(line: __LINE__, values: ['schemaId' => $schemaId, 'error' => $e->getMessage()])
			);
		}//end try

		return $deletedEntries;
	}//end deleteRowsForInvalidation()

	/**
	 * The memory cache keys an invalidation drops for one schema.
	 *
	 * @param int $schemaId The schema ID.
	 *
	 * @return list<string> The keys.
	 */
	private function memoryKeysOf(int $schemaId): array {
		return [
			$this->buildCacheKey(schemaId: $schemaId, cacheKey: self::CACHE_KEY_SCHEMA),
			$this->buildCacheKey(schemaId: $schemaId, cacheKey: self::CACHE_KEY_FACETABLE_FIELDS),
			$this->buildCacheKey(schemaId: $schemaId, cacheKey: self::CACHE_KEY_CONFIGURATION),
			$this->buildCacheKey(schemaId: $schemaId, cacheKey: self::CACHE_KEY_PROPERTIES),
		];
	}//end memoryKeysOf()

	/**
	 * Clear all schema caches (Administrative Operation)
	 *
	 * **NUCLEAR OPTION**: This method clears both database and memory caches for all schemas.
	 * Use with caution as it will impact performance until caches are rebuilt.
	 *
	 * @return void
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function clearAllCaches(): void {
		$startTime = microtime(true);

		// Clear database cache.
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::CACHE_TABLE);
		$deletedEntries = $qb->executeStatement();

		// Clear memory cache.
		$values = ['deletedDbEntries' => $deletedEntries, 'clearedMemoryEntries' => count(self::$memoryCache)];
		self::$memoryCache = [];

		$values['executionTime'] = $this->elapsedSince(startTime: $startTime);
		$this->logger->info(message: '[SchemaCacheHandler] All schema caches cleared', context: $this->context(line: __LINE__, values: $values));
	}//end clearAllCaches()

	/**
	 * Clean expired cache entries
	 *
	 * This method removes expired cache entries from the database.
	 * Should be called periodically via cron job.
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 *
	 * @return int
	 *
	 * @psalm-return int<min, max>
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function cleanExpiredEntries(): int {
		$startTime = microtime(true);

		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::CACHE_TABLE)
			->where($qb->expr()->isNotNull('expires'))
			->andWhere($qb->expr()->lt('expires', $qb->createNamedParameter(new DateTime(), 'datetime')));

		$deletedCount  = $qb->executeStatement();
		$executionTime = $this->elapsedSince(startTime: $startTime);

		if ($deletedCount > 0) {
			$context = $this->context(line: __LINE__, values: ['count' => $deletedCount, 'executionTime' => $executionTime]);
			$this->logger->info(message: '[SchemaCacheHandler] Cleaned expired schema cache entries', context: $context);
		}

		return $deletedCount;
	}//end cleanExpiredEntries()

	/**
	 * Get comprehensive cache statistics
	 *
	 * @return array Cache statistics with total entries, TTL info, memory size, and timing.
	 *
	 * @throws \OCP\DB\Exception If a database error occurs.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function getCacheStatistics(): array {
		$startTime = microtime(true);
		$result    = $this->countRows();
		$queryTime = $this->elapsedSince(startTime: $startTime);

		return [
			'total_entries'     => (int)$result['total_entries'],
			'entries_with_ttl'  => (int)$result['entries_with_ttl'],
			'memory_cache_size' => count(self::$memoryCache),
			'cache_table'       => self::CACHE_TABLE,
			'query_time'        => $queryTime,
			'timestamp'         => time(),
		];
	}//end getCacheStatistics()

	/**
	 * How many cache rows there are, and how many of them expire.
	 *
	 * @return mixed The `total_entries` and `entries_with_ttl` row.
	 *
	 * @throws \OCP\DB\Exception If a database error occurs.
	 */
	private function countRows(): mixed {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'total_entries'))
			->addSelect($qb->func()->count('expires', 'entries_with_ttl'))
			->from(self::CACHE_TABLE);

		return $qb->executeQuery()->fetch();
	}//end countRows()

	/**
	 * Build cache key for a schema and cache type
	 *
	 * @param int $schemaId The schema ID
	 * @param string $cacheKey The cache key type
	 *
	 * @return string The full cache key
	 */
	private function buildCacheKey(int $schemaId, string $cacheKey): string {
		return "schema_{$schemaId}_{$cacheKey}";
	}//end buildCacheKey()

	/**
	 * Milliseconds since a start time, as the `12.34ms` string the logs and statistics carry.
	 *
	 * @param float $startTime The microtime(true) the measurement started at.
	 *
	 * @return string The elapsed time.
	 */
	private function elapsedSince(float $startTime): string {
		return round((microtime(true) - $startTime) * 1000, 2) . 'ms';
	}//end elapsedSince()

	/**
	 * A log context: this file and the caller's line, then the given values.
	 *
	 * @param int                  $line   The caller's line.
	 * @param array<string, mixed> $values The values after file and line.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function context(int $line, array $values): array {
		return array_merge(['file' => __FILE__, 'line' => $line], $values);
	}//end context()

	/**
	 * A log context about one schema: this file, the caller's line and the schema ID.
	 *
	 * @param int $line     The caller's line.
	 * @param int $schemaId The schema ID.
	 *
	 * @return array<string, mixed> The context.
	 */
	private function schemaContext(int $line, int $schemaId): array {
		return $this->context(line: $line, values: ['schemaId' => $schemaId]);
	}//end schemaContext()

	/**
	 * Get cached data from database
	 *
	 * @param int $schemaId The schema ID
	 * @param string $cacheKey The cache key type
	 *
	 * @return mixed|null The cached data or null if not found/expired
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 */
	private function getCachedData(int $schemaId, string $cacheKey): mixed {
		$result = $this->fetchCacheRow(schemaId: $schemaId, cacheKey: $cacheKey);
		if ($result === false || $result === null) {
			return null;
		}

		// Check if expired.
		if ($result['expires'] !== null && new DateTime($result['expires']) <= new DateTime()) {
			// Cache expired, remove it.
			$this->removeCachedData(schemaId: $schemaId, cacheKey: $cacheKey);
			return null;
		}

		return json_decode($result['cache_data'], true);
	}//end getCachedData()

	/**
	 * The stored row of one schema and cache key.
	 *
	 * @param int    $schemaId The schema ID.
	 * @param string $cacheKey The cache key type.
	 *
	 * @return mixed The `cache_data` and `expires` row, or false/null when there is none.
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 */
	private function fetchCacheRow(int $schemaId, string $cacheKey): mixed {
		$qb = $this->db->getQueryBuilder();
		$qb->select('cache_data', 'expires')
			->from(self::CACHE_TABLE)
			->where($qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId)))
			->andWhere($qb->expr()->eq('cache_key', $qb->createNamedParameter($cacheKey)));

		return $qb->executeQuery()->fetch();
	}//end fetchCacheRow()

	/**
	 * The row that holds one piece of cached data, stamped with the time of writing.
	 *
	 * @param SchemaCacheSlot $slot The schema and cache key the data is cached under.
	 * @param mixed           $data The data to cache.
	 *
	 * @return array<string, mixed> The row: schema_id, cache_key, cache_data and now.
	 */
	private function cacheRow(SchemaCacheSlot $slot, mixed $data): array {
		return [
			'schema_id'  => $slot->schemaId,
			'cache_key'  => $slot->cacheKey,
			'cache_data' => json_encode($data),
			'now'        => new DateTime(),
		];
	}//end cacheRow()

	/**
	 * Set cached data in database
	 *
	 * @param array<string, mixed> $row The row from cacheRow().
	 * @param int                  $ttl Cache TTL in seconds
	 *
	 * @return void
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Database upsert logic with conditional insert/update
	 * Insert alternative when update returns zero rows
	 */
	private function setCachedData(array $row, int $ttl): void {
		// Enforce maximum cache TTL for office environments.
		$row['expires'] = $this->expiresAt(now: $row['now'], ttl: min($ttl, self::MAX_CACHE_TTL));

		// First, try to update existing record; if no rows updated, insert new record.
		if ($this->updateCacheRow(row: $row) === 0) {
			$this->insertCacheRow(row: $row);
		}
	}//end setCachedData()

	/**
	 * When an entry written now expires.
	 *
	 * @param DateTime $now The write time.
	 * @param int      $ttl The TTL in seconds; 0 or less never expires.
	 *
	 * @return DateTime|null The expiry, or null for none.
	 */
	private function expiresAt(DateTime $now, int $ttl): ?DateTime {
		if ($ttl <= 0) {
			return null;
		}

		return (clone $now)->add(new DateInterval("PT{$ttl}S"));
	}//end expiresAt()

	/**
	 * Update the cache row of one schema and key.
	 *
	 * @param array{schema_id: int, cache_key: string, cache_data: string|false, now: DateTime, expires: DateTime|null} $row The row.
	 *
	 * @return int The number of rows updated.
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 */
	private function updateCacheRow(array $row): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::CACHE_TABLE)
			->set('cache_data', $qb->createNamedParameter($row['cache_data']))
			->set('updated', $qb->createNamedParameter($row['now'], 'datetime'))
			->set('expires', $qb->createNamedParameter($row['expires'], 'datetime'))
			->where($qb->expr()->eq('schema_id', $qb->createNamedParameter($row['schema_id'])))
			->andWhere($qb->expr()->eq('cache_key', $qb->createNamedParameter($row['cache_key'])));

		return $qb->executeStatement();
	}//end updateCacheRow()

	/**
	 * Insert the cache row of one schema and key.
	 *
	 * @param array{schema_id: int, cache_key: string, cache_data: string|false, now: DateTime, expires: DateTime|null} $row The row.
	 *
	 * @return void
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 */
	private function insertCacheRow(array $row): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::CACHE_TABLE)
			->values(
				values: [
					'schema_id'  => $qb->createNamedParameter($row['schema_id']),
					'cache_key'  => $qb->createNamedParameter($row['cache_key']),
					'cache_data' => $qb->createNamedParameter($row['cache_data']),
					'created'    => $qb->createNamedParameter($row['now'], 'datetime'),
					'updated'    => $qb->createNamedParameter($row['now'], 'datetime'),
					'expires'    => $qb->createNamedParameter($row['expires'], 'datetime'),
				]
			);
		$qb->executeStatement();
	}//end insertCacheRow()

	/**
	 * Remove cached data from database
	 *
	 * @param int $schemaId The schema ID
	 * @param string $cacheKey The cache key type
	 *
	 * @return void
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 */
	private function removeCachedData(int $schemaId, string $cacheKey): void {
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
	 * @return (array|int|mixed|null|string)[] Serialized schema data
	 *
	 * @psalm-return array{
	 *     id: int,
	 *     uuid: null|string,
	 *     title: null|string,
	 *     version: null|string,
	 *     description: null|string,
	 *     summary: null|string,
	 *     required: array|null,
	 *     properties: array|null,
	 *     archive: array|null,
	 *     configuration: array|null,
	 *     source: null|string,
	 *     organisation: null|string,
	 *     owner: null|string,
	 *     created: null|string,
	 *     updated: null|string
	 * }
	 */
	private function serializeSchemaForCache(Schema $schema): array {
		// Only fields Schema actually declares. `tags` and `register` used to
		// be read here and neither is a property of Schema, so both calls fell
		// through Entity::__call into "is not a valid attribute" and every
		// cache write threw. The test could not see it: its Schema double
		// declared both with addMethods(), which invents the accessor on the
		// mock instead of consulting the class.
		return array_merge(
			[
				'id'          => $schema->getId(),
				'uuid'        => $schema->getUuid(),
				'title'       => $schema->getTitle(),
				'version'     => $schema->getVersion(),
				'description' => $schema->getDescription(),
				'summary'     => $schema->getSummary(),
			],
			$this->serializeSchemaBody(schema: $schema)
		);
	}//end serializeSchemaForCache()

	/**
	 * The cached fields of a schema after its identity and texts.
	 *
	 * @param Schema $schema The schema to serialize.
	 *
	 * @return array<string, mixed> The fields, in cache order.
	 */
	private function serializeSchemaBody(Schema $schema): array {
		return [
			'required'      => $schema->getRequired(),
			'properties'    => $schema->getProperties(),
			'archive'       => $schema->getArchive(),
			'configuration' => $schema->getConfiguration(),
			'source'        => $schema->getSource(),
			'organisation'  => $schema->getOrganisation(),
			'owner'         => $schema->getOwner(),
			'created'       => $schema->getCreated()?->format('Y-m-d H:i:s'),
			'updated'       => $schema->getUpdated()?->format('Y-m-d H:i:s'),
		];
	}//end serializeSchemaBody()

	/**
	 * Reconstruct schema object from cached data
	 *
	 * @param array<string, mixed> $cachedData The cached schema data
	 *
	 * @return Schema|null The reconstructed schema or null if reconstruction fails
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple property assignments from cache data
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple conditional property assignments
	 */
	private function reconstructSchemaFromCache(array $cachedData): ?Schema {
		try {
			$schema = new Schema();
			$this->applyCachedFields(schema: $schema, cachedData: $cachedData);
			$this->applyCachedDates(schema: $schema, cachedData: $cachedData);
			return $schema;
		} catch (Exception $e) {
			$this->logger->error(
				message: '[SchemaCacheHandler] Failed to reconstruct schema from cache',
				context: $this->context(line: __LINE__, values: ['schemaId' => $cachedData['id'] ?? 'unknown', 'error' => $e->getMessage()])
			);
			return null;
		}
	}//end reconstructSchemaFromCache()

	/**
	 * Set the scalar and array fields of a cached schema.
	 *
	 * @param Schema               $schema     The schema being rebuilt.
	 * @param array<string, mixed> $cachedData The cached schema data.
	 *
	 * @return void
	 */
	private function applyCachedFields(Schema $schema, array $cachedData): void {
		$schema->setId($cachedData['id']);
			$schema->setUuid($cachedData['uuid']);
			$schema->setTitle($cachedData['title']);
			$schema->setVersion($cachedData['version']);
			$schema->setDescription($cachedData['description']);
			$schema->setSummary($cachedData['summary']);
			$schema->setRequired($cachedData['required']);
			$schema->setProperties($cachedData['properties']);
			$schema->setArchive($cachedData['archive']);
			$schema->setConfiguration($cachedData['configuration']);
			$schema->setSource($cachedData['source']);
			$schema->setOrganisation($cachedData['organisation']);
			$schema->setOwner($cachedData['owner']);
	}//end applyCachedFields()

	/**
	 * Set the created and updated dates of a cached schema, when the cache holds them.
	 *
	 * @param Schema               $schema     The schema being rebuilt.
	 * @param array<string, mixed> $cachedData The cached schema data.
	 *
	 * @return void
	 *
	 * @throws Exception When a cached date does not parse.
	 */
	private function applyCachedDates(Schema $schema, array $cachedData): void {
		$createdValue = $cachedData['created'] ?? null;
		if ($createdValue !== null && $createdValue !== '') {
			$schema->setCreated(new DateTime($createdValue));
		}

		$updatedValue = $cachedData['updated'] ?? null;
		if ($updatedValue !== null && $updatedValue !== '') {
			$schema->setUpdated(new DateTime($cachedData['updated']));
		}
	}//end applyCachedDates()
}//end class
