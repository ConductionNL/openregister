<?php

/**
 * MagicMapper - Dynamic Schema-Based Table Management
 *
 * This mapper provides dynamic table creation and management based on JSON schema objects.
 * It creates dedicated tables for each schema, providing better performance and organization
 * compared to storing all objects in a single generic table.
 *
 * Key Features:
 * - Dynamic table creation based on JSON schema properties
 * - Automatic table updates when schemas change
 * - Schema-specific search and retrieval operations
 * - Integration with existing ObjectEntity metadata system
 * - Support for all major database systems (MySQL, MariaDB, PostgreSQL)
 *
 * Table Structure:
 * - Table naming: oc_openregister_table_[schema_slug]
 * - Metadata columns from ObjectEntity prefixed with underscore (_)
 * - Schema property columns mapped from JSON schema to SQL types
 * - Automatic indexing for performance optimization
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use Exception;
use DateTime;
use stdClass;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectLockedEvent;
use OCA\OpenRegister\Event\ObjectUnlockedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\SettingsService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Doctrine\DBAL\Schema\Schema as DoctrineSchema;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/**
 * Dynamic Schema-Based Table Management Service
 *
 * ARCHITECTURAL OVERVIEW:
 * This service implements dynamic table creation and management based on JSON schema definitions.
 * Instead of storing all objects in a single generic table, it creates dedicated tables for each
 * schema, providing better performance, cleaner data organization, and schema-specific optimizations.
 *
 * KEY RESPONSIBILITIES:
 * - Dynamic table creation from JSON schema definitions
 * - Automatic table schema updates when JSON schemas change
 * - Schema-to-SQL type mapping with validation
 * - High-performance search within schema-specific tables
 * - Integration with existing ObjectEntity metadata system
 * - Support for complex relationships and references
 *
 * TABLE DESIGN:
 * - Naming Convention: oc_openregister_table_{schema_slug}
 * - Metadata Columns: All ObjectEntity properties prefixed with underscore (_)
 * - Schema Columns: JSON schema properties mapped to appropriate SQL types
 * - Indexes: Automatic creation for performance optimization
 *
 * PERFORMANCE BENEFITS:
 * - Reduced table size (schema-specific vs. generic)
 * - Better indexing strategies (schema-aware indexes)
 * - Faster queries (no schema filtering needed)
 * - Optimized storage (appropriate column types vs. JSON)
 * - Better database statistics and query planning
 *
 * DATABASE COMPATIBILITY:
 * - MySQL/MariaDB: Full support with optimized column types
 * - PostgreSQL: Full support with JSONB for complex objects
 * - SQLite: Basic support for development environments
 *
 * @psalm-type SchemaPropertyConfig = array{
 *     type: string,
 *     format?: string,
 *     items?: array,
 *     properties?: array,
 *     required?: array<string>,
 *     maxLength?: int,
 *     minLength?: int,
 *     maximum?: int,
 *     minimum?: int
 * }
 *
 * @psalm-type TableColumnConfig = array{
 *     name: string,
 *     type: string,
 *     length?: int,
 *     nullable: bool,
 *     default?: mixed,
 *     index?: bool,
 *     unique?: bool,
 *     precision?: int,
 *     scale?: int,
 *     autoincrement?: bool,
 *     primary?: bool
 * }
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MagicMapper
{
    /**
     * Table name prefix for register+schema-specific tables
     *
     * NOTE: Does NOT include 'oc_' prefix as Nextcloud's QueryBuilder adds that automatically.
     */
    private const TABLE_PREFIX = 'openregister_table_';

    /**
     * Metadata column prefix to avoid conflicts with schema properties
     */
    private const METADATA_PREFIX = '_';

    /**
     * Cache timeout for table existence checks (5 minutes)
     */
    private const TABLE_CACHE_TIMEOUT = 300;

    /**
     * Maximum table name length (MySQL limit)
     */
    private const MAX_TABLE_NAME_LENGTH = 64;

    /**
     * Cache for table existence to avoid repeated database queries
     * Key format: 'registerId_schemaId' => timestamp
     *
     * @var array<string, int>
     */
    private static array $tableExistsCache = [];

    /**
     * Cache for register+schema table mappings
     * Key format: 'registerId_schemaId' => 'table_name'
     *
     * @var array<string, string>
     */
    private static array $regSchemaTableCache = [];

    /**
     * Cache for table structure versions to detect schema changes
     * Key format: 'registerId_schemaId' => 'version_hash'
     *
     * @var array<string, string>
     */
    private static array $tableStructureCache = [];

    /**
     * Handler instances for specialized functionality
     */

    /**
     * Search handler for dynamic table operations
     *
     * @var MagicSearchHandler|null
     */
    private ?MagicSearchHandler $searchHandler = null;

    /**
     * RBAC handler for permission filtering
     *
     * @var MagicRbacHandler|null
     */
    private ?MagicRbacHandler $rbacHandler = null;

    /**
     * Bulk operations handler for high-performance operations
     *
     * @var MagicBulkHandler|null
     */
    private ?MagicBulkHandler $bulkHandler = null;

    /**
     * Organization handler for multi-tenancy support
     *
     * @var MagicOrganizationHandler|null
     */
    private ?MagicOrganizationHandler $organizationHandler = null;

    /**
     * Facet handler for aggregations and faceting
     *
     * @var MagicFacetHandler|null
     */
    private ?MagicFacetHandler $facetHandler = null;

    /**
     * Constructor for MagicMapper service
     *
     * Initializes the service with required dependencies for database operations,
     * schema and register management, configuration handling, logging, and specialized handlers.
     *
     * @param IDBConnection      $db                 Database connection for table operations
     * @param ObjectEntityMapper $objectEntityMapper Mapper for object operations
     * @param SchemaMapper       $schemaMapper       Mapper for schema operations
     * @param RegisterMapper     $registerMapper     Mapper for register operations
     * @param IConfig            $config             Nextcloud config for settings
     * @param IEventDispatcher   $eventDispatcher    Event dispatcher for audit trail events
     * @param IUserSession       $userSession        User session for authentication context
     * @param IGroupManager      $groupManager       Group manager for RBAC operations
     * @param IUserManager       $userManager        User manager for user operations
     * @param IAppConfig         $appConfig          App configuration for feature flags
     * @param LoggerInterface    $logger             Logger for debugging and monitoring
     * @param SettingsService    $settingsService    Settings service for configuration
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI requires constructor injection
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly IConfig $config,
        private readonly IEventDispatcher $eventDispatcher,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
        private readonly SettingsService $settingsService
    ) {
        // Initialize specialized handlers for modular functionality.
        $this->initializeHandlers();
    }//end __construct()

    /**
     * Initialize specialized handler instances
     *
     * Creates instances of all MagicMapper handlers for modular functionality.
     * Handlers are initialized lazily to improve performance and reduce memory usage.
     *
     * @return void
     */
    private function initializeHandlers(): void
    {
        $this->searchHandler = new MagicSearchHandler(
            db: $this->db,
            logger: $this->logger
        );

        $this->rbacHandler = new MagicRbacHandler(
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            userManager: $this->userManager,
            appConfig: $this->appConfig
        );

        $this->bulkHandler = new MagicBulkHandler(
            db: $this->db,
            logger: $this->logger,
            eventDispatcher: $this->eventDispatcher
        );

        $this->organizationHandler = new MagicOrganizationHandler(
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            appConfig: $this->appConfig,
            logger: $this->logger
        );

        $this->facetHandler = new MagicFacetHandler(
            db: $this->db,
            logger: $this->logger
        );
    }//end initializeHandlers()

    /**
     * Create or update table for a specific register+schema combination
     *
     * This method analyzes the JSON schema and creates/updates the corresponding
     * database table with appropriate columns, indexes, and constraints.
     *
     * @param Register $register The register context for the table
     * @param Schema   $schema   The schema to create/update table for
     * @param bool     $force    Whether to force recreation even if table exists
     *
     * @throws Exception If table creation/update fails
     *
     * @return true True if table was created/updated successfully
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Force flag allows table recreation
     */
    public function ensureTableForRegisterSchema(Register $register, Schema $schema, bool $force=false): bool
    {
        $tableName  = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
        $registerId = $register->getId();
        $schemaId   = $schema->getId();
        $cacheKey   = $this->getCacheKey(registerId: $registerId, schemaId: $schemaId);

        $this->logger->info(
            'Creating/updating table for register+schema',
            [
                'registerId'   => $registerId,
                'schemaId'     => $schemaId,
                'registerSlug' => $register->getSlug(),
                'schemaSlug'   => $schema->getSlug(),
                'tableName'    => $tableName,
                'force'        => $force,
            ]
        );

        try {
            // Check if table exists using cached method.
            $tableExists = $this->tableExistsForRegisterSchema(register: $register, schema: $schema);

            if (($tableExists === true) && ($force === false)) {
                // Table exists and not forcing update - check if schema changed.
                if ($this->hasRegisterSchemaChanged(register: $register, schema: $schema) === false) {
                    $this->logger->debug(
                        'Table exists and schema unchanged, skipping',
                        [
                            'tableName' => $tableName,
                            'cacheKey'  => $cacheKey,
                        ]
                    );
                    return true;
                }

                // Schema changed, update table.
                return $this->updateTableForRegisterSchema(register: $register, schema: $schema);
            }

            // Create new table or recreate if forced.
            if (($tableExists === true) && ($force === true)) {
                $this->dropTable($tableName);
                $this->invalidateTableCache($cacheKey);
            }

            return $this->createTableForRegisterSchema(register: $register, schema: $schema);
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to ensure table for register+schema',
                [
                    'registerId' => $registerId,
                    'schemaId'   => $schemaId,
                    'tableName'  => $tableName,
                    'error'      => $e->getMessage(),
                ]
            );

            $regTitle = $register->getTitle();
            $schTitle = $schema->getTitle();
            $msg      = "Failed to create/update table for register '{$regTitle}' ";
            $msg     .= "+ schema '{$schTitle}': ".$e->getMessage();
            throw new Exception($msg, 0, $e);
        }//end try
    }//end ensureTableForRegisterSchema()

    /**
     * Get table name for a specific register+schema combination
     *
     * @param Register $register The register context
     * @param Schema   $schema   The schema context
     *
     * @return string The table name for the register+schema combination
     */
    public function getTableNameForRegisterSchema(Register $register, Schema $schema): string
    {
        $registerId = $register->getId();
        $schemaId   = $schema->getId();

        // Use numeric IDs for consistent, shorter table names.
        $tableName = self::TABLE_PREFIX.$registerId.'_'.$schemaId;

        // Ensure table name doesn't exceed maximum length (should be fine with numeric IDs).
        if (strlen($tableName) > self::MAX_TABLE_NAME_LENGTH) {
            // This should rarely happen with numeric IDs, but handle it safely.
            $hash      = substr(md5($registerId.'_'.$schemaId), 0, 8);
            $tableName = self::TABLE_PREFIX.$hash;
        }

        // Cache the table name for this register+schema combination.
        $cacheKey = $this->getCacheKey(registerId: $registerId, schemaId: $schemaId);
        self::$regSchemaTableCache[$cacheKey] = $tableName;

        return $tableName;
    }//end getTableNameForRegisterSchema()

    /**
     * Check if a specialized table exists for register+schema combination
     *
     * This method provides fast existence checking with intelligent caching
     * to avoid repeated database calls. Cache is automatically invalidated
     * when tables are created, updated, or dropped.
     *
     * @param Register $register The register context
     * @param Schema   $schema   The schema context
     *
     * @return bool True if specialized table exists, false if should use generic storage
     */
    public function existsTableForRegisterSchema(Register $register, Schema $schema): bool
    {
        $registerId = $register->getId();
        $schemaId   = $schema->getId();
        $cacheKey   = $this->getCacheKey(registerId: $registerId, schemaId: $schemaId);

        // Check cache first (with timeout).
        if ((self::$tableExistsCache[$cacheKey] ?? null) !== null) {
            $cachedTime = self::$tableExistsCache[$cacheKey];
            if ((time() - $cachedTime) < self::TABLE_CACHE_TIMEOUT) {
                $this->logger->debug(
                    'Table existence check: cache hit',
                    [
                        'registerId' => $registerId,
                        'schemaId'   => $schemaId,
                        'cacheKey'   => $cacheKey,
                        'exists'     => true,
                    ]
                );
                return true;
            }

            // Cache expired, remove it.
            unset(self::$tableExistsCache[$cacheKey]);
        }

        // Check database for table existence.
        $tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
        $exists    = $this->checkTableExistsInDatabase($tableName);

        if ($exists === true) {
            // Cache positive result.
            self::$tableExistsCache[$cacheKey] = time();

            $this->logger->debug(
                'Table existence check: database hit - exists',
                [
                    'registerId' => $registerId,
                    'schemaId'   => $schemaId,
                    'tableName'  => $tableName,
                    'cacheKey'   => $cacheKey,
                ]
            );
        }

        if ($exists === false) {
            $this->logger->debug(
                'Table existence check: database hit - not exists',
                [
                    'registerId' => $registerId,
                    'schemaId'   => $schemaId,
                    'tableName'  => $tableName,
                    'cacheKey'   => $cacheKey,
                ]
            );
        }//end if

        return $exists;
    }//end existsTableForRegisterSchema()

    /**
     * Save objects to register+schema-specific table
     *
     * @param array    $objects  Array of object data to save
     * @param Register $register The register context for table selection
     * @param Schema   $schema   The schema for table selection
     *
     * @throws Exception If save operation fails
     *
     * @return string[]
     *
     * @psalm-return list<non-empty-string>
     */
    public function saveObjectsToRegisterSchemaTable(array $objects, Register $register, Schema $schema): array
    {
        // Ensure table exists for this register+schema combination.
        $this->ensureTableForRegisterSchema(register: $register, schema: $schema);

        $tableName  = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
        $savedUuids = [];

        $this->logger->info(
            'Saving objects to register+schema table',
            [
                'registerId'  => $register->getId(),
                'schemaId'    => $schema->getId(),
                'tableName'   => $tableName,
                'objectCount' => count($objects),
            ]
        );

        try {
            foreach ($objects as $object) {
                $uuid = $this->saveObjectToRegisterSchemaTable(
                    objectData: $object,
                    register: $register,
                    schema: $schema,
                    tableName: $tableName
                );
                if ($uuid !== null && $uuid !== '') {
                    $savedUuids[] = $uuid;
                }
            }

            $this->logger->info(
                'Successfully saved objects to register+schema table',
                [
                    'tableName'  => $tableName,
                    'savedCount' => count($savedUuids),
                ]
            );

            return $savedUuids;
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to save objects to register+schema table',
                [
                    'tableName' => $tableName,
                    'error'     => $e->getMessage(),
                ]
            );

            throw $e;
        }//end try
    }//end saveObjectsToRegisterSchemaTable()

    /**
     * Search objects in register+schema-specific table
     *
     * @param array    $query    Search query parameters
     * @param Register $register The register context for table selection
     * @param Schema   $schema   The schema for table selection
     *
     * @throws Exception If search operation fails
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<ObjectEntity>
     */
    public function searchObjectsInRegisterSchemaTable(array $query, Register $register, Schema $schema): array
    {
        // Use fast cached existence check.
        if ($this->existsTableForRegisterSchema(register: $register, schema: $schema) === false) {
            $this->logger->info(
                'Register+schema table does not exist, should use generic storage',
                [
                    'registerId' => $register->getId(),
                    'schemaId'   => $schema->getId(),
                ]
            );
            return [];
        }

        $tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);

        try {
            return $this->executeRegisterSchemaTableSearch(
                query: $query,
                register: $register,
                schema: $schema,
                tableName: $tableName
            );
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to search register+schema table',
                [
                    'tableName' => $tableName,
                    'error'     => $e->getMessage(),
                ]
            );

            throw $e;
        }
    }//end searchObjectsInRegisterSchemaTable()

    /**
     * Count objects in a register+schema specific table.
     *
     * This method counts objects from a dedicated table based on register+schema combination.
     * It supports basic filtering and returns only the count for better performance.
     *
     * @param array    $query    Search parameters for filtering (excluding pagination).
     * @param Register $register The register context for table selection.
     * @param Schema   $schema   The schema for table selection.
     *
     * @return int Count of matching objects.
     */
    public function countObjectsInRegisterSchemaTable(array $query, Register $register, Schema $schema): int
    {
        // Use fast cached existence check.
        if ($this->existsTableForRegisterSchema(register: $register, schema: $schema) === false) {
            $this->logger->info(
                'Register+schema table does not exist for count, returning 0',
                [
                    'registerId' => $register->getId(),
                    'schemaId'   => $schema->getId(),
                ]
            );
            return 0;
        }

        $tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);

        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*) as count'))
                ->from($tableName);

            // Apply all filters (including object field filters) using the same logic as search.
            // This ensures count matches the actual filtered results.
            $this->applySearchFilters(qb: $qb, query: $query, schema: $schema);

            // Apply full-text search if provided.
            if (empty($query['_search']) === false) {
                $this->applyFuzzySearch(qb: $qb, searchTerm: $query['_search'], schema: $schema);
            }

            // Exclude deleted objects by default.
            if (isset($query['@self.deleted']) === false) {
                $qb->andWhere($qb->expr()->isNull('_deleted'));
            } else if ($query['@self.deleted'] === 'IS NOT NULL') {
                $qb->andWhere($qb->expr()->isNotNull('_deleted'));
            }

            $result = $qb->executeQuery();
            $row    = $result->fetch();
            $result->closeCursor();

            $count = (int) ($row['count'] ?? 0);

            $this->logger->debug(
                '[MagicMapper] Count query completed',
                [
                    'tableName' => $tableName,
                    'count'     => $count,
                    'hasSearch' => empty($query['_search']) === false,
                    'query'     => array_keys($query),
                ]
            );

            return $count;
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to count in register+schema table',
                [
                    'tableName' => $tableName,
                    'error'     => $e->getMessage(),
                ]
            );

            // Return 0 on error instead of throwing.
            return 0;
        }//end try
    }//end countObjectsInRegisterSchemaTable()

    /**
     * Get simple facets for a register+schema specific table.
     *
     * This method retrieves facet data (aggregations) from a dedicated magic mapper table
     * based on register+schema combination. It supports both metadata facets (@self fields)
     * and schema property facets.
     *
     * @param array    $query    Search parameters including _facets configuration.
     * @param Register $register The register context for table selection.
     * @param Schema   $schema   The schema for table selection.
     *
     * @return array Facet results with buckets.
     */
    public function getSimpleFacetsFromRegisterSchemaTable(array $query, Register $register, Schema $schema): array
    {
        // Use fast cached existence check.
        if ($this->existsTableForRegisterSchema(register: $register, schema: $schema) === false) {
            $this->logger->info(
                'Register+schema table does not exist for facets, returning empty',
                [
                    'registerId' => $register->getId(),
                    'schemaId'   => $schema->getId(),
                ]
            );
            return [];
        }

        $tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);

        try {
            // Initialize facet handler if not already done.
            $this->initializeHandlers();

            return $this->facetHandler->getSimpleFacets(
                tableName: $tableName,
                query: $query,
                register: $register,
                schema: $schema
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to get facets from register+schema table',
                [
                    'tableName' => $tableName,
                    'error'     => $e->getMessage(),
                ]
            );

            return [];
        }//end try
    }//end getSimpleFacetsFromRegisterSchemaTable()

    /**
     * Search across multiple register+schema tables simultaneously.
     *
     * This method performs a cross-table search by:
     * 1. Querying each register+schema table separately with fuzzy search.
     * 2. Combining results using array merge (since we can't easily UNION with different schemas).
     * 3. Sorting by relevance score globally.
     *
     * @param array $query               Search parameters including _search term.
     * @param array $registerSchemaPairs Array of ['register' => Register, 'schema' => Schema] pairs.
     *
     * @return array Array of ObjectEntity objects from all tables, sorted by relevance.
     */
    public function searchAcrossMultipleTables(array $query, array $registerSchemaPairs): array
    {
        $this->logger->info(
            '[MagicMapper] Starting cross-table search',
            ['pairCount' => count($registerSchemaPairs), 'queryKeys' => array_keys($query)]
        );

        // OPTIMIZATION: Use UNION ALL for multi-table search in a single query.
        // This is MUCH faster than looping through tables individually.
        if (count($registerSchemaPairs) > 1 && $this->shouldUseUnionQuery($query) === true) {
            return $this->searchAcrossMultipleTablesWithUnion(
                query: $query,
                registerSchemaPairs: $registerSchemaPairs
            );
        }

        // Fallback: Individual table queries (for complex queries or single table).
        return $this->searchAcrossMultipleTablesSequential(
            query: $query,
            registerSchemaPairs: $registerSchemaPairs
        );
    }//end searchAcrossMultipleTables()

    /**
     * Determine if we should use UNION ALL optimization.
     *
     * UNION ALL is faster but has limitations:
     * - All tables must exist
     * - No complex aggregations
     * - Simpler query structure
     *
     * @param array $query Search query parameters.
     *
     * @return bool True if UNION ALL can be used.
     */
    private function shouldUseUnionQuery(array $query): bool
    {
        // Don't use UNION for aggregations or facets (not supported).
        if (isset($query['_aggregations']) === true || isset($query['_facets']) === true) {
            return false;
        }

        // UNION ALL is safe for simple searches.
        return true;
    }//end shouldUseUnionQuery()

    /**
     * Search across multiple tables using UNION ALL (FAST).
     *
     * This method builds a single SQL query with UNION ALL to search
     * all tables at once, which is MUCH faster than individual queries.
     *
     * Performance: ~100-200ms for 5 tables vs ~400ms sequential.
     *
     * @param array $query               Search parameters.
     * @param array $registerSchemaPairs Array of register+schema pairs.
     *
     * @return array Array of ObjectEntity objects from all tables.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function searchAcrossMultipleTablesWithUnion(array $query, array $registerSchemaPairs): array
    {
        $qb    = $this->db->getQueryBuilder();
        $parts = [];

        // Build a SELECT for each table.
        foreach ($registerSchemaPairs as $pair) {
            $register = $pair['register'] ?? null;
            $schema   = $pair['schema'] ?? null;

            if ($register === null || $schema === null) {
                continue;
            }

            // Check if table exists (fast cache check).
            if ($this->existsTableForRegisterSchema(register: $register, schema: $schema) === false) {
                continue;
            }

            $tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);

            // Build SELECT for this table with schema/register metadata.
            $selectPart = $this->buildUnionSelectPart(
                tableName: $tableName,
                query: $query,
                schema: $schema,
                register: $register
            );

            if ($selectPart !== null) {
                $parts[] = $selectPart;
            }
        }//end foreach

        if (empty($parts) === true) {
            return [];
        }

        // Combine all SELECTs with UNION ALL.
        $unionSql = implode(' UNION ALL ', $parts);

        // Apply global ORDER BY for relevance (if _search is present).
        $hasSearch = isset($query['_search']) === true && empty($query['_search']) === false;
        if ($hasSearch === true) {
            $unionSql .= ' ORDER BY _search_score DESC';
        }

        // Apply LIMIT/OFFSET to final UNION result.
        $limit     = $query['_limit'] ?? 100;
        $offset    = $query['_offset'] ?? 0;
        $unionSql .= " LIMIT {$limit} OFFSET {$offset}";

        // Execute the combined query.
        $stmt = $qb->getConnection()->prepare($unionSql);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        // Convert rows to ObjectEntity objects.
        $results = [];
        foreach ($rows as $row) {
            try {
                $entity = $this->convertUnionRowToObjectEntity($row);
                if ($entity !== null) {
                    $results[] = $entity;
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to convert union row to entity', ['error' => $e->getMessage()]);
                continue;
            }
        }

        $this->logger->info('[MagicMapper] Union search completed', ['resultCount' => count($results)]);

        return $results;
    }//end searchAcrossMultipleTablesWithUnion()

    /**
     * Build SELECT part for UNION ALL query.
     *
     * @param string   $tableName Table name.
     * @param array    $query     Search query.
     * @param Schema   $schema    Schema entity.
     * @param Register $register  Register entity.
     *
     * @return string|null SQL SELECT statement or null if table doesn't exist.
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function buildUnionSelectPart(string $tableName, array $query, Schema $schema, Register $register): ?string
    {
        $qb = $this->db->getQueryBuilder();

        // Add table prefix.
        $fullTableName = 'oc_'.$tableName;

        // Base SELECT with metadata columns.
        $selectColumns = [
            '*',
            "'{$register->getId()}' AS _union_register_id",
            "'{$schema->getId()}' AS _union_schema_id",
        ];

        // Add search score if _search is present.
        $hasSearch   = isset($query['_search']) === true && empty($query['_search']) === false;
        $searchTerm  = $query['_search'] ?? '';
        $schemaProps = $schema->getProperties() ?? [];

        if ($hasSearch === true && empty($schemaProps) === false) {
            // Build fuzzy search score (same logic as applyFuzzySearch).
            $searchColumns = [];
            foreach ($schemaProps as $propName => $propDef) {
                $type = $propDef['type'] ?? 'string';
                if (in_array($type, ['string', 'text'], true) === true) {
                    $columnName      = $this->sanitizeColumnName($propName);
                    $quotedTerm      = $qb->getConnection()->quote($searchTerm);
                    $searchColumns[] = "COALESCE(similarity({$columnName}::text, '{$quotedTerm}'), 0)";
                }
            }

            $selectColumns[] = '0 AS _search_score';
            if (empty($searchColumns) === false) {
                $scoreExpression = 'GREATEST('.implode(', ', $searchColumns).')';
                $selectColumns[count($selectColumns) - 1] = "{$scoreExpression} AS _search_score";
            }
        }

        if ($hasSearch === false || empty($schemaProps) === true) {
            $selectColumns[] = '0 AS _search_score';
        }

        $selectSql = 'SELECT '.implode(', ', $selectColumns)." FROM {$fullTableName}";

        // Add WHERE clause for search and filters.
        $whereClauses = [];

        // Fuzzy search WHERE.
        if ($hasSearch === true && empty($schemaProps) === false) {
            $searchConditions = [];
            foreach ($schemaProps as $propName => $propDef) {
                $type = $propDef['type'] ?? 'string';
                if (in_array($type, ['string', 'text'], true) === true) {
                    $columnName         = $this->sanitizeColumnName($propName);
                    $searchConditions[] = "{$columnName}::text ILIKE '%{$qb->getConnection()->quote($searchTerm)}%'";
                    $quoted = $qb->getConnection()->quote($searchTerm);
                    $searchConditions[] = "similarity({$columnName}::text, '{$quoted}') > 0.1";
                }
            }

            if (empty($searchConditions) === false) {
                $whereClauses[] = '('.implode(' OR ', $searchConditions).')';
            }
        }

        // Apply other filters (skip reserved params).
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
            'register',
            'schema',
            'registers',
            'schemas',
            'deleted',
        ];

        foreach ($query as $key => $value) {
            if (in_array($key, $reservedParams, true) === true || str_starts_with($key, '_') === true) {
                continue;
            }

            $columnName = $this->sanitizeColumnName($key);
            // Simple equality filter.
            $whereClauses[] = "{$columnName} = '{$qb->getConnection()->quote((string) $value)}'";
        }

        if (empty($whereClauses) === false) {
            $selectSql .= ' WHERE '.implode(' AND ', $whereClauses);
        }

        return $selectSql;
    }//end buildUnionSelectPart()

    /**
     * Convert UNION query row to ObjectEntity.
     *
     * @param array $row Database row from UNION query.
     *
     * @return ObjectEntity|null ObjectEntity or null if conversion fails.
     */
    private function convertUnionRowToObjectEntity(array $row): ?ObjectEntity
    {
        $registerId = $row['_union_register_id'] ?? null;
        $schemaId   = $row['_union_schema_id'] ?? null;

        if ($registerId === null || $schemaId === null) {
            return null;
        }

        // Remove metadata columns before converting to ObjectEntity.
        unset($row['_union_register_id'], $row['_union_schema_id'], $row['_search_score']);

        // Convert to ObjectEntity using existing logic.
        try {
            $register = $this->registerMapper->find((int) $registerId, _multitenancy: false, _rbac: false);
            $schema   = $this->schemaMapper->find((int) $schemaId, _multitenancy: false, _rbac: false);

            return $this->convertRowToObjectEntity(
                row: $row,
                _register: $register,
                _schema: $schema
            );
        } catch (\Exception $e) {
            $this->logger->warning('Failed to convert union row', ['error' => $e->getMessage()]);
            return null;
        }
    }//end convertUnionRowToObjectEntity()

    /**
     * Search across multiple tables sequentially (FALLBACK).
     *
     * This is the original implementation - slower but more flexible.
     *
     * @param array $query               Search parameters.
     * @param array $registerSchemaPairs Array of register+schema pairs.
     *
     * @return array Array of ObjectEntity objects.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function searchAcrossMultipleTablesSequential(array $query, array $registerSchemaPairs): array
    {
        $allResults = [];

        foreach ($registerSchemaPairs as $pair) {
            $register = $pair['register'] ?? null;
            $schema   = $pair['schema'] ?? null;

            if ($register === null || $schema === null) {
                $this->logger->warning('Invalid register+schema pair in cross-table search', ['pair' => $pair]);
                continue;
            }

            try {
                $this->logger->debug('[MagicMapper] Searching table (sequential)', ['schemaId' => $schema->getId()]);

                // Search in this table.
                $results = $this->searchObjectsInRegisterSchemaTable(
                    query: $query,
                    register: $register,
                    schema: $schema
                );

                $this->logger->info(
                    '[MagicMapper] Table search completed',
                    [
                        'schemaId'    => $schema->getId(),
                        'schemaTitle' => $schema->getTitle(),
                        'resultCount' => count($results),
                    ]
                );

                // Add schema information to each result for context.
                foreach ($results as $result) {
                    $result->setSchema((string) $schema->getId());
                    $result->setRegister((string) $register->getId());
                }

                $allResults = array_merge($allResults, $results);
            } catch (Exception $e) {
                $this->logger->error(
                    'Failed to search in register+schema table',
                    [
                        'registerId' => $register->getId(),
                        'schemaId'   => $schema->getId(),
                        'error'      => $e->getMessage(),
                    ]
                );
                // Continue with other tables even if one fails.
                continue;
            }//end try
        }//end foreach

        $this->logger->info('[MagicMapper] Sequential search completed', ['totalResults' => count($allResults)]);

        // Sort all results by search score if available (from _search parameter).
        if (isset($query['_search']) === true && empty($query['_search']) === false) {
            usort(
                $allResults,
                function ($a, $b) {
                    // Extract search score from object data if it exists.
                    $scoreA = 0;
                    $scoreB = 0;

                    $dataA = $a->getObject();
                    $dataB = $b->getObject();

                    if (is_array($dataA) === true && isset($dataA['_search_score']) === true) {
                        $scoreA = (float) $dataA['_search_score'];
                    }

                    if (is_array($dataB) === true && isset($dataB['_search_score']) === true) {
                        $scoreB = (float) $dataB['_search_score'];
                    }

                    // Sort descending (highest score first).
                    return $scoreB <=> $scoreA;
                }
            );
        }//end if

        $this->logger->debug(
            'Cross-table search completed',
            [
                'tableCount'  => count($registerSchemaPairs),
                'resultCount' => count($allResults),
            ]
        );

        return $allResults;
    }//end searchAcrossMultipleTablesSequential()

    /**
     * Get cache key for register+schema combination
     *
     * @param int $registerId The register ID
     * @param int $schemaId   The schema ID
     *
     * @return string Cache key for the combination
     */
    private function getCacheKey(int $registerId, int $schemaId): string
    {
        return $registerId.'_'.$schemaId;
    }//end getCacheKey()

    /**
     * Check if table exists in database (bypassing cache)
     *
     * Uses Nextcloud 32+ compatible API for checking table existence.
     *
     * @param string $tableName The table name to check
     *
     * @return bool True if table exists in database
     */
    private function checkTableExistsInDatabase(string $tableName): bool
    {
        try {
            // Check if table exists in information_schema.
            // NOTE: We use raw SQL here because information_schema is a system table.
            $prefix = 'oc_';
            // Nextcloud default prefix.
            $fullTableName = $prefix.$tableName;

            $sql  = "SELECT 1 FROM information_schema.tables WHERE table_name = ? AND table_schema = 'public' LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$fullTableName]);
            $result = $stmt->fetch();

            return $result !== false;
        } catch (Exception $e) {
            // Table doesn't exist or query failed.
            $this->logger->debug(
                '[MagicMapper] Table does not exist in database',
                [
                    'tableName' => $tableName,
                    'error'     => $e->getMessage(),
                ]
            );

            return false;
        }//end try
    }//end checkTableExistsInDatabase()

    /**
     * Invalidate table cache for specific register+schema
     *
     * @param string $cacheKey The cache key to invalidate
     *
     * @return void
     */
    private function invalidateTableCache(string $cacheKey): void
    {
        unset(self::$tableExistsCache[$cacheKey]);
        unset(self::$regSchemaTableCache[$cacheKey]);
        unset(self::$tableStructureCache[$cacheKey]);

        $this->logger->debug('Invalidated table cache', ['cacheKey' => $cacheKey]);
    }//end invalidateTableCache()

    /**
     * Create table for specific register+schema combination
     *
     * @param Register $register The register context
     * @param Schema   $schema   The schema to create table for
     *
     * @throws Exception If table creation fails
     *
     * @return true True if table created successfully
     */
    private function createTableForRegisterSchema(Register $register, Schema $schema): bool
    {
        $tableName  = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
        $registerId = $register->getId();
        $schemaId   = $schema->getId();
        $cacheKey   = $this->getCacheKey(registerId: $registerId, schemaId: $schemaId);

        $this->logger->info(
            'Creating new register+schema table',
            [
                'registerId' => $registerId,
                'schemaId'   => $schemaId,
                'tableName'  => $tableName,
            ]
        );

        // Get table structure from schema.
        $columns = $this->buildTableColumnsFromSchema($schema);

        // Create table with columns.
        $this->createTable(tableName: $tableName, columns: $columns);

        // Create indexes for performance.
        $this->createTableIndexes(tableName: $tableName, _register: $register, _schema: $schema);

        // Store schema version for change detection.
        $this->storeRegisterSchemaVersion(register: $register, schema: $schema);

        // Update cache with current timestamp.
        self::$tableExistsCache[$cacheKey]    = time();
        self::$regSchemaTableCache[$cacheKey] = $tableName;

        $this->logger->info(
            'Successfully created register+schema table',
            [
                'tableName'   => $tableName,
                'columnCount' => count($columns),
                'cacheKey'    => $cacheKey,
            ]
        );

        return true;
    }//end createTableForRegisterSchema()

    /**
     * Update existing table for register+schema changes
     *
     * @param Register $register The register context
     * @param Schema   $schema   The schema to update table for
     *
     * @throws Exception If table update fails
     *
     * @return true True if table updated successfully
     */
    private function updateTableForRegisterSchema(Register $register, Schema $schema): bool
    {
        $tableName  = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
        $registerId = $register->getId();
        $schemaId   = $schema->getId();
        $cacheKey   = $this->getCacheKey(registerId: $registerId, schemaId: $schemaId);

        $this->logger->info(
            'Updating existing register+schema table',
            [
                'registerId' => $registerId,
                'schemaId'   => $schemaId,
                'tableName'  => $tableName,
            ]
        );

        try {
            // Get current table structure.
            $currentColumns = $this->getExistingTableColumns($tableName);

            // Get required columns from schema.
            $requiredColumns = $this->buildTableColumnsFromSchema($schema);

            // Compare and update table structure.
            $this->updateTableStructure(
                tableName: $tableName,
                currentColumns: $currentColumns,
                requiredColumns: $requiredColumns
            );

            // Update indexes.
            $this->updateTableIndexes(tableName: $tableName, register: $register, schema: $schema);

            // Store updated schema version and refresh cache.
            $this->storeRegisterSchemaVersion(register: $register, schema: $schema);
            self::$tableExistsCache[$cacheKey] = time();
            // Refresh cache timestamp.
            $this->logger->info(
                'Successfully updated register+schema table',
                [
                    'tableName' => $tableName,
                    'cacheKey'  => $cacheKey,
                ]
            );

            return true;
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to update register+schema table',
                [
                    'tableName' => $tableName,
                    'error'     => $e->getMessage(),
                ]
            );

            throw $e;
        }//end try
    }//end updateTableForRegisterSchema()

    /**
     * Build table columns from JSON schema properties
     *
     * This method analyzes the JSON schema and creates appropriate SQL column
     * definitions for each property, plus all metadata columns from ObjectEntity.
     *
     * @param Schema $schema The schema to analyze
     *
     * @return (bool|int|mixed|null|string)[][] Column definitions.
     */
    private function buildTableColumnsFromSchema(Schema $schema): array
    {
        $columns = [];

        // Add all metadata columns from ObjectEntity with underscore prefix.
        $columns = array_merge($columns, $this->getMetadataColumns());

        // Get schema properties and convert to SQL columns.
        $schemaProperties = $schema->getProperties();

        if (is_array($schemaProperties) === true) {
            foreach ($schemaProperties as $propertyName => $propertyConfig) {
                // Note: Schema properties do NOT conflict with metadata columns.
                // Metadata columns have '_' prefix, schema properties don't.
                // Both '_name' (metadata) and 'name' (schema property) can coexist.
                $column = $this->mapSchemaPropertyToColumn(propertyName: $propertyName, propertyConfig: $propertyConfig);
                if ($column !== null && $column !== '') {
                    $columns[$propertyName] = $column;
                }
            }
        }

        return $columns;
    }//end buildTableColumnsFromSchema()

    /**
     * Get metadata columns from ObjectEntity
     *
     * @return (bool|int|string)[][]
     *
     * @psalm-return array{_id: array{name: '_id', type: 'bigint',
     *     nullable: false, autoincrement: true, primary: true},
     *     _uuid: array{name: '_uuid', type: 'string', length: 36,
     *     nullable: false, unique: true, index: true},
     *     _slug: array{name: '_slug', type: 'string', length: 255,
     *     nullable: true, index: true},
     *     _uri: array{name: '_uri', type: 'text', nullable: true},
     *     _version: array{name: '_version', type: 'string', length: 50,
     *     nullable: true},
     *     _register: array{name: '_register', type: 'string', length: 255,
     *     nullable: false, index: true},
     *     _schema: array{name: '_schema', type: 'string', length: 255,
     *     nullable: false, index: true},
     *     _owner: array{name: '_owner', type: 'string', length: 64,
     *     nullable: true, index: true},
     *     _organisation: array{name: '_organisation', type: 'string',
     *     length: 36, nullable: true, index: true},
     *     _application: array{name: '_application', type: 'string',
     *     length: 255, nullable: true},
     *     _folder: array{name: '_folder', type: 'string', length: 255,
     *     nullable: true},
     *     _name: array{name: '_name', type: 'string', length: 255,
     *     nullable: true, index: true},
     *     _description: array{name: '_description', type: 'text',
     *     nullable: true},
     *     _summary: array{name: '_summary', type: 'text', nullable: true},
     *     _image: array{name: '_image', type: 'text', nullable: true},
     *     _size: array{name: '_size', type: 'string', length: 50,
     *     nullable: true},
     *     _schema_version: array{name: '_schema_version', type: 'string',
     *     length: 50, nullable: true},
     *     _created: array{name: '_created', type: 'datetime',
     *     nullable: true, index: true},
     *     _updated: array{name: '_updated', type: 'datetime',
     *     nullable: true, index: true},
     *     _published: array{name: '_published', type: 'datetime',
     *     nullable: true, index: true},
     *     _depublished: array{name: '_depublished', type: 'datetime',
     *     nullable: true, index: true},
     *     _expires: array{name: '_expires', type: 'datetime',
     *     nullable: true, index: true},
     *     _files: array{name: '_files', type: 'json', nullable: true},
     *     _relations: array{name: '_relations', type: 'json', nullable: true},
     *     _locked: array{name: '_locked', type: 'json', nullable: true},
     *     _authorization: array{name: '_authorization', type: 'json',
     *     nullable: true},
     *     _validation: array{name: '_validation', type: 'json',
     *     nullable: true},
     *     _deleted: array{name: '_deleted', type: 'json', nullable: true},
     *     _geo: array{name: '_geo', type: 'json', nullable: true},
     *     _retention: array{name: '_retention', type: 'json', nullable: true},
     *     _groups: array{name: '_groups', type: 'json', nullable: true}}
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function getMetadataColumns(): array
    {
        return [
            self::METADATA_PREFIX.'id'             => [
                'name'          => self::METADATA_PREFIX.'id',
                'type'          => 'bigint',
                'nullable'      => false,
                'autoincrement' => true,
                'primary'       => true,
            ],
            self::METADATA_PREFIX.'uuid'           => [
                'name'     => self::METADATA_PREFIX.'uuid',
                'type'     => 'string',
                'length'   => 36,
                'nullable' => false,
                'unique'   => true,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'slug'           => [
                'name'     => self::METADATA_PREFIX.'slug',
                'type'     => 'string',
                'length'   => 255,
                'nullable' => true,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'uri'            => [
                'name'     => self::METADATA_PREFIX.'uri',
                'type'     => 'text',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'version'        => [
                'name'     => self::METADATA_PREFIX.'version',
                'type'     => 'string',
                'length'   => 50,
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'register'       => [
                'name'     => self::METADATA_PREFIX.'register',
                'type'     => 'string',
                'length'   => 255,
                'nullable' => false,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'schema'         => [
                'name'     => self::METADATA_PREFIX.'schema',
                'type'     => 'string',
                'length'   => 255,
                'nullable' => false,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'owner'          => [
                'name'     => self::METADATA_PREFIX.'owner',
                'type'     => 'string',
                'length'   => 64,
                'nullable' => true,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'organisation'   => [
                'name'     => self::METADATA_PREFIX.'organisation',
                'type'     => 'string',
                'length'   => 36,
                'nullable' => true,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'application'    => [
                'name'     => self::METADATA_PREFIX.'application',
                'type'     => 'string',
                'length'   => 255,
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'folder'         => [
                'name'     => self::METADATA_PREFIX.'folder',
                'type'     => 'string',
                'length'   => 255,
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'name'           => [
                'name'     => self::METADATA_PREFIX.'name',
                'type'     => 'string',
                'length'   => 255,
                'nullable' => true,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'description'    => [
                'name'     => self::METADATA_PREFIX.'description',
                'type'     => 'text',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'summary'        => [
                'name'     => self::METADATA_PREFIX.'summary',
                'type'     => 'string',
                'length'   => 500,
                'nullable' => true,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'image'          => [
                'name'     => self::METADATA_PREFIX.'image',
                'type'     => 'text',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'size'           => [
                'name'     => self::METADATA_PREFIX.'size',
                'type'     => 'string',
                'length'   => 50,
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'schema_version' => [
                'name'     => self::METADATA_PREFIX.'schema_version',
                'type'     => 'string',
                'length'   => 50,
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'created'        => [
                'name'     => self::METADATA_PREFIX.'created',
                'type'     => 'datetime',
                'nullable' => true,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'updated'        => [
                'name'     => self::METADATA_PREFIX.'updated',
                'type'     => 'datetime',
                'nullable' => true,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'published'      => [
                'name'     => self::METADATA_PREFIX.'published',
                'type'     => 'datetime',
                'nullable' => true,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'depublished'    => [
                'name'     => self::METADATA_PREFIX.'depublished',
                'type'     => 'datetime',
                'nullable' => true,
                'index'    => true,
            ],
            self::METADATA_PREFIX.'expires'        => [
                'name'     => self::METADATA_PREFIX.'expires',
                'type'     => 'datetime',
                'nullable' => true,
                'index'    => true,
            ],
            // JSON columns for complex data.
            self::METADATA_PREFIX.'files'          => [
                'name'     => self::METADATA_PREFIX.'files',
                'type'     => 'json',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'relations'      => [
                'name'     => self::METADATA_PREFIX.'relations',
                'type'     => 'json',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'locked'         => [
                'name'     => self::METADATA_PREFIX.'locked',
                'type'     => 'json',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'authorization'  => [
                'name'     => self::METADATA_PREFIX.'authorization',
                'type'     => 'json',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'validation'     => [
                'name'     => self::METADATA_PREFIX.'validation',
                'type'     => 'json',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'deleted'        => [
                'name'     => self::METADATA_PREFIX.'deleted',
                'type'     => 'json',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'geo'            => [
                'name'     => self::METADATA_PREFIX.'geo',
                'type'     => 'json',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'retention'      => [
                'name'     => self::METADATA_PREFIX.'retention',
                'type'     => 'json',
                'nullable' => true,
            ],
            self::METADATA_PREFIX.'groups'         => [
                'name'     => self::METADATA_PREFIX.'groups',
                'type'     => 'json',
                'nullable' => true,
            ],
        ];
    }//end getMetadataColumns()

    /**
     * Map JSON schema property to SQL column definition
     *
     * @param string $propertyName   The property name
     * @param array  $propertyConfig The property configuration from JSON schema
     *
     * @psalm-param SchemaPropertyConfig $propertyConfig
     *
     * @return (bool|int|mixed|null|string)[] Column definition.
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function mapSchemaPropertyToColumn(string $propertyName, array $propertyConfig): array
    {
        $type   = $propertyConfig['type'] ?? 'string';
        $format = $propertyConfig['format'] ?? null;

        // Sanitize column name.
        $columnName = $this->sanitizeColumnName($propertyName);

        switch ($type) {
            case 'string':
                return $this->mapStringProperty(columnName: $columnName, propertyConfig: $propertyConfig, format: $format);

            case 'integer':
                return $this->mapIntegerProperty(columnName: $columnName, propertyConfig: $propertyConfig);

            case 'number':
                return $this->mapNumberProperty(columnName: $columnName, propertyConfig: $propertyConfig);

            case 'boolean':
                // Determine default value.
                $defaultValue = null;
                if (is_array($propertyConfig) === true && array_key_exists('default', $propertyConfig) === true) {
                    $defaultValue = $propertyConfig['default'];
                }

                // Handle 'required' - can be boolean (property level) or array (schema level).
                $required   = $propertyConfig['required'] ?? false;
                $isRequired = false;
                if (is_array($required) === true) {
                    $isRequired = in_array($propertyName, $required);
                } else if (is_bool($required) === true) {
                    $isRequired = $required;
                }
                return [
                    'name'     => $columnName,
                    'type'     => 'boolean',
                    'nullable' => $isRequired === false,
                    // PropertyConfig may contain 'default' key even if not in type definition.
                    'default'  => $defaultValue,
                ];

            case 'array':
            case 'object':
                // Handle 'required' - can be boolean (property level) or array (schema level).
                $required   = $propertyConfig['required'] ?? false;
                $isRequired = false;
                if (is_array($required) === true) {
                    $isRequired = in_array($propertyName, $required);
                } else if (is_bool($required) === true) {
                    $isRequired = $required;
                }

                // Check if this is an object reference (related-object).
                // For object references, we store only the UUID string instead of full JSON object.
                // This allows CSV imports with UUID strings to work directly.
                $objectConfig = $propertyConfig['objectConfiguration'] ?? [];
                $handling     = $objectConfig['handling'] ?? null;
                $hasRef       = isset($propertyConfig['$ref']);

                if ($type === 'object' && $hasRef === true && $handling === 'related-object') {
                    // This is a reference to another object - store as UUID string.
                    $this->logger->debug(
                        'Detected object reference property, using VARCHAR for UUID storage',
                        [
                            'propertyName' => $propertyName,
                            '$ref'         => $propertyConfig['$ref'],
                            'handling'     => $handling,
                        ]
                    );

                    return [
                        'name'     => $columnName,
                        'type'     => 'string',
                        'length'   => 255,
                        'nullable' => $isRequired === false,
                        'comment'  => 'Object reference (UUID)',
                    ];
                }

                // Store complex types as JSON.
                return [
                    'name'     => $columnName,
                    'type'     => 'json',
                    'nullable' => $isRequired === false,
                ];

            default:
                // Unknown type - store as JSON for flexibility.
                $this->logger->warning(
                    'Unknown schema property type, storing as JSON',
                    [
                        'propertyName' => $propertyName,
                        'type'         => $type,
                    ]
                );

                return [
                    'name'     => $columnName,
                    'type'     => 'json',
                    'nullable' => true,
                ];
        }//end switch
    }//end mapSchemaPropertyToColumn()

    /**
     * Map string property to SQL column
     *
     * @param string      $columnName     The column name
     * @param array       $propertyConfig The property configuration
     * @param string|null $format         The format specification
     *
     * @return (bool|int|string)[]
     *
     * @psalm-param SchemaPropertyConfig $propertyConfig
     *
     * @psalm-return array{name: string, type: 'datetime'|'string'|'text',
     *                       nullable: bool, index?: bool, length?: int<min, 320>}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function mapStringProperty(string $columnName, array $propertyConfig, ?string $format): array
    {
        $maxLength = $propertyConfig['maxLength'] ?? null;
        // Handle 'required' - can be boolean (property level) or array (schema level).
        $required   = $propertyConfig['required'] ?? false;
        $isRequired = false;
        if (is_array($required) === true) {
            $isRequired = in_array($columnName, $required);
        } else if (is_bool($required) === true) {
            $isRequired = $required;
        }

        // Handle special formats.
        switch ($format) {
            case 'date':
            case 'date-time':
                return [
                    'name'     => $columnName,
                    'type'     => 'datetime',
                    'nullable' => $isRequired === false,
                    'index'    => true,
            // Date fields are often used for filtering.
                ];

            case 'email':
                return [
                    'name'     => $columnName,
                    'type'     => 'string',
                    'length'   => 320,
            // RFC 5321 email length limit.
                    'nullable' => $isRequired === false,
                    'index'    => true,
                ];

            case 'uri':
            case 'url':
                return [
                    'name'     => $columnName,
                    'type'     => 'text',
                    'nullable' => $isRequired === false,
                ];

            case 'uuid':
                return [
                    'name'     => $columnName,
                    'type'     => 'string',
                    'length'   => 36,
                    'nullable' => $isRequired === false,
                    'index'    => true,
                ];

            default:
                // Regular string.
                if (($maxLength !== null) === false || $maxLength > 255) {
                    return [
                        'name'     => $columnName,
                        'type'     => 'text',
                        'nullable' => ($isRequired === false),
                    ];
                }
                return [
                    'name'     => $columnName,
                    'type'     => 'string',
                    'length'   => $maxLength,
                    'nullable' => $isRequired === false,
                    'index'    => $maxLength <= 100,
                // Index shorter strings for performance.
                ];
        }//end switch
    }//end mapStringProperty()

    /**
     * Map integer property to SQL column
     *
     * @param string $columnName     The column name
     * @param array  $propertyConfig The property configuration
     *
     * @return (bool|mixed|null|string)[]
     *
     * @psalm-param SchemaPropertyConfig $propertyConfig
     *
     * @psalm-return array{name: string, type: 'bigint'|'integer'|'smallint',
     *                       nullable: bool, default: mixed|null, index: true}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function mapIntegerProperty(string $columnName, array $propertyConfig): array
    {
        $minimum = $propertyConfig['minimum'] ?? null;
        $maximum = $propertyConfig['maximum'] ?? null;
        // Handle 'required' - can be boolean (property level) or array (schema level).
        $required   = $propertyConfig['required'] ?? false;
        $isRequired = false;
        if (is_array($required) === true) {
            $isRequired = in_array($columnName, $required);
        } else if (is_bool($required) === true) {
            $isRequired = $required;
        }

        // Choose appropriate integer type based on range.
        $intType = 'integer';
        if ($minimum !== null && $minimum >= 0 && $maximum !== null
            && $maximum <= 65535
        ) {
            $intType = 'smallint';
        } else if ($maximum !== null && $maximum > 2147483647) {
            $intType = 'bigint';
        }

        // Determine default value.
        $defaultValue = null;
        if (is_array($propertyConfig) === true && array_key_exists('default', $propertyConfig) === true) {
            $defaultValue = $propertyConfig['default'];
        }

        return [
            'name'     => $columnName,
            'type'     => $intType,
            'nullable' => $isRequired === false,
            // PropertyConfig may contain 'default' key even if not in type definition.
            'default'  => $defaultValue,
            'index'    => true,
        // Integer fields are often used for filtering.
        ];
    }//end mapIntegerProperty()

    /**
     * Map number property to SQL column
     *
     * @param string $columnName     The column name
     * @param array  $propertyConfig The property configuration
     *
     * @return array Column definition
     *
     * @psalm-param  SchemaPropertyConfig $propertyConfig
     * @psalm-return array{default: mixed|null, index: true, name: string,
     *                      nullable: bool, precision: 10, scale: 2, type: 'decimal'}
     */
    private function mapNumberProperty(string $columnName, array $propertyConfig): array
    {
        // Handle 'required' - can be boolean (property level) or array (schema level).
        $required   = $propertyConfig['required'] ?? false;
        $isRequired = false;
        if (is_array($required) === true) {
            $isRequired = in_array($columnName, $required);
        } else if (is_bool($required) === true) {
            $isRequired = $required;
        }

        // Determine default value.
        $defaultValue = null;
        if (is_array($propertyConfig) === true && array_key_exists('default', $propertyConfig) === true) {
            $defaultValue = $propertyConfig['default'];
        }

        return [
            'name'      => $columnName,
            'type'      => 'decimal',
            'precision' => 10,
            'scale'     => 2,
            'nullable'  => $isRequired === false,
            // PropertyConfig may contain 'default' key even if not in type definition.
            'default'   => $defaultValue,
            'index'     => true,
        // Numeric fields are often used for filtering.
        ];
    }//end mapNumberProperty()

    /**
     * Create table with specified columns
     *
     * @param string $tableName The table name
     * @param array  $columns   Array of column definitions
     *
     * @throws Exception If table creation fails
     *
     * @return void
     */

    /**
     * Create a new table with specified columns.
     *
     * Uses Nextcloud 32+ compatible schema API for table creation.
     *
     * @param string $tableName The table name
     * @param array  $columns   The column definitions
     *
     * @throws Exception If table creation fails
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)       Table creation requires handling many column types
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Complete table creation requires comprehensive column handling
     */
    private function createTable(string $tableName, array $columns): void
    {
        try {
            // Build CREATE TABLE SQL manually for Nextcloud 32 compatibility.
            $platform   = $this->db->getDatabasePlatform();
            $isPostgres = ($platform->getName() === 'postgresql');

            // Get database table prefix from Nextcloud config.
            $tablePrefix   = $this->config->getSystemValue('dbtableprefix', 'oc_');
            $fullTableName = $tablePrefix.$tableName;

            // Build column definitions.
            $columnDefs        = [];
            $primaryKey        = null;
            $uniqueConstraints = [];

            foreach ($columns as $column) {
                $colName = '`'.$column['name'].'`';
                if ($isPostgres === true) {
                    $colName = '"'.$column['name'].'"';
                }

                $def = $colName.' ';

                // Map type to SQL.
                $def .= $this->mapColumnTypeToSQL(type: $column['type'], column: $column);

                // NOT NULL constraint.
                if (($column['nullable'] ?? true) === false) {
                    $def .= ' NOT NULL';
                }

                // DEFAULT value.
                if (isset($column['default']) === true) {
                    $defaultValue = $column['default'];
                    if (is_bool($column['default']) === true) {
                        // Boolean values need special handling for SQL.
                        $defaultValue = 'FALSE';
                        if ($column['default'] === true) {
                            $defaultValue = 'TRUE';
                        }
                    } else if (is_string($column['default']) === true) {
                        $defaultValue = "'".$column['default']."'";
                    } else if ($column['default'] === null) {
                        $defaultValue = 'NULL';
                    }

                    $def .= ' DEFAULT '.$defaultValue;
                }

                // AUTOINCREMENT (primary key columns).
                if (($column['autoincrement'] ?? false) === true) {
                    // MySQL uses AUTO_INCREMENT.
                    $def .= ' AUTO_INCREMENT';
                    if ($isPostgres === true) {
                        // PostgreSQL uses BIGSERIAL.
                        $def = $colName.' BIGSERIAL';
                    }
                }

                $columnDefs[] = $def;

                // Track primary key.
                if (($column['primary'] ?? false) === true) {
                    $primaryKey = '`'.$column['name'].'`';
                    if ($isPostgres === true) {
                        $primaryKey = '"'.$column['name'].'"';
                    }
                }

                // Track unique constraints (required for PostgreSQL ON CONFLICT).
                if (($column['unique'] ?? false) === true) {
                    $uniqueConstraints[] = $colName;
                }
            }//end foreach

            // Build CREATE TABLE SQL with full table name (including prefix).
            $tableNameQuoted = '`'.$fullTableName.'`';
            if ($isPostgres === true) {
                $tableNameQuoted = '"'.$fullTableName.'"';
            }

            $sql  = 'CREATE TABLE IF NOT EXISTS '.$tableNameQuoted.' (';
            $sql .= implode(', ', $columnDefs);

            // Add PRIMARY KEY constraint.
            if ($primaryKey !== null) {
                $sql .= ', PRIMARY KEY ('.$primaryKey.')';
            }

            // Add UNIQUE constraints (required for PostgreSQL ON CONFLICT).
            foreach ($uniqueConstraints as $uniqueCol) {
                $sql .= ', UNIQUE ('.$uniqueCol.')';
            }

            $sql .= ')';

            // Execute table creation.
            $this->db->executeStatement($sql);

            $this->logger->debug(
                '[MagicMapper] Created table with columns',
                [
                    'tableName'     => $tableName,
                    'fullTableName' => $fullTableName,
                    'columns'       => array_column($columns, 'name'),
                ]
            );
        } catch (Exception $e) {
            $this->logger->error(
                '[MagicMapper] Failed to create table',
                [
                    'tableName' => $tableName,
                    'error'     => $e->getMessage(),
                ]
            );

            throw new Exception('Failed to create table '.$tableName.': '.$e->getMessage(), 0, $e);
        }//end try
    }//end createTable()

    /**
     * Map column type to SQL type string.
     *
     * @param string $type   The Doctrine type
     * @param array  $column The column configuration
     *
     * @return string The SQL type string
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Type mapping switch requires handling all SQL types
     */
    private function mapColumnTypeToSQL(string $type, array $column): string
    {
        $platform   = $this->db->getDatabasePlatform();
        $isPostgres = ($platform->getName() === 'postgresql');

        switch ($type) {
            case 'bigint':
                return 'BIGINT';
            case 'integer':
                return 'INTEGER';
            case 'smallint':
                return 'SMALLINT';
            case 'string':
                $length = $column['length'] ?? 255;
                return "VARCHAR($length)";
            case 'text':
                return 'TEXT';
            case 'datetime':
                if ($isPostgres === true) {
                    return 'TIMESTAMP';
                }
                return 'DATETIME';
            case 'boolean':
                return 'BOOLEAN';
            case 'decimal':
                $precision = $column['precision'] ?? 10;
                $scale     = $column['scale'] ?? 2;
                return "DECIMAL($precision,$scale)";
            case 'json':
                if ($isPostgres === true) {
                    return 'JSONB';
                }
                return 'JSON';
            default:
                return 'TEXT';
        }//end switch
    }//end mapColumnTypeToSQL()

    /**
     * Create indexes for table performance
     *
     * @param string   $tableName The table name
     * @param Register $_register The register context (unused)
     * @param Schema   $_schema   The schema for index analysis (unused)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function createTableIndexes(string $tableName, Register $_register, Schema $_schema): void
    {
        try {
            // Add prefix for raw SQL queries.
            $fullTableName = 'oc_'.$tableName;

            // Create unique index on UUID.
            // Phpcs:ignore Generic.Files.LineLength.TooLong
            $this->db->executeStatement(
                "CREATE UNIQUE INDEX IF NOT EXISTS {$tableName}_uuid_idx ON {$fullTableName} (".self::METADATA_PREFIX."uuid)"
            );

            // Create composite index on register + schema for multitenancy.
            $registerCol = self::METADATA_PREFIX.'register';
            $schemaCol   = self::METADATA_PREFIX.'schema';
            $idxName     = "{$tableName}_register_schema_idx";
            $this->db->executeStatement(
                "CREATE INDEX IF NOT EXISTS {$idxName} ON {$fullTableName} ({$registerCol}, {$schemaCol})"
            );

            // Create index on organisation for multitenancy.
            $orgCol = self::METADATA_PREFIX.'organisation';
            $orgIdx = "{$tableName}_organisation_idx";
            $this->db->executeStatement(
                "CREATE INDEX IF NOT EXISTS {$orgIdx} ON {$fullTableName} ({$orgCol})"
            );

            // Create index on owner for RBAC.
            $ownerCol = self::METADATA_PREFIX.'owner';
            $ownerIdx = "{$tableName}_owner_idx";
            $this->db->executeStatement(
                "CREATE INDEX IF NOT EXISTS {$ownerIdx} ON {$fullTableName} ({$ownerCol})"
            );

            // Create indexes on frequently filtered metadata fields.
            $idxMetaFields = ['created', 'updated', 'published', 'name'];
            foreach ($idxMetaFields as $field) {
                $col = self::METADATA_PREFIX.$field;
                $idx = "{$tableName}_{$field}_idx";
                $this->db->executeStatement(
                    "CREATE INDEX IF NOT EXISTS {$idx} ON {$fullTableName} ({$col})"
                );
            }

            $this->logger->debug(
                'Created table indexes',
                [
                    'tableName'  => $tableName,
                    'indexCount' => 4 + count($idxMetaFields),
                ]
            );
        } catch (Exception $e) {
            $this->logger->warning(
                'Failed to create some table indexes',
                [
                    'tableName' => $tableName,
                    'error'     => $e->getMessage(),
                ]
            );
            // Don't fail table creation if indexes fail.
        }//end try
    }//end createTableIndexes()

    /**
     * Save single object to register+schema-specific table
     *
     * @param array    $objectData The object data to save
     * @param Register $register   The register context
     * @param Schema   $schema     The schema for validation and table selection
     * @param string   $tableName  The table name to save to
     *
     * @throws Exception If save operation fails
     *
     * @return string The UUID of the saved object
     */
    private function saveObjectToRegisterSchemaTable(
        array $objectData,
        Register $register,
        Schema $schema,
        string $tableName
    ): string {
        // Prepare object data for table storage with register+schema context.
        $preparedData = $this->prepareObjectDataForTable(objectData: $objectData, register: $register, schema: $schema);

        // Generate UUID if not provided in prepared data.
        // Check both in metadata and top-level for UUID.
        $metaKey      = self::METADATA_PREFIX.'uuid';
        $uuidFromMeta = $preparedData[$metaKey] ?? null;
        $uuidFromSelf = $objectData['@self']['id'] ?? $objectData['@self']['uuid'] ?? null;
        $uuidFromTop  = $objectData['id'] ?? $objectData['uuid'] ?? null;
        $existingUuid = $uuidFromMeta ?? $uuidFromSelf ?? $uuidFromTop;

        $preparedData[self::METADATA_PREFIX.'uuid'] = Uuid::v4()->toRfc4122();
        if (empty($existingUuid) === false) {
            $preparedData[self::METADATA_PREFIX.'uuid'] = $existingUuid;
        }

        $uuid = $preparedData[self::METADATA_PREFIX.'uuid'];

        try {
            // Check if object exists (for update vs insert).
            $existingObject = $this->findObjectInRegisterSchemaTable(uuid: $uuid, tableName: $tableName);

            if ($existingObject === null) {
                // Insert new object.
                $this->insertObjectInRegisterSchemaTable(data: $preparedData, tableName: $tableName);
                $this->logger->debug(
                    'Inserted object in register+schema table',
                    [
                        'uuid'      => $uuid,
                        'tableName' => $tableName,
                    ]
                );
                return $uuid;
            }

            // Update existing object.
            $this->updateObjectInRegisterSchemaTable(uuid: $uuid, data: $preparedData, tableName: $tableName);
            $this->logger->debug(
                'Updated object in register+schema table',
                [
                    'uuid'      => $uuid,
                    'tableName' => $tableName,
                ]
            );
            return $uuid;
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to save object to register+schema table',
                [
                    'uuid'      => $uuid,
                    'tableName' => $tableName,
                    'error'     => $e->getMessage(),
                ]
            );

            throw $e;
        }//end try
    }//end saveObjectToRegisterSchemaTable()

    /**
     * Prepare object data for storage in register+schema table
     *
     * @param array    $objectData The object data to prepare
     * @param Register $register   The register context
     * @param Schema   $schema     The schema for validation
     *
     * @return (false|mixed|null|string)[]
     *
     * @psalm-return array<string, false|mixed|null|string>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Data preparation requires handling many field types
     * @SuppressWarnings(PHPMD.NPathComplexity)       Data preparation requires handling many field types
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Complex field mapping requires comprehensive handling
     */
    private function prepareObjectDataForTable(array $objectData, Register $register, Schema $schema): array
    {
        $preparedData = [];
        $now          = new DateTime();

        // Extract @self metadata if present.
        $metadata = $objectData['@self'] ?? [];
        $data     = $objectData;
        unset($data['@self']);

        // Ensure register and schema IDs are set correctly.
        if (empty($metadata['register']) === true) {
            $metadata['register'] = $register->getId();
        }

        if (empty($metadata['schema']) === true) {
            $metadata['schema'] = $schema->getId();
        }

        // Map metadata fields with prefix.
        $metadataFields = [
            'uuid',
            'slug',
            'uri',
            'version',
            'register',
            'schema',
            'owner',
            'organisation',
            'application',
            'folder',
            'name',
            'description',
            'summary',
            'image',
            'size',
            'schema_version',
            'files',
            'relations',
            'locked',
            'authorization',
            'validation',
            'deleted',
            'geo',
            'retention',
            'groups',
            'created',
            'updated',
            'published',
            'depublished',
            'expires',
        ];

        foreach ($metadataFields as $field) {
            $value = $metadata[$field] ?? null;

            // Handle datetime fields.
            if (in_array($field, ['created', 'updated', 'published', 'depublished', 'expires']) === true) {
                if ($value === null && in_array($field, ['created', 'updated']) === true) {
                    $value = $now;
                }

                if ($value instanceof DateTime) {
                    $value = $value->format('Y-m-d H:i:s');
                } else if (is_string($value) === true) {
                    // Validate and convert datetime strings.
                    try {
                        $dateTime = new DateTime($value);
                        $value    = $dateTime->format('Y-m-d H:i:s');
                    } catch (Exception $e) {
                        $value = null;
                    }
                }
            }

            // Handle JSON fields.
            $jsonFields = [
                'files',
                'relations',
                'locked',
                'authorization',
                'validation',
                'deleted',
                'geo',
                'retention',
                'groups',
            ];
            if (in_array($field, $jsonFields) === true) {
                // Convert to JSON if not already a string, but treat empty arrays as NULL.
                if ($value !== null) {
                    if (is_array($value) === true && empty($value) === true) {
                        // Empty array should be NULL for proper IS NULL checks.
                        $value = null;
                    } else if (is_string($value) === false) {
                        $value = json_encode($value);
                    }
                }
            }

            $preparedData[self::METADATA_PREFIX.$field] = $value;
        }//end foreach

        // Map schema properties to columns.
        $schemaProperties = $schema->getProperties();
        if (is_array($schemaProperties) === true) {
            foreach (array_keys($schemaProperties) as $propertyName) {
                if (($data[$propertyName] ?? null) !== null) {
                    $value = $data[$propertyName];

                    // Convert complex types to JSON.
                    if (is_array($value) === true || is_object($value) === true) {
                        $value = json_encode($value);
                    }

                    $preparedData[$this->sanitizeColumnName($propertyName)] = $value;
                }
            }
        }

        return $preparedData;
    }//end prepareObjectDataForTable()

    /**
     * Execute search in register+schema-specific table
     *
     * @param array    $query     Search query parameters
     * @param Register $register  The register context
     * @param Schema   $schema    The schema for context
     * @param string   $tableName The table name to search
     *
     * @throws Exception If search fails
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<ObjectEntity>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Search execution requires handling many query options
     * @SuppressWarnings(PHPMD.NPathComplexity)      Search execution requires handling many query options
     */
    private function executeRegisterSchemaTableSearch(
        array $query,
        Register $register,
        Schema $schema,
        string $tableName
    ): array {
        $qb = $this->db->getQueryBuilder();

        // Don't use select('*') yet - we'll add it conditionally.
        $qb->from($tableName);

        // Apply _search (fuzzy, case-insensitive, multi-column search).
        $hasSearch = isset($query['_search']) === true && empty($query['_search']) === false;
        // No search, just select all columns.
        $qb->select('*');
        if ($hasSearch === true) {
            // Then apply fuzzy search which will add the score column.
            $this->applyFuzzySearch(qb: $qb, searchTerm: $query['_search'], schema: $schema);
        }

        // Apply filters.
        $this->applySearchFilters(qb: $qb, query: $query, schema: $schema);

        // Apply pagination.
        if (($query['_limit'] ?? null) !== null) {
            $qb->setMaxResults((int) $query['_limit']);
        }

        if (($query['_offset'] ?? null) !== null) {
            $qb->setFirstResult((int) $query['_offset']);
        }

        // Apply ordering (default: order by search relevance if _search is used).
        if (isset($query['_search']) === true && empty($query['_search']) === false && empty($query['_order']) === true) {
            // Order by search score descending when using _search (if it was added).
            $qb->addOrderBy('_search_score', 'DESC');
        } else if (($query['_order'] ?? null) !== null && is_array($query['_order']) === true) {
            foreach ($query['_order'] as $field => $direction) {
                $columnName = $this->sanitizeColumnName($field);
                if (str_starts_with($field, '@self.') === true) {
                    $columnName = self::METADATA_PREFIX.substr($field, 6);
                }

                $qb->addOrderBy($columnName, strtoupper($direction));
            }
        }

        try {
            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();

            // Convert rows back to ObjectEntity objects.
            $objects = [];
            foreach ($rows as $row) {
                $objectEntity = $this->convertRowToObjectEntity(row: $row, _register: $register, _schema: $schema);
                if ($objectEntity !== null) {
                    $objects[] = $objectEntity;
                }
            }

            $this->logger->debug(
                'Register+schema table search completed',
                [
                    'tableName'    => $tableName,
                    'resultCount'  => count($objects),
                    'queryFilters' => array_keys($query),
                ]
            );

            return $objects;
        } catch (Exception $e) {
            $this->logger->error(
                'Register+schema table search failed',
                [
                    'tableName' => $tableName,
                    'error'     => $e->getMessage(),
                ]
            );

            throw $e;
        }//end try
    }//end executeRegisterSchemaTableSearch()

    /**
     * Convert database row back to ObjectEntity
     *
     * @param array    $row       Database row data
     * @param Register $_register Register context for validation
     * @param Schema   $_schema   Schema for context
     *
     * @return ObjectEntity|null ObjectEntity or null if conversion fails
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */

    /**
     * Convert database row to ObjectEntity.
     *
     * This method is public to allow bulk handlers to convert rows for event dispatching.
     *
     * @param array    $row       Database row
     * @param Register $_register Register context
     * @param Schema   $_schema   Schema context
     *
     * @return ObjectEntity|null Converted entity or null on failure
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Row to entity conversion requires many field mappings
     * @SuppressWarnings(PHPMD.NPathComplexity)       Row to entity conversion requires many field mappings
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Complete field mapping requires comprehensive handling
     */
    public function convertRowToObjectEntity(array $row, Register $_register, Schema $_schema): ?ObjectEntity
    {
        try {
            $objectEntity = new ObjectEntity();

            // Set register and schema from parameters (these are the context we're in).
            $objectEntity->setRegister((string) $_register->getId());
            $objectEntity->setSchema((string) $_schema->getId());

            // Extract metadata fields (remove prefix).
            $metadata   = [];
            $objectData = [];

            foreach ($row as $columnName => $value) {
                if (str_starts_with($columnName, self::METADATA_PREFIX) === true) {
                    // This is a metadata field.
                    $metadataField = substr($columnName, strlen(self::METADATA_PREFIX));

                    // Handle datetime fields.
                    if (in_array(
                            $metadataField,
                            [
                                'created',
                                'updated',
                                'published',
                                'depublished',
                                'expires',
                            ],
                            true
                        ) === true
                        && ($value !== null) === true
                    ) {
                        $value = new DateTime($value);
                    }

                    // Handle JSON fields.
                    if (in_array(
                            $metadataField,
                            [
                                'files',
                                'relations',
                                'locked',
                                'authorization',
                                'validation',
                                'deleted',
                                'geo',
                                'retention',
                                'groups',
                            ],
                            true
                        ) === true
                        && ($value !== null) === true
                    ) {
                        $value = json_decode($value, true);
                    }

                    $metadata[$metadataField] = $value;
                    continue;
                }//end if

                // This is a schema property.
                // Convert snake_case column name back to camelCase property name.
                $propertyName = $this->columnNameToPropertyName($columnName);

                // Decode JSON values if they're JSON strings.
                $objectData[$propertyName] = $value;
                if (is_string($value) === true && $this->isJsonString($value) === true) {
                    $decodedValue = json_decode($value, true);
                    if ($decodedValue !== null) {
                        $objectData[$propertyName] = $decodedValue;
                    }
                }
            }//end foreach

            // Set metadata fields on ObjectEntity.
            foreach ($metadata as $field => $value) {
                if ($value === null) {
                    // Log when metadata field is null.
                    if ($field === 'uuid' || $field === 'id' || $field === 'owner') {
                        $this->logger->warning(
                            '[MagicMapper] Critical metadata field is null',
                            ['field' => $field]
                        );
                    }

                    continue;
                }

                $method = 'set'.ucfirst($field);
                // Use is_callable() instead of method_exists() to support magic methods.
                // Entity base class uses __call() for property setters.
                if (is_callable([$objectEntity, $method]) === false) {
                    $this->logger->warning(
                        '[MagicMapper] Method is not callable for metadata field',
                        ['field' => $field, 'method' => $method]
                    );
                    continue;
                }

                $objectEntity->$method($value);
                // Debug critical fields.
                if (in_array($field, ['id', 'uuid', 'owner'], true) === true) {
                    $this->logger->debug(
                        '[MagicMapper] Set critical metadata field',
                        ['field' => $field, 'value' => $value]
                    );
                }
            }//end foreach

            // Verify entity state after setting metadata.
            $this->logger->debug(
                '[MagicMapper] Entity state after metadata',
                [
                    'entityId'    => $objectEntity->getId(),
                    'entityUuid'  => $objectEntity->getUuid(),
                    'entityOwner' => $objectEntity->getOwner(),
                ]
            );
            // End foreach.
            // Set object data.
            $objectEntity->setObject($objectData);

            // CRITICAL FIX: Explicitly set ID and UUID to ensure they are never null.
            // These are essential for audit trails, rendering, and API responses.
            if (isset($metadata['id']) === true && $metadata['id'] !== null) {
                $idValue = $metadata['id'];
                if (is_numeric($idValue) === true) {
                    $objectEntity->setId((int) $idValue);
                }
            }

            if (isset($metadata['uuid']) === true && $metadata['uuid'] !== null) {
                $objectEntity->setUuid($metadata['uuid']);
            }

            // Debug logging.
            $this->logger->debug(
                '[MagicMapper] Successfully converted row to ObjectEntity',
                [
                    'uuid'           => $metadata['uuid'] ?? 'unknown',
                    'register'       => $metadata['register'] ?? 'missing',
                    'schema'         => $metadata['schema'] ?? 'missing',
                    'objectDataKeys' => array_keys($objectData),
                    'metadataCount'  => count($metadata),
                ]
            );

            return $objectEntity;
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to convert row to ObjectEntity',
                [
                    'error' => $e->getMessage(),
                    'uuid'  => $row[self::METADATA_PREFIX.'uuid'] ?? 'unknown',
                ]
            );

            return null;
        }//end try
    }//end convertRowToObjectEntity()

    /**
     * Check if register+schema table exists (with caching)
     *
     * @param Register $register The register context
     * @param Schema   $schema   The schema context
     *
     * @return bool True if table exists
     */
    public function tableExistsForRegisterSchema(Register $register, Schema $schema): bool
    {
        return $this->existsTableForRegisterSchema(register: $register, schema: $schema);
    }//end tableExistsForRegisterSchema()

    /**
     * Sanitize column name for database compatibility.
     *
     * Converts camelCase to snake_case for PostgreSQL compatibility while
     * maintaining readability. OpenRegister properties are typically camelCase,
     * but PostgreSQL lowercases unquoted identifiers, so we explicitly convert
     * to snake_case.
     *
     * Examples:
     * - inStock -> in_stock
     * - firstName -> first_name
     * - isActive -> is_active
     *
     * @param string $name The property name to sanitize
     *
     * @return string The sanitized column name
     */
    private function sanitizeColumnName(string $name): string
    {
        // Convert camelCase to snake_case.
        // Insert underscore before uppercase letters, then lowercase everything.
        $name = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name);
        $name = strtolower($name);

        // Replace any remaining invalid characters with underscore.
        $name = preg_replace('/[^a-z0-9_]/', '_', $name);

        // Ensure it starts with a letter or underscore.
        if (preg_match('/^[a-z_]/', $name) === false) {
            $name = 'col_'.$name;
        }

        // Remove consecutive underscores.
        $name = preg_replace('/_+/', '_', $name);

        // Remove trailing underscores.
        $name = rtrim($name, '_');

        return $name;
    }//end sanitizeColumnName()

    /**
     * Convert snake_case column name back to camelCase property name.
     *
     * Used when reading data from magic mapper tables to restore the original
     * property names that OpenRegister expects.
     *
     * Examples:
     * - in_stock -> inStock
     * - first_name -> firstName
     * - is_active -> isActive
     *
     * @param string $columnName The snake_case column name
     *
     * @return string The camelCase property name
     */
    private function columnNameToPropertyName(string $columnName): string
    {
        // Convert snake_case to camelCase.
        return lcfirst(str_replace('_', '', ucwords($columnName, '_')));
    }//end columnNameToPropertyName()

    /**
     * Check if register+schema combination has changed since last table update
     *
     * @param Register $register The register to check
     * @param Schema   $schema   The schema to check
     *
     * @return bool True if register+schema has changed
     */
    private function hasRegisterSchemaChanged(Register $register, Schema $schema): bool
    {
        $registerId = $register->getId();
        $schemaId   = $schema->getId();
        $this->getCacheKey(registerId: $registerId, schemaId: $schemaId);

        $currentVersion = $this->getStoredRegisterSchemaVersion(registerId: $registerId, schemaId: $schemaId);
        $newVersion     = $this->calculateRegisterSchemaVersion(register: $register, schema: $schema);

        return $currentVersion !== $newVersion;
    }//end hasRegisterSchemaChanged()

    /**
     * Store register+schema version for change detection
     *
     * @param Register $register The register context
     * @param Schema   $schema   The schema to store version for
     *
     * @return void
     */
    private function storeRegisterSchemaVersion(Register $register, Schema $schema): void
    {
        $registerId = $register->getId();
        $schemaId   = $schema->getId();
        $cacheKey   = $this->getCacheKey(registerId: $registerId, schemaId: $schemaId);

        $version   = $this->calculateRegisterSchemaVersion(register: $register, schema: $schema);
        $configKey = 'table_version_'.$cacheKey;

        $this->appConfig->setValueString('openregister', $configKey, $version);

        // Also update structure cache.
        self::$tableStructureCache[$cacheKey] = $version;
    }//end storeRegisterSchemaVersion()

    /**
     * Get stored register+schema version
     *
     * @param int $registerId The register ID
     * @param int $schemaId   The schema ID
     *
     * @return null|string The stored version or null if not found
     */
    private function getStoredRegisterSchemaVersion(int $registerId, int $schemaId): string|null
    {
        $cacheKey  = $this->getCacheKey(registerId: $registerId, schemaId: $schemaId);
        $configKey = 'table_version_'.$cacheKey;

        $version = $this->appConfig->getValueString('openregister', $configKey, '');
        if ($version === '') {
            return null;
        }

        return $version;
    }//end getStoredRegisterSchemaVersion()

    /**
     * Calculate register+schema version hash for change detection
     *
     * @param Register $register The register to calculate version for
     * @param Schema   $schema   The schema to calculate version for
     *
     * @return string Register+schema version hash
     */
    private function calculateRegisterSchemaVersion(Register $register, Schema $schema): string
    {
        $combinedData = [
            'register' => [
                'id'      => $register->getId(),
                'title'   => $register->getTitle(),
                'version' => $register->getVersion(),
            ],
            'schema'   => [
                'id'         => $schema->getId(),
                'properties' => $schema->getProperties(),
                'required'   => $schema->getRequired(),
                'title'      => $schema->getTitle(),
                'version'    => $schema->getVersion(),
            ],
        ];

        return md5(json_encode($combinedData));
    }//end calculateRegisterSchemaVersion()

    /**
     * Apply search filters to query builder
     *
     * @param IQueryBuilder $qb     The query builder.
     * @param array         $query  The search parameters.
     * @param Schema|null   $schema The schema for type checking.
     *
     * @return void
     */
    private function applySearchFilters(IQueryBuilder $qb, array $query, ?Schema $schema=null): void
    {
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
            'limit',
            'offset',
            'page',
            'order',
            'sort',
            'search',
            'extend',
            'fields',
            'filter',
            'unset',
            'facets',
            'facetable',
            'aggregations',
            'debug',
            'source',
            'published',
            'rbac',
            'multi',
            'multitenancy',
            'validation',
            'events',
            'deleted',
            'register',
            'schema',
            'registers',
            'schemas',
        ];

        // Get schema properties for type checking.
        $properties = [];
        if ($schema !== null) {
            $properties = ($schema->getProperties() ?? []);
        }

        foreach ($query as $key => $value) {
            // Skip reserved parameters (both with and without underscore prefix).
            if (in_array($key, $reservedParams, true) === true) {
                continue;
            }

            // Handle _ids filter specially (UUID/slug lookup).
            if ($key === '_ids' && is_array($value) === true && empty($value) === false) {
                $orX = $qb->expr()->orX();
                $orX->add($qb->expr()->in('_uuid', $qb->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
                $orX->add($qb->expr()->in('_slug', $qb->createNamedParameter($value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));
                $qb->andWhere($orX);
                continue;
            }

            // Handle _relations_contains filter (find objects that reference a UUID).
            if ($key === '_relations_contains' && is_string($value) === true && empty($value) === false) {
                // Use PostgreSQL JSONB @> operator to check if _relations array contains the UUID.
                $qb->andWhere(
                    '_relations @> ' . $qb->createNamedParameter(json_encode([$value]))
                );
                continue;
            }

            // Skip other system parameters starting with underscore.
            if (str_starts_with($key, '_') === true) {
                continue;
            }

            // Handle @self metadata filters.
            if ($key === '@self' && is_array($value) === true) {
                foreach ($value as $metaField => $metaValue) {
                    $columnName = self::METADATA_PREFIX.$metaField;
                    $this->addWhereCondition(qb: $qb, columnName: $columnName, value: $metaValue);
                }

                continue;
            }

            // Handle schema property filters.
            $columnName   = $this->sanitizeColumnName($key);
            $propertyType = $properties[$key]['type'] ?? 'string';

            // Check if this is an array-type property (JSON array column).
            if ($propertyType === 'array') {
                $this->addJsonArrayWhereCondition(qb: $qb, columnName: $columnName, value: $value);
                continue;
            }

            $this->addWhereCondition(qb: $qb, columnName: $columnName, value: $value);
        }//end foreach
    }//end applySearchFilters()

    /**
     * Apply fuzzy search across multiple columns using PostgreSQL pg_trgm.
     *
     * This method implements case-insensitive, fuzzy search across all text-based
     * schema properties using trigram similarity. It adds:
     * - A WHERE clause that matches on any column using ILIKE and trigram % operator.
     * - A computed _search_score column for ranking results by relevance.
     *
     * Performance: ~1-2ms per query on typical datasets (tested with 6 rows).
     *
     * @param IQueryBuilder $qb         The query builder to modify.
     * @param string        $searchTerm The search term entered by the user.
     * @param Schema        $schema     The schema to determine searchable columns.
     *
     * @return void
     *
     * @psalm-suppress UndefinedClass PostgreSQLPlatform may not exist in all Doctrine versions.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Fuzzy search requires handling multiple database platforms
     * @SuppressWarnings(PHPMD.NPathComplexity)      Search scoring requires many conditional paths
     */
    private function applyFuzzySearch(IQueryBuilder $qb, string $searchTerm, Schema $schema): void
    {
        // Get all text-based properties from the schema.
        $properties       = $schema->getProperties() ?? [];
        $searchableFields = [];

        if (is_array($properties) === true) {
            foreach ($properties as $propertyName => $propertyConfig) {
                $type = $propertyConfig['type'] ?? 'string';
                // Only search in string fields.
                if ($type === 'string') {
                    $columnName         = $this->sanitizeColumnName($propertyName);
                    $searchableFields[] = $columnName;
                }
            }
        }

        if (empty($searchableFields) === true) {
            // No searchable fields found, skip _search.
            return;
        }

        // Build WHERE clause: match if ANY column matches (using OR).
        $orConditions = [];
        $platform     = $this->db->getDatabasePlatform();

        foreach ($searchableFields as $columnName) {
            if ($platform instanceof PostgreSQLPlatform === true) {
                // PostgreSQL: Use pg_trgm % operator and ILIKE for fuzzy + case-insensitive.
                // The % operator uses trigram similarity (requires pg_trgm extension).
                $orConditions[] = "LOWER({$columnName}) ILIKE LOWER(".$qb->createNamedParameter('%'.$searchTerm.'%').')';
                $orConditions[] = "LOWER({$columnName}) % LOWER(".$qb->createNamedParameter($searchTerm).')';
                continue;
            }

            // MariaDB/MySQL: Use LIKE for case-insensitive substring match.
            $orConditions[] = "LOWER({$columnName}) LIKE LOWER(".$qb->createNamedParameter('%'.$searchTerm.'%').')';
        }

        if (empty($orConditions) === false) {
            $qb->andWhere(implode(' OR ', $orConditions));
        }

        // Add computed _search_score column for PostgreSQL (for ranking).
        // We need to add the score as a literal expression to avoid quoting issues.
        if ($platform instanceof PostgreSQLPlatform === false) {
            // MariaDB doesn't have similarity function, use a constant score.
            $qb->addSelect($qb->createFunction('1 AS _search_score'));
            return;
        }

        $scoreExpressions = [];
        foreach ($searchableFields as $columnName) {
            // Build similarity expression for each field.
            $paramPlaceholder   = $qb->createNamedParameter($searchTerm);
            $scoreExpressions[] = "similarity(LOWER({$columnName}), LOWER({$paramPlaceholder}))";
        }

        // Build the GREATEST() expression.
        if (count($scoreExpressions) > 0) {
            $scoreFormula = 'GREATEST('.implode(', ', $scoreExpressions).')';
            // Use createFunction to add raw SQL expression.
            $qb->addSelect($qb->createFunction($scoreFormula.' AS _search_score'));
        }
    }//end applyFuzzySearch()

    /**
     * Add WHERE condition to query builder
     *
     * @param IQueryBuilder $qb         The query builder
     * @param string        $columnName The column name
     * @param mixed         $value      The filter value
     *
     * @return void
     */
    private function addWhereCondition(IQueryBuilder $qb, string $columnName, $value): void
    {
        if (is_array($value) === true) {
            // Handle array filters (IN operation).
            $qb->andWhere($qb->expr()->in($columnName, $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR_ARRAY)));
            return;
        }

        if (is_string($value) === true && str_contains($value, '%') === true) {
            // Handle LIKE operation.
            $qb->andWhere($qb->expr()->like($columnName, $qb->createNamedParameter($value)));
            return;
        }

        // Handle exact match.
        $qb->andWhere($qb->expr()->eq($columnName, $qb->createNamedParameter($value)));
    }//end addWhereCondition()

    /**
     * Add WHERE condition for JSON array columns using PostgreSQL jsonb operators.
     *
     * For JSON array columns (e.g., ["SaaS", "PaaS"]), this uses PostgreSQL's
     * jsonb containment operator (@>) to check if the array contains the value.
     *
     * When multiple values are provided, uses AND logic: the array must contain
     * ALL specified values (intersection filtering).
     *
     * @param IQueryBuilder $qb         Query builder to modify
     * @param string        $columnName Column name to filter
     * @param mixed         $value      Filter value (string or array of strings)
     *
     * @return void
     */
    private function addJsonArrayWhereCondition(IQueryBuilder $qb, string $columnName, mixed $value): void
    {
        // Normalize value to array.
        $values = [$value];
        if (is_array($value) === true) {
            $values = $value;
        }

        // Use createFunction to avoid quoting of the column name with type cast.
        $columnCast = $qb->createFunction("{$columnName}::jsonb");

        // Multiple values use AND logic: array must contain ALL specified values.
        foreach ($values as $v) {
            $jsonValue = json_encode([$v]);
            $paramName = $qb->createNamedParameter($jsonValue);
            $qb->andWhere("{$columnCast} @> {$paramName}");
        }
    }//end addJsonArrayWhereCondition()

    /**
     * Find object in register+schema table by UUID
     *
     * @param string $uuid      The object UUID
     * @param string $tableName The table name
     *
     * @return array|null Object data or null if not found
     */
    private function findObjectInRegisterSchemaTable(string $uuid, string $tableName): ?array
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from($tableName)
                ->where($qb->expr()->eq(self::METADATA_PREFIX.'uuid', $qb->createNamedParameter($uuid)));

            $result = $qb->executeQuery();
            $row    = $result->fetch();

            if (is_array($row) === false) {
                return null;
            }

            return $row;
        } catch (Exception $e) {
            $this->logger->warning(
                'Failed to find object in register+schema table',
                [
                    'uuid'      => $uuid,
                    'tableName' => $tableName,
                    'error'     => $e->getMessage(),
                ]
            );

            return null;
        }//end try
    }//end findObjectInRegisterSchemaTable()

    /**
     * Insert object into register+schema table
     *
     * @param array  $data      The object data to insert
     * @param string $tableName The table name
     *
     * @throws Exception If insert fails
     *
     * @return void
     */
    private function insertObjectInRegisterSchemaTable(array $data, string $tableName): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->insert($tableName);

        foreach ($data as $column => $value) {
            $qb->setValue($column, $qb->createNamedParameter($value));
        }

        $qb->executeStatement();
    }//end insertObjectInRegisterSchemaTable()

    /**
     * Update object in register+schema table
     *
     * @param string $uuid      The object UUID
     * @param array  $data      The object data to update
     * @param string $tableName The table name
     *
     * @throws Exception If update fails
     *
     * @return void
     */
    private function updateObjectInRegisterSchemaTable(string $uuid, array $data, string $tableName): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update($tableName);

        foreach ($data as $column => $value) {
            // Don't update the UUID itself.
            if ($column !== self::METADATA_PREFIX.'uuid') {
                $qb->set($column, $qb->createNamedParameter($value));
            }
        }

        $qb->where($qb->expr()->eq(self::METADATA_PREFIX.'uuid', $qb->createNamedParameter($uuid)));
        $qb->executeStatement();
    }//end updateObjectInRegisterSchemaTable()

    /**
     * Get existing table columns
     *
     * @param string $tableName The table name
     *
     * @throws Exception If unable to get table columns
     *
     * @return (bool|mixed)[][] Array of existing column definitions
     */
    private function getExistingTableColumns(string $tableName): array
    {
        try {
            // Use direct SQL query to get table columns (Nextcloud 32 compatible).
            // NOTE: We use raw SQL here because information_schema is a system table that should not be prefixed.
            $prefix = 'oc_';
            // Nextcloud default prefix.
            $fullTableName = $prefix.$tableName;

            $sql = "SELECT column_name, data_type, character_maximum_length, is_nullable, column_default 
                    FROM information_schema.columns 
                    WHERE table_name = ? AND table_schema = 'public'";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$fullTableName]);
            $columns = $stmt->fetchAll();

            $columnDefinitions = [];
            foreach ($columns as $column) {
                $columnDefinitions[$column['column_name']] = [
                    'name'     => $column['column_name'],
                    'type'     => $column['data_type'],
                    'length'   => $column['character_maximum_length'],
                    'nullable' => $column['is_nullable'] === 'YES',
                    'default'  => $column['column_default'],
                ];
            }

            return $columnDefinitions;
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to get existing table columns',
                [
                    'tableName' => $tableName,
                    'error'     => $e->getMessage(),
                ]
            );

            throw $e;
        }//end try
    }//end getExistingTableColumns()

    /**
     * Update table structure with new columns
     *
     * @param string $tableName       The table name
     * @param array  $currentColumns  Current column definitions
     * @param array  $requiredColumns Required column definitions
     *
     * @throws Exception If table update fails
     *
     * @return void
     */
    private function updateTableStructure(string $tableName, array $currentColumns, array $requiredColumns): void
    {
        // Find columns to add.
        $platform      = $this->db->getDatabasePlatform();
        $isPostgres    = ($platform->getName() === 'postgresql');
        $tablePrefix   = $this->config->getSystemValue('dbtableprefix', 'oc_');
        $fullTableName = $tablePrefix.$tableName;

        $tableNameQuoted = '`'.$fullTableName.'`';
        if ($isPostgres === true) {
            $tableNameQuoted = '"'.$fullTableName.'"';
        }

        foreach ($requiredColumns as $columnName => $columnDef) {
            if (isset($currentColumns[$columnName]) === false) {
                $this->logger->info(
                    'Adding new column to schema table',
                    [
                        'tableName'  => $tableName,
                        'columnName' => $columnName,
                        'columnType' => $columnDef['type'],
                    ]
                );

                // Build ALTER TABLE ADD COLUMN SQL.
                $colNameQuoted = '`'.$columnName.'`';
                if ($isPostgres === true) {
                    $colNameQuoted = '"'.$columnName.'"';
                }

                $colType = $this->mapColumnTypeToSQL(type: $columnDef['type'], column: $columnDef);

                $sql = 'ALTER TABLE '.$tableNameQuoted.' ADD COLUMN '.$colNameQuoted.' '.$colType;

                // Add NOT NULL if specified.
                if (($columnDef['nullable'] ?? true) === false) {
                    $sql .= ' NOT NULL';
                }

                // Add DEFAULT if specified.
                if (isset($columnDef['default']) === true) {
                    $defaultValue = $columnDef['default'];
                    if (is_bool($columnDef['default']) === true) {
                        // Boolean values need special handling for SQL.
                        $defaultValue = 'FALSE';
                        if ($columnDef['default'] === true) {
                            $defaultValue = 'TRUE';
                        }
                    } else if (is_string($columnDef['default']) === true) {
                        $defaultValue = "'".$columnDef['default']."'";
                    } else if ($columnDef['default'] === null) {
                        $defaultValue = 'NULL';
                    }

                    $sql .= ' DEFAULT '.$defaultValue;
                }

                // Execute ALTER TABLE.
                $this->db->executeStatement($sql);
            }//end if
        }//end foreach

        $this->logger->info(
            'Successfully updated table structure',
            [
                'tableName'    => $tableName,
                'columnsAdded' => array_diff(array_keys($requiredColumns), array_keys($currentColumns)),
            ]
        );
    }//end updateTableStructure()

    /**
     * Update table indexes
     *
     * @param string   $tableName The table name
     * @param Register $register  The register context
     * @param Schema   $schema    The schema for index analysis
     *
     * @return void
     */
    private function updateTableIndexes(string $tableName, Register $register, Schema $schema): void
    {
        // For now, recreate all indexes (more complex differential updates can be added later).
        $this->createTableIndexes(tableName: $tableName, _register: $register, _schema: $schema);
    }//end updateTableIndexes()

    /**
     * Drop table
     *
     * @param string $tableName The table name to drop
     *
     * @throws Exception If table drop fails
     *
     * @return void
     *
     * @psalm-suppress UndefinedInterfaceMethod quoteIdentifier exists via DBAL Connection
     */
    private function dropTable(string $tableName): void
    {
        try {
            // Use direct SQL to drop table (Nextcloud 32 compatible).
            $qb     = $this->db->getQueryBuilder();
            $prefix = 'oc_';
            // Nextcloud default prefix.
            $fullTableName = $prefix.$tableName;
            $quotedTable   = $qb->getConnection()->quoteIdentifier($fullTableName);
            $qb->getConnection()->executeStatement('DROP TABLE IF EXISTS '.$quotedTable);

            // Clear from cache - need to clear by table name pattern.
            foreach (array_keys(self::$tableExistsCache) as $cacheKey) {
                if ((self::$regSchemaTableCache[$cacheKey] ?? null) !== null
                    && self::$regSchemaTableCache[$cacheKey] === $tableName
                ) {
                    $this->invalidateTableCache($cacheKey);
                    break;
                }
            }

            $this->logger->info(
                'Dropped register+schema table',
                [
                    'tableName' => $tableName,
                ]
            );
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to drop table',
                [
                    'tableName' => $tableName,
                    'error'     => $e->getMessage(),
                ]
            );

            throw $e;
        }//end try
    }//end dropTable()

    /**
     * Check if string is valid JSON
     *
     * @param string $string The string to check
     *
     * @return bool True if string is valid JSON
     *
     * @psalm-suppress UnusedFunctionCall - intentional, we only check json_last_error()
     */
    private function isJsonString(string $string): bool
    {
        // Decode JSON to check for errors via json_last_error().
        // Note: We only care about json_last_error(), not the decoded value.
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }//end isJsonString()

    /**
     * Clear all caches for MagicMapper
     *
     * @param int|null $registerId Optional register ID to clear cache for specific register
     * @param int|null $schemaId   Optional schema ID to clear cache for specific schema
     *
     * @return void
     */
    public function clearCache(?int $registerId=null, ?int $schemaId=null): void
    {
        if ($registerId === null || $schemaId === null) {
            // Clear all caches.
            self::$tableExistsCache    = [];
            self::$regSchemaTableCache = [];
            self::$tableStructureCache = [];

            $this->logger->debug('Cleared all MagicMapper caches');
            return;
        }

        // Clear cache for specific register+schema combination.
        $cacheKey = $this->getCacheKey(registerId: $registerId, schemaId: $schemaId);
        $this->invalidateTableCache($cacheKey);

        $this->logger->debug(
            'Cleared MagicMapper cache for register+schema',
            [
                'registerId' => $registerId,
                'schemaId'   => $schemaId,
                'cacheKey'   => $cacheKey,
            ]
        );
    }//end clearCache()

    /**
     * Get all existing register+schema tables
     *
     * This method scans the database for all tables matching our naming pattern
     * and returns them as an array of register+schema combinations.
     *
     * @return (int|string)[][] Array of ['registerId' => int, 'schemaId' => int, 'tableName' => string].
     */
    public function getExistingRegisterSchemaTables(): array
    {
        try {
            // Use direct SQL to list tables (Nextcloud 32 compatible).
            // NOTE: We use raw SQL here because pg_tables is a system table that should not be prefixed.
            $prefix = 'oc_';
            // Nextcloud default prefix.
            $searchPattern = $prefix.self::TABLE_PREFIX.'%';

            $sql  = "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$searchPattern]);
            $rows = $stmt->fetchAll();

            $registerSchemaTables = [];
            $fullPrefix           = $prefix.self::TABLE_PREFIX;

            foreach ($rows as $row) {
                $tableName = $row['tablename'];
                if (str_starts_with($tableName, $fullPrefix) === true) {
                    // Extract register and schema IDs from table name.
                    $suffix = substr($tableName, strlen($fullPrefix));

                    // Expected format: {registerId}_{schemaId}.
                    if (preg_match('/^(\d+)_(\d+)$/', $suffix, $matches) === 1) {
                        $registerId = (int) $matches[1];
                        $schemaId   = (int) $matches[2];

                        $registerSchemaTables[] = [
                            'registerId' => $registerId,
                            'schemaId'   => $schemaId,
                            'tableName'  => $tableName,
                        ];

                        // Pre-populate cache while we're at it.
                        $cacheKey = $this->getCacheKey(registerId: $registerId, schemaId: $schemaId);
                        self::$tableExistsCache[$cacheKey]    = time();
                        self::$regSchemaTableCache[$cacheKey] = $tableName;
                    }
                }//end if
            }//end foreach

            $this->logger->info(
                'Found existing register+schema tables',
                [
                    'tableCount' => count($registerSchemaTables),
                ]
            );

            return $registerSchemaTables;
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to get existing register+schema tables',
                [
                    'error' => $e->getMessage(),
                ]
            );

            return [];
        }//end try
    }//end getExistingRegisterSchemaTables()

    /**
     * Check if MagicMapper is enabled for a register+schema combination
     *
     * @param Register $_register The register to check
     * @param Schema   $schema    The schema to check
     *
     * @return bool True if MagicMapper should be used for this register+schema
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function isMagicMappingEnabled(Register $_register, Schema $schema): bool
    {
        // Check schema configuration for magic mapping flag.
        $configuration = $schema->getConfiguration();

        // Enable magic mapping if explicitly enabled in schema config.
        $hasMagicMapping = is_array($configuration) === true
            && ($configuration['magicMapping'] ?? null) !== null
            && $configuration['magicMapping'] === true;
        if ($hasMagicMapping === true) {
            return true;
        }

        // Check global configuration.
        $globalEnabled = $this->appConfig->getValueString('openregister', 'magic_mapping_enabled', 'false');

        return $globalEnabled === 'true';
    }//end isMagicMappingEnabled()

    /**
     * BACKWARD COMPATIBILITY: Check if MagicMapper is enabled for a schema only
     *
     * @param Schema $schema The schema to check
     *
     * @deprecated Use isMagicMappingEnabled(Register, Schema) instead
     * @return     bool True if MagicMapper should be used for this schema
     */
    public function isMagicMappingEnabledForSchema(Schema $schema): bool
    {
        // For backward compatibility, just check schema config without register context.
        $configuration = $schema->getConfiguration();

        $hasMagicMapping = is_array($configuration) === true
            && ($configuration['magicMapping'] ?? null) !== null
            && $configuration['magicMapping'] === true;
        if ($hasMagicMapping === true) {
            return true;
        }

        $globalEnabled = $this->appConfig->getValueString(
            'openregister',
            'magic_mapping_enabled',
            'false'
        );
        return $globalEnabled === 'true';
    }//end isMagicMappingEnabledForSchema()

    // ==================================================================================
    // OBJECTENTITY-COMPATIBLE METHODS (UnifiedObjectMapper Integration)
    // ==================================================================================

    /**
     * Find object in register+schema table by identifier (ID, UUID, slug, or URI).
     *
     * This method provides ObjectEntity compatibility for the UnifiedObjectMapper.
     *
     * @param string|int $identifier Object identifier (ID, UUID, slug, or URI).
     * @param Register   $register   The register context.
     * @param Schema     $schema     The schema context.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple objects found.
     *
     * @return ObjectEntity The found object.
     */
    public function findInRegisterSchemaTable(
        string|int $identifier,
        Register $register,
        Schema $schema
    ): ObjectEntity {
        $tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);

        $this->logger->debug(
            'Finding object in register+schema table',
            [
                'identifier' => $identifier,
                'tableName'  => $tableName,
            ]
        );

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($tableName);

        // Build identifier conditions (ID, UUID, slug, or URI).
        $idParam = -1;
        if (is_numeric($identifier) === true) {
            $idParam = (int) $identifier;
        }

        $idCol   = self::METADATA_PREFIX.'id';
        $uuidCol = self::METADATA_PREFIX.'uuid';
        $slugCol = self::METADATA_PREFIX.'slug';
        $uriCol  = self::METADATA_PREFIX.'uri';
        $qb->where(
            $qb->expr()->orX(
                $qb->expr()->eq($idCol, $qb->createNamedParameter($idParam, IQueryBuilder::PARAM_INT)),
                $qb->expr()->eq($uuidCol, $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR)),
                $qb->expr()->eq($slugCol, $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR)),
                $qb->expr()->eq($uriCol, $qb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR))
            )
        );

        // Exclude deleted objects by default.
        $qb->andWhere($qb->expr()->isNull(self::METADATA_PREFIX.'deleted'));

        try {
            $result = $qb->executeQuery();
            $row    = $result->fetch();

            if ($row === false) {
                throw new DoesNotExistException('Object not found in magic table');
            }

            // Check for multiple results.
            if ($result->fetch() !== false) {
                $msg = 'Multiple objects found with same identifier';
                throw new MultipleObjectsReturnedException($msg);
            }

            $objectEntity = $this->convertRowToObjectEntity(row: $row, _register: $register, _schema: $schema);

            if ($objectEntity === null) {
                throw new DoesNotExistException('Failed to convert row to ObjectEntity');
            }

            return $objectEntity;
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->logger->error(
                'Failed to find object in register+schema table',
                [
                    'identifier' => $identifier,
                    'tableName'  => $tableName,
                    'error'      => $e->getMessage(),
                ]
            );

            throw new DoesNotExistException($e->getMessage());
        }//end try
    }//end findInRegisterSchemaTable()

    /**
     * Find all objects in register+schema table with filtering and pagination.
     *
     * @param Register   $register  The register context.
     * @param Schema     $schema    The schema context.
     * @param int|null   $limit     Maximum number of results.
     * @param int|null   $offset    Offset for pagination.
     * @param array|null $filters   Filters to apply.
     * @param array      $sort      Sort order.
     * @param bool|null  $published Whether to filter by published status.
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<ObjectEntity>
     */
    public function findAllInRegisterSchemaTable(
        Register $register,
        Schema $schema,
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=null,
        array $sort=[],
        ?bool $published=null
    ): array {
        $query = [];

        if ($limit !== null) {
            $query['_limit'] = $limit;
        }

        if ($offset !== null) {
            $query['_offset'] = $offset;
        }

        if (empty($sort) === false) {
            $query['_order'] = $sort;
        }

        if ($filters !== null) {
            $query = array_merge($query, $filters);
        }

        // Add published filter if specified.
        if ($published !== null) {
            // Only unpublished objects.
            $query['@self']['published'] = 'IS NULL';
            if ($published === true) {
                // Only published objects.
                $query['@self']['published'] = 'IS NOT NULL';
            }
        }

        return $this->searchObjectsInRegisterSchemaTable(query: $query, register: $register, schema: $schema);
    }//end findAllInRegisterSchemaTable()

    /**
     * Insert ObjectEntity into register+schema table.
     *
     * @param ObjectEntity $entity   The object entity to insert.
     * @param Register     $register The register context.
     * @param Schema       $schema   The schema context.
     *
     * @throws Exception If insertion fails.
     *
     * @return ObjectEntity The inserted object entity.
     */
    public function insertObjectEntity(
        ObjectEntity $entity,
        Register $register,
        Schema $schema
    ): ObjectEntity {
        // Dispatch creating event for audit trails.
        $this->eventDispatcher->dispatch(ObjectCreatingEvent::class, new ObjectCreatingEvent(object: $entity));

        // Ensure table exists.
        $this->ensureTableForRegisterSchema(register: $register, schema: $schema);

        $tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);

        $this->logger->debug(
            'Inserting object entity into register+schema table',
            [
                'uuid'      => $entity->getUuid(),
                'tableName' => $tableName,
            ]
        );

        // Set register and schema on entity if not already set.
        if ($entity->getRegister() === null) {
            $entity->setRegister((string) $register->getId());
        }

        if ($entity->getSchema() === null) {
            $entity->setSchema((string) $schema->getId());
        }

        // Ensure entity has a UUID before serialization.
        $entityUuid = $entity->getUuid();
        if ($entityUuid === null || $entityUuid === '') {
            $entityUuid = Uuid::v4()->toRfc4122();
            $entity->setUuid($entityUuid);
        }

        // Convert entity to array for table storage.
        $objectArray = $entity->jsonSerialize();

        // Save to table.
        $uuid = $this->saveObjectToRegisterSchemaTable(
            objectData: $objectArray,
            register: $register,
            schema: $schema,
            tableName: $tableName
        );

        // Update entity UUID if it was generated.
        if ($entity->getUuid() === null) {
            $entity->setUuid($uuid);
        }

        // CRITICAL FIX: Re-fetch the inserted object from database to get complete metadata.
        // This ensures the returned entity has all database-generated fields (ID, timestamps, etc.).
        try {
            $insertedEntity = $this->findInRegisterSchemaTable(
                identifier: $uuid,
                register: $register,
                schema: $schema
            );
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Fallback: manually set ID if re-fetch fails.
            $this->logger->warning('[MagicMapper] Failed to re-fetch inserted entity, using fallback');
            $row = $this->findObjectInRegisterSchemaTable(uuid: $uuid, tableName: $tableName);
            if ($row !== null) {
                $entity->setId((int) $row[self::METADATA_PREFIX.'id']);
            }

            $insertedEntity = $entity;
        }

        // Dispatch created event for audit trails with fresh entity.
        $this->eventDispatcher->dispatch(ObjectCreatedEvent::class, new ObjectCreatedEvent(object: $insertedEntity));

        return $insertedEntity;
    }//end insertObjectEntity()

    /**
     * Update ObjectEntity in register+schema table.
     *
     * @param ObjectEntity $entity   The object entity to update.
     * @param Register     $register The register context.
     * @param Schema       $schema   The schema context.
     *
     * @throws Exception If update fails.
     *
     * @return ObjectEntity The updated object entity.
     */
    public function updateObjectEntity(
        ObjectEntity $entity,
        Register $register,
        Schema $schema
    ): ObjectEntity {
        // Fetch old object for event dispatching.
        $oldObject = $this->findInRegisterSchemaTable(identifier: $entity->getUuid(), register: $register, schema: $schema);

        $this->logger->debug('[MagicMapper] updateObjectEntity called - UUID: '.$entity->getUuid());

        // Dispatch updating event for audit trails.
        $event = new ObjectUpdatingEvent(newObject: $entity, oldObject: $oldObject);
        $this->eventDispatcher->dispatch(ObjectUpdatingEvent::class, $event);

        $tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
        $uuid      = $entity->getUuid();

        if ($uuid === null) {
            throw new Exception('Cannot update object entity without UUID');
        }

        $this->logger->debug(
            'Updating object entity in register+schema table',
            [
                'uuid'      => $uuid,
                'tableName' => $tableName,
            ]
        );

        // Convert entity to array for table storage.
        $objectArray = $entity->jsonSerialize();

        // Update in table.
        $this->saveObjectToRegisterSchemaTable(
            objectData: $objectArray,
            register: $register,
            schema: $schema,
            tableName: $tableName
        );

        // CRITICAL FIX: Re-fetch the updated object from database to get fresh metadata.
        // This ensures the returned entity has correct updated timestamps, ID, etc.
        try {
            $updatedEntity = $this->findInRegisterSchemaTable(
                identifier: $uuid,
                register: $register,
                schema: $schema
            );
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Fallback: return input entity if re-fetch fails.
            $this->logger->warning('[MagicMapper] Failed to re-fetch updated entity, returning input entity');
            $updatedEntity = $entity;
        }

        // Dispatch updated event for audit trails with fresh entity.
        $event = new ObjectUpdatedEvent(newObject: $updatedEntity, oldObject: $oldObject);
        $this->eventDispatcher->dispatch(ObjectUpdatedEvent::class, $event);

        return $updatedEntity;
    }//end updateObjectEntity()

    /**
     * Delete ObjectEntity from register+schema table.
     *
     * Supports both soft delete (sets _deleted field) and hard delete (removes from table).
     *
     * @param ObjectEntity $entity     The object entity to delete.
     * @param Register     $register   The register context.
     * @param Schema       $schema     The schema context.
     * @param bool         $hardDelete Whether to perform hard delete (default: false for soft delete).
     *
     * @throws Exception If deletion fails.
     *
     * @return ObjectEntity The deleted object entity.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Hard delete toggle controls permanent vs soft delete
     */
    public function deleteObjectEntity(
        ObjectEntity $entity,
        Register $register,
        Schema $schema,
        bool $hardDelete=false
    ): ObjectEntity {
        // Dispatch deleting event for audit trails.
        $this->eventDispatcher->dispatch(ObjectDeletingEvent::class, new ObjectDeletingEvent(object: $entity));

        $tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
        $uuid      = $entity->getUuid();

        if ($uuid === null) {
            throw new Exception('Cannot delete object entity without UUID');
        }

        $this->logger->debug(
            'Deleting object entity from register+schema table',
            [
                'uuid'       => $uuid,
                'tableName'  => $tableName,
                'hardDelete' => $hardDelete,
            ]
        );

        if ($hardDelete === true) {
            // Hard delete - actually remove from table.
            $qb = $this->db->getQueryBuilder();
            $qb->delete($tableName)
                ->where($qb->expr()->eq(self::METADATA_PREFIX.'uuid', $qb->createNamedParameter($uuid)));
            $qb->executeStatement();

            $this->logger->info(
                'Hard deleted object from register+schema table',
                [
                    'uuid'      => $uuid,
                    'tableName' => $tableName,
                ]
            );
        }

        if ($hardDelete === false) {
            // Soft delete - set _deleted field.
            if ($entity->getDeleted() === null || empty($entity->getDeleted()) === true) {
                // Mark as deleted using entity method.
                $entity->delete($this->userSession, 'Soft deleted via MagicMapper', 30);
            }

            // Update entity in table with deleted field set.
            $this->updateObjectEntity(entity: $entity, register: $register, schema: $schema);

            $this->logger->info(
                'Soft deleted object in register+schema table',
                [
                    'uuid'      => $uuid,
                    'tableName' => $tableName,
                ]
            );
        }

        // Dispatch deleted event for audit trails.
        $this->eventDispatcher->dispatch(ObjectDeletedEvent::class, new ObjectDeletedEvent(object: $entity));

        return $entity;
    }//end deleteObjectEntity()

    /**
     * Lock object in register+schema table.
     *
     * @param ObjectEntity $entity       The object entity to lock.
     * @param Register     $register     The register context.
     * @param Schema       $schema       The schema context.
     * @param int|null     $lockDuration Lock duration in seconds (null for default).
     *
     * @throws Exception If locking fails.
     *
     * @return ObjectEntity The locked object entity.
     */
    public function lockObjectEntity(
        ObjectEntity $entity,
        Register $register,
        Schema $schema,
        ?int $lockDuration=null
    ): ObjectEntity {
        // Lock using entity method.
        $entity->lock(userSession: $this->userSession, process: 'MagicMapper lock', duration: $lockDuration);

        // Update entity in table with locked field set.
        $this->updateObjectEntity(entity: $entity, register: $register, schema: $schema);

        $this->logger->info(
            'Locked object in register+schema table',
            [
                'uuid'     => $entity->getUuid(),
                'duration' => $lockDuration,
            ]
        );

        // Dispatch locked event for audit trails.
        $this->eventDispatcher->dispatch(ObjectLockedEvent::class, new ObjectLockedEvent(object: $entity));

        return $entity;
    }//end lockObjectEntity()

    /**
     * Unlock object in register+schema table.
     *
     * @param ObjectEntity $entity   The object entity to unlock.
     * @param Register     $register The register context.
     * @param Schema       $schema   The schema context.
     *
     * @throws Exception If unlocking fails.
     *
     * @return ObjectEntity The unlocked object entity.
     */
    public function unlockObjectEntity(
        ObjectEntity $entity,
        Register $register,
        Schema $schema
    ): ObjectEntity {
        // Unlock using entity method.
        $entity->unlock($this->userSession);

        // Update entity in table with locked field cleared.
        $this->updateObjectEntity(entity: $entity, register: $register, schema: $schema);

        $this->logger->info(
            'Unlocked object in register+schema table',
            ['uuid' => $entity->getUuid()]
        );

        // Dispatch unlocked event for audit trails.
        $this->eventDispatcher->dispatch(ObjectUnlockedEvent::class, new ObjectUnlockedEvent(object: $entity));

        return $entity;
    }//end unlockObjectEntity()

    /**
     * Perform bulk upsert operation on register+schema table
     *
     * This method provides high-performance bulk insert/update operations for dynamic tables.
     * It delegates to MagicBulkHandler for the actual database operations.
     *
     * @param array    $objects   Array of object data in standard format
     * @param Register $register  Register context
     * @param Schema   $schema    Schema context
     * @param string   $tableName Target table name
     *
     * @return array[] Array of complete objects with object_status field
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @psalm-return list<array<string, mixed>>
     */
    public function bulkUpsert(array $objects, Register $register, Schema $schema, string $tableName): array
    {
        $this->logger->info(
            '[MagicMapper] Delegating bulk upsert to MagicBulkHandler',
            [
                'register'     => $register->getId(),
                'schema'       => $schema->getId(),
                'table'        => $tableName,
                'object_count' => count($objects),
            ]
        );

        return $this->bulkHandler->bulkUpsert(
            objects: $objects,
            register: $register,
            schema: $schema,
            tableName: $tableName
        );
    }//end bulkUpsert()
}//end class
