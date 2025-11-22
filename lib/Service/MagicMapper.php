<?php
/**
 * MagicMapper - Dynamic Schema-Based Table Management
 *
 * This service provides dynamic table creation and management based on JSON schema objects.
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
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use Exception;
use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\MagicMapperHandlers\MagicSearchHandler;
use OCA\OpenRegister\Service\MagicMapperHandlers\MagicRbacHandler;
use OCA\OpenRegister\Service\MagicMapperHandlers\MagicBulkHandler;
use OCA\OpenRegister\Service\MagicMapperHandlers\MagicOrganizationHandler;
use OCA\OpenRegister\Service\MagicMapperHandlers\MagicFacetHandler;
use OCA\OpenRegister\Service\SettingsService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

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
 *     unique?: bool
 * }
 */
class MagicMapper
{

    /**
     * Table name prefix for register+schema-specific tables
     */
    private const TABLE_PREFIX = 'oc_openregister_table_';

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
    private static array $registerSchemaTableCache = [];

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
     * @param IUserSession       $userSession        User session for authentication context
     * @param IGroupManager      $groupManager       Group manager for RBAC operations
     * @param IUserManager       $userManager        User manager for user operations
     * @param IAppConfig         $appConfig          App configuration for feature flags
     * @param LoggerInterface    $logger             Logger for debugging and monitoring
     * @param SettingsService    $settingsService    Settings service for configuration
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly IConfig $config,
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
            $this->db,
            $this->logger
        );

        $this->rbacHandler = new MagicRbacHandler(
            $this->userSession,
            $this->groupManager,
            $this->userManager,
            $this->appConfig,
            $this->logger
        );

        $this->bulkHandler = new MagicBulkHandler(
            $this->db,
            $this->logger
        );

        $this->organizationHandler = new MagicOrganizationHandler(
            $this->db,
            $this->userSession,
            $this->groupManager,
            $this->userManager,
            $this->appConfig,
            $this->logger
        );

        $this->facetHandler = new MagicFacetHandler(
            $this->db,
            $this->logger
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
     * @return bool True if table was created/updated successfully
     */
    public function ensureTableForRegisterSchema(Register $register, Schema $schema, bool $force=false): bool
    {
        $tableName  = $this->getTableNameForRegisterSchema($register, $schema);
        $registerId = $register->getId();
        $schemaId   = $schema->getId();
        $cacheKey   = $this->getCacheKey($registerId, $schemaId);

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
            $tableExists = $this->tableExistsForRegisterSchema($register, $schema);

            if ($tableExists && !$force) {
                // Table exists and not forcing update - check if schema changed.
                if (!$this->hasRegisterSchemaChanged($register, $schema)) {
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
                return $this->updateTableForRegisterSchema($register, $schema);
            }

            // Create new table or recreate if forced.
            if ($tableExists && $force) {
                $this->dropTable($tableName);
                $this->invalidateTableCache($cacheKey);
            }

            return $this->createTableForRegisterSchema($register, $schema);
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

            throw new Exception(
                "Failed to create/update table for register '{$register->getTitle()}' + schema '{$schema->getTitle()}': ".$e->getMessage(),
                0,
                $e
            );
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
        $cacheKey = $this->getCacheKey($registerId, $schemaId);
        self::$registerSchemaTableCache[$cacheKey] = $tableName;

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
        $cacheKey   = $this->getCacheKey($registerId, $schemaId);

        // Check cache first (with timeout).
        if (isset(self::$tableExistsCache[$cacheKey])) {
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
        $tableName = $this->getTableNameForRegisterSchema($register, $schema);
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
        } else {
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
     * @return array Array of saved object UUIDs
     */
    public function saveObjectsToRegisterSchemaTable(array $objects, Register $register, Schema $schema): array
    {
        // Ensure table exists for this register+schema combination.
        $this->ensureTableForRegisterSchema($register, $schema);

        $tableName  = $this->getTableNameForRegisterSchema($register, $schema);
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
                $uuid = $this->saveObjectToRegisterSchemaTable($object, $register, $schema, $tableName);
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
     * @return array Array of ObjectEntity objects
     */
    public function searchObjectsInRegisterSchemaTable(array $query, Register $register, Schema $schema): array
    {
        // Use fast cached existence check.
        if (!$this->existsTableForRegisterSchema($register, $schema)) {
            $this->logger->info(
                    'Register+schema table does not exist, should use generic storage',
                    [
                        'registerId' => $register->getId(),
                        'schemaId'   => $schema->getId(),
                    ]
                    );
            return [];
        }

        $tableName = $this->getTableNameForRegisterSchema($register, $schema);

        try {
            return $this->executeRegisterSchemaTableSearch($query, $register, $schema, $tableName);
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
     * @param string $tableName The table name to check
     *
     * @return bool True if table exists in database
     */
    private function checkTableExistsInDatabase(string $tableName): bool
    {
        try {
            $schemaManager = $this->db->getSchemaManager();
            return $schemaManager->tablesExist([$tableName]);
        } catch (Exception $e) {
            $this->logger->warning(
                    'Failed to check table existence in database',
                    [
                        'tableName' => $tableName,
                        'error'     => $e->getMessage(),
                    ]
                    );

            return false;
        }

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
        unset(self::$registerSchemaTableCache[$cacheKey]);
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
     * @return bool True if table created successfully
     */
    private function createTableForRegisterSchema(Register $register, Schema $schema): bool
    {
        $tableName  = $this->getTableNameForRegisterSchema($register, $schema);
        $registerId = $register->getId();
        $schemaId   = $schema->getId();
        $cacheKey   = $this->getCacheKey($registerId, $schemaId);

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
        $this->createTable($tableName, $columns);

        // Create indexes for performance.
        $this->createTableIndexes($tableName, $register, $schema);

        // Store schema version for change detection.
        $this->storeRegisterSchemaVersion($register, $schema);

        // Update cache with current timestamp.
        self::$tableExistsCache[$cacheKey]         = time();
        self::$registerSchemaTableCache[$cacheKey] = $tableName;

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
     * @return bool True if table updated successfully
     */
    private function updateTableForRegisterSchema(Register $register, Schema $schema): bool
    {
        $tableName  = $this->getTableNameForRegisterSchema($register, $schema);
        $registerId = $register->getId();
        $schemaId   = $schema->getId();
        $cacheKey   = $this->getCacheKey($registerId, $schemaId);

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
            $this->updateTableStructure($tableName, $currentColumns, $requiredColumns);

            // Update indexes.
            $this->updateTableIndexes($tableName, $register, $schema);

            // Store updated schema version and refresh cache.
            $this->storeRegisterSchemaVersion($register, $schema);
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
     * @return array Array of column definitions
     *
     * @psalm-return array<string, TableColumnConfig>
     */
    private function buildTableColumnsFromSchema(Schema $schema): array
    {
        $columns = [];

        // Add all metadata columns from ObjectEntity with underscore prefix.
        $columns = array_merge($columns, $this->getMetadataColumns());

        // Get schema properties and convert to SQL columns.
        $schemaProperties = $schema->getProperties();

        if (is_array($schemaProperties)) {
            foreach ($schemaProperties as $propertyName => $propertyConfig) {
                // Skip if property name conflicts with metadata columns.
                if (isset($columns[self::METADATA_PREFIX.$propertyName])) {
                    $this->logger->warning(
                            'Schema property conflicts with metadata column',
                            [
                                'propertyName' => $propertyName,
                                'schemaId'     => $schema->getId(),
                            ]
                            );
                    continue;
                }

                $column = $this->mapSchemaPropertyToColumn($propertyName, $propertyConfig);
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
     * @return array Array of metadata column definitions
     *
     * @psalm-return array<string, TableColumnConfig>
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
                'type'     => 'text',
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
     * @return array|null Column definition or null if property should be skipped
     *
     * @psalm-param  SchemaPropertyConfig $propertyConfig
     * @psalm-return TableColumnConfig|null
     */
    private function mapSchemaPropertyToColumn(string $propertyName, array $propertyConfig): ?array
    {
        $type   = $propertyConfig['type'] ?? 'string';
        $format = $propertyConfig['format'] ?? null;

        // Sanitize column name.
        $columnName = $this->sanitizeColumnName($propertyName);

        switch ($type) {
            case 'string':
                return $this->mapStringProperty($columnName, $propertyConfig, $format);

            case 'integer':
                return $this->mapIntegerProperty($columnName, $propertyConfig);

            case 'number':
                return $this->mapNumberProperty($columnName, $propertyConfig);

            case 'boolean':
                return [
                    'name'     => $columnName,
                    'type'     => 'boolean',
                    'nullable' => !in_array($propertyName, $propertyConfig['required'] ?? []),
                    'default'  => $propertyConfig['default'] ?? null,
                ];

            case 'array':
            case 'object':
                // Store complex types as JSON.
                return [
                    'name'     => $columnName,
                    'type'     => 'json',
                    'nullable' => !in_array($propertyName, $propertyConfig['required'] ?? []),
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
     * @return array Column definition
     *
     * @psalm-param  SchemaPropertyConfig $propertyConfig
     * @psalm-return TableColumnConfig
     */
    private function mapStringProperty(string $columnName, array $propertyConfig, ?string $format): array
    {
        $maxLength  = $propertyConfig['maxLength'] ?? null;
        $isRequired = in_array($columnName, $propertyConfig['required'] ?? []);

        // Handle special formats.
        switch ($format) {
            case 'date':
            case 'date-time':
                return [
                    'name'     => $columnName,
                    'type'     => 'datetime',
                    'nullable' => !$isRequired,
                    'index'    => true,
            // Date fields are often used for filtering.
                ];

            case 'email':
                return [
                    'name'     => $columnName,
                    'type'     => 'string',
                    'length'   => 320,
            // RFC 5321 email length limit.
                    'nullable' => !$isRequired,
                    'index'    => true,
                ];

            case 'uri':
            case 'url':
                return [
                    'name'     => $columnName,
                    'type'     => 'text',
                    'nullable' => !$isRequired,
                ];

            case 'uuid':
                return [
                    'name'     => $columnName,
                    'type'     => 'string',
                    'length'   => 36,
                    'nullable' => !$isRequired,
                    'index'    => true,
                ];

            default:
                // Regular string.
                if ($maxLength && $maxLength <= 255) {
                    return [
                        'name'     => $columnName,
                        'type'     => 'string',
                        'length'   => $maxLength,
                        'nullable' => !$isRequired,
                        'index'    => $maxLength <= 100,
                    // Index shorter strings for performance.
                    ];
                } else {
                    return [
                        'name'     => $columnName,
                        'type'     => $maxLength && $maxLength > 65535 ? 'text' : 'text',
                        'nullable' => !$isRequired,
                    ];
                }
        }//end switch

    }//end mapStringProperty()


    /**
     * Map integer property to SQL column
     *
     * @param string $columnName     The column name
     * @param array  $propertyConfig The property configuration
     *
     * @return array Column definition
     *
     * @psalm-param  SchemaPropertyConfig $propertyConfig
     * @psalm-return TableColumnConfig
     */
    private function mapIntegerProperty(string $columnName, array $propertyConfig): array
    {
        $minimum    = $propertyConfig['minimum'] ?? null;
        $maximum    = $propertyConfig['maximum'] ?? null;
        $isRequired = in_array($columnName, $propertyConfig['required'] ?? []);

        // Choose appropriate integer type based on range.
        $intType = 'integer';
        if ($minimum !== null && $minimum >= 0 && $maximum !== null && $maximum <= 65535) {
            $intType = 'smallint';
        } else if ($maximum !== null && $maximum > 2147483647) {
            $intType = 'bigint';
        }

        return [
            'name'     => $columnName,
            'type'     => $intType,
            'nullable' => !$isRequired,
            'default'  => $propertyConfig['default'] ?? null,
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
     * @psalm-return TableColumnConfig
     */
    private function mapNumberProperty(string $columnName, array $propertyConfig): array
    {
        $isRequired = in_array($columnName, $propertyConfig['required'] ?? []);

        return [
            'name'      => $columnName,
            'type'      => 'decimal',
            'precision' => 10,
            'scale'     => 2,
            'nullable'  => !$isRequired,
            'default'   => $propertyConfig['default'] ?? null,
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
    private function createTable(string $tableName, array $columns): void
    {
        $schema = $this->db->createSchema();
        $table  = $schema->createTable($tableName);

        foreach ($columns as $column) {
            $this->addColumnToTable($table, $column);
        }

        // Execute table creation.
        $this->db->migrateToSchema($schema);

        $this->logger->debug(
                'Created schema table with columns',
                [
                    'tableName' => $tableName,
                    'columns'   => array_keys($columns),
                ]
                );

    }//end createTable()


    /**
     * Add column to table definition
     *
     * @param \Doctrine\DBAL\Schema\Table $table  The table object
     * @param array                       $column The column definition
     *
     * @return void
     *
     * @psalm-param TableColumnConfig $column
     */
    private function addColumnToTable($table, array $column): void
    {
        $options = [
            'notnull' => !($column['nullable'] ?? true),
        ];

        if (isset($column['length'])) {
            $options['length'] = $column['length'];
        }

        if (isset($column['default'])) {
            $options['default'] = $column['default'];
        }

        if (isset($column['autoincrement']) && $column['autoincrement']) {
            $options['autoincrement'] = true;
        }

        if (isset($column['precision'])) {
            $options['precision'] = $column['precision'];
        }

        if (isset($column['scale'])) {
            $options['scale'] = $column['scale'];
        }

        // Add column to table.
        $table->addColumn($column['name'], $column['type'], $options);

        // Set primary key if specified.
        if (isset($column['primary']) && $column['primary']) {
            $table->setPrimaryKey([$column['name']]);
        }

    }//end addColumnToTable()


    /**
     * Create indexes for table performance
     *
     * @param string   $tableName The table name
     * @param Register $register  The register context
     * @param Schema   $schema    The schema for index analysis
     *
     * @return void
     */
    private function createTableIndexes(string $tableName, Register $register, Schema $schema): void
    {
        try {
            // Create unique index on UUID.
            $this->db->executeStatement(
                "CREATE UNIQUE INDEX IF NOT EXISTS {$tableName}_uuid_idx ON {$tableName} (".self::METADATA_PREFIX."uuid)"
            );

            // Create composite index on register + schema for multitenancy.
            $this->db->executeStatement(
                "CREATE INDEX IF NOT EXISTS {$tableName}_register_schema_idx ON {$tableName} (".self::METADATA_PREFIX."register, ".self::METADATA_PREFIX."schema)"
            );

            // Create index on organisation for multitenancy.
            $this->db->executeStatement(
                "CREATE INDEX IF NOT EXISTS {$tableName}_organisation_idx ON {$tableName} (".self::METADATA_PREFIX."organisation)"
            );

            // Create index on owner for RBAC.
            $this->db->executeStatement(
                "CREATE INDEX IF NOT EXISTS {$tableName}_owner_idx ON {$tableName} (".self::METADATA_PREFIX."owner)"
            );

            // Create indexes on frequently filtered metadata fields.
            $indexableMetadataFields = ['created', 'updated', 'published', 'name'];
            foreach ($indexableMetadataFields as $field) {
                $this->db->executeStatement(
                    "CREATE INDEX IF NOT EXISTS {$tableName}_{$field}_idx ON {$tableName} (".self::METADATA_PREFIX."{$field})"
                );
            }

            $this->logger->debug(
                    'Created table indexes',
                    [
                        'tableName'  => $tableName,
                        'indexCount' => 4 + count($indexableMetadataFields),
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
    private function saveObjectToRegisterSchemaTable(array $objectData, Register $register, Schema $schema, string $tableName): string
    {
        // Prepare object data for table storage with register+schema context.
        $preparedData = $this->prepareObjectDataForTable($objectData, $register, $schema);

        // Generate UUID if not provided.
        if (empty($preparedData[self::METADATA_PREFIX.'uuid'])) {
            $preparedData[self::METADATA_PREFIX.'uuid'] = Uuid::v4()->toRfc4122();
        }

        $uuid = $preparedData[self::METADATA_PREFIX.'uuid'];

        try {
            // Check if object exists (for update vs insert).
            $existingObject = $this->findObjectInRegisterSchemaTable($uuid, $tableName);

            if ($existingObject !== null) {
                // Update existing object.
                $this->updateObjectInRegisterSchemaTable($uuid, $preparedData, $tableName);
                $this->logger->debug(
                        'Updated object in register+schema table',
                        [
                            'uuid'      => $uuid,
                            'tableName' => $tableName,
                        ]
                        );
            } else {
                // Insert new object.
                $this->insertObjectInRegisterSchemaTable($preparedData, $tableName);
                $this->logger->debug(
                        'Inserted object in register+schema table',
                        [
                            'uuid'      => $uuid,
                            'tableName' => $tableName,
                        ]
                        );
            }//end if

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
     * @return array Prepared data with metadata and schema fields
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
        if (empty($metadata['register'])) {
            $metadata['register'] = $register->getId();
        }

        if (empty($metadata['schema'])) {
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
            if (in_array($field, ['created', 'updated', 'published', 'depublished', 'expires'])) {
                if ($value === null && in_array($field, ['created', 'updated'])) {
                    $value = $now;
                }

                if ($value instanceof DateTime) {
                    $value = $value->format('Y-m-d H:i:s');
                } else if (is_string($value)) {
                    // Validate and convert datetime strings.
                    try {
                        $dateTime = new \DateTime(datetime: $value);
                        $value    = $dateTime->format('Y-m-d H:i:s');
                    } catch (Exception $e) {
                        $value = null;
                    }
                }
            }

            // Handle JSON fields.
            if (in_array($field, ['files', 'relations', 'locked', 'authorization', 'validation', 'deleted', 'geo', 'retention', 'groups'])) {
                if ($value !== null && !is_string($value)) {
                    $value = json_encode($value);
                }
            }

            $preparedData[self::METADATA_PREFIX.$field] = $value;
        }//end foreach

        // Map schema properties to columns.
        $schemaProperties = $schema->getProperties();
        if (is_array($schemaProperties)) {
            foreach ($schemaProperties as $propertyName => $propertyConfig) {
                if (isset($data[$propertyName])) {
                    $value = $data[$propertyName];

                    // Convert complex types to JSON.
                    if (is_array($value) || is_object($value)) {
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
     * @return array Array of ObjectEntity objects
     */
    private function executeRegisterSchemaTableSearch(array $query, Register $register, Schema $schema, string $tableName): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($tableName);

        // Apply filters.
        $this->applySearchFilters($qb, $query);

        // Apply pagination.
        if (isset($query['_limit'])) {
            $qb->setMaxResults((int) $query['_limit']);
        }

        if (isset($query['_offset'])) {
            $qb->setFirstResult((int) $query['_offset']);
        }

        // Apply ordering.
        if (isset($query['_order']) && is_array($query['_order'])) {
            foreach ($query['_order'] as $field => $direction) {
                $columnName = str_starts_with($field, '@self.') ? self::METADATA_PREFIX.substr($field, 6) : $this->sanitizeColumnName($field);

                $qb->addOrderBy($columnName, strtoupper($direction));
            }
        }

        try {
            $result = $qb->executeQuery();
            $rows   = $result->fetchAll();

            // Convert rows back to ObjectEntity objects.
            $objects = [];
            foreach ($rows as $row) {
                $objectEntity = $this->convertRowToObjectEntity($row, $register, $schema);
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
     * @param array    $row      Database row data
     * @param Register $register Register context for validation
     * @param Schema   $schema   Schema for context
     *
     * @return ObjectEntity|null ObjectEntity or null if conversion fails
     */
    private function convertRowToObjectEntity(array $row, Register $register, Schema $schema): ?ObjectEntity
    {
        try {
            $objectEntity = new ObjectEntity();

            // Extract metadata fields (remove prefix).
            $metadata   = [];
            $objectData = [];

            foreach ($row as $columnName => $value) {
                if (str_starts_with($columnName, self::METADATA_PREFIX)) {
                    // This is a metadata field.
                    $metadataField = substr($columnName, strlen(self::METADATA_PREFIX));

                    // Handle datetime fields.
                    if (in_array($metadataField, ['created', 'updated', 'published', 'depublished', 'expires']) && $value) {
                        $value = new \DateTime(datetime: $value);
                    }

                    // Handle JSON fields.
                    if (in_array($metadataField, ['files', 'relations', 'locked', 'authorization', 'validation', 'deleted', 'geo', 'retention', 'groups']) && $value) {
                        $value = json_decode($value, true);
                    }

                    $metadata[$metadataField] = $value;
                } else {
                    // This is a schema property.
                    // Decode JSON values if they're JSON strings.
                    if (is_string($value) && $this->isJsonString($value)) {
                        $decodedValue            = json_decode($value, true);
                        $objectData[$columnName] = $decodedValue !== null ? $decodedValue : $value;
                    } else {
                        $objectData[$columnName] = $value;
                    }
                }//end if
            }//end foreach

            // Set metadata fields on ObjectEntity.
            foreach ($metadata as $field => $value) {
                if ($value !== null) {
                    $method = 'set'.ucfirst($field);
                    if (method_exists($objectEntity, $method)) {
                        $objectEntity->$method($value);
                    }
                }
            }

            // Set object data.
            $objectEntity->setObject($objectData);

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
        return $this->existsTableForRegisterSchema($register, $schema);

    }//end tableExistsForRegisterSchema()


    /**
     * Sanitize table name for database safety
     *
     * @param string $name The name to sanitize
     *
     * @return string Sanitized table name
     */
    private function sanitizeTableName(string $name): string
    {
        // Convert to lowercase and replace invalid characters.
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9_]/', '_', $name);

        // Ensure it starts with a letter or underscore.
        if (!preg_match('/^[a-z_]/', $name)) {
            $name = 'table_'.$name;
        }

        // Remove consecutive underscores.
        $name = preg_replace('/_+/', '_', $name);

        // Remove trailing underscores.
        $name = rtrim($name, '_');

        return $name;

    }//end sanitizeTableName()


    /**
     * Sanitize column name for database safety
     *
     * @param string $name The name to sanitize
     *
     * @return string Sanitized column name
     */
    private function sanitizeColumnName(string $name): string
    {
        // Convert to lowercase and replace invalid characters.
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9_]/', '_', $name);

        // Ensure it starts with a letter or underscore.
        if (!preg_match('/^[a-z_]/', $name)) {
            $name = 'col_'.$name;
        }

        // Remove consecutive underscores.
        $name = preg_replace('/_+/', '_', $name);

        // Remove trailing underscores.
        $name = rtrim($name, '_');

        return $name;

    }//end sanitizeColumnName()


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
        $cacheKey   = $this->getCacheKey($registerId, $schemaId);

        $currentVersion = $this->getStoredRegisterSchemaVersion($registerId, $schemaId);
        $newVersion     = $this->calculateRegisterSchemaVersion($register, $schema);

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
        $cacheKey   = $this->getCacheKey($registerId, $schemaId);

        $version   = $this->calculateRegisterSchemaVersion($register, $schema);
        $configKey = 'table_version_'.$cacheKey;

        $this->config->setAppValue('openregister', $configKey, $version);

        // Also update structure cache.
        self::$tableStructureCache[$cacheKey] = $version;

    }//end storeRegisterSchemaVersion()


    /**
     * Get stored register+schema version
     *
     * @param int $registerId The register ID
     * @param int $schemaId   The schema ID
     *
     * @return string|null The stored version or null if not found
     */
    private function getStoredRegisterSchemaVersion(int $registerId, int $schemaId): ?string
    {
        $cacheKey  = $this->getCacheKey($registerId, $schemaId);
        $configKey = 'table_version_'.$cacheKey;

        return $this->config->getAppValue('openregister', $configKey, null);

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
     * @param IQueryBuilder $qb    The query builder
     * @param array         $query The search parameters
     *
     * @return void
     */
    private function applySearchFilters(IQueryBuilder $qb, array $query): void
    {
        foreach ($query as $key => $value) {
            // Skip system parameters.
            if (str_starts_with($key, '_')) {
                continue;
            }

            // Handle @self metadata filters.
            if ($key === '@self' && is_array($value)) {
                foreach ($value as $metaField => $metaValue) {
                    $columnName = self::METADATA_PREFIX.$metaField;
                    $this->addWhereCondition($qb, $columnName, $metaValue);
                }

                continue;
            }

            // Handle schema property filters.
            $columnName = $this->sanitizeColumnName($key);
            $this->addWhereCondition($qb, $columnName, $value);
        }

    }//end applySearchFilters()


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
        if (is_array($value)) {
            // Handle array filters (IN operation).
            $qb->andWhere($qb->expr()->in($columnName, $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR_ARRAY)));
        } else if (is_string($value) && str_contains($value, '%')) {
            // Handle LIKE operation.
            $qb->andWhere($qb->expr()->like($columnName, $qb->createNamedParameter($value)));
        } else {
            // Handle exact match.
            $qb->andWhere($qb->expr()->eq($columnName, $qb->createNamedParameter($value)));
        }

    }//end addWhereCondition()


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

            return $row ?: null;
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
     * @return array Array of existing column definitions
     */
    private function getExistingTableColumns(string $tableName): array
    {
        try {
            $schemaManager = $this->db->getSchemaManager();
            $columns       = $schemaManager->listTableColumns($tableName);

            $columnDefinitions = [];
            foreach ($columns as $column) {
                $columnDefinitions[$column->getName()] = [
                    'name'     => $column->getName(),
                    'type'     => $column->getType()->getName(),
                    'length'   => $column->getLength(),
                    'nullable' => !$column->getNotnull(),
                    'default'  => $column->getDefault(),
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
        $schema = $this->db->createSchema();
        $table  = $schema->getTable($tableName);

        // Find columns to add.
        foreach ($requiredColumns as $columnName => $columnDef) {
            if (!isset($currentColumns[$columnName])) {
                $this->logger->info(
                        'Adding new column to schema table',
                        [
                            'tableName'  => $tableName,
                            'columnName' => $columnName,
                            'columnType' => $columnDef['type'],
                        ]
                        );

                $this->addColumnToTable($table, $columnDef);
            }
        }

        // Execute schema changes.
        $this->db->migrateToSchema($schema);

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
        $this->createTableIndexes($tableName, $register, $schema);

    }//end updateTableIndexes()


    /**
     * Drop table
     *
     * @param string $tableName The table name to drop
     *
     * @throws Exception If table drop fails
     *
     * @return void
     */
    private function dropTable(string $tableName): void
    {
        try {
            $schemaManager = $this->db->getSchemaManager();
            $schemaManager->dropTable($tableName);

            // Clear from cache - need to clear by table name pattern.
            foreach (self::$tableExistsCache as $cacheKey => $timestamp) {
                if (isset(self::$registerSchemaTableCache[$cacheKey])
                    && self::$registerSchemaTableCache[$cacheKey] === $tableName
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
     */
    private function isJsonString(string $string): bool
    {
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
        if ($registerId !== null && $schemaId !== null) {
            // Clear cache for specific register+schema combination.
            $cacheKey = $this->getCacheKey($registerId, $schemaId);
            $this->invalidateTableCache($cacheKey);

            $this->logger->debug(
                    'Cleared MagicMapper cache for register+schema',
                    [
                        'registerId' => $registerId,
                        'schemaId'   => $schemaId,
                        'cacheKey'   => $cacheKey,
                    ]
                    );
        } else {
            // Clear all caches.
            self::$tableExistsCache         = [];
            self::$registerSchemaTableCache = [];
            self::$tableStructureCache      = [];

            $this->logger->debug('Cleared all MagicMapper caches');
        }//end if

    }//end clearCache()


    /**
     * Get all existing register+schema tables
     *
     * This method scans the database for all tables matching our naming pattern
     * and returns them as an array of register+schema combinations.
     *
     * @return array Array of ['registerId' => int, 'schemaId' => int, 'tableName' => string]
     */
    public function getExistingRegisterSchemaTables(): array
    {
        try {
            $schemaManager = $this->db->getSchemaManager();
            $allTables     = $schemaManager->listTableNames();

            $registerSchemaTables = [];
            $prefix = self::TABLE_PREFIX;

            foreach ($allTables as $tableName) {
                if (str_starts_with($tableName, $prefix)) {
                    // Extract register and schema IDs from table name.
                    $suffix = substr($tableName, strlen($prefix));

                    // Expected format: {registerId}_{schemaId}
                    if (preg_match('/^(\d+)_(\d+)$/', $suffix, $matches)) {
                        $registerId = (int) $matches[1];
                        $schemaId   = (int) $matches[2];

                        $registerSchemaTables[] = [
                            'registerId' => $registerId,
                            'schemaId'   => $schemaId,
                            'tableName'  => $tableName,
                        ];

                        // Pre-populate cache while we're at it.
                        $cacheKey = $this->getCacheKey($registerId, $schemaId);
                        self::$tableExistsCache[$cacheKey]         = time();
                        self::$registerSchemaTableCache[$cacheKey] = $tableName;
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
     * @param Register $register The register to check
     * @param Schema   $schema   The schema to check
     *
     * @return bool True if MagicMapper should be used for this register+schema
     */
    public function isMagicMappingEnabled(Register $register, Schema $schema): bool
    {
        // Check schema configuration for magic mapping flag.
        $configuration = $schema->getConfiguration();

        // Enable magic mapping if explicitly enabled in schema config.
        if (isset($configuration['magicMapping']) && $configuration['magicMapping'] === true) {
            return true;
        }

        // Check global configuration.
        $globalEnabled = $this->config->getAppValue('openregister', 'magic_mapping_enabled', 'false');

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

        if (isset($configuration['magicMapping']) && $configuration['magicMapping'] === true) {
            return true;
        }

        $globalEnabled = $this->config->getAppValue('openregister', 'magic_mapping_enabled', 'false');
        return $globalEnabled === 'true';

    }//end isMagicMappingEnabledForSchema()


}//end class
