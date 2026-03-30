<?php

/**
 * MagicMapper Table Management Handler
 *
 * This handler provides table lifecycle management for dynamic schema-based tables.
 * It implements table creation, existence checking, synchronization, caching,
 * and configuration checking specifically designed for register+schema table combinations.
 *
 * KEY RESPONSIBILITIES:
 * - Table existence checking with intelligent caching
 * - Table creation and recreation (force mode)
 * - Table synchronization when schemas change
 * - Cache management for table metadata
 * - Discovery of existing register+schema tables
 * - Magic mapping configuration checking
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Initial implementation for MagicMapper table management
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Table management handler for MagicMapper dynamic tables
 *
 * This class provides table lifecycle operations specifically optimized
 * for register+schema dynamic tables, including creation, synchronization,
 * existence checking, and cache management.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class MagicTableHandler
{
    /**
     * Constructor for MagicTableHandler
     *
     * @param IDBConnection   $db          Database connection for table operations
     * @param IAppConfig      $appConfig   App configuration for feature flags
     * @param LoggerInterface $logger      Logger for debugging and error reporting
     * @param MagicMapper     $magicMapper Parent MagicMapper for callback access to helpers
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
        private readonly MagicMapper $magicMapper
    ) {
    }//end __construct()

    /**
     * Ensure a specialized table exists for register+schema combination
     *
     * Creates the table if it doesn't exist, updates it if schema changed,
     * and optionally recreates it if force flag is set.
     *
     * @param Register $register The register context
     * @param Schema   $schema   The schema context
     * @param bool     $force    Force table recreation even if exists
     *
     * @return bool True if table exists/was created successfully
     *
     * @throws Exception If table creation/update fails
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Force flag allows table recreation
     */
    public function ensureTableForRegisterSchema(Register $register, Schema $schema, bool $force=false): bool
    {
        $tableName  = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
        $registerId = $register->getId();
        $schemaId   = $schema->getId();
        $cacheKey   = $this->magicMapper->getCacheKey(registerId: $registerId, schemaId: $schemaId);

        $this->logger->info(
            message: '[MagicTableHandler] Creating/updating table for register+schema',
            context: [
                'file'         => __FILE__,
                'line'         => __LINE__,
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
                if ($this->magicMapper->hasRegisterSchemaChanged(register: $register, schema: $schema) === false) {
                    $this->logger->debug(
                        message: '[MagicTableHandler] Table exists and schema unchanged, skipping',
                        context: [
                            'file'      => __FILE__,
                            'line'      => __LINE__,
                            'tableName' => $tableName,
                            'cacheKey'  => $cacheKey,
                        ]
                    );
                    return true;
                }

                // Schema changed, update table.
                $result = $this->syncTableForRegisterSchema(register: $register, schema: $schema);
                return $result['success'] ?? true;
            }

            // Create new table or recreate if forced.
            if (($tableExists === true) && ($force === true)) {
                $this->magicMapper->dropTable(tableName: $tableName);
                $this->magicMapper->invalidateTableCache(cacheKey: $cacheKey);
            }

            return $this->magicMapper->createTableForRegisterSchema(register: $register, schema: $schema);
        } catch (Exception $e) {
            $this->logger->error(
                message: '[MagicTableHandler] Failed to ensure table for register+schema',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
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
        $tableName = MagicMapper::TABLE_PREFIX.$registerId.'_'.$schemaId;

        // Ensure table name doesn't exceed maximum length (should be fine with numeric IDs).
        if (strlen($tableName) > MagicMapper::MAX_TABLE_NAME_LENGTH) {
            // This should rarely happen with numeric IDs, but handle it safely.
            $hash      = substr(md5($registerId.'_'.$schemaId), 0, 8);
            $tableName = MagicMapper::TABLE_PREFIX.$hash;
        }

        // Cache the table name for this register+schema combination.
        $cacheKey = $this->magicMapper->getCacheKey(registerId: $registerId, schemaId: $schemaId);
        MagicMapper::setRegSchemaTableCache(key: $cacheKey, value: $tableName);

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
        $cacheKey   = $this->magicMapper->getCacheKey(registerId: $registerId, schemaId: $schemaId);

        // Check cache first (with timeout).
        $cachedTime = MagicMapper::getTableExistsCache(key: $cacheKey);
        if ($cachedTime !== null) {
            if ((time() - $cachedTime) < MagicMapper::TABLE_CACHE_TIMEOUT) {
                $this->logger->debug(
                    message: '[MagicTableHandler] Table existence check: cache hit',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'registerId' => $registerId,
                        'schemaId'   => $schemaId,
                        'cacheKey'   => $cacheKey,
                        'exists'     => true,
                    ]
                );
                return true;
            }

            // Cache expired, remove it.
            MagicMapper::unsetTableExistsCache(key: $cacheKey);
        }

        // Check database for table existence.
        $tableName = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
        $exists    = $this->magicMapper->checkTableExistsInDatabase(tableName: $tableName);

        if ($exists === true) {
            // Cache positive result.
            MagicMapper::setTableExistsCache(key: $cacheKey, value: time());

            $this->logger->debug(
                message: '[MagicTableHandler] Table existence check: database hit - exists',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'registerId' => $registerId,
                    'schemaId'   => $schemaId,
                    'tableName'  => $tableName,
                    'cacheKey'   => $cacheKey,
                ]
            );
        }

        if ($exists === false) {
            $this->logger->debug(
                message: '[MagicTableHandler] Table existence check: database hit - not exists',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
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
     * Sync table structure for register+schema combination
     *
     * Creates the table if it doesn't exist, or updates its structure
     * if the schema has changed. Returns statistics about the changes made.
     *
     * @param Register $register The register context
     * @param Schema   $schema   The schema context
     *
     * @return array Statistics about what was changed
     *
     * @throws Exception If table sync fails
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function syncTableForRegisterSchema(Register $register, Schema $schema): array
    {
        $tableName  = $this->getTableNameForRegisterSchema(register: $register, schema: $schema);
        $registerId = $register->getId();
        $schemaId   = $schema->getId();
        $cacheKey   = $this->magicMapper->getCacheKey(registerId: $registerId, schemaId: $schemaId);

        $this->logger->info(
            message: '[MagicTableHandler] Syncing register+schema table',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'registerId' => $registerId,
                'schemaId'   => $schemaId,
                'tableName'  => $tableName,
            ]
        );

        try {
            // Check if table exists - if not, create it instead of trying to update.
            $tableExists = $this->tableExistsForRegisterSchema(register: $register, schema: $schema);

            if ($tableExists === false) {
                $this->logger->info(
                    message: '[MagicTableHandler] Table does not exist, creating it',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'registerId' => $registerId,
                        'schemaId'   => $schemaId,
                        'tableName'  => $tableName,
                    ]
                );

                // Create the table.
                $this->magicMapper->createTableForRegisterSchema(register: $register, schema: $schema);

                // Get the columns that were created.
                $requiredColumns  = $this->magicMapper->buildTableColumnsFromSchema(schema: $schema);
                $metadataColumns  = [
                    'id',
                    'uuid',
                    'register',
                    'schema',
                    'object',
                    'deleted',
                    'locked',
                    'updated',
                    'created',
                    'version',
                ];
                $metadataCount    = count(array_intersect(array_keys($requiredColumns), $metadataColumns));
                $regularPropCount = count($requiredColumns) - $metadataCount;

                // Return statistics for newly created table.
                return [
                    'success'               => true,
                    'created'               => true,
                    'metadataProperties'    => $metadataCount,
                    'regularProperties'     => $regularPropCount,
                    'totalProperties'       => count($requiredColumns),
                    'columnsAdded'          => count($requiredColumns),
                    'columnsDeRequired'     => 0,
                    'columnsDropped'        => 0,
                    'columnsUnchanged'      => 0,
                    'columnsAddedList'      => array_keys($requiredColumns),
                    'columnsDeRequiredList' => [],
                    'columnsDroppedList'    => [],
                ];
            }//end if

            // Table exists, update its structure.
            $this->logger->info(
                message: '[MagicTableHandler] Table exists, updating structure',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'registerId' => $registerId,
                    'schemaId'   => $schemaId,
                    'tableName'  => $tableName,
                ]
            );

            // Get current table structure.
            $currentColumns = $this->magicMapper->getExistingTableColumns(tableName: $tableName);

            // Get required columns from schema.
            $requiredColumns = $this->magicMapper->buildTableColumnsFromSchema(schema: $schema);

            // Count metadata properties (non-schema columns).
            $metadataColumns = [
                'id',
                'uuid',
                'register',
                'schema',
                'object',
                'deleted',
                'locked',
                'updated',
                'created',
                'version',
            ];
            $metadataCount   = count(array_intersect(array_keys($requiredColumns), $metadataColumns));

            // Compare and update table structure - this returns statistics.
            $columnStats = $this->magicMapper->updateTableStructure(
                tableName: $tableName,
                currentColumns: $currentColumns,
                requiredColumns: $requiredColumns
            );

            // Update indexes.
            $this->magicMapper->updateTableIndexes(tableName: $tableName, register: $register, schema: $schema);

            // Store updated schema version and refresh cache.
            $this->magicMapper->storeRegisterSchemaVersion(register: $register, schema: $schema);
            MagicMapper::setTableExistsCache(key: $cacheKey, value: time());
            // Refresh cache timestamp.
            // Calculate regular properties (excluding metadata).
            $regularPropCount = count($requiredColumns) - $metadataCount;

            $added          = count($columnStats['columnsAdded']);
            $dropped        = count($columnStats['columnsDropped']);
            $unchangedCount = count($currentColumns) - $added - $dropped;

            $result = [
                'success'               => true,
                'metadataProperties'    => $metadataCount,
                'regularProperties'     => $regularPropCount,
                'totalProperties'       => count($requiredColumns),
                'columnsAdded'          => count($columnStats['columnsAdded']),
                'columnsDeRequired'     => count($columnStats['columnsDeRequired']),
                'columnsReRequired'     => count($columnStats['columnsReRequired']),
                'columnsDropped'        => count($columnStats['columnsDropped']),
                'columnsUnchanged'      => $unchangedCount,
                'columnsAddedList'      => $columnStats['columnsAdded'],
                'columnsDeRequiredList' => $columnStats['columnsDeRequired'],
                'columnsReRequiredList' => $columnStats['columnsReRequired'],
                'columnsDroppedList'    => $columnStats['columnsDropped'],
            ];

            $this->logger->info(
                message: '[MagicTableHandler] Successfully updated register+schema table',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'tableName' => $tableName,
                    'cacheKey'  => $cacheKey,
                    'stats'     => $result,
                ]
            );

            return $result;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[MagicTableHandler] Failed to update register+schema table',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'tableName' => $tableName,
                    'error'     => $e->getMessage(),
                ]
            );

            throw $e;
        }//end try
    }//end syncTableForRegisterSchema()

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
            MagicMapper::clearAllStaticCaches();

            $this->logger->debug(
                message: '[MagicTableHandler] Cleared all MagicMapper caches',
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return;
        }

        // Clear cache for specific register+schema combination.
        $cacheKey = $this->magicMapper->getCacheKey(registerId: $registerId, schemaId: $schemaId);
        $this->magicMapper->invalidateTableCache(cacheKey: $cacheKey);

        $this->logger->debug(
            message: '[MagicTableHandler] Cleared MagicMapper cache for register+schema',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
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
            $searchPattern = $prefix.MagicMapper::TABLE_PREFIX.'%';

            $sql  = "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$searchPattern]);
            $rows = $stmt->fetchAll();

            $registerSchemaTables = [];
            $fullPrefix           = $prefix.MagicMapper::TABLE_PREFIX;

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
                        $cacheKey = $this->magicMapper->getCacheKey(registerId: $registerId, schemaId: $schemaId);
                        MagicMapper::setTableExistsCache(key: $cacheKey, value: time());
                        MagicMapper::setRegSchemaTableCache(key: $cacheKey, value: $tableName);
                    }
                }//end if
            }//end foreach

            $this->logger->info(
                message: '[MagicTableHandler] Found existing register+schema tables',
                context: [
                    'file'       => __FILE__,
                    'line'       => __LINE__,
                    'tableCount' => count($registerSchemaTables),
                ]
            );

            return $registerSchemaTables;
        } catch (Exception $e) {
            $this->logger->error(
                message: '[MagicTableHandler] Failed to get existing register+schema tables',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
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
}//end class
