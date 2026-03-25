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
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12.
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use Exception;
use DateTime;
use stdClass;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCP\AppFramework\Db\Entity;
use OCA\OpenRegister\Db\MagicMapper\MagicSearchHandler;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicBulkHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicOrganizationHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicStatisticsHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
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
use Psr\Container\ContainerInterface;
use Symfony\Component\Uid\Uuid;
use Doctrine\DBAL\Schema\Schema as DoctrineSchema;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use OCA\OpenRegister\Exception\HookStoppedException;

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
 * @SuppressWarnings(PHPMD.ExcessivePublicCount)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class MagicMapper extends AbstractObjectMapper
{
    /**
     * Table name prefix for register+schema-specific tables.
     *
     * NOTE: Does NOT include 'oc_' prefix as Nextcloud's QueryBuilder adds that automatically.
     *
     * @internal Used by MagicTableHandler
     */
    public const TABLE_PREFIX = 'openregister_table_';

    /**
     * Metadata column prefix to avoid conflicts with schema properties
     */
    private const METADATA_PREFIX = '_';

    /**
     * Cache timeout for table existence checks (5 minutes)
     *
     * @internal Used by MagicTableHandler
     */
    public const TABLE_CACHE_TIMEOUT = 300;

    /**
     * Maximum table name length (MySQL limit)
     *
     * @internal Used by MagicTableHandler
     */
    public const MAX_TABLE_NAME_LENGTH = 64;

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
     * Cache for calculated schema versions (avoids recalculating MD5 hash)
     * Key format: 'registerId_schemaId' => 'calculated_version_hash'
     *
     * @var array<string, string>
     */
    private static array $calcVersionCache = [];

    /**
     * Cache for column existence checks to avoid repeated information_schema queries.
     * Key format: 'tableName' => ['column1' => true, 'column2' => true, ...]
     *
     * @var array<string, array<string, bool>>
     */
    private static array $columnExistsCache = [];

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
     * Table management handler for table lifecycle operations
     *
     * @var MagicTableHandler|null
     */
    private ?MagicTableHandler $tableHandler = null;

    /**
     * Statistics handler for aggregations and chart data
     *
     * @var MagicStatisticsHandler|null
     */
    private ?MagicStatisticsHandler $statisticsHandler = null;

    /**
     * Cached result of pg_trgm extension availability check
     *
     * @var boolean|null null = not checked yet, true/false = checked result
     */
    private ?bool $hasPgTrgm = null;

    /**
     * Count of constructor calls.
     *
     * @var integer
     */
    private static int $constructCount = 0;

    /**
     * Constructor for MagicMapper service.
     *
     * Initializes the service with required dependencies for database operations,
     * schema and register management, configuration handling, logging, and specialized handlers.
     *
     * @param IDBConnection      $db              Database connection for table operations
     * @param SchemaMapper       $schemaMapper    Mapper for schema operations
     * @param RegisterMapper     $registerMapper  Mapper for register operations
     * @param IConfig            $config          Nextcloud config for settings
     * @param IEventDispatcher   $eventDispatcher Event dispatcher for audit trail events
     * @param IUserSession       $userSession     User session for authentication context
     * @param IGroupManager      $groupManager    Group manager for RBAC operations
     * @param IUserManager       $userManager     User manager for user operations
     * @param IAppConfig         $appConfig       App configuration for feature flags
     * @param LoggerInterface    $logger          Logger for debugging and monitoring
     * @param SettingsService    $settingsService Settings service for configuration
     * @param ContainerInterface $container       Container for lazy loading services
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI requires constructor injection
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly IConfig $config,
        private readonly IEventDispatcher $eventDispatcher,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly IUserManager $userManager,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
        private readonly SettingsService $settingsService,
        private readonly ContainerInterface $container
    ) {
        self::$constructCount++;
        file_put_contents(
            '/tmp/or-debug.log',
            "MagicMapper::__construct #".self::$constructCount."\n",
            FILE_APPEND
        );
        if (self::$constructCount > 2) {
            file_put_contents(
                '/tmp/or-debug.log',
                "CIRCULAR! Stack:\n".(new Exception())->getTraceAsString()."\n",
                FILE_APPEND
            );
            return;
        }

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
        $this->rbacHandler = new MagicRbacHandler(
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            userManager: $this->userManager,
            appConfig: $this->appConfig,
            container: $this->container,
            logger: $this->logger
        );

        $this->organizationHandler = new MagicOrganizationHandler(
            userSession: $this->userSession,
            groupManager: $this->groupManager,
            appConfig: $this->appConfig,
            container: $this->container,
            logger: $this->logger
        );

        $this->searchHandler = new MagicSearchHandler(
            db: $this->db,
            logger: $this->logger,
            rbacHandler: $this->rbacHandler,
            organizationHandler: $this->organizationHandler
        );

        $this->bulkHandler = new MagicBulkHandler(
            db: $this->db,
            logger: $this->logger,
            eventDispatcher: $this->eventDispatcher
        );

        // CacheHandler and ICacheFactory are resolved lazily via container
        // to avoid circular DI: MagicMapper → CacheHandler → RegisterMapper → MagicMapper.
        $this->facetHandler = new MagicFacetHandler(
            db: $this->db,
            logger: $this->logger,
            cacheHandler: null,
            cacheFactory: null,
            searchHandler: $this->searchHandler,
            container: $this->container
        );

        $this->tableHandler = new MagicTableHandler(
            db: $this->db,
            appConfig: $this->appConfig,
            logger: $this->logger,
            magicMapper: $this
        );

        $this->statisticsHandler = new MagicStatisticsHandler(
            db: $this->db,
            logger: $this->logger,
            registerMapper: $this->registerMapper,
            schemaMapper: $this->schemaMapper
        );

        // Use setter injection for the count callback to avoid circular dependency.
        $this->statisticsHandler->setCountCallback(
            function (array $query, Register $register, Schema $schema): int {
                return $this->countObjectsInRegisterSchemaTable(
                    query: $query,
                    register: $register,
                    schema: $schema
                );
            }
        );
    }//end initializeHandlers()

    /**
     * Get a value from the table exists cache.
     *
     * @param string $key Cache key.
     *
     * @return int|null Cached timestamp or null if not cached.
     *
     * @internal Used by MagicTableHandler.
     */
    public static function getTableExistsCache(string $key): ?int
    {
        return self::$tableExistsCache[$key] ?? null;
    }//end getTableExistsCache()

    /**
     * Set a value in the table exists cache.
     *
     * @param string $key   Cache key.
     * @param int    $value Timestamp value.
     *
     * @return void
     *
     * @internal Used by MagicTableHandler.
     */
    public static function setTableExistsCache(string $key, int $value): void
    {
        self::$tableExistsCache[$key] = $value;
    }//end setTableExistsCache()

    /**
     * Unset a value from the table exists cache.
     *
     * @param string $key Cache key to remove.
     *
     * @return void
     *
     * @internal Used by MagicTableHandler.
     */
    public static function unsetTableExistsCache(string $key): void
    {
        unset(self::$tableExistsCache[$key]);
    }//end unsetTableExistsCache()

    /**
     * Set a value in the register+schema table name cache.
     *
     * @param string $key   Cache key.
     * @param string $value Table name to cache.
     *
     * @return void
     *
     * @internal Used by MagicTableHandler.
     */
    public static function setRegSchemaTableCache(string $key, string $value): void
    {
        self::$regSchemaTableCache[$key] = $value;
    }//end setRegSchemaTableCache()

    /**
     * Clear all static caches used by MagicMapper.
     *
     * @return void
     *
     * @internal Used by MagicTableHandler.
     */
    public static function clearAllStaticCaches(): void
    {
        self::$tableExistsCache    = [];
        self::$regSchemaTableCache = [];
        self::$tableStructureCache = [];
        self::$calcVersionCache    = [];
    }//end clearAllStaticCaches()

    /**
     * Check if PostgreSQL pg_trgm extension is available
     *
     * This extension provides the similarity() function and % operator
     * for fuzzy text searching. Result is cached for the request lifetime.
     *
     * @return bool True if pg_trgm is available, false otherwise
     */
    private function hasPgTrgmExtension(): bool
    {
        // Return cached result if available.
        if ($this->hasPgTrgm !== null) {
            return $this->hasPgTrgm;
        }

        // Not PostgreSQL = no pg_trgm.
        $platform = $this->db->getDatabasePlatform();
        if ($platform instanceof PostgreSQLPlatform === false) {
            $this->hasPgTrgm = false;
            return false;
        }

        // Check if pg_trgm extension is installed.
        try {
            $stmt            = $this->db->prepare("SELECT COUNT(*) FROM pg_extension WHERE extname = 'pg_trgm'");
            $result          = $stmt->execute();
            $count           = (int) $result->fetchOne();
            $this->hasPgTrgm = $count > 0;
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[MagicMapper] Failed to check pg_trgm extension availability',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            $this->hasPgTrgm = false;
        }

        return $this->hasPgTrgm;
    }//end hasPgTrgmExtension()

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
     * @return bool True if table was created/updated successfully
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Force flag allows table recreation
     */
    public function ensureTableForRegisterSchema(Register $register, Schema $schema, bool $force=false): bool
    {
        return $this->tableHandler->ensureTableForRegisterSchema(register: $register, schema: $schema, force: $force);
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
        return $this->tableHandler->getTableNameForRegisterSchema(register: $register, schema: $schema);
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
        return $this->tableHandler->existsTableForRegisterSchema(register: $register, schema: $schema);
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
            message: '[MagicMapper] Saving objects to register+schema table',
            context: [
                'file'        => __FILE__,
                'line'        => __LINE__,
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
                message: '[MagicMapper] Successfully saved objects to register+schema table',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'tableName'  => $tableName,
                    'savedCount' => count($savedUuids),
                ]
            );

            return $savedUuids;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[MagicMapper] Failed to save objects to register+schema table',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
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
            // Check if magic mapping is enabled for this schema.
            $isMagicEnabled = $register->isMagicMappingEnabledForSchema(
                schemaId: $schema->getId(),
                schemaSlug: $schema->getSlug()
            );
            if ($isMagicEnabled !== true) {
                $this->logger->info(
                    message: '[MagicMapper] Register+schema table does not exist, should use generic storage',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'registerId' => $register->getId(),
                        'schemaId'   => $schema->getId(),
                    ]
                );
                return [];
            }

            // Create the table since magic mapping is enabled.
            $this->logger->info(
                message: '[MagicMapper] Register+schema table does not exist but magic mapping enabled, creating table',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'registerId' => $register->getId(),
                    'schemaId'   => $schema->getId(),
                ]
            );
            $this->ensureTableForRegisterSchema(register: $register, schema: $schema);
        }//end if

        $tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);

        try {
            // Use MagicSearchHandler for search with RBAC and multi-tenancy support.
            $result = $this->searchHandler->searchObjects(
                query: $query,
                register: $register,
                schema: $schema,
                tableName: $tableName
            );

            // If result is an integer (count), return empty array.
            if (is_int($result) === true) {
                return [];
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[MagicMapper] Failed to search register+schema table',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'tableName' => $tableName,
                    'error'     => $e->getMessage(),
                ]
            );

            throw $e;
        }//end try
    }//end searchObjectsInRegisterSchemaTable()

    /**
     * Get the list of filter properties that were ignored during the last search.
     *
     * These are properties that were requested as filters but don't exist in the schema.
     * Useful for providing feedback to API clients about invalid filter parameters.
     *
     * @return array<string> List of ignored filter property names
     */
    public function getIgnoredFilters(): array
    {
        return $this->searchHandler->getIgnoredFilters();
    }//end getIgnoredFilters()

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
            // Check if magic mapping is enabled for this schema.
            $isMagicEnabled = $register->isMagicMappingEnabledForSchema(
                schemaId: $schema->getId(),
                schemaSlug: $schema->getSlug()
            );
            if ($isMagicEnabled !== true) {
                $this->logger->info(
                    message: '[MagicMapper] Register+schema table does not exist for count, returning 0',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'registerId' => $register->getId(),
                        'schemaId'   => $schema->getId(),
                    ]
                );
                return 0;
            }

            // Create the table since magic mapping is enabled.
            $this->logger->info(
                message: '[MagicMapper] Register+schema table does not exist but magic mapping enabled, creating table',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'registerId' => $register->getId(),
                    'schemaId'   => $schema->getId(),
                ]
            );
            $this->ensureTableForRegisterSchema(register: $register, schema: $schema);
        }//end if

        $tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);

        try {
            // Add _count flag to use MagicSearchHandler with RBAC and multi-tenancy filters.
            $countQuery           = $query;
            $countQuery['_count'] = true;

            $result = $this->searchHandler->searchObjects(
                query: $countQuery,
                register: $register,
                schema: $schema,
                tableName: $tableName
            );

            $count = 0;
            if (is_int($result) === true) {
                $count = $result;
            }

            $this->logger->debug(
                message: '[MagicMapper] Count query completed',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'tableName' => $tableName,
                    'count'     => $count,
                    'hasSearch' => empty($query['_search']) === false,
                    'query'     => array_keys($query),
                ]
            );

            return $count;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[MagicMapper] Failed to count in register+schema table',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
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
            // Check if magic mapping is enabled for this schema - if so, create the table.
            $isMagicEnabled = $register->isMagicMappingEnabledForSchema(
                schemaId: $schema->getId(),
                schemaSlug: $schema->getSlug()
            );
            if ($isMagicEnabled !== true) {
                $this->logger->info(
                    message: '[MagicMapper] Register+schema table does not exist for facets, returning empty',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'registerId' => $register->getId(),
                        'schemaId'   => $schema->getId(),
                    ]
                );
                return [];
            }

            $msg  = '[MagicMapper] Register+schema table does not exist';
            $msg .= ' but magic mapping enabled, creating table for facets';
            $this->logger->info(
                message: $msg,
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'registerId' => $register->getId(),
                    'schemaId'   => $schema->getId(),
                ]
            );
            $this->ensureTableForRegisterSchema(register: $register, schema: $schema);
        }//end if

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
                message: '[MagicMapper] Failed to get facets from register+schema table',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'tableName' => $tableName,
                    'error'     => $e->getMessage(),
                ]
            );

            return [];
        }//end try
    }//end getSimpleFacetsFromRegisterSchemaTable()

    /**
     * Get facets using UNION ALL across multiple register+schema tables.
     *
     * This method is optimized for multi-schema faceting by executing ONE query
     * per facet field using UNION ALL, instead of separate queries per table.
     * Benchmarks show 2-2.5x speedup for large datasets.
     *
     * @param array    $query               Search parameters including _facets configuration.
     * @param Register $register            The register context.
     * @param array    $schemas             Array of Schema objects to include.
     * @param array    $registerSchemaPairs Register/schema pairs.
     *
     * @return array Merged facet results.
     */
    public function getSimpleFacetsUnion(
        array $query,
        ?Register $register=null,
        array $schemas=[],
        array $registerSchemaPairs=[]
    ): array {
        // Build table configs for each schema.
        $tableConfigs = [];

        // Support new register+schema pairs format (multi-register).
        if (empty($registerSchemaPairs) === false) {
            foreach ($registerSchemaPairs as $pair) {
                $pairRegister = $pair['register'];
                $pairSchema   = $pair['schema'];
                if ($this->existsTableForRegisterSchema(register: $pairRegister, schema: $pairSchema) === false) {
                    continue;
                }

                $tableName      = $this->getTableNameForRegisterSchema(register: $pairRegister, schema: $pairSchema);
                $tableConfigs[] = [
                    'tableName' => $tableName,
                    'register'  => $pairRegister,
                    'schema'    => $pairSchema,
                ];
            }
        } else if ($register !== null) {
            // Legacy single-register format.
            foreach ($schemas as $schema) {
                if ($this->existsTableForRegisterSchema(register: $register, schema: $schema) === false) {
                    continue;
                }

                $tableName      = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
                $tableConfigs[] = [
                    'tableName' => $tableName,
                    'register'  => $register,
                    'schema'    => $schema,
                ];
            }
        }//end if

        if (empty($tableConfigs) === true) {
            return [];
        }

        // Initialize handlers if needed.
        $this->initializeHandlers();

        return $this->facetHandler->getSimpleFacetsUnion(
            tableConfigs: $tableConfigs,
            query: $query
        );
    }//end getSimpleFacetsUnion()

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
            message: '[MagicMapper] Starting cross-table search',
            context: [
                'file'      => __FILE__,
                'line'      => __LINE__,
                'pairCount' => count($registerSchemaPairs),
                'queryKeys' => array_keys($query),
            ]
        );

        // OPTIMIZATION: Use UNION ALL for multi-table search in a single query.
        // This is MUCH faster than looping through tables individually.
        if (count($registerSchemaPairs) > 1 && $this->shouldUseUnionQuery(query: $query) === true) {
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

        // Detect database platform for identifier quoting.
        $platform   = $this->db->getDatabasePlatform();
        $isPostgres = stripos($platform::class, 'PostgreSQL') !== false;

        // Collect superset of all property columns across all schemas for UNION compatibility.
        // Each SELECT must include the same columns; schemas that lack a column get NULL AS alias.
        $allPropertyColumns = $this->collectAllPropertyColumns(registerSchemaPairs: $registerSchemaPairs);

        // Build a SELECT for each table.
        foreach ($registerSchemaPairs as $pair) {
            $register = $pair['register'] ?? null;
            $schema   = $pair['schema'] ?? null;

            if ($register === null || $schema === null) {
                continue;
            }

            // Check if table exists (fast cache check).
            if ($this->existsTableForRegisterSchema(register: $register, schema: $schema) === false) {
                // Check if magic mapping is enabled for this schema - if so, create the table.
                $isMagicEnabled = $register->isMagicMappingEnabledForSchema(
                    schemaId: $schema->getId(),
                    schemaSlug: $schema->getSlug()
                );
                if ($isMagicEnabled !== true) {
                    continue;
                }

                $msg  = '[MagicMapper] Register+schema table does not exist';
                $msg .= ' but magic mapping enabled, creating table for cross-search';
                $this->logger->info(
                    message: $msg,
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'registerId' => $register->getId(),
                        'schemaId'   => $schema->getId(),
                    ]
                );
                $this->ensureTableForRegisterSchema(register: $register, schema: $schema);
            }//end if

            $tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);

            // Build SELECT for this table with schema/register metadata and property columns.
            $selectPart = $this->buildUnionSelectPart(
                tableName: $tableName,
                query: $query,
                schema: $schema,
                register: $register,
                allPropertyColumns: $allPropertyColumns
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

        // Apply global ORDER BY - supports _order parameter or defaults to search score.
        $hasSearch   = isset($query['_search']) === true && empty($query['_search']) === false;
        $orderParams = $query['_order'] ?? [];

        if (empty($orderParams) === false && is_array($orderParams) === true) {
            // Use custom ordering from _order parameter.
            $orderClauses = [];
            foreach ($orderParams as $field => $direction) {
                // Special handling for _relevance: map to _search_score in UNION queries.
                // The _relevance column is used by MagicSearchHandler for single-table queries,.
                // but UNION queries use _search_score for relevance scoring.
                if ($field === '_relevance') {
                    // Only use _search_score if we have a search term.
                    if ($hasSearch === true) {
                        if (strtoupper($direction) === 'DESC') {
                            $dir = 'DESC';
                        } else {
                            $dir = 'ASC';
                        }

                        $orderClauses[] = "_search_score {$dir}";
                    }

                    // Skip _relevance ordering if no search term (nothing to order by).
                    continue;
                }

                // Translate field name to column name.
                $columnName = $this->sanitizeColumnName(name: $field);
                if (str_starts_with($field, '@self.') === true) {
                    $columnName = self::METADATA_PREFIX.substr($field, 6);
                } else if (str_starts_with($field, '_') === false) {
                    // Non-metadata fields - property columns are included in UNION queries.
                    // The column must exist in the SELECT for ordering to work.
                    // Quote to protect against SQL reserved keywords (e.g. "order", "group").
                    $columnName = $this->quoteIdentifier(
                        name: $this->sanitizeColumnName(name: $field),
                        isPostgres: $isPostgres
                    );
                }

                if (strtoupper($direction) === 'DESC') {
                    $dir = 'DESC';
                } else {
                    $dir = 'ASC';
                }

                $orderClauses[] = "{$columnName} {$dir}";
            }//end foreach

            if (empty($orderClauses) === false) {
                $unionSql .= ' ORDER BY '.implode(', ', $orderClauses);
            }
        } else if ($hasSearch === true) {
            // Default to search score ordering when no _order specified but search is present.
            $unionSql .= ' ORDER BY _search_score DESC';
        }//end if

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
                $entity = $this->convertUnionRowToObjectEntity(row: $row);
                if ($entity !== null) {
                    $results[] = $entity;
                }
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[MagicMapper] Failed to convert union row to entity',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
                );
                continue;
            }
        }

        $this->logger->info(
            message: '[MagicMapper] Union search completed',
            context: ['file' => __FILE__, 'line' => __LINE__, 'resultCount' => count($results)]
        );

        return $results;
    }//end searchAcrossMultipleTablesWithUnion()

    /**
     * Build SELECT part for UNION ALL query.
     *
     * @param string   $tableName          Table name.
     * @param array    $query              Search query.
     * @param Schema   $schema             Schema entity.
     * @param Register $register           Register entity.
     * @param array    $allPropertyColumns Superset of all property columns across schemas.
     *
     * @return string|null SQL SELECT statement or null if table doesn't exist.
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function buildUnionSelectPart(
        string $tableName,
        array $query,
        Schema $schema,
        Register $register,
        array $allPropertyColumns=[]
    ): ?string {
        $qb = $this->db->getQueryBuilder();

        // Detect database platform for identifier quoting.
        $platform   = $this->db->getDatabasePlatform();
        $isPostgres = stripos($platform::class, 'PostgreSQL') !== false;

        // Add table prefix.
        $fullTableName = 'oc_'.$tableName;

        // Get metadata column names (common across all tables).
        $metadataColumns = array_keys($this->getMetadataColumns());

        // Base SELECT with metadata columns.
        $selectColumns = $metadataColumns;

        // Add schema property columns for UNION compatibility.
        // Each schema's SELECT includes ALL property columns across all schemas.
        // Columns that exist in this schema's table use the real column cast to text.
        // Columns that don't exist use NULL::text AS placeholder.
        // Cast to text ensures type compatibility across schemas in UNION.
        // (e.g., one schema has 'type' as text, another as jsonb).
        foreach (array_keys($allPropertyColumns) as $columnName) {
            $quotedCol = $this->quoteIdentifier(name: $columnName, isPostgres: $isPostgres);
            $colExpr   = "NULL::text AS {$quotedCol}";
            if ($this->columnExistsInTable(tableName: $tableName, columnName: $columnName) === true) {
                $colExpr = "{$quotedCol}::text AS {$quotedCol}";
            }

            $selectColumns[] = $colExpr;
        }

        $selectColumns[] = "'{$register->getId()}' AS _union_register_id";
        $selectColumns[] = "'{$schema->getId()}' AS _union_schema_id";

        // Add search score if _search is present.
        $hasSearch   = isset($query['_search']) === true && empty($query['_search']) === false;
        $searchTerm  = $query['_search'] ?? '';
        $schemaProps = $schema->getProperties() ?? [];

        if ($hasSearch === true && empty($schemaProps) === false) {
            // Build fuzzy search score.
            // Note: quote() already adds quotes, so don't wrap in additional quotes.
            $searchColumns = [];
            $quotedTerm    = $qb->getConnection()->quote($searchTerm);
            $hasTrgm       = $this->hasPgTrgmExtension();

            foreach ($schemaProps as $propName => $propDef) {
                $type = $propDef['type'] ?? 'string';
                if (in_array($type, ['string', 'text'], true) === true) {
                    $columnName = $this->sanitizeColumnName(name: $propName);
                    $quotedCol  = $this->quoteIdentifier(name: $columnName, isPostgres: $isPostgres);
                    // Fallback: use CASE with ILIKE for basic relevance scoring.
                    $likePattern = "'%".trim($quotedTerm, "'")."%'";
                    $scoreExpr   = "CASE WHEN {$quotedCol}::text ILIKE {$likePattern} THEN 1 ELSE 0 END";
                    if ($hasTrgm === true) {
                        // Use similarity() for fuzzy scoring when pg_trgm is available.
                        $scoreExpr = "COALESCE(similarity({$quotedCol}::text, {$quotedTerm}), 0)";
                    }

                    $searchColumns[] = $scoreExpr;
                }
            }

            $selectColumns[] = '0 AS _search_score';
            if (empty($searchColumns) === false) {
                $scoreExpression = 'GREATEST('.implode(', ', $searchColumns).')';
                $selectColumns[count($selectColumns) - 1] = "{$scoreExpression} AS _search_score";
            }
        }//end if

        if ($hasSearch === false || empty($schemaProps) === true) {
            $selectColumns[] = '0 AS _search_score';
        }

        $selectSql = 'SELECT '.implode(', ', $selectColumns)." FROM {$fullTableName}";

        // Build WHERE conditions using shared method (single source of truth for filters).
        // This ensures search, count, and facets all use the same filter logic.
        $whereClauses = $this->searchHandler->buildWhereConditionsSql(query: $query, schema: $schema);

        if (empty($whereClauses) === false) {
            $selectSql .= ' WHERE '.implode(' AND ', $whereClauses);
        }

        return $selectSql;
    }//end buildUnionSelectPart()

    /**
     * Collect the superset of all sanitized property column names across schemas.
     *
     * For UNION queries, all SELECTs must have the same columns.
     * This method collects all unique property columns from all schemas
     * so that each SELECT can include them (real column or NULL AS alias).
     *
     * @param array $registerSchemaPairs Array of register+schema pairs.
     *
     * @return array Associative array of sanitized_column_name => original_property_name.
     */
    private function collectAllPropertyColumns(array $registerSchemaPairs): array
    {
        $allColumns = [];

        // List of metadata/configuration fields that should NOT be treated as properties.
        // Same exclusion list as buildTableColumnsFromSchema().
        $metadataFields = [
            'objectNameField',
            'objectDescriptionField',
            'objectSummaryField',
            'required',
            '$schema',
            '$id',
        ];

        foreach ($registerSchemaPairs as $pair) {
            $schema = $pair['schema'] ?? null;
            if ($schema === null) {
                continue;
            }

            $properties = $schema->getProperties() ?? [];
            if (is_array($properties) === false) {
                continue;
            }

            foreach ($properties as $propertyName => $propertyConfig) {
                if (in_array($propertyName, $metadataFields, true) === true) {
                    continue;
                }

                if (is_array($propertyConfig) === false) {
                    continue;
                }

                $columnName = $this->sanitizeColumnName(name: $propertyName);
                // Only store the first mapping for each column name.
                if (isset($allColumns[$columnName]) === false) {
                    $allColumns[$columnName] = $propertyName;
                }
            }//end foreach
        }//end foreach

        return $allColumns;
    }//end collectAllPropertyColumns()

    /**
     * Convert UNION query row to ObjectEntity.
     *
     * @param array $row Database row from UNION query.
     *
     * @return ObjectEntity|null ObjectEntity or null if conversion fails.
     */
    private function convertUnionRowToObjectEntity(array $row): ?ObjectEntity
    {
        $registerId  = $row['_union_register_id'] ?? null;
        $schemaId    = $row['_union_schema_id'] ?? null;
        $searchScore = $row['_search_score'] ?? null;

        if ($registerId === null || $schemaId === null) {
            return null;
        }

        // Remove metadata columns before converting to ObjectEntity.
        unset($row['_union_register_id'], $row['_union_schema_id'], $row['_search_score']);

        // Convert to ObjectEntity using existing logic.
        try {
            $register = $this->registerMapper->find((int) $registerId, _multitenancy: false, _rbac: false);
            $schema   = $this->schemaMapper->find((int) $schemaId, _multitenancy: false, _rbac: false);

            $entity = $this->convertRowToObjectEntity(
                row: $row,
                _register: $register,
                _schema: $schema
            );

            // Set relevance score from UNION search score (converted to percentage 0-100).
            // The _search_score from UNION is already a similarity score (0-1), convert to percentage.
            if ($entity !== null && $searchScore !== null) {
                $relevancePercent = round((float) $searchScore * 100);
                $entity->setRelevance($relevancePercent);
            }

            return $entity;
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[MagicMapper] Failed to convert union row',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            return null;
        }//end try
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
                $this->logger->warning(
                    message: '[MagicMapper] Invalid register+schema pair in cross-table search',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'pair' => $pair]
                );
                continue;
            }

            try {
                $this->logger->debug(
                    message: '[MagicMapper] Searching table (sequential)',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $schema->getId()]
                );

                // Search in this table.
                $results = $this->searchObjectsInRegisterSchemaTable(
                    query: $query,
                    register: $register,
                    schema: $schema
                );

                $this->logger->info(
                    message: '[MagicMapper] Table search completed',
                    context: [
                        'file'        => __FILE__,
                        'line'        => __LINE__,
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
                    message: '[MagicMapper] Failed to search in register+schema table',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'registerId' => $register->getId(),
                        'schemaId'   => $schema->getId(),
                        'error'      => $e->getMessage(),
                    ]
                );
                // Continue with other tables even if one fails.
                continue;
            }//end try
        }//end foreach

        $this->logger->info(
            message: '[MagicMapper] Sequential search completed',
            context: ['file' => __FILE__, 'line' => __LINE__, 'totalResults' => count($allResults)]
        );

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
            message: '[MagicMapper] Cross-table search completed',
            context: [
                'file'        => __FILE__,
                'line'        => __LINE__,
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
    public function getCacheKey(int $registerId, int $schemaId): string
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
    public function checkTableExistsInDatabase(string $tableName): bool
    {
        try {
            // Check if table exists in information_schema.
            // NOTE: We use raw SQL here because information_schema is a system table.
            $prefix = 'oc_';
            // Nextcloud default prefix.
            $fullTableName = $prefix.$tableName;

            // Get database platform to use correct schema check.
            // MySQL/MariaDB: table_schema = DATABASE().
            // PostgreSQL: table_schema = current_schema().
            $platform   = $this->db->getDatabasePlatform();
            $isPostgres = stripos($platform::class, 'PostgreSQL') !== false;

            // MySQL/MariaDB/SQLite.
            $sql = "SELECT 1 FROM information_schema.tables WHERE table_name = ? AND table_schema = DATABASE() LIMIT 1";
            if ($isPostgres === true) {
                $sql  = "SELECT 1 FROM information_schema.tables";
                $sql .= " WHERE table_name = ? AND table_schema = current_schema() LIMIT 1";
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$fullTableName]);
            $result = $stmt->fetch();

            return $result !== false;
        } catch (Exception $e) {
            // Table doesn't exist or query failed.
            $this->logger->debug(
                message: '[MagicMapper] Table does not exist in database',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
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
    public function invalidateTableCache(string $cacheKey): void
    {
        unset(self::$tableExistsCache[$cacheKey]);
        unset(self::$regSchemaTableCache[$cacheKey]);
        unset(self::$tableStructureCache[$cacheKey]);
        unset(self::$calcVersionCache[$cacheKey]);

        $this->logger->debug(
            message: '[MagicMapper] Invalidated table cache',
            context: ['file' => __FILE__, 'line' => __LINE__, 'cacheKey' => $cacheKey]
        );
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
    public function createTableForRegisterSchema(Register $register, Schema $schema): bool
    {
        $tableName  = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
        $registerId = $register->getId();
        $schemaId   = $schema->getId();
        $cacheKey   = $this->getCacheKey(registerId: $registerId, schemaId: $schemaId);

        $this->logger->info(
            message: '[MagicMapper] Creating new register+schema table',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'registerId' => $registerId,
                'schemaId'   => $schemaId,
                'tableName'  => $tableName,
            ]
        );

        // Get table structure from schema.
        $columns = $this->buildTableColumnsFromSchema(schema: $schema);

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
            message: '[MagicMapper] Successfully created register+schema table',
            context: [
                'file'        => __FILE__,
                'line'        => __LINE__,
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
     * @return array Statistics about what was changed
     */
    public function updateTableForRegisterSchema(Register $register, Schema $schema): array
    {
        return $this->syncTableForRegisterSchema(register: $register, schema: $schema);
    }//end updateTableForRegisterSchema()

    /**
     * Synchronize table structure with schema definition.
     *
     * This is a public method that can be called to update an existing magic table
     * to match the current schema definition. It will:
     * - Add missing columns
     * - De-require columns that are no longer required in schema
     * - Drop duplicate camelCase columns when snake_case exists
     * - Make obsolete columns nullable
     * - Update indexes for relations and facetable fields
     *
     * @param Register $register The register context
     * @param Schema   $schema   The schema definition
     *
     * @return array Statistics about what was changed
     *
     * @throws Exception If table sync fails
     */
    public function syncTableForRegisterSchema(Register $register, Schema $schema): array
    {
        return $this->tableHandler->syncTableForRegisterSchema(register: $register, schema: $schema);
    }//end syncTableForRegisterSchema()

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
    public function buildTableColumnsFromSchema(Schema $schema): array
    {
        $columns = [];

        // Add all metadata columns from ObjectEntity with underscore prefix.
        $columns = array_merge($columns, $this->getMetadataColumns());

        // Get schema properties and convert to SQL columns.
        $schemaProperties = $schema->getProperties();

        // List of metadata/configuration fields that should NOT be treated as properties.
        // NOTE: 'title', 'description', and 'type' are NOT included here because they are.
        // legitimate schema properties (e.g., catalog.title, module.type).
        // The metadata columns _name and _description serve a different purpose.
        // Root-level JSON Schema fields like "type": "object" are filtered by checking.
        // if propertyConfig is an array (real properties have array configs).
        $metadataFields = [
            'objectNameField',
            'objectDescriptionField',
            'objectSummaryField',
            'required',
            '$schema',
            '$id',
        ];

        if (is_array($schemaProperties) === true) {
            foreach ($schemaProperties as $propertyName => $propertyConfig) {
                // Skip metadata/configuration fields that are not actual properties.
                if (in_array($propertyName, $metadataFields, true) === true) {
                    continue;
                }

                // Skip if propertyConfig is not an array (it should be an object/array for real properties).
                if (is_array($propertyConfig) === false) {
                    $this->logger->debug(
                        message: '[MagicMapper] Skipping non-array property in schema',
                        context: [
                            'file'         => __FILE__,
                            'line'         => __LINE__,
                            'propertyName' => $propertyName,
                            'propertyType' => gettype($propertyConfig),
                        ]
                    );
                    continue;
                }

                // Note: Schema properties do NOT conflict with metadata columns.
                // Metadata columns have '_' prefix, schema properties don't.
                // Both '_name' (metadata) and 'name' (schema property) can coexist.
                $column = $this->mapSchemaPropertyToColumn(propertyName: $propertyName, propertyConfig: $propertyConfig);
                if ($column !== null && $column !== '') {
                    $columns[$propertyName] = $column;
                }
            }//end foreach
        }//end if

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
                'length'   => 40,
            // ArchiMate identifiers are max 39 chars (id-{uuid-36}).
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
                'type'     => 'text',
            // Changed from varchar(500) to text to support longer summaries.
                'nullable' => true,
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
            self::METADATA_PREFIX.'tmlo'           => [
                'name'     => self::METADATA_PREFIX.'tmlo',
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
        $columnName = $this->sanitizeColumnName(name: $propertyName);

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

            case 'file':
                // File properties store file IDs (integers) after processing by FilePropertyHandler.
                // Use TEXT to safely store the file ID reference without JSON parsing issues.
                // This prevents "invalid input syntax for type json" errors if raw base64 data.
                // accidentally gets stored instead of the processed file ID.
                $required   = $propertyConfig['required'] ?? false;
                $isRequired = false;
                if (is_array($required) === true) {
                    $isRequired = in_array($propertyName, $required);
                } else if (is_bool($required) === true) {
                    $isRequired = $required;
                }
                return [
                    'name'     => $columnName,
                    'type'     => 'text',
                    'nullable' => $isRequired === false,
                    'comment'  => 'File ID reference',
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

                // Also check for nested items.oneOf[] pattern (e.g., moduleB with multiple possible types).
                // Pattern: { "type": "object", "items": { "oneOf": [{ "$ref": "...", "objectConfiguration": {...} }] } }.
                if ($handling === null && isset($propertyConfig['items']['oneOf']) === true) {
                    foreach ($propertyConfig['items']['oneOf'] as $oneOfItem) {
                        if (isset($oneOfItem['objectConfiguration']['handling']) === true
                            && $oneOfItem['objectConfiguration']['handling'] === 'related-object'
                        ) {
                            $handling = 'related-object';
                            $hasRef   = isset($oneOfItem['$ref']);
                            break;
                        }
                    }
                }

                if ($type === 'object' && $hasRef === true && $handling === 'related-object') {
                    // This is a reference to another object - store as UUID string.
                    $this->logger->debug(
                        message: '[MagicMapper] Detected object reference property, using VARCHAR for UUID storage',
                        context: [
                            'file'         => __FILE__,
                            'line'         => __LINE__,
                            'propertyName' => $propertyName,
                            '$ref'         => $propertyConfig['$ref'] ?? 'nested in items.oneOf',
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
                }//end if
                return [
                    'name'     => $columnName,
                    'type'     => 'json',
                    'nullable' => $isRequired === false,
                ];

            default:
                // Unknown type - store as JSON for flexibility.
                $this->logger->warning(
                    message: '[MagicMapper] Unknown schema property type, storing as JSON',
                    context: [
                        'file'         => __FILE__,
                        'line'         => __LINE__,
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
            $isPostgres = ($platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform);

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
                message: '[MagicMapper] Created table with columns',
                context: [
                    'file'          => __FILE__,
                    'line'          => __LINE__,
                    'tableName'     => $tableName,
                    'fullTableName' => $fullTableName,
                    'columns'       => array_column($columns, 'name'),
                ]
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[MagicMapper] Failed to create table',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
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
        $isPostgres = ($platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform);

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
            // Phpcs:ignore Generic.Files.LineLength.TooLong.
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
            $idxMetaFields = ['created', 'updated', 'name'];
            foreach ($idxMetaFields as $field) {
                $col = self::METADATA_PREFIX.$field;
                $idx = "{$tableName}_{$field}_idx";
                $this->db->executeStatement(
                    "CREATE INDEX IF NOT EXISTS {$idx} ON {$fullTableName} ({$col})"
                );
            }

            // Create GIN index on _relations for fast relationship lookups.
            // This enables O(log n) containment queries with @> operator.
            $platform   = $this->db->getDatabasePlatform();
            $isPostgres = stripos($platform::class, 'PostgreSQL') !== false;

            if ($isPostgres === true) {
                $relationsIdx = "{$tableName}_relations_gin_idx";
                $this->db->executeStatement(
                    "CREATE INDEX IF NOT EXISTS {$relationsIdx} ON {$fullTableName} USING GIN (_relations)"
                );
            }

            // Create indexes for schema-specific properties.
            $schemaProperties = $_schema->getProperties();
            $relationIndexes  = [];
            $facetIndexes     = [];

            if (is_array($schemaProperties) === true) {
                foreach ($schemaProperties as $propertyName => $propertyConfig) {
                    $columnName = $this->sanitizeColumnName(name: $propertyName);
                    $quotedCol  = $this->quoteIdentifier(name: $columnName, isPostgres: $isPostgres);

                    // Create indexes on relation properties (object references) for _extend queries.
                    $hasRef       = isset($propertyConfig['$ref']);
                    $objectConfig = $propertyConfig['objectConfiguration'] ?? [];
                    $handling     = $objectConfig['handling'] ?? null;
                    $type         = $propertyConfig['type'] ?? 'string';

                    // Index single object references (stored as VARCHAR UUID).
                    if ($type === 'object' && $hasRef === true && $handling === 'related-object') {
                        $idxName = "{$tableName}_{$columnName}_rel_idx";
                        try {
                            $this->db->executeStatement(
                                "CREATE INDEX IF NOT EXISTS {$idxName} ON {$fullTableName} ({$quotedCol})"
                            );
                            $relationIndexes[] = $columnName;
                        } catch (Exception $e) {
                            // Index may already exist or column type incompatible.
                        }
                    }

                    // For array of object references with inversedBy, create GIN index on PostgreSQL.
                    if ($type === 'array' && $isPostgres === true) {
                        $items      = $propertyConfig['items'] ?? [];
                        $itemsRef   = $items['$ref'] ?? null;
                        $inversedBy = $items['inversedBy'] ?? ($propertyConfig['inversedBy'] ?? null);

                        if ($itemsRef !== null || $inversedBy !== null) {
                            $idxName = "{$tableName}_{$columnName}_arr_gin_idx";
                            try {
                                $this->db->executeStatement(
                                    "CREATE INDEX IF NOT EXISTS {$idxName} ON {$fullTableName} USING GIN ({$quotedCol})"
                                );
                                $relationIndexes[] = $columnName.' (GIN)';
                            } catch (Exception $e) {
                                // Index may already exist or column type incompatible.
                            }
                        }
                    }

                    // Create indexes on facetable fields for efficient facet queries.
                    if (($propertyConfig['facetable'] ?? false) === true) {
                        $idxName = "{$tableName}_{$columnName}_facet_idx";
                        try {
                            $this->db->executeStatement(
                                "CREATE INDEX IF NOT EXISTS {$idxName} ON {$fullTableName} ({$quotedCol})"
                            );
                            $facetIndexes[] = $columnName;
                        } catch (Exception $e) {
                            // Index may already exist or column type incompatible.
                        }
                    }
                }//end foreach
            }//end if

            $this->logger->debug(
                message: '[MagicMapper] Created table indexes',
                context: [
                    'file'            => __FILE__,
                    'line'            => __LINE__,
                    'tableName'       => $tableName,
                    'baseIndexCount'  => 5 + count($idxMetaFields),
                    'relationIndexes' => $relationIndexes,
                    'facetIndexes'    => $facetIndexes,
                ]
            );
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[MagicMapper] Failed to create some table indexes',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
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
                    message: '[MagicMapper] Inserted object in register+schema table',
                    context: [
                        'file'      => __FILE__,
                        'line'      => __LINE__,
                        'uuid'      => $uuid,
                        'tableName' => $tableName,
                    ]
                );
                return $uuid;
            }

            // Update existing object.
            $this->updateObjectInRegisterSchemaTable(uuid: $uuid, data: $preparedData, tableName: $tableName);
            $this->logger->debug(
                message: '[MagicMapper] Updated object in register+schema table',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'uuid'      => $uuid,
                    'tableName' => $tableName,
                ]
            );
            return $uuid;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[MagicMapper] Failed to save object to register+schema table',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
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
            'tmlo',
            'created',
            'updated',
            'expires',
        ];

        foreach ($metadataFields as $field) {
            $value = $metadata[$field] ?? null;

            // Handle datetime fields.
            if (in_array($field, ['created', 'updated', 'expires']) === true) {
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
                'tmlo',
            ];
            if (in_array($field, $jsonFields) === true) {
                // Convert to JSON if not already a string.
                // Note: Empty string → NULL conversion is handled at final insert/update stage.
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

        // DEBUG: Log schema property mapping for gemmaType.
        if ($schema->getSlug() === 'element' && isset($schemaProperties['gemmaType']) === true) {
            $this->logger->error(
                message: '[MagicMapper] MAGIC_MAPPER_DEBUG: Mapping element properties',
                context: [
                    'file'                    => __FILE__,
                    'line'                    => __LINE__,
                    'has_gemmaType_in_schema' => isset($schemaProperties['gemmaType']),
                    'has_gemmaType_in_data'   => isset($data['gemmaType']),
                    'gemmaType_value'         => $data['gemmaType'] ?? 'NOT IN DATA',
                    'data_keys'               => array_keys($data),
                    'objectData_keys'         => array_keys($objectData),
                ]
                    );
        }

        if (is_array($schemaProperties) === true) {
            foreach (array_keys($schemaProperties) as $propertyName) {
                // Use array_key_exists to distinguish between:.
                // - Property exists with null value → include in prepared data (update DB to null).
                // - Property doesn't exist at all → skip (don't change DB value).
                if (array_key_exists($propertyName, $data) === true) {
                    $value          = $data[$propertyName];
                    $propertyConfig = $schemaProperties[$propertyName] ?? [];
                    $propertyType   = $propertyConfig['type'] ?? 'string';

                    // Safety check for file properties: if a base64 data URL is still present,.
                    // the FilePropertyHandler didn't process it. Log a warning and set to null.
                    // to prevent "invalid input syntax for type json" errors in PostgreSQL.
                    $isFileProperty = $propertyType === 'file';
                    $isArrayOfFiles = $propertyType === 'array'
                        && (($propertyConfig['items']['type'] ?? '') === 'file');

                    if ($isFileProperty === true && is_string($value) === true && strpos($value, 'data:') === 0) {
                        $msg  = '[MagicMapper] File property contains unprocessed';
                        $msg .= ' base64 data URL - setting to null to prevent DB error';
                        $this->logger->warning(
                            message: $msg,
                            context: [
                                'file'         => __FILE__,
                                'line'         => __LINE__,
                                'propertyName' => $propertyName,
                                'valueLength'  => strlen($value),
                            ]
                        );
                        $value = null;
                    }

                    // Handle array of files - filter out unprocessed base64 data URLs.
                    if ($isArrayOfFiles === true && is_array($value) === true) {
                        $cleanedArray = [];
                        foreach ($value as $item) {
                            if (is_string($item) === true
                                && strpos($item, 'data:') === 0
                            ) {
                                $msg  = '[MagicMapper] Array file item contains';
                                $msg .= ' unprocessed base64 data URL - skipping item';
                                $this->logger->warning(
                                    message: $msg,
                                    context: [
                                        'file'         => __FILE__,
                                        'line'         => __LINE__,
                                        'propertyName' => $propertyName,
                                        'valueLength'  => strlen($item),
                                    ]
                                );
                                continue;
                            }

                            $cleanedArray[] = $item;
                        }

                        if (empty($cleanedArray) === true) {
                            $value = null;
                        } else {
                            $value = $cleanedArray;
                        }
                    }//end if

                    // Convert boolean values to integers (0/1) for database compatibility.
                    // PHP's false can be incorrectly converted to empty string '' by some drivers.
                    // Using 0/1 integers ensures PostgreSQL and other databases handle booleans correctly.
                    if (is_bool($value) === true) {
                        if ($value === true) {
                            $value = 1;
                        } else {
                            $value = 0;
                        }
                    }

                    // Convert complex types to JSON.
                    // Note: Empty string → NULL conversion is handled at final insert/update stage.
                    if (is_array($value) === true || is_object($value) === true) {
                        $value = json_encode($value);
                    }

                    $preparedData[$this->sanitizeColumnName(name: $propertyName)] = $value;
                }//end if
            }//end foreach
        }//end if

        return $preparedData;
    }//end prepareObjectDataForTable()

    /**
     * Convert database row to ObjectEntity.
     *
     * This method is public to allow bulk handlers to convert rows for event dispatching.
     * Delegates to MagicStatisticsHandler.
     *
     * @param array    $row       Database row
     * @param Register $_register Register context
     * @param Schema   $_schema   Schema context
     *
     * @return ObjectEntity|null Converted entity or null on failure
     */
    public function convertRowToObjectEntity(array $row, Register $_register, Schema $_schema): ?ObjectEntity
    {
        return $this->statisticsHandler->convertRowToObjectEntity(
            row: $row,
            _register: $_register,
            _schema: $_schema
        );
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
        return $this->tableHandler->tableExistsForRegisterSchema(register: $register, schema: $schema);
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
    /**
     * Check if register+schema combination has changed since last table update
     *
     * @param Register $register The register to check
     * @param Schema   $schema   The schema to check
     *
     * @return bool True if register+schema has changed
     */
    public function hasRegisterSchemaChanged(Register $register, Schema $schema): bool
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
    public function storeRegisterSchemaVersion(Register $register, Schema $schema): void
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
        $cacheKey = $this->getCacheKey(registerId: $registerId, schemaId: $schemaId);

        // Check in-memory cache first to avoid database query.
        if (isset(self::$tableStructureCache[$cacheKey]) === true) {
            return self::$tableStructureCache[$cacheKey];
        }

        // Fall back to appConfig (database).
        $configKey = 'table_version_'.$cacheKey;
        $version   = $this->appConfig->getValueString('openregister', $configKey, '');

        if ($version === '') {
            return null;
        }

        // Store in cache for future calls.
        self::$tableStructureCache[$cacheKey] = $version;

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
        $registerId = $register->getId();
        $schemaId   = $schema->getId();
        $cacheKey   = $this->getCacheKey(registerId: $registerId, schemaId: $schemaId);

        // Check cache first to avoid expensive json_encode + md5.
        if (isset(self::$calcVersionCache[$cacheKey]) === true) {
            return self::$calcVersionCache[$cacheKey];
        }

        $combinedData = [
            'register' => [
                'id'      => $registerId,
                'title'   => $register->getTitle(),
                'version' => $register->getVersion(),
            ],
            'schema'   => [
                'id'         => $schemaId,
                'properties' => $schema->getProperties(),
                'required'   => $schema->getRequired(),
                'title'      => $schema->getTitle(),
                'version'    => $schema->getVersion(),
            ],
        ];

        $version = md5(json_encode($combinedData));

        // Cache for future calls within this request.
        self::$calcVersionCache[$cacheKey] = $version;

        return $version;
    }//end calculateRegisterSchemaVersion()

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
                message: '[MagicMapper] Failed to find object in register+schema table',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
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
            // Convert empty strings to NULL to prevent PostgreSQL JSON/JSONB column errors.
            // PostgreSQL rejects empty strings for JSON columns with "invalid input syntax for type json".
            if ($value === '') {
                $value = null;
            }

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
                // Convert empty strings to NULL to prevent PostgreSQL JSON/JSONB column errors.
                // PostgreSQL rejects empty strings for JSON columns with "invalid input syntax for type json".
                if ($value === '') {
                    $value = null;
                }

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
    public function getExistingTableColumns(string $tableName): array
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
                message: '[MagicMapper] Failed to get existing table columns',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
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
     * @return array Column operation statistics with keys columnsAdded, columnsDeRequired, columnsReRequired, columnsDropped
     */
    public function updateTableStructure(string $tableName, array $currentColumns, array $requiredColumns): array
    {
        $platform      = $this->db->getDatabasePlatform();
        $isPostgres    = ($platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform);
        $tablePrefix   = $this->config->getSystemValue('dbtableprefix', 'oc_');
        $fullTableName = $tablePrefix.$tableName;

        $tableNameQuoted = '`'.$fullTableName.'`';
        if ($isPostgres === true) {
            $tableNameQuoted = '"'.$fullTableName.'"';
        }

        // 1. Add missing columns.
        $columnsAdded = $this->addMissingColumns(
            tableName: $tableName,
            tableNameQuoted: $tableNameQuoted,
            currentColumns: $currentColumns,
            requiredColumns: $requiredColumns,
            isPostgres: $isPostgres
        );

        // 2. De-require columns that are now nullable in schema but NOT NULL in table.
        $columnsDeRequired = $this->deRequireColumns(
            tableName: $tableName,
            tableNameQuoted: $tableNameQuoted,
            currentColumns: $currentColumns,
            requiredColumns: $requiredColumns,
            isPostgres: $isPostgres
        );

        // 3. Re-require columns that are NOT NULL in schema but nullable in table.
        $columnsReRequired = $this->reRequireColumns(
            tableName: $tableName,
            tableNameQuoted: $tableNameQuoted,
            currentColumns: $currentColumns,
            requiredColumns: $requiredColumns,
            isPostgres: $isPostgres
        );

        // 5. Handle duplicate columns (camelCase versions when snake_case exists).
        // Build map of snake_case column names from required columns.
        $snakeCaseColumns = $this->buildSnakeCaseColumnMap(requiredColumns: $requiredColumns);

        $columnsDropped = $this->dropDuplicateCamelCaseColumns(
            tableName: $tableName,
            tableNameQuoted: $tableNameQuoted,
            currentColumns: $currentColumns,
            snakeCaseColumns: $snakeCaseColumns,
            isPostgres: $isPostgres
        );

        // 6. Make obsolete columns nullable (columns in table but not in schema).
        $obsoleteDeRequired = $this->makeObsoleteColumnsNullable(
            tableName: $tableName,
            tableNameQuoted: $tableNameQuoted,
            currentColumns: $currentColumns,
            snakeCaseColumns: $snakeCaseColumns,
            isPostgres: $isPostgres
        );

        $columnsDeRequired = array_merge($columnsDeRequired, $obsoleteDeRequired);

        $this->logger->info(
            message: '[MagicMapper] Successfully updated table structure',
            context: [
                'file'              => __FILE__,
                'line'              => __LINE__,
                'tableName'         => $tableName,
                'columnsAdded'      => $columnsAdded,
                'columnsDeRequired' => $columnsDeRequired,
                'columnsReRequired' => $columnsReRequired,
                'columnsDropped'    => $columnsDropped,
            ]
        );

        // Return statistics about what was changed.
        return [
            'columnsAdded'      => $columnsAdded,
            'columnsDeRequired' => $columnsDeRequired,
            'columnsReRequired' => $columnsReRequired,
            'columnsDropped'    => $columnsDropped,
        ];
    }//end updateTableStructure()

    /**
     * Quote a column or identifier name for the current database platform.
     *
     * @param string $name       The unquoted identifier name.
     * @param bool   $isPostgres Whether the platform is PostgreSQL.
     *
     * @return string The quoted identifier.
     */
    private function quoteIdentifier(string $name, bool $isPostgres): string
    {
        if ($isPostgres === true) {
            return '"'.$name.'"';
        }

        return '`'.$name.'`';
    }//end quoteIdentifier()

    /**
     * Add columns that exist in the schema but not yet in the table.
     *
     * NOTE: $requiredColumns is keyed by property name (camelCase), but the actual
     * column name to use is in $columnDef['name'] (snake_case). We must use
     * $columnDef['name'] to check for existing columns and create new ones.
     *
     * @param string $tableName       The logical table name.
     * @param string $tableNameQuoted The quoted full table name for SQL.
     * @param array  $currentColumns  Current column definitions from the database.
     * @param array  $requiredColumns Required column definitions from the schema.
     * @param bool   $isPostgres      Whether the platform is PostgreSQL.
     *
     * @return array List of column names that were added.
     */
    private function addMissingColumns(
        string $tableName,
        string $tableNameQuoted,
        array $currentColumns,
        array $requiredColumns,
        bool $isPostgres
    ): array {
        $columnsAdded = [];

        foreach ($requiredColumns as $propertyName => $columnDef) {
            // Get the actual column name (snake_case) from the column definition.
            $columnName = $columnDef['name'] ?? $this->sanitizeColumnName(name: $propertyName);

            if (isset($currentColumns[$columnName]) === false) {
                $this->logger->info(
                    message: '[MagicMapper] Adding new column to schema table',
                    context: [
                        'file'         => __FILE__,
                        'line'         => __LINE__,
                        'tableName'    => $tableName,
                        'propertyName' => $propertyName,
                        'columnName'   => $columnName,
                        'columnType'   => $columnDef['type'],
                    ]
                );

                $colNameQuoted = $this->quoteIdentifier(name: $columnName, isPostgres: $isPostgres);
                $colType       = $this->mapColumnTypeToSQL(type: $columnDef['type'], column: $columnDef);
                $sql           = 'ALTER TABLE '.$tableNameQuoted.' ADD COLUMN '.$colNameQuoted.' '.$colType;

                // Add NOT NULL if specified.
                if (($columnDef['nullable'] ?? true) === false) {
                    $sql .= ' NOT NULL';
                }

                // Add DEFAULT if specified.
                if (isset($columnDef['default']) === true) {
                    $defaultValue = $this->formatDefaultValueForSQL(default: $columnDef['default']);
                    $sql         .= ' DEFAULT '.$defaultValue;
                }

                $this->db->executeStatement($sql);
                $columnsAdded[] = $columnName;
            }//end if
        }//end foreach

        return $columnsAdded;
    }//end addMissingColumns()

    /**
     * De-require columns that are now nullable in the schema but NOT NULL in the table.
     *
     * @param string $tableName       The logical table name.
     * @param string $tableNameQuoted The quoted full table name for SQL.
     * @param array  $currentColumns  Current column definitions from the database.
     * @param array  $requiredColumns Required column definitions from the schema.
     * @param bool   $isPostgres      Whether the platform is PostgreSQL.
     *
     * @return array List of column names that were made nullable.
     */
    private function deRequireColumns(
        string $tableName,
        string $tableNameQuoted,
        array $currentColumns,
        array $requiredColumns,
        bool $isPostgres
    ): array {
        $columnsDeRequired = [];

        foreach ($requiredColumns as $propertyName => $columnDef) {
            // Get the actual column name (snake_case) from the column definition.
            $columnName = $columnDef['name'] ?? $this->sanitizeColumnName(name: $propertyName);

            if (isset($currentColumns[$columnName]) === false) {
                continue;
            }

            $currentCol       = $currentColumns[$columnName];
            $schemaIsNullable = ($columnDef['nullable'] ?? true);
            $tableIsNullable  = ($currentCol['nullable'] ?? true);

            // If schema says nullable but table says NOT NULL, make column nullable.
            if ($schemaIsNullable === true && $tableIsNullable === false) {
                $this->logger->info(
                    message: '[MagicMapper] Making column nullable (no longer required)',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'tableName'  => $tableName,
                        'columnName' => $columnName,
                    ]
                );

                $colNameQuoted = $this->quoteIdentifier(name: $columnName, isPostgres: $isPostgres);

                // MySQL syntax - need to specify full column definition.
                $colType = $this->mapColumnTypeToSQL(type: $columnDef['type'], column: $columnDef);
                $sql     = 'ALTER TABLE '.$tableNameQuoted.' MODIFY COLUMN '.$colNameQuoted.' '.$colType.' NULL';
                if ($isPostgres === true) {
                    $sql = 'ALTER TABLE '.$tableNameQuoted.' ALTER COLUMN '.$colNameQuoted.' DROP NOT NULL';
                }

                try {
                    $this->db->executeStatement($sql);
                    $columnsDeRequired[] = $columnName;
                } catch (Exception $e) {
                    $this->logger->warning(
                        message: '[MagicMapper] Failed to make column nullable',
                        context: [
                            'file'       => __FILE__,
                            'line'       => __LINE__,
                            'columnName' => $columnName,
                            'error'      => $e->getMessage(),
                        ]
                    );
                }
            }//end if
        }//end foreach

        return $columnsDeRequired;
    }//end deRequireColumns()

    /**
     * Re-require columns that are NOT NULL in the schema but nullable in the table.
     *
     * @param string $tableName       The logical table name.
     * @param string $tableNameQuoted The quoted full table name for SQL.
     * @param array  $currentColumns  Current column definitions from the database.
     * @param array  $requiredColumns Required column definitions from the schema.
     * @param bool   $isPostgres      Whether the platform is PostgreSQL.
     *
     * @return array List of column names that were made NOT NULL.
     */
    private function reRequireColumns(
        string $tableName,
        string $tableNameQuoted,
        array $currentColumns,
        array $requiredColumns,
        bool $isPostgres
    ): array {
        $columnsReRequired = [];

        foreach ($requiredColumns as $propertyName => $columnDef) {
            // Get the actual column name (snake_case) from the column definition.
            $columnName = $columnDef['name'] ?? $this->sanitizeColumnName(name: $propertyName);

            if (isset($currentColumns[$columnName]) === false) {
                continue;
            }

            $currentCol       = $currentColumns[$columnName];
            $schemaIsNullable = ($columnDef['nullable'] ?? true);
            $tableIsNullable  = ($currentCol['nullable'] ?? true);

            // If schema says NOT NULL but table says nullable, add NOT NULL constraint.
            if ($schemaIsNullable === false && $tableIsNullable === true) {
                $this->logger->info(
                    message: '[MagicMapper] Making column NOT NULL (now required)',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'tableName'  => $tableName,
                        'columnName' => $columnName,
                    ]
                );

                $colNameQuoted = $this->quoteIdentifier(name: $columnName, isPostgres: $isPostgres);

                // MySQL syntax - need to specify full column definition.
                $colType = $this->mapColumnTypeToSQL(type: $columnDef['type'], column: $columnDef);
                $sql     = 'ALTER TABLE '.$tableNameQuoted.' MODIFY COLUMN '.$colNameQuoted.' '.$colType.' NOT NULL';
                if ($isPostgres === true) {
                    $sql = 'ALTER TABLE '.$tableNameQuoted.' ALTER COLUMN '.$colNameQuoted.' SET NOT NULL';
                }

                try {
                    $this->db->executeStatement($sql);
                    $columnsReRequired[] = $columnName;
                } catch (Exception $e) {
                    $this->logger->warning(
                        message: '[MagicMapper] Failed to make column NOT NULL (may contain null values)',
                        context: [
                            'file'       => __FILE__,
                            'line'       => __LINE__,
                            'columnName' => $columnName,
                            'error'      => $e->getMessage(),
                        ]
                    );
                }
            }//end if
        }//end foreach

        return $columnsReRequired;
    }//end reRequireColumns()

    /**
     * Build a lookup map of snake_case column names from the required columns.
     *
     * @param array $requiredColumns Required column definitions from the schema.
     *
     * @return array Associative array with snake_case column names as keys and true as values.
     */
    private function buildSnakeCaseColumnMap(array $requiredColumns): array
    {
        $snakeCaseColumns = [];
        foreach ($requiredColumns as $propertyName => $colDef) {
            $actualColName = $colDef['name'] ?? $this->sanitizeColumnName(name: $propertyName);
            $snakeCaseColumns[$actualColName] = true;
        }

        return $snakeCaseColumns;
    }//end buildSnakeCaseColumnMap()

    /**
     * Drop duplicate camelCase columns when a snake_case equivalent exists.
     *
     * @param string $tableName        The logical table name.
     * @param string $tableNameQuoted  The quoted full table name for SQL.
     * @param array  $currentColumns   Current column definitions from the database.
     * @param array  $snakeCaseColumns Map of snake_case column names from the schema.
     * @param bool   $isPostgres       Whether the platform is PostgreSQL.
     *
     * @return array List of column names that were dropped.
     */
    private function dropDuplicateCamelCaseColumns(
        string $tableName,
        string $tableNameQuoted,
        array $currentColumns,
        array $snakeCaseColumns,
        bool $isPostgres
    ): array {
        $columnsDropped = [];

        // Find camelCase duplicates in current columns.
        foreach (array_keys($currentColumns) as $colName) {
            // Skip metadata columns (start with _).
            if (str_starts_with($colName, '_') === true) {
                continue;
            }

            // Check if this looks like a camelCase version of a snake_case column.
            $snakeVersion = $this->sanitizeColumnName(name: $colName);
            if ($snakeVersion !== $colName && isset($snakeCaseColumns[$snakeVersion]) === true) {
                // This is a camelCase duplicate - drop it.
                $this->logger->info(
                    message: '[MagicMapper] Dropping duplicate camelCase column (snake_case version exists)',
                    context: [
                        'file'         => __FILE__,
                        'line'         => __LINE__,
                        'tableName'    => $tableName,
                        'camelCaseCol' => $colName,
                        'snakeCaseCol' => $snakeVersion,
                    ]
                );

                $colNameQuoted = $this->quoteIdentifier(name: $colName, isPostgres: $isPostgres);
                $sql           = 'ALTER TABLE '.$tableNameQuoted.' DROP COLUMN IF EXISTS '.$colNameQuoted;

                try {
                    $this->db->executeStatement($sql);
                    $columnsDropped[] = $colName;
                } catch (Exception $e) {
                    $this->logger->warning(
                        message: '[MagicMapper] Failed to drop duplicate column',
                        context: [
                            'file'       => __FILE__,
                            'line'       => __LINE__,
                            'columnName' => $colName,
                            'error'      => $e->getMessage(),
                        ]
                    );
                }
            }//end if
        }//end foreach

        return $columnsDropped;
    }//end dropDuplicateCamelCaseColumns()

    /**
     * Make obsolete columns nullable (columns in the table but not in the schema).
     *
     * This is safer than dropping them — data is preserved.
     *
     * @param string $tableName        The logical table name.
     * @param string $tableNameQuoted  The quoted full table name for SQL.
     * @param array  $currentColumns   Current column definitions from the database.
     * @param array  $snakeCaseColumns Map of snake_case column names from the schema.
     * @param bool   $isPostgres       Whether the platform is PostgreSQL.
     *
     * @return array List of column names (suffixed with " (obsolete)") that were made nullable.
     */
    private function makeObsoleteColumnsNullable(
        string $tableName,
        string $tableNameQuoted,
        array $currentColumns,
        array $snakeCaseColumns,
        bool $isPostgres
    ): array {
        $columnsDeRequired = [];

        foreach ($currentColumns as $colName => $colDef) {
            // Skip metadata columns.
            if (str_starts_with($colName, '_') === true) {
                continue;
            }

            // Skip if column is a schema column (exists in snakeCaseColumns map).
            if (isset($snakeCaseColumns[$colName]) === true) {
                continue;
            }

            // Skip if column is already nullable.
            if (($colDef['nullable'] ?? true) === true) {
                continue;
            }

            // This is an obsolete column - make it nullable.
            $this->logger->info(
                message: '[MagicMapper] Making obsolete column nullable',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'tableName'  => $tableName,
                    'columnName' => $colName,
                ]
            );

            $colNameQuoted = $this->quoteIdentifier(name: $colName, isPostgres: $isPostgres);

            $colType = $colDef['type'] ?? 'text';
            $sql     = 'ALTER TABLE '.$tableNameQuoted.' MODIFY COLUMN '.$colNameQuoted.' '.$colType.' NULL';
            if ($isPostgres === true) {
                $sql = 'ALTER TABLE '.$tableNameQuoted.' ALTER COLUMN '.$colNameQuoted.' DROP NOT NULL';
            }

            try {
                $this->db->executeStatement($sql);
                $columnsDeRequired[] = $colName.' (obsolete)';
            } catch (Exception $e) {
                $this->logger->warning(
                    message: '[MagicMapper] Failed to make obsolete column nullable',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'columnName' => $colName, 'error' => $e->getMessage()]
                );
            }
        }//end foreach

        return $columnsDeRequired;
    }//end makeObsoleteColumnsNullable()

    /**
     * Format a default value for SQL statement.
     *
     * @param mixed $default The default value
     *
     * @return string SQL-formatted default value
     */
    private function formatDefaultValueForSQL(mixed $default): string
    {
        if (is_bool($default) === true) {
            if ($default === true) {
                return 'TRUE';
            }

            return 'FALSE';
        }

        if (is_string($default) === true) {
            return "'".$default."'";
        }

        if ($default === null) {
            return 'NULL';
        }

        return (string) $default;
    }//end formatDefaultValueForSQL()

    /**
     * Update table indexes
     *
     * @param string   $tableName The table name
     * @param Register $register  The register context
     * @param Schema   $schema    The schema for index analysis
     *
     * @return void
     */
    public function updateTableIndexes(string $tableName, Register $register, Schema $schema): void
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
    public function dropTable(string $tableName): void
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
                    $this->invalidateTableCache(cacheKey: $cacheKey);
                    break;
                }
            }

            $this->logger->info(
                message: '[MagicMapper] Dropped register+schema table',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'tableName' => $tableName,
                ]
            );
        } catch (Exception $e) {
            $this->logger->error(
                message: '[MagicMapper] Failed to drop table',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'tableName' => $tableName,
                    'error'     => $e->getMessage(),
                ]
            );

            throw $e;
        }//end try
    }//end dropTable()

    /**
     * Clear all caches for MagicMapper.
     *
     * @param int|null $registerId Optional register ID to clear cache for specific register
     * @param int|null $schemaId   Optional schema ID to clear cache for specific schema
     *
     * @return void
     */
    public function clearCache(?int $registerId=null, ?int $schemaId=null): void
    {
        $this->tableHandler->clearCache(registerId: $registerId, schemaId: $schemaId);
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
        return $this->tableHandler->getExistingRegisterSchemaTables();
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
        return $this->tableHandler->isMagicMappingEnabled(_register: $_register, schema: $schema);
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
        return $this->tableHandler->isMagicMappingEnabledForSchema(schema: $schema);
    }//end isMagicMappingEnabledForSchema()

    // ==================================================================================.
    // OBJECTENTITY-COMPATIBLE METHODS (Internal Magic Table Operations).
    // ==================================================================================.

    /**
     * Find object in register+schema table by identifier (ID, UUID, slug, or URI).
     *
     * This method provides ObjectEntity compatibility for the public find() method.
     *
     * @param string|int $identifier     Object identifier (ID, UUID, slug, or URI).
     * @param Register   $register       The register context.
     * @param Schema     $schema         The schema context.
     * @param bool       $_rbac          Whether to apply RBAC.
     * @param bool       $_multitenancy  Whether to apply multi-tenancy.
     * @param bool       $includeDeleted Whether to include soft-deleted objects.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple objects found.
     *
     * @return ObjectEntity The found object.
     */
    public function findInRegisterSchemaTable(
        string|int $identifier,
        Register $register,
        Schema $schema,
        bool $_rbac=true,
        bool $_multitenancy=true,
        bool $includeDeleted=false
    ): ObjectEntity {
        // Ensure table exists if magic mapping is enabled.
        if ($this->existsTableForRegisterSchema(register: $register, schema: $schema) === false) {
            $isMagicEnabled = $register->isMagicMappingEnabledForSchema(
                schemaId: $schema->getId(),
                schemaSlug: $schema->getSlug()
            );
            if ($isMagicEnabled === true) {
                $this->logger->info(
                    message: '[MagicMapper] Register+schema table does not exist but magic mapping enabled, creating table',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'registerId' => $register->getId(),
                        'schemaId'   => $schema->getId(),
                    ]
                );
                $this->ensureTableForRegisterSchema(register: $register, schema: $schema);
            }
        }

        $tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);

        $this->logger->debug(
            message: '[MagicMapper] Finding object in register+schema table',
            context: [
                'file'         => __FILE__,
                'line'         => __LINE__,
                'identifier'   => $identifier,
                'tableName'    => $tableName,
                'rbac'         => $_rbac,
                'multitenancy' => $_multitenancy,
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

        // Exclude deleted objects by default (unless includeDeleted is true).
        if ($includeDeleted === false) {
            $qb->andWhere($qb->expr()->isNull(self::METADATA_PREFIX.'deleted'));
        }

        // Apply multitenancy filtering if enabled.
        // Note: For MagicMapper, we rely on the table structure itself for multitenancy,.
        // as the organisation column is part of the schema. The $_multitenancy parameter.
        // is primarily used to decide whether to filter at all.
        // For now, we skip adding explicit organisation filters in MagicMapper.
        // as that's handled by RBAC and the table structure.
        // Apply RBAC filtering if enabled.
        if ($_rbac === true) {
            // Add RBAC filtering logic here if needed.
            // Currently skipped as owner/authorization logic is complex.
        }

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
                message: '[MagicMapper] Failed to find object in register+schema table',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'identifier' => $identifier,
                    'tableName'  => $tableName,
                    'error'      => $e->getMessage(),
                ]
            );

            throw new DoesNotExistException($e->getMessage());
        }//end try
    }//end findInRegisterSchemaTable()

    /**
     * Find an object across all magic tables without knowing register/schema upfront.
     *
     * This method searches all existing magic tables for an object by its identifier.
     * It's useful for operations like lock/unlock where the caller doesn't know
     * which storage backend contains the object.
     *
     * @param string|int $identifier     Object identifier (ID, UUID, slug, or URI).
     * @param bool       $includeDeleted Whether to include deleted objects.
     * @param bool       $_rbac          Whether to apply RBAC checks.
     * @param bool       $_multitenancy  Whether to apply multitenancy filtering.
     *
     * @return array{object: ObjectEntity, register: Register|null, schema: Schema|null}
     *               The found object with its register and schema context.
     *
     * @throws DoesNotExistException If object not found in any magic table.
     */
    public function findAcrossAllMagicTables(
        string|int $identifier,
        bool $includeDeleted=false,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): array {
        $this->logger->debug(
            message: '[MagicMapper] findAcrossAllMagicTables: Starting search',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'identifier' => $identifier,
            ]
                );

        // Get all magic tables from information_schema.
        // NOTE: We use raw SQL here because the query builder adds the table prefix.
        // to information_schema, which is a system schema and shouldn't be prefixed.
        $prefix       = 'oc_';
        $tablePattern = $prefix.'openregister_table_%';

        $sql    = "SELECT table_name FROM information_schema.tables WHERE table_name LIKE ?";
        $stmt   = $this->db->prepare($sql);
        $result = $stmt->execute([$tablePattern]);
        $tables = $result->fetchAll();

        $this->logger->debug(
            message: '[MagicMapper] findAcrossAllMagicTables: Found magic tables',
            context: [
                'file'  => __FILE__,
                'line'  => __LINE__,
                'count' => count($tables),
            ]
                );

        // Get register and schema mappers.
        $registerMapper = \OC::$server->get(RegisterMapper::class);
        $schemaMapper   = \OC::$server->get(SchemaMapper::class);

        // Search each magic table.
        foreach ($tables as $tableRow) {
            $fullTableName = $tableRow['table_name'] ?? $tableRow['TABLE_NAME'] ?? null;
            if ($fullTableName === null) {
                continue;
            }

            // Extract register and schema IDs from table name: oc_openregister_table_{registerId}_{schemaId}.
            $tableName = str_replace($prefix, '', $fullTableName);
            if (preg_match('/^openregister_table_(\d+)_(\d+)$/', $tableName, $matches) !== 1) {
                continue;
            }

            $registerId = (int) $matches[1];
            $schemaId   = (int) $matches[2];

            try {
                // Build query to search this table.
                // NOTE: Use $tableName (without prefix) because QueryBuilder adds prefix automatically.
                $searchQb = $this->db->getQueryBuilder();
                $searchQb->select('*')->from($tableName);

                // Build identifier conditions.
                $idCol      = self::METADATA_PREFIX.'id';
                $uuidCol    = self::METADATA_PREFIX.'uuid';
                $slugCol    = self::METADATA_PREFIX.'slug';
                $uriCol     = self::METADATA_PREFIX.'uri';
                $deletedCol = self::METADATA_PREFIX.'deleted';

                $idParam = -1;
                if (is_numeric($identifier) === true) {
                    $idParam = (int) $identifier;
                }

                $idExpr   = $searchQb->expr()->eq(
                    $idCol,
                    $searchQb->createNamedParameter($idParam, IQueryBuilder::PARAM_INT)
                );
                $uuidExpr = $searchQb->expr()->eq(
                    $uuidCol,
                    $searchQb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR)
                );
                $slugExpr = $searchQb->expr()->eq(
                    $slugCol,
                    $searchQb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR)
                );
                $uriExpr  = $searchQb->expr()->eq(
                    $uriCol,
                    $searchQb->createNamedParameter($identifier, IQueryBuilder::PARAM_STR)
                );
                $searchQb->where(
                    $searchQb->expr()->orX($idExpr, $uuidExpr, $slugExpr, $uriExpr)
                );

                // Exclude deleted unless requested.
                if ($includeDeleted === false) {
                    $searchQb->andWhere($searchQb->expr()->isNull($deletedCol));
                }

                $searchResult = $searchQb->executeQuery();
                $row          = $searchResult->fetch();
                $searchResult->closeCursor();

                if ($row !== false) {
                    // Found the object! Get register and schema entities.
                    $register = null;
                    $schema   = null;

                    try {
                        $register = $registerMapper->find(id: $registerId, _multitenancy: false);
                        $schema   = $schemaMapper->find(id: $schemaId, _multitenancy: false);
                    } catch (\Exception $e) {
                        $this->logger->warning(
                            message: '[MagicMapper] findAcrossAllMagicTables: Could not load register/schema',
                            context: [
                                'file'       => __FILE__,
                                'line'       => __LINE__,
                                'registerId' => $registerId,
                                'schemaId'   => $schemaId,
                                'error'      => $e->getMessage(),
                            ]
                                );
                    }

                    // Convert row to ObjectEntity.
                    $object = $this->convertRowToObjectEntity(
                        row: $row,
                        _register: $register,
                        _schema: $schema
                    );

                    $this->logger->debug(
                        message: '[MagicMapper] findAcrossAllMagicTables: Found object',
                        context: [
                            'file'       => __FILE__,
                            'line'       => __LINE__,
                            'uuid'       => $object->getUuid(),
                            'registerId' => $registerId,
                            'schemaId'   => $schemaId,
                        ]
                    );

                    return [
                        'object'   => $object,
                        'register' => $register,
                        'schema'   => $schema,
                    ];
                }//end if
            } catch (\Exception $e) {
                // Table might not have the expected structure, skip it.
                $this->logger->debug(
                    message: '[MagicMapper] findAcrossAllMagicTables: Error searching table',
                    context: [
                        'file'  => __FILE__,
                        'line'  => __LINE__,
                        'table' => $fullTableName,
                        'error' => $e->getMessage(),
                    ]
                );
                continue;
            }//end try
        }//end foreach

        // Not found in any magic table.
        throw new DoesNotExistException("Object with identifier '$identifier' not found in any magic table");
    }//end findAcrossAllMagicTables()

    /**
     * Find multiple objects by UUIDs across ALL magic tables.
     *
     * This method efficiently searches all magic tables for multiple UUIDs in batch.
     * It's optimized for performance by searching all UUIDs in each table with a single query.
     *
     * @param array $uuids          Array of UUIDs to search for.
     * @param bool  $includeDeleted Whether to include soft-deleted objects.
     *
     * @return ObjectEntity[] Array of found objects (may be fewer than requested if some not found).
     */
    public function findMultipleAcrossAllMagicTables(
        array $uuids,
        bool $includeDeleted=false
    ): array {
        if (empty($uuids) === true) {
            return [];
        }

        $uuids        = array_unique($uuids);
        $foundObjects = [];

        // Get all magic tables from information_schema.
        $prefix       = 'oc_';
        $tablePattern = $prefix.'openregister_table_%';

        $sql    = "SELECT table_name FROM information_schema.tables WHERE table_name LIKE ?";
        $stmt   = $this->db->prepare($sql);
        $result = $stmt->execute([$tablePattern]);
        $tables = $result->fetchAll();

        // PERFORMANCE OPTIMIZATION: Use a single UNION query to find ALL UUIDs across ALL tables.
        // This reduces ~60 queries (COUNT + SELECT per table) to just 1 query.
        $unionParts   = [];
        $tableInfoMap = [];
        // Maps table name to register/schema IDs.
        $uuidCol    = self::METADATA_PREFIX.'uuid';
        $deletedCol = self::METADATA_PREFIX.'deleted';

        // Prepare UUID placeholders for raw SQL.
        $uuidPlaceholders = implode(',', array_fill(0, count($uuids), '?'));

        foreach ($tables as $tableRow) {
            $fullTableName = $tableRow['table_name'] ?? $tableRow['TABLE_NAME'] ?? null;
            if ($fullTableName === null) {
                continue;
            }

            // Extract register and schema IDs from table name.
            $tableName = str_replace($prefix, '', $fullTableName);
            if (preg_match('/^openregister_table_(\d+)_(\d+)$/', $tableName, $matches) !== 1) {
                continue;
            }

            $registerId = (int) $matches[1];
            $schemaId   = (int) $matches[2];
            $tableInfoMap[$fullTableName] = ['registerId' => $registerId, 'schemaId' => $schemaId];

            // Build UNION part for this table - select only metadata columns for efficiency.
            $deletedCondition = " AND {$deletedCol} IS NULL";
            if ($includeDeleted === true) {
                $deletedCondition = '';
            }

            $unionParts[] = sprintf(
                "SELECT '%s' AS _source_table, %s AS found_uuid FROM %s WHERE %s IN (%s)%s",
                $fullTableName,
                $uuidCol,
                $fullTableName,
                $uuidCol,
                $uuidPlaceholders,
                $deletedCondition
            );
        }//end foreach

        if (empty($unionParts) === true) {
            return [];
        }

        // Execute single UNION query to find which tables contain which UUIDs.
        $unionSql    = implode(' UNION ALL ', $unionParts);
        $unionParams = array_merge(...array_fill(0, count($unionParts), $uuids));

        try {
            $stmt        = $this->db->prepare($unionSql);
            $unionResult = $stmt->execute($unionParams);
            $matches     = $unionResult->fetchAll();
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[MagicMapper] findMultipleAcrossAllMagicTables: UNION query failed',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
                    );
            // Fallback to old per-table approach would go here, but for now return empty.
            return [];
        }

        // Group found UUIDs by table for efficient batch fetching.
        $uuidsByTable = [];
        foreach ($matches as $match) {
            $table = $match['_source_table'];
            $uuid  = $match['found_uuid'];
            if (isset($uuidsByTable[$table]) === false) {
                $uuidsByTable[$table] = [];
            }

            $uuidsByTable[$table][] = $uuid;
        }

        // Get register and schema mappers.
        $registerMapper = \OC::$server->get(RegisterMapper::class);
        $schemaMapper   = \OC::$server->get(SchemaMapper::class);

        // Cache for register/schema lookups.
        static $registerCache = [];
        static $schemaCache   = [];

        // Now fetch full rows only from tables that have matches.
        foreach ($uuidsByTable as $fullTableName => $tableUuids) {
            $tableInfo = $tableInfoMap[$fullTableName] ?? null;
            if ($tableInfo === null) {
                continue;
            }

            $registerId    = $tableInfo['registerId'];
            $schemaId      = $tableInfo['schemaId'];
            $bareTableName = str_replace($prefix, '', $fullTableName);

            try {
                // Load register and schema (with caching).
                if (isset($registerCache[$registerId]) === false) {
                    $registerCache[$registerId] = $registerMapper->find(id: $registerId, _multitenancy: false);
                }

                if (isset($schemaCache[$schemaId]) === false) {
                    $schemaCache[$schemaId] = $schemaMapper->find(id: $schemaId, _multitenancy: false);
                }

                // Fetch full rows for found UUIDs.
                $searchQb = $this->db->getQueryBuilder();
                $searchQb->select('*')->from($bareTableName);
                $searchQb->where(
                    $searchQb->expr()->in(
                        $uuidCol,
                        $searchQb->createNamedParameter($tableUuids, IQueryBuilder::PARAM_STR_ARRAY)
                    )
                );

                if ($includeDeleted === false) {
                    $searchQb->andWhere($searchQb->expr()->isNull($deletedCol));
                }

                $searchResult = $searchQb->executeQuery();
                $rows         = $searchResult->fetchAll();
                $searchResult->closeCursor();

                // Convert found rows to ObjectEntity objects.
                // Add register and schema IDs to row since they're derived from table name, not stored in columns.
                foreach ($rows as $row) {
                    $row['_register'] = (string) $registerId;
                    $row['_schema']   = (string) $schemaId;
                    $foundObjects[]   = $this->rowToObjectEntity(row: $row);
                }
            } catch (\Exception $e) {
                $this->logger->debug(
                    message: '[MagicMapper] findMultipleAcrossAllMagicTables: Error fetching from table',
                    context: [
                        'file'  => __FILE__,
                        'line'  => __LINE__,
                        'table' => $fullTableName,
                        'error' => $e->getMessage(),
                    ]
                        );
                continue;
            }//end try
        }//end foreach

        $this->logger->debug(
            message: '[MagicMapper] findMultipleAcrossAllMagicTables: Batch search complete',
            context: [
                'file'              => __FILE__,
                'line'              => __LINE__,
                'requestedCount'    => count($uuids),
                'foundCount'        => count($foundObjects),
                'tablesWithMatches' => count($uuidsByTable),
            ]
        );

        return $foundObjects;
    }//end findMultipleAcrossAllMagicTables()

    /**
     * Find all objects across ALL magic tables that have the given UUID in their relations.
     *
     * This method searches across all magic tables to find objects that reference the given UUID.
     * Relations are stored as JSON objects like {"fieldName": "uuid", ...}.
     *
     * @param string $uuid           The UUID to search for in relations.
     * @param bool   $includeDeleted Whether to include deleted objects.
     *
     * @return ObjectEntity[] Array of found ObjectEntity objects.
     */
    public function findByRelationAcrossAllMagicTables(
        string $uuid,
        bool $includeDeleted=false
    ): array {
        if (empty($uuid) === true) {
            return [];
        }

        $foundObjects = [];

        // Get all magic tables from information_schema.
        $prefix       = 'oc_';
        $tablePattern = $prefix.'openregister_table_%';

        $sql    = "SELECT table_name FROM information_schema.tables WHERE table_name LIKE ?";
        $stmt   = $this->db->prepare($sql);
        $result = $stmt->execute([$tablePattern]);
        $tables = $result->fetchAll();

        // PERFORMANCE OPTIMIZATION: Use a single UNION query to find objects across ALL tables.
        $unionParts   = [];
        $tableInfoMap = [];
        // Maps table name to register/schema IDs.
        $uuidCol      = self::METADATA_PREFIX.'uuid';
        $deletedCol   = self::METADATA_PREFIX.'deleted';
        $relationsCol = self::METADATA_PREFIX.'relations';

        foreach ($tables as $tableRow) {
            $fullTableName = $tableRow['table_name'] ?? $tableRow['TABLE_NAME'] ?? null;
            if ($fullTableName === null) {
                continue;
            }

            // Extract register and schema IDs from table name.
            $tableName = str_replace($prefix, '', $fullTableName);
            if (preg_match('/^openregister_table_(\d+)_(\d+)$/', $tableName, $matches) !== 1) {
                continue;
            }

            $registerId = (int) $matches[1];
            $schemaId   = (int) $matches[2];
            $tableInfoMap[$fullTableName] = ['registerId' => $registerId, 'schemaId' => $schemaId];

            // Build UNION part - search for UUID in relation VALUES using text search.
            // This is more reliable than jsonb_each_text as it handles various JSON formats.
            $deletedCondition = " AND {$deletedCol} IS NULL";
            if ($includeDeleted === true) {
                $deletedCondition = '';
            }

            $unionParts[] = sprintf(
                "SELECT '%s' AS _source_table, %s AS found_uuid FROM %s WHERE %s::text LIKE ?%s",
                $fullTableName,
                $uuidCol,
                $fullTableName,
                $relationsCol,
                $deletedCondition
            );
        }//end foreach

        if (empty($unionParts) === true) {
            return [];
        }

        // Execute single UNION query.
        $unionSql = implode(' UNION ALL ', $unionParts);
        // Use LIKE pattern to match UUID anywhere in the JSON text.
        $likePattern = '%"'.$uuid.'"%';
        $unionParams = array_fill(0, count($unionParts), $likePattern);

        try {
            $stmt        = $this->db->prepare($unionSql);
            $unionResult = $stmt->execute($unionParams);
            $matches     = $unionResult->fetchAll();
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[MagicMapper] findByRelationAcrossAllMagicTables: UNION query failed',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
                    );
            return [];
        }

        // Group found UUIDs by table for efficient batch fetching.
        $uuidsByTable = [];
        foreach ($matches as $match) {
            $table     = $match['_source_table'];
            $foundUuid = $match['found_uuid'];
            if (isset($uuidsByTable[$table]) === false) {
                $uuidsByTable[$table] = [];
            }

            $uuidsByTable[$table][] = $foundUuid;
        }

        // Get register and schema mappers.
        $registerMapper = \OC::$server->get(RegisterMapper::class);
        $schemaMapper   = \OC::$server->get(SchemaMapper::class);

        // Cache for register/schema lookups.
        static $registerCache = [];
        static $schemaCache   = [];

        // Fetch full rows only from tables that have matches.
        foreach ($uuidsByTable as $fullTableName => $tableUuids) {
            $tableInfo = $tableInfoMap[$fullTableName] ?? null;
            if ($tableInfo === null) {
                continue;
            }

            $registerId    = $tableInfo['registerId'];
            $schemaId      = $tableInfo['schemaId'];
            $bareTableName = str_replace($prefix, '', $fullTableName);

            try {
                // Load register and schema (with caching).
                if (isset($registerCache[$registerId]) === false) {
                    $registerCache[$registerId] = $registerMapper->find(id: $registerId, _multitenancy: false);
                }

                if (isset($schemaCache[$schemaId]) === false) {
                    $schemaCache[$schemaId] = $schemaMapper->find(id: $schemaId, _multitenancy: false);
                }

                // Fetch full rows for found UUIDs.
                $searchQb = $this->db->getQueryBuilder();
                $searchQb->select('*')->from($bareTableName);
                $searchQb->where(
                    $searchQb->expr()->in(
                        $uuidCol,
                        $searchQb->createNamedParameter($tableUuids, IQueryBuilder::PARAM_STR_ARRAY)
                    )
                );

                if ($includeDeleted === false) {
                    $searchQb->andWhere($searchQb->expr()->isNull($deletedCol));
                }

                $searchResult = $searchQb->executeQuery();
                $rows         = $searchResult->fetchAll();
                $searchResult->closeCursor();

                // Convert found rows to ObjectEntity objects.
                foreach ($rows as $row) {
                    $row['_register'] = (string) $registerId;
                    $row['_schema']   = (string) $schemaId;
                    $foundObjects[]   = $this->rowToObjectEntity(row: $row);
                }
            } catch (\Exception $e) {
                $this->logger->debug(
                    message: '[MagicMapper] findByRelationAcrossAllMagicTables: Error fetching from table',
                    context: [
                        'file'  => __FILE__,
                        'line'  => __LINE__,
                        'table' => $fullTableName,
                        'error' => $e->getMessage(),
                    ]
                        );
                continue;
            }//end try
        }//end foreach

        $this->logger->debug(
            message: '[MagicMapper] findByRelationAcrossAllMagicTables: Search complete',
            context: [
                'file'              => __FILE__,
                'line'              => __LINE__,
                'uuid'              => $uuid,
                'foundCount'        => count($foundObjects),
                'tablesWithMatches' => count($uuidsByTable),
            ]
        );

        return $foundObjects;
    }//end findByRelationAcrossAllMagicTables()

    /**
     * Find all objects in register+schema table with filtering and pagination.
     *
     * @param Register   $register The register context.
     * @param Schema     $schema   The schema context.
     * @param int|null   $limit    Maximum number of results.
     * @param int|null   $offset   Offset for pagination.
     * @param array|null $filters  Filters to apply.
     * @param array      $sort     Sort order.
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
        array $sort=[]
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

        return $this->searchObjectsInRegisterSchemaTable(query: $query, register: $register, schema: $schema);
    }//end findAllInRegisterSchemaTable()

    /**
     * Insert ObjectEntity into register+schema table.
     *
     * @param ObjectEntity $entity         The object entity to insert.
     * @param Register     $register       The register context.
     * @param Schema       $schema         The schema context.
     * @param bool         $dispatchEvents Whether to dispatch events.
     *
     * @throws Exception If insertion fails.
     *
     * @return ObjectEntity The inserted object entity.
     */
    public function insertObjectEntity(
        ObjectEntity $entity,
        Register $register,
        Schema $schema,
        bool $dispatchEvents=true
    ): ObjectEntity {
        // Dispatch creating event (pre-save hook).
        if ($dispatchEvents === true) {
            $creatingEvent = new ObjectCreatingEvent(object: $entity);
            $this->eventDispatcher->dispatchTyped($creatingEvent);

            // Check if a hook stopped propagation (reject mode).
            if ($creatingEvent->isPropagationStopped() === true) {
                throw new HookStoppedException(
                    message: (string) ($creatingEvent->getErrors()['message'] ?? 'Object creation rejected by hook'),
                    errors: $creatingEvent->getErrors()
                );
            }

            // Merge modified data from hooks if any.
            $modifiedData = $creatingEvent->getModifiedData();
            if (empty($modifiedData) === false) {
                $objectData = $entity->getObject() ?? [];
                $entity->setObject(array_merge($objectData, $modifiedData));
            }
        }

        // Ensure table exists.
        $this->ensureTableForRegisterSchema(register: $register, schema: $schema);

        $tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);

        $this->logger->debug(
            message: '[MagicMapper] Inserting object entity into register+schema table',
            context: [
                'file'      => __FILE__,
                'line'      => __LINE__,
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
            $this->logger->warning(
                message: '[MagicMapper] Failed to re-fetch inserted entity, using fallback',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            $row = $this->findObjectInRegisterSchemaTable(uuid: $uuid, tableName: $tableName);
            if ($row !== null) {
                $entity->setId((int) $row[self::METADATA_PREFIX.'id']);
            }

            $insertedEntity = $entity;
        }

        // NOTE: Event dispatching is handled by the public insert/update/delete methods to avoid duplicate events.
        // Do NOT dispatch ObjectCreatedEvent here.
        return $insertedEntity;
    }//end insertObjectEntity()

    /**
     * Update ObjectEntity in register+schema table.
     *
     * @param ObjectEntity      $entity    The object entity to update.
     * @param Register          $register  The register context.
     * @param Schema            $schema    The schema context.
     * @param ObjectEntity|null $oldEntity The old entity for comparison.
     *
     * @throws Exception If update fails.
     *
     * @return ObjectEntity The updated object entity.
     */
    public function updateObjectEntity(
        ObjectEntity $entity,
        Register $register,
        Schema $schema,
        ?ObjectEntity $oldEntity=null
    ): ObjectEntity {
        // Use provided oldEntity or fetch from database.
        $oldObject = $oldEntity;
        if ($oldEntity === null) {
            $oldObject = $this->findInRegisterSchemaTable(
                identifier: $entity->getUuid(),
                register: $register,
                schema: $schema
            );
        }

        $this->logger->debug(
            message: '[MagicMapper] updateObjectEntity called - UUID: '.$entity->getUuid(),
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        // Dispatch updating event (pre-save hook).
        $updatingEvent = new ObjectUpdatingEvent(newObject: $entity, oldObject: $oldObject);
        $this->eventDispatcher->dispatchTyped($updatingEvent);

        // Check if a hook stopped propagation (reject mode).
        if ($updatingEvent->isPropagationStopped() === true) {
            throw new HookStoppedException(
                message: (string) ($updatingEvent->getErrors()['message'] ?? 'Object update rejected by hook'),
                errors: $updatingEvent->getErrors()
            );
        }

        // Merge modified data from hooks if any.
        $modifiedData = $updatingEvent->getModifiedData();
        if (empty($modifiedData) === false) {
            $objectData = $entity->getObject() ?? [];
            $entity->setObject(array_merge($objectData, $modifiedData));
        }

        $tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
        $uuid      = $entity->getUuid();

        if ($uuid === null) {
            throw new Exception('Cannot update object entity without UUID');
        }

        $this->logger->debug(
            message: '[MagicMapper] Updating object entity in register+schema table',
            context: [
                'file'      => __FILE__,
                'line'      => __LINE__,
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
        // Include deleted objects in re-fetch — the update may have soft-deleted the entity.
        try {
            $updatedEntity = $this->findInRegisterSchemaTable(
                identifier: $uuid,
                register: $register,
                schema: $schema,
                includeDeleted: true
            );
        } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
            // Fallback: return input entity if re-fetch fails.
            $this->logger->warning(
                message: '[MagicMapper] Failed to re-fetch updated entity, returning input entity',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            $updatedEntity = $entity;
        }

        // NOTE: Event dispatching is handled by the public insert/update/delete methods to avoid duplicate events.
        // Do NOT dispatch ObjectUpdatedEvent here.
        return $updatedEntity;
    }//end updateObjectEntity()

    /**
     * Delete ObjectEntity from register+schema table.
     *
     * Supports both soft delete (sets _deleted field) and hard delete (removes from table).
     *
     * @param ObjectEntity $entity         The object entity to delete.
     * @param Register     $register       The register context.
     * @param Schema       $schema         The schema context.
     * @param bool         $hardDelete     Whether to perform hard delete (default: false for soft delete).
     * @param bool         $dispatchEvents Whether to dispatch events.
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
        bool $hardDelete=false,
        bool $dispatchEvents=true
    ): ObjectEntity {
        // Dispatch deleting event (pre-save hook).
        if ($dispatchEvents === true) {
            $deletingEvent = new ObjectDeletingEvent(object: $entity);
            $this->eventDispatcher->dispatchTyped($deletingEvent);

            // Check if a hook stopped propagation (reject mode).
            if ($deletingEvent->isPropagationStopped() === true) {
                throw new HookStoppedException(
                    message: (string) ($deletingEvent->getErrors()['message'] ?? 'Object deletion rejected by hook'),
                    errors: $deletingEvent->getErrors()
                );
            }
        }

        $tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
        $uuid      = $entity->getUuid();

        if ($uuid === null) {
            throw new Exception('Cannot delete object entity without UUID');
        }

        $this->logger->debug(
            message: '[MagicMapper] Deleting object entity from register+schema table',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
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
                message: '[MagicMapper] Hard deleted object from register+schema table',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
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
                message: '[MagicMapper] Soft deleted object in register+schema table',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'uuid'      => $uuid,
                    'tableName' => $tableName,
                ]
            );
        }

        // NOTE: Event dispatching is handled by the public insert/update/delete methods to avoid duplicate events.
        // Do NOT dispatch ObjectDeletedEvent here.
        return $entity;
    }//end deleteObjectEntity()

    /**
     * Delete all objects belonging to a specific schema from the magic table.
     *
     * This method performs an optimized bulk deletion from the magic table
     * for all objects belonging to a given register/schema combination.
     *
     * @param Register $register   The register context.
     * @param Schema   $schema     The schema context.
     * @param bool     $hardDelete Whether to perform a hard delete (default: false for soft delete).
     *
     * @throws Exception If deletion fails.
     *
     * @return int The number of objects deleted.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Hard delete toggle controls permanent vs soft delete
     */
    public function deleteObjectsBySchema(
        Register $register,
        Schema $schema,
        bool $hardDelete=false
    ): int {
        $tableName  = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
        $registerId = $register->getId();
        $schemaId   = $schema->getId();

        $this->logger->info(
            message: '[MagicMapper] Deleting all objects from magic table for schema',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'registerId' => $registerId,
                'schemaId'   => $schemaId,
                'tableName'  => $tableName,
                'hardDelete' => $hardDelete,
            ]
        );

        // Check if table exists before attempting deletion.
        if ($this->tableExistsForRegisterSchema(register: $register, schema: $schema) === false) {
            $this->logger->warning(
                message: '[MagicMapper] Cannot delete from magic table - table does not exist',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'registerId' => $registerId,
                    'schemaId'   => $schemaId,
                    'tableName'  => $tableName,
                ]
            );
            return 0;
        }

        $qb = $this->db->getQueryBuilder();

        if ($hardDelete === true) {
            // Hard delete - remove all rows for this register+schema combination.
            $regCol = self::METADATA_PREFIX.'register';
            $schCol = self::METADATA_PREFIX.'schema';
            $qb->delete($tableName)
                ->where(
                        $qb->expr()->eq(
                    $regCol,
                    $qb->createNamedParameter($registerId, \PDO::PARAM_INT)
                )
                        )
                ->andWhere(
                        $qb->expr()->eq(
                    $schCol,
                    $qb->createNamedParameter($schemaId, \PDO::PARAM_INT)
                )
                        );

            $deletedCount = $qb->executeStatement();

            $this->logger->info(
                message: '[MagicMapper] Hard deleted objects from magic table',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'deletedCount' => $deletedCount,
                    'registerId'   => $registerId,
                    'schemaId'     => $schemaId,
                    'tableName'    => $tableName,
                ]
            );
            return $deletedCount;
        }//end if

        // Soft delete - set _deleted field for all rows.
        // Prepare the deletion metadata as JSONB.
        $deletedMetadata = json_encode(
                    [
                        'time'      => (new DateTime())->format('Y-m-d H:i:s'),
                        'user'      => $this->userSession->getUser()?->getUID() ?? 'system',
                        'reason'    => 'Bulk soft delete via deleteObjectsBySchema',
                        'retention' => 30,
                    ]
                    );

            $regCol = self::METADATA_PREFIX.'register';
            $schCol = self::METADATA_PREFIX.'schema';
            $delCol = self::METADATA_PREFIX.'deleted';
            $qb->update($tableName)
                ->set(
                    $delCol,
                    $qb->createNamedParameter($deletedMetadata, \PDO::PARAM_STR)
                )
                ->where(
                        $qb->expr()->eq(
                    $regCol,
                    $qb->createNamedParameter($registerId, \PDO::PARAM_INT)
                )
                        )
                ->andWhere(
                        $qb->expr()->eq(
                    $schCol,
                    $qb->createNamedParameter($schemaId, \PDO::PARAM_INT)
                )
                        )
                // Only soft-delete objects that aren't already soft-deleted.
                ->andWhere($qb->expr()->isNull(self::METADATA_PREFIX.'deleted'));

            $deletedCount = $qb->executeStatement();

            $this->logger->info(
                message: '[MagicMapper] Soft deleted objects in magic table',
                context: [
                    'file'         => __FILE__,
                    'line'         => __LINE__,
                    'deletedCount' => $deletedCount,
                    'registerId'   => $registerId,
                    'schemaId'     => $schemaId,
                    'tableName'    => $tableName,
                ]
            );

        return $deletedCount;
    }//end deleteObjectsBySchema()

    /**
     * Batch delete objects by UUID list from a register+schema magic table.
     *
     * Performs a single SQL statement for all UUIDs instead of one-by-one.
     * Supports both soft delete (sets _deleted metadata) and hard delete.
     *
     * @param Register $register   The register context.
     * @param Schema   $schema     The schema context.
     * @param array    $uuids      Array of object UUIDs to delete.
     * @param bool     $hardDelete Whether to hard delete (default: soft delete).
     *
     * @return int Number of objects deleted.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    public function deleteObjectsByUuids(
        Register $register,
        Schema $schema,
        array $uuids,
        bool $hardDelete=false
    ): int {
        if (empty($uuids) === true) {
            return 0;
        }

        $tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);

        if ($this->tableExistsForRegisterSchema(register: $register, schema: $schema) === false) {
            return 0;
        }

        $uuidCol = self::METADATA_PREFIX.'uuid';
        $qb      = $this->db->getQueryBuilder();

        if ($hardDelete === true) {
            $qb->delete($tableName)
                ->where($qb->expr()->in($uuidCol, $qb->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY)));

            return $qb->executeStatement();
        }

        // Soft delete — set _deleted metadata in a single UPDATE.
        $deletedMetadata = json_encode(
            [
                'time'      => (new DateTime())->format('Y-m-d H:i:s'),
                'user'      => $this->userSession->getUser()?->getUID() ?? 'system',
                'reason'    => 'Bulk soft delete via deleteObjectsByUuids',
                'retention' => 30,
            ]
        );

        $qb->update($tableName)
            ->set(self::METADATA_PREFIX.'deleted', $qb->createNamedParameter($deletedMetadata, \PDO::PARAM_STR))
            ->where($qb->expr()->in($uuidCol, $qb->createNamedParameter($uuids, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->isNull(self::METADATA_PREFIX.'deleted'));

        return $qb->executeStatement();
    }//end deleteObjectsByUuids()

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
            message: '[MagicMapper] Locked object in register+schema table',
            context: [
                'file'     => __FILE__,
                'line'     => __LINE__,
                'uuid'     => $entity->getUuid(),
                'duration' => $lockDuration,
            ]
        );

        // Dispatch locked event for audit trails.
        $this->eventDispatcher->dispatchTyped(new ObjectLockedEvent(object: $entity));

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
            message: '[MagicMapper] Unlocked object in register+schema table',
            context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $entity->getUuid()]
        );

        // Dispatch unlocked event for audit trails.
        $this->eventDispatcher->dispatchTyped(new ObjectUnlockedEvent(object: $entity));

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
            message: '[MagicMapper] Delegating bulk upsert to MagicBulkHandler',
            context: [
                'file'         => __FILE__,
                'line'         => __LINE__,
                'register'     => $register->getId(),
                'schema'       => $schema->getId(),
                'table'        => $tableName,
                'object_count' => count($objects),
            ]
        );

        try {
            return $this->bulkHandler->bulkUpsert(
                objects: $objects,
                register: $register,
                schema: $schema,
                tableName: $tableName
            );
        } catch (\Exception $e) {
            // Check if this is a "table does not exist" error (PostgreSQL: 42P01, MySQL: 1146).
            $message = $e->getMessage();
            if (str_contains($message, '42P01') === true
                || str_contains($message, 'does not exist') === true
                || str_contains($message, "doesn't exist") === true
                || str_contains($message, '1146') === true
            ) {
                $this->logger->warning(
                    message: '[MagicMapper] Table does not exist, creating and retrying bulkUpsert',
                    context: [
                        'file'     => __FILE__,
                        'line'     => __LINE__,
                        'register' => $register->getId(),
                        'schema'   => $schema->getId(),
                        'table'    => $tableName,
                        'error'    => $message,
                    ]
                );

                // Create the table.
                $this->ensureTableForRegisterSchema(register: $register, schema: $schema, force: true);

                // Retry the bulk upsert.
                return $this->bulkHandler->bulkUpsert(
                    objects: $objects,
                    register: $register,
                    schema: $schema,
                    tableName: $tableName
                );
            }//end if

            // Re-throw if it's not a table-not-found error.
            throw $e;
        }//end try
    }//end bulkUpsert()

    /**
     * Find objects that reference a specific UUID in any of their columns.
     *
     * This method searches across ALL magic mapper tables for objects that
     * contain the specified UUID in any column. This is used for inverse
     * relationship resolution.
     *
     * For PostgreSQL, it uses casting to text and LIKE for JSON columns.
     * For other databases, it uses LIKE on all columns.
     *
     * @param string      $uuid          The UUID to search for
     * @param string|null $_search       Optional search filter
     * @param bool        $_partialMatch Whether to use partial matching
     *
     * @return ObjectEntity[] Array of objects that contain the UUID
     *
     * @psalm-return list<ObjectEntity>
     */
    public function findByRelation(string $uuid, ?string $_search=null, bool $_partialMatch=false): array
    {
        if (empty($uuid) === true) {
            return [];
        }

        $results = [];

        // Get all existing magic mapper tables.
        $tables = $this->getAllMagicMapperTables();

        $this->logger->debug(
            message: '[MagicMapper] findByRelation searching across tables',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'uuid'       => $uuid,
                'tableCount' => count($tables),
            ]
        );

        foreach ($tables as $tableName) {
            try {
                $tableResults = $this->findByRelationInTable(uuid: $uuid, tableName: $tableName);
                $results      = array_merge($results, $tableResults);
            } catch (Exception $e) {
                $this->logger->debug(
                    message: '[MagicMapper] Failed to search table for relation',
                    context: [
                        'file'      => __FILE__,
                        'line'      => __LINE__,
                        'tableName' => $tableName,
                        'uuid'      => $uuid,
                        'error'     => $e->getMessage(),
                    ]
                );
                // Continue with other tables even if one fails.
            }
        }

        $this->logger->debug(
            message: '[MagicMapper] findByRelation completed',
            context: [
                'file'        => __FILE__,
                'line'        => __LINE__,
                'uuid'        => $uuid,
                'resultCount' => count($results),
            ]
        );

        return $results;
    }//end findByRelation()

    /**
     * Find objects that reference a UUID using the _relations column.
     *
     * This is a PERFORMANT alternative to findByRelation() that uses
     * PostgreSQL's JSONB @> operator on the indexed _relations column
     * instead of slow full-text LIKE searches.
     *
     * The _relations column stores an array of UUIDs that the object references,
     * making containment queries very efficient (O(log n) with GIN index).
     *
     * @param string $uuid The UUID to search for in _relations
     *
     * @return ObjectEntity[] Array of objects that have this UUID in their _relations
     *
     * @psalm-return list<ObjectEntity>
     */
    public function findByRelationUsingRelationsColumn(string $uuid): array
    {
        if (empty($uuid) === true) {
            return [];
        }

        $results = [];
        $tables  = $this->getAllMagicMapperTables();

        $platform   = $this->db->getDatabasePlatform();
        $isPostgres = stripos($platform::class, 'PostgreSQL') !== false;

        $startTime = microtime(true);

        // Apply multi-tenancy filtering to inverse relationship lookups.
        [$orgFilter, $orgParams] = $this->buildOrganisationFilterForRelation();

        foreach ($tables as $tableName) {
            try {
                $fullTableName = 'oc_'.$tableName;

                // Search for the UUID as a VALUE within the _relations JSONB.
                // The _relations column can be either:.
                // - An object: {"propertyName": "uuid", ...} (new format).
                // - An array: ["uuid1", "uuid2", ...] (legacy format).
                // We need to find rows where the UUID appears in either format.
                // MySQL: Use JSON_SEARCH to find the UUID as a value anywhere.
                // This works for both arrays and objects.
                $sql       = "SELECT * FROM {$fullTableName}
                        WHERE _deleted IS NULL
                        AND JSON_SEARCH(_relations, 'one', ?) IS NOT NULL
                        {$orgFilter}
                        LIMIT 100";
                $sqlParams = array_merge([$uuid], $orgParams);

                if ($isPostgres === true) {
                    // PostgreSQL: Handle both object and array formats.
                    // - For objects: use jsonb_each_text to search values.
                    // - For arrays: use @> containment operator (can't use ? as it conflicts with PDO placeholders).
                    // Note: We use @> with a JSON array literal instead of ? operator.
                    $sql = "SELECT * FROM {$fullTableName}
                            WHERE (_deleted IS NULL OR _deleted = 'null'::jsonb)
                            AND (
                                -- Array format: check if UUID is in the array using @> containment
                                (jsonb_typeof(_relations) = 'array' AND _relations @> to_jsonb(?::text))
                                OR
                                -- Object format: check if UUID is a value in the object
                                (jsonb_typeof(_relations) = 'object' AND EXISTS (
                                    SELECT 1 FROM jsonb_each_text(_relations) AS kv
                                    WHERE kv.value = ?
                                ))
                            )
                            {$orgFilter}
                            LIMIT 100";
                    // Need to pass UUID twice for both checks.
                    $sqlParams = array_merge([$uuid, $uuid], $orgParams);
                }//end if

                $stmt = $this->db->prepare($sql);
                $stmt->execute($sqlParams);
                $rows = $stmt->fetchAll();

                foreach ($rows as $row) {
                    try {
                        $entity = $this->rowToObjectEntity(row: $row);
                        if ($entity !== null) {
                            $results[] = $entity;
                        }
                    } catch (Exception $e) {
                        // Skip rows that can't be converted.
                        continue;
                    }
                }
            } catch (Exception $e) {
                $this->logger->debug(
                    message: '[MagicMapper] findByRelationUsingRelationsColumn table query failed',
                    context: [
                        'file'      => __FILE__,
                        'line'      => __LINE__,
                        'tableName' => $tableName,
                        'error'     => $e->getMessage(),
                    ]
                );
                continue;
            }//end try
        }//end foreach

        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        $this->logger->debug(
            message: '[MagicMapper] findByRelationUsingRelationsColumn completed',
            context: [
                'file'          => __FILE__,
                'line'          => __LINE__,
                'uuid'          => $uuid,
                'tableCount'    => count($tables),
                'resultCount'   => count($results),
                'executionTime' => $executionTime.'ms',
            ]
        );

        return $results;
    }//end findByRelationUsingRelationsColumn()

    /**
     * Batch find objects in a specific schema that reference ANY of the given UUIDs.
     *
     * This is a CRITICAL performance optimization for inverse relationship preloading.
     * Instead of N queries (one per entity), we do ONE query per target schema to find
     * ALL objects that reference ANY of our entities.
     *
     * Uses the _relations GIN index for efficient containment queries.
     *
     * @param array  $uuids                Array of UUIDs to search for in _relations.
     * @param int    $schemaId             The target schema ID to search in.
     * @param int    $registerId           The register ID for the magic table.
     * @param string $fieldName            The field name to check (for logging/debugging).
     * @param array  $additionalFieldNames Additional field names to check.
     *
     * @return ObjectEntity[] Array of objects that have ANY of the UUIDs in _relations
     *
     * @psalm-return list<ObjectEntity>
     */
    public function findByRelationBatchInSchema(
        array $uuids,
        int $schemaId,
        int $registerId,
        string $fieldName,
        array $additionalFieldNames=[]
    ): array {
        if (empty($uuids) === true) {
            return [];
        }

        // Construct the magic table name directly: openregister_table_{registerId}_{schemaId}.
        $tableName     = self::TABLE_PREFIX.$registerId.'_'.$schemaId;
        $fullTableName = 'oc_'.$tableName;

        // Check if the table exists.
        if ($this->checkTableExistsInDatabase(tableName: $tableName) === false) {
            $this->logger->debug(
                message: '[MagicMapper] findByRelationBatchInSchema: table does not exist',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'tableName'  => $fullTableName,
                    'schemaId'   => $schemaId,
                    'registerId' => $registerId,
                ]
            );
            return [];
        }

        $platform   = $this->db->getDatabasePlatform();
        $isPostgres = stripos($platform::class, 'PostgreSQL') !== false;

        $startTime = microtime(true);
        $results   = [];

        try {
            // Build a query that finds objects whose _relations contains ANY of the given UUIDs.
            // The _relations column can be either:.
            // - An object: {"propertyName": "uuid", ...} (new format).
            // - An array: ["uuid1", "uuid2", ...] (legacy format).
            // We need to find rows where ANY UUID appears in either format.
            $conditions = [];
            $params     = [];

            foreach ($uuids as $uuid) {
                if ($isPostgres === true) {
                    // PostgreSQL: Handle both array and object formats.
                    // - For arrays: use @> containment operator (can't use ? as it conflicts with PDO placeholders).
                    // - For objects: use jsonb_each_text to search values.
                    $arrSql       = "(jsonb_typeof(_relations)='array' AND _relations @> to_jsonb(?::text))";
                    $objSql       = "(jsonb_typeof(_relations)='object'";
                    $objSql      .= " AND EXISTS(SELECT 1 FROM jsonb_each_text(_relations) kv";
                    $objSql      .= " WHERE kv.value=?))";
                    $conditions[] = "({$arrSql} OR {$objSql})";
                    $params[]     = $uuid;
                    $params[]     = $uuid;
                }

                if ($isPostgres !== true) {
                    // MySQL: Use JSON_SEARCH to find the UUID as a value anywhere.
                    // This works for both arrays and objects.
                    $conditions[] = 'JSON_SEARCH(_relations, \'one\', ?) IS NOT NULL';
                    $params[]     = $uuid;
                }
            }//end foreach

            // Also search additional column names directly for object-format references.
            // Some fields store references as {"value": "uuid"} which may not be in _relations.
            foreach ($additionalFieldNames as $extraField) {
                $columnName = strtolower(preg_replace('/[A-Z]/', '_$0', $extraField));
                $quotedCol  = $this->quoteIdentifier(name: $columnName, isPostgres: $isPostgres);
                foreach ($uuids as $uuid) {
                    // Match both plain UUID strings and {"value": "uuid"} JSON objects.
                    $conditions[] = "({$quotedCol} = ? OR {$quotedCol}::text LIKE ?)";
                    $params[]     = $uuid;
                    $params[]     = '%'.$uuid.'%';
                }
            }

            $conditionSql = implode(' OR ', $conditions);

            // Build the WHERE clause for _deleted check (different syntax for PostgreSQL vs MySQL).
            $deletedCheck = '_deleted IS NULL';
            if ($isPostgres === true) {
                $deletedCheck = "(_deleted IS NULL OR _deleted = 'null'::jsonb)";
            }

            // Apply multi-tenancy filtering to inverse relationship lookups.
            // This ensures that _extend does not bypass RBAC — preventing PII exposure.
            // (e.g., contactpersonen of gemeente organisations leaking to unauthenticated users).
            [$orgFilter, $orgParams] = $this->buildOrganisationFilterForRelation();
            $params = array_merge($params, $orgParams);

            $sql = "SELECT * FROM {$fullTableName}
                    WHERE {$deletedCheck}
                    AND ({$conditionSql})
                    {$orgFilter}
                    LIMIT 1000";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            foreach ($rows as $row) {
                try {
                    $entity = $this->rowToObjectEntity(row: $row);
                    if ($entity !== null) {
                        $results[] = $entity;
                    }
                } catch (Exception $e) {
                    // Skip rows that can't be converted.
                    continue;
                }
            }

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->logger->info(
                message: '[MagicMapper] findByRelationBatchInSchema completed',
                context: [
                    'file'          => __FILE__,
                    'line'          => __LINE__,
                    'tableName'     => $fullTableName,
                    'schemaId'      => $schemaId,
                    'registerId'    => $registerId,
                    'fieldName'     => $fieldName,
                    'uuidCount'     => count($uuids),
                    'resultCount'   => count($results),
                    'executionTime' => $executionTime.'ms',
                ]
            );
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[MagicMapper] findByRelationBatchInSchema failed',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'tableName'  => $fullTableName,
                    'schemaId'   => $schemaId,
                    'registerId' => $registerId,
                    'fieldName'  => $fieldName,
                    'uuidCount'  => count($uuids),
                    'error'      => $e->getMessage(),
                ]
            );
        }//end try

        return $results;
    }//end findByRelationBatchInSchema()

    /**
     * Build a multi-tenancy SQL fragment and params for _organisation filtering.
     *
     * Returns the SQL condition string and any additional params to bind.
     * Ensures that inverse relationship lookups (via _extend) respect RBAC:
     * - Unauthenticated users: only see objects with no organisation (prevents PII exposure)
     * - Authenticated users: can see all related objects (cross-org access via publications)
     *
     * This addresses the GDPR/AVG issue where contactpersonen of gemeente organisations
     * were publicly accessible via the _extend=contactpersonen mechanism.
     *
     * @return array{string, array} Tuple of [sqlFragment, params]
     */
    private function buildOrganisationFilterForRelation(): array
    {
        $user = $this->userSession->getUser();

        if ($user !== null) {
            // Authenticated user: allow cross-org access for inverse relations.
            // The publications endpoint is designed to show data from all orgs,.
            // and authenticated users should see contact information.
            return ['', []];
        }

        // Unauthenticated: only allow objects with no organisation set.
        // This prevents PII exposure (names, emails, phone numbers) of.
        // gemeente/samenwerking contact persons to the public internet.
        return [
            " AND _organisation IS NULL",
            [],
        ];
    }//end buildOrganisationFilterForRelation()

    /**
     * Search for objects containing a UUID in a specific magic mapper table.
     *
     * @param string $uuid      The UUID to search for
     * @param string $tableName The table name to search in
     *
     * @return ObjectEntity[] Array of matching objects
     */
    private function findByRelationInTable(string $uuid, string $tableName): array
    {
        // Get database platform to determine proper search approach.
        $platform   = $this->db->getDatabasePlatform();
        $isPostgres = stripos($platform::class, 'PostgreSQL') !== false;

        // Construct the full table name with prefix for use in SQL functions.
        $fullTableName = 'oc_'.$tableName;
        $searchPattern = '%'.$uuid.'%';

        try {
            // Apply multi-tenancy filtering to inverse relationship lookups.
            [$orgFilter, $orgParams] = $this->buildOrganisationFilterForRelation();

            // For PostgreSQL, use row_to_json to convert entire row to searchable text.
            // This approach works reliably for finding UUIDs in any column.
            // MySQL/MariaDB: Use JSON_UNQUOTE and CONCAT to search all columns.
            // This is a fallback approach - may need adjustment for MySQL.
            // MySQL/MariaDB fallback.
            $sql = "SELECT * FROM {$fullTableName} WHERE _deleted IS NULL
                    AND CAST({$fullTableName} AS CHAR) LIKE ?
                    {$orgFilter}
                    LIMIT 100";
            if ($isPostgres === true) {
                $sql = "SELECT * FROM {$fullTableName} WHERE _deleted IS NULL
                        AND row_to_json({$fullTableName}.*)::text LIKE ?
                        {$orgFilter}
                        LIMIT 100";
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute(array_merge([$searchPattern], $orgParams));
            $rows = $stmt->fetchAll();

            $this->logger->debug(
                message: '[MagicMapper] findByRelationInTable query executed',
                context: [
                    'file'        => __FILE__,
                    'line'        => __LINE__,
                    'tableName'   => $fullTableName,
                    'uuid'        => $uuid,
                    'resultCount' => count($rows),
                ]
            );
        } catch (Exception $e) {
            $this->logger->debug(
                message: '[MagicMapper] findByRelationInTable query failed',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'tableName' => $fullTableName,
                    'uuid'      => $uuid,
                    'error'     => $e->getMessage(),
                ]
            );
            return [];
        }//end try

        // Convert rows to ObjectEntity instances.
        $entities = [];
        foreach ($rows as $row) {
            try {
                $entity = $this->rowToObjectEntity(row: $row);
                if ($entity !== null) {
                    $entities[] = $entity;
                }
            } catch (Exception $e) {
                $this->logger->debug(
                    message: '[MagicMapper] Failed to convert row to ObjectEntity',
                    context: [
                        'file'      => __FILE__,
                        'line'      => __LINE__,
                        'tableName' => $tableName,
                        'error'     => $e->getMessage(),
                    ]
                );
            }
        }

        return $entities;
    }//end findByRelationInTable()

    /**
     * Get all magic mapper table names from the database.
     *
     * Magic mapper tables follow the naming convention: openregister_table_{registerId}_{schemaId}
     *
     * @return string[] Array of table names (without prefix)
     */
    private function getAllMagicMapperTables(): array
    {
        try {
            $platform   = $this->db->getDatabasePlatform();
            $isPostgres = stripos($platform::class, 'PostgreSQL') !== false;

            $sql = "SELECT table_name FROM information_schema.tables
                    WHERE table_schema = DATABASE()
                    AND table_name LIKE 'oc_openregister_table_%'";
            if ($isPostgres === true) {
                $sql = "SELECT table_name FROM information_schema.tables
                        WHERE table_schema = current_schema()
                        AND table_name LIKE 'oc_openregister_table_%'";
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            $tables = [];

            while (($row = $stmt->fetch()) !== false) {
                // Remove the 'oc_' prefix to get the table name for query builder.
                $tableName = $row['table_name'];
                if (str_starts_with($tableName, 'oc_') === true) {
                    $tableName = substr($tableName, 3);
                }

                $tables[] = $tableName;
            }

            return $tables;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[MagicMapper] Failed to get magic mapper tables',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            return [];
        }//end try
    }//end getAllMagicMapperTables()

    /**
     * Get all register/schema pairs that have magic tables in the database.
     *
     * Discovers magic tables from information_schema and extracts register/schema IDs
     * from table names (oc_openregister_table_{registerId}_{schemaId}).
     *
     * @return array<array{registerId: int, schemaId: int}> Array of register/schema ID pairs
     */
    public function getAllRegisterSchemaPairs(): array
    {
        return $this->statisticsHandler->getAllRegisterSchemaPairs();
    }//end getAllRegisterSchemaPairs()

    /**
     * Convert a database row from a magic mapper table to an ObjectEntity.
     *
     * @param array $row The database row
     *
     * @return ObjectEntity|null The ObjectEntity or null if conversion fails
     */
    private function rowToObjectEntity(array $row): ?ObjectEntity
    {
        // Check if we have the minimum required fields.
        if (isset($row['_uuid']) === false) {
            return null;
        }

        $entity = new ObjectEntity();
        $entity->setUuid($row['_uuid']);

        // Set metadata fields (register and schema are stored as strings).
        if (isset($row['_register']) === true) {
            $entity->setRegister((string) $row['_register']);
        }

        if (isset($row['_schema']) === true) {
            $entity->setSchema((string) $row['_schema']);
        }

        if (isset($row['_name']) === true) {
            $entity->setName($row['_name']);
        }

        // Build column-to-property mapping from schema if available.
        // This allows us to restore original property names (e.g., 'e-mailadres').
        // from their sanitized column names (e.g., 'e_mailadres').
        $columnToPropertyMap = [];
        if (isset($row['_schema']) === true) {
            try {
                $schema = $this->schemaMapper->find((int) $row['_schema']);
                if ($schema !== null) {
                    $properties = $schema->getProperties() ?? [];
                    foreach (array_keys($properties) as $propertyName) {
                        $columnName = $this->sanitizeColumnName(name: $propertyName);
                        $columnToPropertyMap[$columnName] = $propertyName;
                    }
                }
            } catch (\Exception $e) {
                // Schema not found - will fall back to column names as-is.
                $this->logger->debug(
                    message: '[MagicMapper] Could not load schema for property mapping',
                    context: [
                        'file'     => __FILE__,
                        'line'     => __LINE__,
                        'schemaId' => $row['_schema'],
                        'error'    => $e->getMessage(),
                    ]
                        );
            }//end try
        }//end if

        // Build the object data from non-metadata columns.
        $objectData = [];
        foreach ($row as $column => $value) {
            // Skip metadata columns (those starting with _).
            if (str_starts_with($column, '_') === true) {
                continue;
            }

            // Map column name back to original property name if we have a mapping.
            $propertyName = $columnToPropertyMap[$column] ?? $column;

            // Decode JSON values.
            if (is_string($value) === true) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $objectData[$propertyName] = $decoded;
                    continue;
                }
            }

            $objectData[$propertyName] = $value;
        }

        $entity->setObject($objectData);

        return $entity;
    }//end rowToObjectEntity()

    /**
     * Check if a column exists in a database table.
     *
     * Queries information_schema to verify column existence. Used to prevent
     * SQL errors when filtering on columns that don't exist in the table.
     *
     * @param string $tableName  The table name (without oc_ prefix).
     * @param string $columnName The column name to check.
     *
     * @return bool True if the column exists, false otherwise.
     */
    private function columnExistsInTable(string $tableName, string $columnName): bool
    {
        try {
            // Ensure table name has prefix for information_schema lookup.
            $prefix        = 'oc_';
            $fullTableName = $tableName;
            if (str_starts_with($tableName, $prefix) === false) {
                $fullTableName = $prefix.$tableName;
            }

            // PostgreSQL stores unquoted identifiers in lowercase.
            $fullTableNameLower = strtolower($fullTableName);
            $columnNameLower    = strtolower($columnName);

            // OPTIMIZATION: Check in-memory cache first.
            if (isset(self::$columnExistsCache[$fullTableNameLower]) === true) {
                return isset(self::$columnExistsCache[$fullTableNameLower][$columnNameLower]);
            }

            // Load ALL columns for this table in one query (instead of one query per column).
            $sql = "SELECT LOWER(column_name) as col FROM information_schema.columns
                    WHERE LOWER(table_name) = ?";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$fullTableNameLower]);

            // Cache all columns for this table.
            self::$columnExistsCache[$fullTableNameLower] = [];
            while (($row = $stmt->fetch()) !== false) {
                self::$columnExistsCache[$fullTableNameLower][$row['col']] = true;
            }

            return isset(self::$columnExistsCache[$fullTableNameLower][$columnNameLower]);
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[MagicMapper] Failed to check column existence',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'tableName' => $tableName,
                    'column'    => $columnName,
                    'error'     => $e->getMessage(),
                ]
            );
            // Return false on error to prevent invalid queries.
            return false;
        }//end try
    }//end columnExistsInTable()

    // ==================================================================================
    // ABSTRACT OBJECT MAPPER IMPLEMENTATION (public API facade methods)
    // ==================================================================================

    /**
     * Extract register and schema from ObjectEntity if not explicitly provided.
     *
     * @param ObjectEntity  $entity   The object entity.
     * @param Register|null $register Optional register (will be fetched if null).
     * @param Schema|null   $schema   Optional schema (will be fetched if null).
     *
     * @return array{Register|null, Schema|null} Array with [register, schema].
     */
    private function getResolvedRegisterAndSchema(
        ObjectEntity $entity,
        ?Register $register=null,
        ?Schema $schema=null
    ): array {
        if ($register === null && $entity->getRegister() !== null) {
            try {
                $register = $this->registerMapper->find((int) $entity->getRegister(), [], null, true, false);
            } catch (Exception $e) {
                $this->logger->warning(
                    message: '[MagicMapper] Failed to resolve register from entity',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'registerId' => $entity->getRegister(),
                        'error'      => $e->getMessage(),
                    ]
                );
            }
        }

        if ($schema === null && $entity->getSchema() !== null) {
            try {
                $schema = $this->schemaMapper->find((int) $entity->getSchema(), [], null, true, false);
            } catch (Exception $e) {
                $this->logger->warning(
                    message: '[MagicMapper] Failed to resolve schema from entity',
                    context: [
                        'file'     => __FILE__,
                        'line'     => __LINE__,
                        'schemaId' => $entity->getSchema(),
                        'error'    => $e->getMessage(),
                    ]
                );
            }
        }

        return [$register, $schema];
    }//end getResolvedRegisterAndSchema()

    /**
     * Find an object entity by identifier (ID, UUID, slug, or URI).
     *
     * When register+schema context is available, searches the specific magic table.
     * Otherwise searches across all magic tables.
     *
     * @param string|int    $identifier     Object identifier (ID, UUID, slug, or URI).
     * @param Register|null $register       Optional register to filter by.
     * @param Schema|null   $schema         Optional schema to filter by.
     * @param bool          $includeDeleted Whether to include deleted objects.
     * @param bool          $_rbac          Whether to apply RBAC checks (default: true).
     * @param bool          $_multitenancy  Whether to apply multitenancy filtering (default: true).
     *
     * @return ObjectEntity The found object.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple objects found.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
     */
    public function find(
        string|int $identifier,
        ?Register $register=null,
        ?Schema $schema=null,
        bool $includeDeleted=false,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): ObjectEntity {
        if ($register !== null && $schema !== null) {
            $entity = $this->findInRegisterSchemaTable(
                identifier: $identifier,
                register: $register,
                schema: $schema,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy
            );
            $entity->setSource('orm');
            return $entity;
        }

        $result = $this->findAcrossAllMagicTables(
            identifier: $identifier,
            includeDeleted: $includeDeleted,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy
        );
        $result['object']->setSource('orm');
        return $result['object'];
    }//end find()

    /**
     * Find an object across all storage sources (all magic tables).
     *
     * Searches all magic tables to find an object by its identifier without
     * requiring register/schema context.
     *
     * @param string|int $identifier     Object identifier (ID, UUID, slug, or URI).
     * @param bool       $includeDeleted Whether to include deleted objects.
     * @param bool       $_rbac          Whether to apply RBAC checks.
     * @param bool       $_multitenancy  Whether to apply multitenancy filtering.
     *
     * @return array{object: ObjectEntity, register: Register|null, schema: Schema|null}
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found in any source.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
     */
    public function findAcrossAllSources(
        string|int $identifier,
        bool $includeDeleted=false,
        // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- named arg convention.
        bool $_rbac=true,
        // phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- named arg convention.
        bool $_multitenancy=true
    ): array {
        return $this->findAcrossAllMagicTables(
            identifier: $identifier,
            includeDeleted: $includeDeleted,
            _rbac: $_rbac,
            _multitenancy: $_multitenancy
        );
    }//end findAcrossAllSources()

    /**
     * Find all ObjectEntities with filtering, pagination, and search.
     *
     * @param int|null      $limit            The number of objects to return.
     * @param int|null      $offset           The offset of the objects to return.
     * @param array|null    $filters          The filters to apply to the objects.
     * @param array|null    $searchConditions The search conditions to apply to the objects.
     * @param array|null    $searchParams     The search parameters to apply to the objects.
     * @param array         $sort             The sort order to apply.
     * @param string|null   $search           The search string to apply.
     * @param array|null    $ids              Array of IDs or UUIDs to filter by.
     * @param string|null   $uses             Value that must be present in relations.
     * @param bool          $includeDeleted   Whether to include deleted objects.
     * @param Register|null $register         Optional register to filter objects.
     * @param Schema|null   $schema           Optional schema to filter objects.
     *
     * @return ObjectEntity[]
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     *
     * @psalm-return list<ObjectEntity>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Include deleted toggle is intentional
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Required for flexible query interface
     */
    public function findAll(
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=null,
        ?array $searchConditions=null,
        ?array $searchParams=null,
        array $sort=[],
        ?string $search=null,
        ?array $ids=null,
        ?string $uses=null,
        bool $includeDeleted=false,
        ?Register $register=null,
        ?Schema $schema=null
    ): array {
        if ($register === null || $schema === null) {
            $this->logger->warning(
                message: '[MagicMapper] findAll() called without register/schema context',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return [];
        }

        $entities = $this->findAllInRegisterSchemaTable(
            register: $register,
            schema: $schema,
            limit: $limit,
            offset: $offset,
            filters: $filters,
            sort: $sort
        );
        foreach ($entities as $entity) {
            $entity->setSource('orm');
        }

        return $entities;
    }//end findAll()

    /**
     * Find multiple objects by their IDs or UUIDs.
     *
     * @param array $ids Array of IDs or UUIDs.
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<ObjectEntity>
     */
    public function findMultiple(array $ids): array
    {
        return $this->findMultipleAcrossAllMagicTables(uuids: $ids);
    }//end findMultiple()

    /**
     * Find all objects for a given schema.
     *
     * Searches across all magic tables that belong to the given schema.
     *
     * @param int $schemaId Schema ID.
     *
     * @return ObjectEntity[]
     *
     * @psalm-return list<ObjectEntity>
     */
    public function findBySchema(int $schemaId): array
    {
        // Find all register+schema pairs that include this schema.
        $allPairs = $this->getAllRegisterSchemaPairs();
        $results  = [];

        foreach ($allPairs as $pair) {
            if ((int) $pair['schemaId'] !== $schemaId) {
                continue;
            }

            try {
                $register = $this->registerMapper->find($pair['registerId'], _multitenancy: false, _rbac: false);
                $schema   = $this->schemaMapper->find($pair['schemaId'], _multitenancy: false, _rbac: false);

                $entities = $this->findAllInRegisterSchemaTable(
                    register: $register,
                    schema: $schema
                );
                $results  = array_merge($results, $entities);
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[MagicMapper] Failed to search table for findBySchema',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'registerId' => $pair['registerId'],
                        'schemaId'   => $pair['schemaId'],
                        'error'      => $e->getMessage(),
                    ]
                );
            }//end try
        }//end foreach

        return $results;
    }//end findBySchema()

    /**
     * Insert a new object entity with event dispatching.
     *
     * @param Entity        $entity   Entity to insert.
     * @param Register|null $register Optional register for routing.
     * @param Schema|null   $schema   Optional schema for routing.
     *
     * @return ObjectEntity Inserted entity.
     *
     * @throws Exception If insertion fails.
     */
    public function insert(Entity $entity, ?Register $register=null, ?Schema $schema=null): Entity
    {
        if ($entity instanceof ObjectEntity === false) {
            throw new Exception('Entity must be an instance of ObjectEntity');
        }

        if ($register === null || $schema === null) {
            [$register, $schema] = $this->getResolvedRegisterAndSchema(entity: $entity);
        }

        if ($register === null || $schema === null) {
            throw new Exception('Cannot insert object without register and schema context');
        }

        $insertedEntity = $this->insertObjectEntity(entity: $entity, register: $register, schema: $schema);

        $this->logger->debug(
            message: '[MagicMapper] Dispatching ObjectCreatedEvent',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'entityUuid' => $insertedEntity->getUuid(),
            ]
        );
        $this->eventDispatcher->dispatchTyped(new ObjectCreatedEvent(object: $insertedEntity));

        return $insertedEntity;
    }//end insert()

    /**
     * Update an existing object entity with event dispatching.
     *
     * @param Entity            $entity    Entity to update.
     * @param Register|null     $register  Optional register for routing.
     * @param Schema|null       $schema    Optional schema for routing.
     * @param ObjectEntity|null $oldEntity Old entity for comparison.
     *
     * @return ObjectEntity Updated entity.
     *
     * @throws Exception If update fails.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function update(
        Entity $entity,
        ?Register $register=null,
        ?Schema $schema=null,
        ?ObjectEntity $oldEntity=null
    ): Entity {
        if ($entity instanceof ObjectEntity === false) {
            throw new Exception('Entity must be an instance of ObjectEntity');
        }

        if ($register === null || $schema === null) {
            [$register, $schema] = $this->getResolvedRegisterAndSchema(entity: $entity);
        }

        if ($oldEntity === null) {
            try {
                $oldEntity = $this->find(
                    identifier: $entity->getUuid(),
                    register: $register,
                    schema: $schema,
                    includeDeleted: false,
                    _rbac: false,
                    _multitenancy: false
                );
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[MagicMapper] Could not fetch old entity for update event',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'entityId'   => $entity->getId(),
                        'entityUuid' => $entity->getUuid(),
                        'error'      => $e->getMessage(),
                    ]
                );
                $oldEntity = $entity;
            }//end try
        }//end if

        if ($register === null || $schema === null) {
            throw new Exception('Cannot update object without register and schema context');
        }

        $updatedEntity = $this->updateObjectEntity(
            entity: $entity,
            register: $register,
            schema: $schema,
            oldEntity: $oldEntity
        );

        $this->logger->debug(
            message: '[MagicMapper] Dispatching ObjectUpdatedEvent',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'entityUuid' => $updatedEntity->getUuid(),
            ]
        );
        $this->eventDispatcher->dispatchTyped(new ObjectUpdatedEvent(newObject: $updatedEntity, oldObject: $oldEntity));

        return $updatedEntity;
    }//end update()

    /**
     * Delete an object entity with event dispatching.
     *
     * @param Entity $entity Entity to delete.
     *
     * @return ObjectEntity Deleted entity.
     *
     * @throws Exception If deletion fails.
     */
    public function delete(Entity $entity): Entity
    {
        if ($entity instanceof ObjectEntity === false) {
            throw new Exception('Entity must be an instance of ObjectEntity');
        }

        [$register, $schema] = $this->getResolvedRegisterAndSchema(entity: $entity);

        if ($register === null || $schema === null) {
            throw new Exception('Cannot delete object without register and schema context');
        }

        $deletedEntity = $this->deleteObjectEntity(
            entity: $entity,
            register: $register,
            schema: $schema,
            hardDelete: true
        );

        $this->eventDispatcher->dispatchTyped(new ObjectDeletedEvent(object: $deletedEntity));

        return $deletedEntity;
    }//end delete()

    /**
     * Lock an object.
     *
     * @param string   $uuid         The object UUID
     * @param int|null $lockDuration Lock duration in seconds
     *
     * @return array Lock result.
     *
     * @psalm-return array{locked: mixed, uuid: string}
     */
    public function lockObject(string $uuid, ?int $lockDuration=null): array
    {
        $result   = $this->findAcrossAllSources(
            identifier: $uuid,
            _multitenancy: false,
            _rbac: false
        );
        $entity   = $result['object'];
        $register = $result['register'];
        $schema   = $result['schema'];

        $locked = $this->lockObjectEntity(
            entity: $entity,
            register: $register,
            schema: $schema,
            lockDuration: $lockDuration
        );

        return ['locked' => $locked->getLocked(), 'uuid' => $uuid];
    }//end lockObject()

    /**
     * Unlock an object.
     *
     * @param string $uuid The object UUID
     *
     * @return bool True on success
     */
    public function unlockObject(string $uuid): bool
    {
        $result   = $this->findAcrossAllSources(
            identifier: $uuid,
            _multitenancy: false,
            _rbac: false
        );
        $entity   = $result['object'];
        $register = $result['register'];
        $schema   = $result['schema'];

        $this->unlockObjectEntity(entity: $entity, register: $register, schema: $schema);

        return true;
    }//end unlockObject()

    /**
     * Ultra-fast bulk save operation with automatic routing.
     *
     * Returns complete objects with database-computed classification (created/updated/unchanged).
     *
     * @param array         $insertObjects Objects to insert/upsert
     * @param array         $updateObjects Objects to update (legacy parameter)
     * @param Register|null $register      Optional register context
     * @param Schema|null   $schema        Optional schema context
     *
     * @return array Array of complete objects with object_status field
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function ultraFastBulkSave(
        array $insertObjects=[],
        array $updateObjects=[],
        ?Register $register=null,
        ?Schema $schema=null
    ): array {
        $this->logger->info(
            message: '[MagicMapper] ultraFastBulkSave called',
            context: [
                'file'        => __FILE__,
                'line'        => __LINE__,
                'insertCount' => count($insertObjects),
                'updateCount' => count($updateObjects),
                'hasRegister' => $register !== null,
                'hasSchema'   => $schema !== null,
            ]
        );

        // MIXED SCHEMA SUPPORT: If schema is null and we have objects with different schemas,
        // group them by register+schema and process each group separately.
        if ($schema === null && count($insertObjects) > 0) {
            $schemaGroups = [];
            foreach ($insertObjects as $obj) {
                $objSchemaId   = $obj['@self']['schema'] ?? null;
                $objRegisterId = $obj['@self']['register'] ?? ($register?->getId());
                if ($objSchemaId !== null) {
                    $groupKey = "{$objRegisterId}_{$objSchemaId}";
                    $schemaGroups[$groupKey][] = $obj;
                }
            }

            if (count($schemaGroups) > 1) {
                $allResults = [];
                foreach ($schemaGroups as $groupKey => $groupObjects) {
                    [$groupRegisterId, $groupSchemaId] = explode('_', $groupKey);

                    $groupRegister = $register;
                    $groupSchema   = null;

                    if ($groupRegister === null && $groupRegisterId !== null) {
                        try {
                            $groupRegister = $this->registerMapper->find(id: (int) $groupRegisterId, _multitenancy: false);
                        } catch (\Exception $e) {
                            $this->logger->warning(
                                message: '[MagicMapper] Failed to resolve register for group',
                                context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $groupRegisterId]
                            );
                        }
                    }

                    if ($groupSchemaId !== null) {
                        try {
                            $groupSchema = $this->schemaMapper->find(id: (int) $groupSchemaId, _multitenancy: false);
                        } catch (\Exception $e) {
                            $this->logger->warning(
                                message: '[MagicMapper] Failed to resolve schema for group',
                                context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $groupSchemaId]
                            );
                        }
                    }

                    $groupResults = $this->ultraFastBulkSaveSingleSchema(
                        insertObjects: $groupObjects,
                        updateObjects: [],
                        register: $groupRegister,
                        schema: $groupSchema
                    );

                    $allResults = array_merge($allResults, $groupResults);
                }//end foreach

                return $allResults;
            }//end if
        }//end if

        return $this->ultraFastBulkSaveSingleSchema(
            insertObjects: $insertObjects,
            updateObjects: $updateObjects,
            register: $register,
            schema: $schema
        );
    }//end ultraFastBulkSave()

    /**
     * Ultra-fast bulk save for a single schema (internal method).
     *
     * @param array         $insertObjects Objects to insert/upsert
     * @param array         $updateObjects Objects to update
     * @param Register|null $register      Register context
     * @param Schema|null   $schema        Schema context
     *
     * @return array Array of complete objects with object_status field
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function ultraFastBulkSaveSingleSchema(
        array $insertObjects,
        array $updateObjects,
        ?Register $register,
        ?Schema $schema
    ): array {
        if ($register === null || $schema === null) {
            $firstObject = $insertObjects[0] ?? [];
            $registerId  = $firstObject['@self']['register'] ?? null;
            $schemaId    = $firstObject['@self']['schema'] ?? null;

            if ($registerId !== null && $register === null) {
                try {
                    $register = $this->registerMapper->find(id: $registerId, _multitenancy: false);
                } catch (\Exception $e) {
                    $this->logger->warning(
                        message: '[MagicMapper] Failed to resolve register',
                        context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $registerId]
                    );
                }
            }

            if ($schemaId !== null && $schema === null) {
                try {
                    $schema = $this->schemaMapper->find(id: $schemaId, _multitenancy: false);
                } catch (\Exception $e) {
                    $this->logger->warning(
                        message: '[MagicMapper] Failed to resolve schema',
                        context: ['file' => __FILE__, 'line' => __LINE__, 'id' => $schemaId]
                    );
                }
            }
        }//end if

        if ($register === null || $schema === null) {
            throw new Exception('Cannot bulk save without register and schema context');
        }

        $tableName = 'openregister_table_'.$register->getId().'_'.$schema->getId();

        $this->ensureTableForRegisterSchema(register: $register, schema: $schema);

        $result = $this->bulkUpsert(
            objects: $insertObjects,
            register: $register,
            schema: $schema,
            tableName: $tableName
        );

        return $result;
    }//end ultraFastBulkSaveSingleSchema()

    /**
     * Delete multiple objects.
     *
     * @param array $uuids      Object UUIDs to delete
     * @param bool  $hardDelete Whether to hard delete
     *
     * @return array Delete results
     *
     * @psalm-return list<mixed>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Hard delete toggle controls permanent vs soft delete
     */
    public function deleteObjects(array $uuids=[], bool $hardDelete=false): array
    {
        $results = [];
        // Group UUIDs by register+schema for batch deletion.
        $grouped = [];
        foreach ($uuids as $uuid) {
            try {
                $result   = $this->findAcrossAllSources(
                identifier: $uuid,
                _multitenancy: false,
                _rbac: false
                );
                $register = $result['register'];
                $schema   = $result['schema'];
                $key      = $register->getId().'-'.$schema->getId();
                $grouped[$key]          ??= ['register' => $register, 'schema' => $schema, 'uuids' => []];
                $grouped[$key]['uuids'][] = $uuid;
            } catch (\Exception $e) {
                // Skip UUIDs that can't be found.
            }
        }

        foreach ($grouped as $group) {
            $count     = $this->deleteObjectsByUuids(
                register: $group['register'],
                schema: $group['schema'],
                uuids: $group['uuids'],
                hardDelete: $hardDelete
            );
            $results[] = ['count' => $count, 'uuids' => $group['uuids']];
        }

        return $results;
    }//end deleteObjects()

    /**
     * Get statistics for objects across all magic tables.
     *
     * @param int|array|null $registerId Register ID filter
     * @param int|array|null $schemaId   Schema ID filter
     * @param array          $exclude    Exclusions
     *
     * @return int[] Statistics data
     *
     * @psalm-return array{total: int, size: int, invalid: int, deleted: int, locked: int}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function getStatistics(
        int|array|null $registerId=null,
        int|array|null $schemaId=null,
        array $exclude=[]
    ): array {
        return $this->statisticsHandler->getStatistics(
            registerId: $registerId,
            schemaId: $schemaId,
            exclude: $exclude
        );
    }//end getStatistics()

    /**
     * Get object statistics grouped by schema for multiple schemas.
     *
     * Returns per-schema statistics using one count query per register-schema pair,
     * grouped by schema ID. Replaces N individual getStatistics() calls.
     *
     * @param int[] $schemaIds Array of schema IDs to get statistics for.
     *
     * @return array<int, array{total: int, size: int}> Map of schemaId => statistics array.
     */
    public function getStatisticsGroupedBySchema(array $schemaIds): array
    {
        return $this->statisticsHandler->getStatisticsGroupedBySchema(schemaIds: $schemaIds);
    }//end getStatisticsGroupedBySchema()

    /**
     * Get register chart data.
     *
     * @param int|null $registerId Register ID filter
     * @param int|null $schemaId   Schema ID filter
     *
     * @return (int|mixed|string)[][] Chart data
     *
     * @psalm-return array{labels: array<'Unknown'|mixed>, series: array<int>}
     */
    public function getRegisterChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        return $this->statisticsHandler->getRegisterChartData(
            registerId: $registerId,
            schemaId: $schemaId
        );
    }//end getRegisterChartData()

    /**
     * Get schema chart data.
     *
     * @param int|null $registerId Register ID filter
     * @param int|null $schemaId   Schema ID filter
     *
     * @return (int|mixed|string)[][] Chart data
     *
     * @psalm-return array{labels: array<'Unknown'|mixed>, series: array<int>}
     */
    public function getSchemaChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        return $this->statisticsHandler->getSchemaChartData(
            registerId: $registerId,
            schemaId: $schemaId
        );
    }//end getSchemaChartData()

    /**
     * Get simple facets.
     *
     * Routes to the correct faceting method based on query parameters.
     *
     * @param array $query Search query containing register, schema, and _facets configuration.
     *
     * @return array Facets data.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function getSimpleFacets(array $query=[]): array
    {
        $registerId  = $query['@self']['register'] ?? $query['_register'] ?? $query['register'] ?? null;
        $schemaIds   = $query['@self']['schemas'] ?? $query['_schemas'] ?? null;
        $schemaId    = $query['@self']['schema'] ?? $query['_schema'] ?? $query['schema'] ?? null;
        $registerIds = $query['@self']['registers'] ?? $query['_registers'] ?? null;

        // Multi-schema faceting.
        if ($schemaIds !== null && is_array($schemaIds) === true
            && ($registerId !== null
            || ($registerIds !== null
            && is_array($registerIds) === true
            && count($registerIds) > 0))
        ) {
            $allRegisterIds = [(int) $registerId];
            if ($registerIds !== null && is_array($registerIds) === true && count($registerIds) > 0) {
                $allRegisterIds = array_map('intval', $registerIds);
            }

            return $this->getSimpleFacetsMultiSchema(
                query: $query,
                registerIds: $allRegisterIds,
                schemaIds: array_map('intval', $schemaIds)
            );
        }

        // Single schema faceting.
        if ($registerId !== null && $schemaId !== null) {
            try {
                $register = $this->registerMapper->find((int) $registerId, _multitenancy: false, _rbac: false);
                $schema   = $this->schemaMapper->find((int) $schemaId, _multitenancy: false, _rbac: false);

                return $this->getSimpleFacetsFromRegisterSchemaTable(
                    query: $query,
                    register: $register,
                    schema: $schema
                );
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[MagicMapper] Failed to resolve register/schema for facets',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
                );
                return [];
            }
        }

        return [];
    }//end getSimpleFacets()

    /**
     * Get facets aggregated across multiple schemas and registers.
     *
     * @param array $query       The search query.
     * @param array $registerIds Array of register IDs to search.
     * @param array $schemaIds   Array of schema IDs to aggregate.
     *
     * @return array Merged facet results.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function getSimpleFacetsMultiSchema(array $query, array $registerIds, array $schemaIds): array
    {
        $registers = [];
        foreach ($registerIds as $regId) {
            try {
                $register = $this->registerMapper->find($regId, _multitenancy: false, _rbac: false);
                $registers[$register->getId()] = $register;
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[MagicMapper] Failed to find register for multi-schema facets',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'registerId' => $regId, 'error' => $e->getMessage()]
                );
            }
        }

        if (empty($registers) === true) {
            return [];
        }

        $registerSchemaPairs = [];
        foreach ($schemaIds as $sId) {
            try {
                $schema = $this->schemaMapper->find($sId, _multitenancy: false, _rbac: false);

                $matchedRegister = null;
                foreach ($registers as $register) {
                    $registerSchemas = $register->getSchemas() ?? [];
                    if (is_array($registerSchemas) === true) {
                        $schemaIdStr = (string) $sId;
                        $schemaIdInt = (int) $sId;
                        $inValues    = in_array($schemaIdInt, $registerSchemas, false)
                            || in_array($schemaIdStr, $registerSchemas, false);
                        $inKeys      = array_key_exists($schemaIdInt, $registerSchemas)
                            || array_key_exists($schemaIdStr, $registerSchemas);
                        if ($inValues === true || $inKeys === true) {
                            $matchedRegister = $register;
                            break;
                        }
                    }
                }

                if ($matchedRegister === null) {
                    $matchedRegister = reset($registers);
                }

                $registerSchemaPairs[] = ['register' => $matchedRegister, 'schema' => $schema];
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[MagicMapper] Failed to find schema for multi-schema facets',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $sId, 'error' => $e->getMessage()]
                );
            }//end try
        }//end foreach

        if (empty($registerSchemaPairs) === true) {
            return [];
        }

        return $this->getSimpleFacetsUnion(
            query: $query,
            registerSchemaPairs: $registerSchemaPairs
        );
    }//end getSimpleFacetsMultiSchema()

    /**
     * Get facetable fields from schemas.
     *
     * Collects facetable fields from all schemas referenced in the query.
     *
     * @param array $baseQuery Base query
     *
     * @return array[] Facetable fields
     *
     * @psalm-return array<string, array>
     */
    public function getFacetableFieldsFromSchemas(array $baseQuery=[]): array
    {
        $facetableFields = [];
        $schemaIds       = $baseQuery['@self']['schemas'] ?? $baseQuery['_schemas'] ?? null;
        $schemaId        = $baseQuery['@self']['schema'] ?? $baseQuery['_schema'] ?? $baseQuery['schema'] ?? null;

        if ($schemaId !== null && $schemaIds === null) {
            $schemaIds = [$schemaId];
        }

        if ($schemaIds === null || is_array($schemaIds) === false) {
            return $facetableFields;
        }

        foreach ($schemaIds as $sId) {
            try {
                $schema     = $this->schemaMapper->find((int) $sId, _multitenancy: false, _rbac: false);
                $properties = $schema->getProperties();
                if (is_string($properties) === true) {
                    $properties = json_decode($properties, true) ?? [];
                }

                if (is_array($properties) === false) {
                    continue;
                }

                foreach ($properties as $propName => $propConfig) {
                    if (isset($facetableFields[$propName]) === false) {
                        $facetableFields[$propName] = $propConfig;
                    }
                }
            } catch (\Exception $e) {
                // Skip missing schemas.
            }
        }//end foreach

        return $facetableFields;
    }//end getFacetableFieldsFromSchemas()

    /**
     * Search objects.
     *
     * @param array       $query          Search query
     * @param string|null $_activeOrgUuid Organisation UUID
     * @param bool        $_rbac          Apply RBAC
     * @param bool        $_multitenancy  Apply multitenancy
     * @param array|null  $ids            Specific IDs
     * @param string|null $uses           Uses filter
     *
     * @return array<int, ObjectEntity>|int
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
     */
    public function searchObjects(
        array $query=[],
        ?string $_activeOrgUuid=null,
        bool $_rbac=true,
        bool $_multitenancy=true,
        ?array $ids=null,
        ?string $uses=null
    ): array | int {
        $registerId = $query['@self']['register'] ?? $query['_register'] ?? $query['register'] ?? null;
        $schemaId   = $query['@self']['schema'] ?? $query['_schema'] ?? $query['schema'] ?? null;

        if ($registerId !== null && $schemaId !== null) {
            try {
                $register = $this->registerMapper->find((int) $registerId, _multitenancy: false, _rbac: false);
                $schema   = $this->schemaMapper->find((int) $schemaId, _multitenancy: false, _rbac: false);

                $query['_rbac']         = $_rbac;
                $query['_multitenancy'] = $_multitenancy;
                return $this->searchObjectsInRegisterSchemaTable(
                    query: $query,
                    register: $register,
                    schema: $schema
                );
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[MagicMapper] Failed to resolve register/schema for search',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
                );
            }
        }

        $this->logger->warning(
            message: '[MagicMapper] searchObjects() called without register/schema context',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );
        return [];
    }//end searchObjects()

    /**
     * Count search objects.
     *
     * @param array       $query          Search query
     * @param string|null $_activeOrgUuid Organisation UUID
     * @param bool        $_rbac          Apply RBAC
     * @param bool        $_multitenancy  Apply multitenancy
     * @param array|null  $ids            Specific IDs
     * @param string|null $uses           Uses filter
     *
     * @return int Object count
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
     */
    public function countSearchObjects(
        array $query=[],
        ?string $_activeOrgUuid=null,
        bool $_rbac=true,
        bool $_multitenancy=true,
        ?array $ids=null,
        ?string $uses=null
    ): int {
        $registerId = $query['@self']['register'] ?? $query['_register'] ?? $query['register'] ?? null;
        $schemaId   = $query['@self']['schema'] ?? $query['_schema'] ?? $query['schema'] ?? null;

        if ($registerId !== null && $schemaId !== null) {
            try {
                $register = $this->registerMapper->find((int) $registerId, _multitenancy: false, _rbac: false);
                $schema   = $this->schemaMapper->find((int) $schemaId, _multitenancy: false, _rbac: false);

                return $this->countObjectsInRegisterSchemaTable(
                    query: $query,
                    register: $register,
                    schema: $schema
                );
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[MagicMapper] Failed to resolve register/schema for count',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
                );
            }
        }

        return 0;
    }//end countSearchObjects()

    /**
     * Count all objects with optional filtering.
     *
     * @param array|null    $_filters Filters
     * @param Schema|null   $schema   Schema filter
     * @param Register|null $register Register filter
     *
     * @return int Object count
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function countAll(?array $_filters=null, ?Schema $schema=null, ?Register $register=null): int
    {
        // If register+schema context provided, count in the specific table.
        if ($register !== null && $schema !== null) {
            return $this->countObjectsInRegisterSchemaTable(
                query: $_filters ?? [],
                register: $register,
                schema: $schema
            );
        }

        // Count across all magic tables.
        $total    = 0;
        $allPairs = $this->getAllRegisterSchemaPairs();

        foreach ($allPairs as $pair) {
            if ($register !== null && (int) $pair['registerId'] !== $register->getId()) {
                continue;
            }

            if ($schema !== null && (int) $pair['schemaId'] !== $schema->getId()) {
                continue;
            }

            try {
                $pairRegister = $this->registerMapper->find($pair['registerId'], _multitenancy: false, _rbac: false);
                $pairSchema   = $this->schemaMapper->find($pair['schemaId'], _multitenancy: false, _rbac: false);

                $total += $this->countObjectsInRegisterSchemaTable(
                    query: $_filters ?? [],
                    register: $pairRegister,
                    schema: $pairSchema
                );
            } catch (\Exception $e) {
                // Skip.
            }
        }//end foreach

        return $total;
    }//end countAll()

    /**
     * Get query builder instance.
     *
     * @return IQueryBuilder Query builder instance
     */
    public function getQueryBuilder(): IQueryBuilder
    {
        return $this->db->getQueryBuilder();
    }//end getQueryBuilder()

    /**
     * Get max allowed packet size.
     *
     * @return int Max packet size
     */
    public function getMaxAllowedPacketSize(): int
    {
        try {
            $result = $this->db->executeQuery("SHOW VARIABLES LIKE 'max_allowed_packet'");
            $row    = $result->fetch();
            if ($row !== false && isset($row['Value']) === true) {
                return (int) $row['Value'];
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[MagicMapper] Failed to get max_allowed_packet',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
        }

        // Default to 16MB.
        return 16777216;
    }//end getMaxAllowedPacketSize()

    /**
     * Optimized paginated search that loads register/schema once and performs both search and count.
     *
     * @param array       $searchQuery    Query for search (with _limit, _offset).
     * @param array       $countQuery     Query for count (without pagination).
     * @param string|null $_activeOrgUuid Active organization UUID.
     * @param bool        $_rbac          Whether to apply RBAC.
     * @param bool        $_multitenancy  Whether to apply multitenancy.
     * @param array|null  $ids            Optional ID filter.
     * @param string|null $uses           Optional uses filter.
     *
     * @return array<string, mixed>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Flags control security filtering behavior
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function searchObjectsPaginated(
        array $searchQuery=[],
        array $countQuery=[],
        ?string $_activeOrgUuid=null,
        bool $_rbac=true,
        bool $_multitenancy=true,
        ?array $ids=null,
        ?string $uses=null
    ): array {
        $registerId = $searchQuery['@self']['register'] ?? $searchQuery['_register'] ?? $searchQuery['register'] ?? null;
        $schemaId   = $searchQuery['@self']['schema'] ?? $searchQuery['_schema'] ?? $searchQuery['schema'] ?? null;
        $schemaIds  = $searchQuery['@self']['schemas'] ?? $searchQuery['_schemas'] ?? null;

        // Handle case where @self.schema is an array.
        if (is_array($schemaId) === true && count($schemaId) > 0) {
            $schemaIds = $schemaId;
            $schemaId  = null;
        }

        $register       = null;
        $schema         = null;
        $registersCache = [];
        $schemasCache   = [];

        $registerIds = $searchQuery['@self']['registers'] ?? $searchQuery['_registers'] ?? null;

        // Multi-schema search.
        $isMultiSchemaSearch = $schemaId === null
            && $schemaIds !== null
            && is_array($schemaIds) === true
            && count($schemaIds) > 0
            && ($registerId !== null
                || ($registerIds !== null
                    && is_array($registerIds) === true
                    && count($registerIds) > 0));
        if ($isMultiSchemaSearch === true) {
            $allRegisterIds = [(int) $registerId];
            if ($registerIds !== null && is_array($registerIds) === true && count($registerIds) > 0) {
                $allRegisterIds = array_map('intval', $registerIds);
            }

            return $this->searchObjectsPaginatedMultiSchema(
                searchQuery: $searchQuery,
                countQuery: $countQuery,
                registerIds: $allRegisterIds,
                schemaIds: $schemaIds,
                activeOrgUuid: $_activeOrgUuid,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy,
                ids: $ids,
                uses: $uses
            );
        }

        // Single schema search.
        if ($registerId !== null && $schemaId !== null) {
            try {
                $register = $this->registerMapper->find((int) $registerId, _multitenancy: false, _rbac: false);
                $schema   = $this->schemaMapper->find((int) $schemaId, _multitenancy: false, _rbac: false);

                $registersCache[$register->getId()] = $register->jsonSerialize();
                $schemasCache[$schema->getId()]     = $schema->jsonSerialize();
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[MagicMapper] Failed to resolve register/schema',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
                );
            }
        }

        if ($register !== null && $schema !== null) {
            $searchQuery['_rbac']         = $_rbac;
            $searchQuery['_multitenancy'] = $_multitenancy;

            $searchStart = microtime(true);
            $results     = $this->searchObjectsInRegisterSchemaTable(
                query: $searchQuery,
                register: $register,
                schema: $schema
            );
            $searchTime  = round((microtime(true) - $searchStart) * 1000, 2);

            $countQuery['_rbac']         = $_rbac;
            $countQuery['_multitenancy'] = $_multitenancy;

            $countStart = microtime(true);
            $total      = $this->countObjectsInRegisterSchemaTable(
                query: $countQuery,
                register: $register,
                schema: $schema
            );
            $countTime  = round((microtime(true) - $countStart) * 1000, 2);

            $ignoredFilters = $this->getIgnoredFilters();

            return [
                'results'        => $results,
                'total'          => $total,
                'registers'      => $registersCache,
                'schemas'        => $schemasCache,
                'ignoredFilters' => $ignoredFilters,
                'metrics'        => [
                    'search_ms' => $searchTime,
                    'count_ms'  => $countTime,
                ],
            ];
        }//end if

        // ID search across all tables.
        $queryIds   = $searchQuery['_ids'] ?? null;
        $isIdSearch = $queryIds !== null
            && is_array($queryIds) === true
            && count($queryIds) > 0;

        if ($isIdSearch === true) {
            $idResults = $this->findMultipleAcrossAllMagicTables(
                uuids: $queryIds,
                includeDeleted: false
            );

            return $this->getGlobalSearchResult(results: $idResults, searchQuery: $searchQuery, _rbac: $_rbac);
        }

        // Global relations search.
        $relationsContains = $searchQuery['_relations_contains'] ?? null;
        $isGlobalRelSearch = $registerId === null
            && $schemaId === null
            && $relationsContains !== null
            && is_string($relationsContains) === true
            && empty($relationsContains) === false;

        if ($isGlobalRelSearch === true) {
            $relResults = $this->findByRelationAcrossAllMagicTables(
                uuid: $relationsContains,
                includeDeleted: false
            );

            return $this->getGlobalSearchResult(results: $relResults, searchQuery: $searchQuery, _rbac: $_rbac);
        }

        // Global text search.
        $searchTerm         = $searchQuery['_search'] ?? null;
        $isGlobalTextSearch = $registerId === null
            && $schemaId === null
            && $searchTerm !== null
            && is_string($searchTerm) === true
            && trim($searchTerm) !== '';

        if ($isGlobalTextSearch === true) {
            return $this->searchObjectsGloballyBySearch(
                searchQuery: $searchQuery,
                countQuery: $countQuery,
                activeOrgUuid: $_activeOrgUuid,
                _rbac: $_rbac,
                _multitenancy: $_multitenancy
            );
        }

        $this->logger->warning(
            message: '[MagicMapper] searchObjectsPaginated() called without register/schema context',
            context: ['file' => __FILE__, 'line' => __LINE__]
        );

        return [
            'results'   => [],
            'total'     => 0,
            'registers' => $registersCache,
            'schemas'   => $schemasCache,
        ];
    }//end searchObjectsPaginated()

    /**
     * Search objects across multiple schemas using UNION queries.
     *
     * @param array       $searchQuery   Search query parameters.
     * @param array       $countQuery    Count query parameters.
     * @param array       $registerIds   Register IDs to search.
     * @param array       $schemaIds     Array of schema IDs to search.
     * @param string|null $activeOrgUuid Organisation UUID.
     * @param bool        $_rbac         Apply RBAC.
     * @param bool        $_multitenancy Apply multitenancy.
     * @param array|null  $ids           Specific IDs to filter.
     * @param string|null $uses          Uses filter.
     *
     * @return array{results: ObjectEntity[], total: int, registers: array, schemas: array}
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Flags control security filtering behavior
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @psalm-suppress                                UnusedParam
     * Parameters reserved for future per-schema security filtering.
     */
    private function searchObjectsPaginatedMultiSchema(
        array $searchQuery,
        array $countQuery,
        array $registerIds,
        array $schemaIds,
        ?string $activeOrgUuid=null,
        bool $_rbac=true,
        bool $_multitenancy=true,
        ?array $ids=null,
        ?string $uses=null
    ): array {
        $registersCache = [];
        $schemasCache   = [];

        $registers = [];
        foreach ($registerIds as $regId) {
            try {
                $register = $this->registerMapper->find($regId, _multitenancy: false, _rbac: false);
                $registers[$register->getId()]      = $register;
                $registersCache[$register->getId()] = $register->jsonSerialize();
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[MagicMapper] Failed to find register for multi-schema search',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'registerId' => $regId, 'error' => $e->getMessage()]
                );
            }
        }

        if (empty($registers) === true) {
            return [
                'results'   => [],
                'total'     => 0,
                'registers' => [],
                'schemas'   => [],
            ];
        }

        $registerSchemaPairs = [];
        $totalCount          = 0;

        foreach ($schemaIds as $sId) {
            try {
                $schema = $this->schemaMapper->find((int) $sId, _multitenancy: false, _rbac: false);
                $schemasCache[$schema->getId()] = $schema->jsonSerialize();

                $matchedRegister = null;
                foreach ($registers as $register) {
                    $registerSchemas = $register->getSchemas() ?? [];
                    if (is_array($registerSchemas) === true) {
                        $schemaIdStr = (string) $sId;
                        $schemaIdInt = (int) $sId;
                        $inValues    = in_array($schemaIdInt, $registerSchemas, false)
                            || in_array($schemaIdStr, $registerSchemas, false);
                        $inKeys      = array_key_exists($schemaIdInt, $registerSchemas)
                            || array_key_exists($schemaIdStr, $registerSchemas);
                        if ($inValues === true || $inKeys === true) {
                            $matchedRegister = $register;
                            break;
                        }
                    }
                }

                if ($matchedRegister === null) {
                    $matchedRegister = reset($registers);
                }

                $registerSchemaPairs[] = ['register' => $matchedRegister, 'schema' => $schema];

                $schemaCountQuery          = $countQuery;
                $schemaCountQuery['_rbac'] = $_rbac;
                $schemaCountQuery['_multitenancy'] = $_multitenancy;
                $schemaCount = $this->countObjectsInRegisterSchemaTable(
                    query: $schemaCountQuery,
                    register: $matchedRegister,
                    schema: $schema
                );
                $totalCount += $schemaCount;
            } catch (\Exception $e) {
                $this->logger->warning(
                    message: '[MagicMapper] Failed to load schema for multi-schema search',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'schemaId' => $sId, 'error' => $e->getMessage()]
                );
            }//end try
        }//end foreach

        if (empty($registerSchemaPairs) === true) {
            return [
                'results'        => [],
                'total'          => 0,
                'registers'      => $registersCache,
                'schemas'        => $schemasCache,
                'ignoredFilters' => [],
                'source'         => 'magic_mapper',
            ];
        }

        $unionQuery          = $searchQuery;
        $unionQuery['_rbac'] = $_rbac;
        $unionQuery['_multitenancy'] = $_multitenancy;

        $results = $this->searchAcrossMultipleTables(
            query: $unionQuery,
            registerSchemaPairs: $registerSchemaPairs
        );

        return [
            'results'        => $results,
            'total'          => $totalCount,
            'registers'      => $registersCache,
            'schemas'        => $schemasCache,
            'ignoredFilters' => [],
            'source'         => 'magic_mapper',
        ];
    }//end searchObjectsPaginatedMultiSchema()

    /**
     * Filter objects by schema RBAC permissions.
     *
     * @param array $objects      Array of ObjectEntity objects to filter.
     * @param array $schemasCache Cache of schema data by ID.
     * @param bool  $_rbac        Whether RBAC is enabled.
     *
     * @return array Filtered array of ObjectEntity objects.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function filterBySchemaRbac(array $objects, array &$schemasCache, bool $_rbac): array
    {
        if ($_rbac === false) {
            return $objects;
        }

        if ($this->rbacHandler->isAdmin() === true) {
            return $objects;
        }

        $schemaEntityCache = [];
        $filtered          = [];

        foreach ($objects as $object) {
            if (($object instanceof ObjectEntity) === false) {
                $filtered[] = $object;
                continue;
            }

            $schemaId = $object->getSchema();

            if ($schemaId === null) {
                $filtered[] = $object;
                continue;
            }

            if (isset($schemaEntityCache[$schemaId]) === false) {
                try {
                    $schema = $this->schemaMapper->find((int) $schemaId, _multitenancy: false, _rbac: false);
                    $schemaEntityCache[$schemaId] = $schema;

                    if (isset($schemasCache[$schemaId]) === false) {
                        $schemasCache[$schemaId] = $schema->jsonSerialize();
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            $schema = $schemaEntityCache[$schemaId];

            $objectData = $object->getObject() ?? [];
            $objectData['_organisation'] = $object->getOrganisation();
            $objectData['_owner']        = $object->getOwner();

            if ($this->rbacHandler->hasPermission(
                schema: $schema,
                action: 'read',
                objectOwner: $object->getOwner(),
                objectData: $objectData
            ) === true
            ) {
                $filtered[] = $object;
            }
        }//end foreach

        return $filtered;
    }//end filterBySchemaRbac()

    /**
     * Build a global search result with register/schema caches, RBAC filtering, and pagination.
     *
     * @param array $results     Array of ObjectEntity results from the storage search.
     * @param array $searchQuery The original search query parameters (for _limit/_offset).
     * @param bool  $_rbac       Whether to apply RBAC filtering.
     *
     * @return array{results: array, total: int, registers: array, schemas: array}
     */
    private function getGlobalSearchResult(array $results, array $searchQuery, bool $_rbac): array
    {
        $registersCache = [];
        $schemasCache   = [];

        foreach ($results as $result) {
            if (($result instanceof ObjectEntity) === false) {
                continue;
            }

            $regId = $result->getRegister();
            $schId = $result->getSchema();

            if ($regId !== null && isset($registersCache[$regId]) === false) {
                try {
                    $reg = $this->registerMapper->find(id: (int) $regId, _multitenancy: false, _rbac: false);
                    $registersCache[$reg->getId()] = $reg->jsonSerialize();
                } catch (\Exception $e) {
                    // Skip if register not found.
                }
            }

            if ($schId !== null && isset($schemasCache[$schId]) === false) {
                try {
                    $sch = $this->schemaMapper->find((int) $schId, _multitenancy: false, _rbac: false);
                    $schemasCache[$sch->getId()] = $sch->jsonSerialize();
                } catch (\Exception $e) {
                    // Skip if schema not found.
                }
            }
        }//end foreach

        $results = $this->filterBySchemaRbac(objects: $results, schemasCache: $schemasCache, _rbac: $_rbac);

        $total = count($results);

        $limit   = $searchQuery['_limit'] ?? 1000;
        $offset  = $searchQuery['_offset'] ?? 0;
        $results = array_slice($results, $offset, $limit);

        $finalSchemaIds   = [];
        $finalRegisterIds = [];
        foreach ($results as $object) {
            $schId = $object->getSchema();
            $regId = $object->getRegister();
            if ($schId !== null) {
                $finalSchemaIds[$schId] = true;
            }

            if ($regId !== null) {
                $finalRegisterIds[$regId] = true;
            }
        }

        $schemasCache   = array_intersect_key($schemasCache, $finalSchemaIds);
        $registersCache = array_intersect_key($registersCache, $finalRegisterIds);

        return [
            'results'   => $results,
            'total'     => $total,
            'registers' => $registersCache,
            'schemas'   => $schemasCache,
        ];
    }//end getGlobalSearchResult()

    /**
     * Search for objects across ALL magic tables using a text search term.
     *
     * @param array       $searchQuery   The search query parameters (must contain _search).
     * @param array       $countQuery    The count query parameters.
     * @param string|null $activeOrgUuid The active organisation UUID for multitenancy.
     * @param bool        $_rbac         Whether to apply RBAC filtering.
     * @param bool        $_multitenancy Whether to apply multitenancy filtering.
     *
     * @return array Search results with pagination info.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
     */
    private function searchObjectsGloballyBySearch(
        array $searchQuery,
        array $countQuery,
        ?string $activeOrgUuid=null,
        bool $_rbac=true,
        bool $_multitenancy=true
    ): array {
        $registersCache      = [];
        $schemasCache        = [];
        $registerSchemaPairs = [];

        $idPairs = $this->getAllRegisterSchemaPairs();

        foreach ($idPairs as $idPair) {
            try {
                $regId = $idPair['registerId'];
                $schId = $idPair['schemaId'];

                $register = $this->registerMapper->find($regId, _multitenancy: false, _rbac: false);
                $schema   = $this->schemaMapper->find($schId, _multitenancy: false, _rbac: false);

                if (isset($registersCache[$regId]) === false) {
                    $registersCache[$regId] = $register->jsonSerialize();
                }

                if (isset($schemasCache[$schId]) === false) {
                    $schemasCache[$schId] = $schema->jsonSerialize();
                }

                $registerSchemaPairs[] = ['register' => $register, 'schema' => $schema];
            } catch (\Exception $e) {
                continue;
            }
        }//end foreach

        if (empty($registerSchemaPairs) === true) {
            return [
                'results'   => [],
                'total'     => 0,
                'registers' => $registersCache,
                'schemas'   => $schemasCache,
                '@self'     => ['source' => 'magic_mapper'],
            ];
        }

        $unionQuery          = $searchQuery;
        $unionQuery['_rbac'] = $_rbac;
        $unionQuery['_multitenancy'] = $_multitenancy;

        $results = $this->searchAcrossMultipleTables(
            query: $unionQuery,
            registerSchemaPairs: $registerSchemaPairs
        );

        $countQuery['_rbac']         = $_rbac;
        $countQuery['_multitenancy'] = $_multitenancy;
        $totalCount = 0;
        foreach ($registerSchemaPairs as $pair) {
            $totalCount += $this->countObjectsInRegisterSchemaTable(
                query: $countQuery,
                register: $pair['register'],
                schema: $pair['schema']
            );
        }

        return [
            'results'        => $results,
            'total'          => $totalCount,
            'registers'      => $registersCache,
            'schemas'        => $schemasCache,
            'ignoredFilters' => [],
            '@self'          => ['source' => 'magic_mapper'],
        ];
    }//end searchObjectsGloballyBySearch()

    /**
     * Get size distribution chart data for objects.
     *
     * @param int|null $registerId Optional register ID filter.
     * @param int|null $schemaId   Optional schema ID filter.
     *
     * @return array{labels: list<string>, series: list<int>} Chart data.
     */
    public function getSizeDistributionChartData(?int $registerId=null, ?int $schemaId=null): array
    {
        return [
            'labels' => [],
            'series' => [],
        ];
    }//end getSizeDistributionChartData()

    /**
     * Count objects across multiple schemas.
     *
     * @param array $schemaIds Array of schema IDs.
     *
     * @return int Total count of objects across the given schemas.
     */
    public function countBySchemas(array $schemaIds): int
    {
        return 0;
    }//end countBySchemas()

    /**
     * Find objects across multiple schemas.
     *
     * @param array $schemaIds Array of schema IDs.
     * @param int   $limit     Maximum number of objects to return.
     * @param int   $offset    Offset for pagination.
     *
     * @return ObjectEntity[] Array of object entities.
     *
     * @psalm-return list<ObjectEntity>
     */
    public function findBySchemas(array $schemaIds, int $limit=100, int $offset=0): array
    {
        return [];
    }//end findBySchemas()
}//end class
