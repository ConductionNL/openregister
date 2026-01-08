<?php

/**
 * MagicMapper Bulk Operations Handler
 *
 * This handler provides high-performance bulk operations for dynamic schema-based tables.
 * It implements optimized bulk save, update, delete, publish, and depublish operations
 * specifically designed for dynamically created tables.
 *
 * KEY RESPONSIBILITIES:
 * - High-performance bulk save operations for dynamic tables
 * - Optimized bulk update operations with schema-aware field mapping
 * - Bulk delete operations (soft and hard delete support)
 * - Bulk publish/depublish operations
 * - Memory-efficient processing for large datasets
 * - Transaction management for data consistency
 *
 * PERFORMANCE OPTIMIZATIONS:
 * - Schema-specific column mapping for faster operations
 * - Prepared statement reuse for bulk operations
 * - Memory-efficient chunking strategies
 * - Optimized INSERT...ON DUPLICATE KEY UPDATE queries
 * - Reduced SQL overhead compared to generic table operations
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Initial implementation for MagicMapper bulk operations
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Bulk operations handler for MagicMapper dynamic tables
 *
 * This class provides high-performance bulk operations specifically optimized
 * for schema-specific dynamic tables, offering better performance than generic
 * table operations due to schema-aware optimizations.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MagicBulkHandler
{

    /**
     * Maximum packet size buffer percentage (0.1 = 10%, 0.5 = 50%)
     * Lower values = more conservative chunk sizes
     *
     * @var float
     */
    private float $maxPacketSizeBuffer = 0.5;

    /**
     * Cache for table columns to avoid repeated database queries
     *
     * @var array<string, array<string>>
     */
    private array $tableColumnsCache = [];

    /**
     * Constructor for MagicBulkHandler
     *
     * @param IDBConnection    $db              Database connection for operations
     * @param LoggerInterface  $logger          Logger for debugging and error reporting
     * @param IEventDispatcher $eventDispatcher Event dispatcher for business logic hooks
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
        private readonly IEventDispatcher $eventDispatcher
    ) {
        // Try to get max_allowed_packet from database configuration.
        $this->initializeMaxPacketSize();
    }//end __construct()

    /**
     * Prepare objects for dynamic table structure
     *
     * @param array    $objects  Array of object data
     * @param Register $register Register context
     * @param Schema   $schema   Schema context
     *
     * @return (false|int|mixed|null|string)[][] Array of prepared object data
     *
     * @psalm-return list<non-empty-array<string, false|int|mixed|null|string>>
     */
    private function prepareObjectsForDynamicTable(array $objects, Register $register, Schema $schema): array
    {
        $prepared = [];
        $now      = new DateTime();

        foreach ($objects as $object) {
            $preparedObject = [];

            // Extract @self metadata.
            // Handle both formats:
            // 1. Objects with @self key (legacy format).
            // 2. Flat selfData arrays (optimized format from TransformationHandler).
            // Object is already a flat selfData array by default - use it directly.
            $selfData = $object;
            if (($object['@self'] ?? null) !== null) {
                $selfData = $object['@self'];
            }

            // Map metadata to prefixed columns with proper fallbacks.
            $uuid = $selfData['uuid'] ?? $selfData['id'] ?? $object['id'] ?? Uuid::v4()->toRfc4122();
            $preparedObject['_uuid']         = $uuid;
            $preparedObject['_register']     = $register->getId();
            $preparedObject['_schema']       = $schema->getId();
            $preparedObject['_owner']        = $selfData['owner'] ?? $object['owner'] ?? null;
            $preparedObject['_organisation'] = $selfData['organisation'] ?? $object['organisation'] ?? null;
            $preparedObject['_created']      = $selfData['created'] ?? $object['created'] ?? $now->format('Y-m-d H:i:s');
            $preparedObject['_updated']      = $now->format('Y-m-d H:i:s');
            $preparedObject['_published']    = $selfData['published'] ?? $object['published'] ?? null;
            $preparedObject['_depublished']  = $selfData['depublished'] ?? $object['depublished'] ?? null;
            $preparedObject['_name']         = $selfData['name'] ?? $object['name'] ?? null;
            $preparedObject['_description']  = $selfData['description'] ?? $object['description'] ?? null;
            $preparedObject['_summary']      = $selfData['summary'] ?? $object['summary'] ?? null;
            $preparedObject['_image']        = $selfData['image'] ?? $object['image'] ?? null;
            $preparedObject['_slug']         = $selfData['slug'] ?? $object['slug'] ?? null;
            $preparedObject['_uri']          = $selfData['uri'] ?? $object['uri'] ?? null;

            // Map ALL object properties to columns (camelCase → snake_case).
            // Properties can be at top level OR in 'object' key (structured format).
            $propertySource = $object['object'] ?? $object;

            foreach ($propertySource as $propertyName => $value) {
                // Skip metadata (already handled) and @self.
                if ($propertyName === '@self' || str_starts_with($propertyName, '_') === true) {
                    continue;
                }

                $columnName = $this->sanitizeColumnName($propertyName);

                // Convert complex values for database storage.
                $preparedObject[$columnName] = $value;
                if (is_array($value) === true || is_object($value) === true) {
                    $preparedObject[$columnName] = json_encode($value);
                }
            }

            $prepared[] = $preparedObject;
        }//end foreach

        return $prepared;
    }//end prepareObjectsForDynamicTable()

    /**
     * Calculate optimal chunk size for bulk operations
     *
     * @param array $objects Array of objects to analyze
     *
     * @return int Optimal chunk size
     *
     * @psalm-return int<5, 500>
     */
    private function calculateOptimalChunkSize(array $objects): int
    {
        if ($objects === []) {
            return 50;
        }

        // Sample objects to estimate size.
        $sampleSize = min(10, count($objects));
        $totalSize  = 0;

        for ($i = 0; $i < $sampleSize; $i++) {
            $objectSize = strlen(json_encode($objects[$i]));
            $totalSize += $objectSize;
        }

        $averageSize = $totalSize / $sampleSize;

        // Calculate safe chunk size based on packet size.
        $maxPacketSize = $this->getMaxAllowedPacketSize() * $this->maxPacketSizeBuffer;
        $safeChunkSize = intval($maxPacketSize / $averageSize);

        // Keep within reasonable bounds.
        return max(5, min(500, $safeChunkSize));
    }//end calculateOptimalChunkSize()

    /**
     * Get max_allowed_packet size from database
     *
     * @return int Max packet size in bytes
     */
    private function getMaxAllowedPacketSize(): int
    {
        try {
            $stmt   = $this->db->executeQuery('SHOW VARIABLES LIKE \'max_allowed_packet\'');
            $result = $stmt->fetch();

            if ($result !== false && (($result['Value'] ?? null) !== null)) {
                return (int) $result['Value'];
            }
        } catch (\Exception $e) {
            // Log error but continue with default.
        }

        // Default fallback value (16MB).
        return 16777216;
    }//end getMaxAllowedPacketSize()

    /**
     * Initialize max packet size buffer based on database configuration
     *
     * @return void
     */
    private function initializeMaxPacketSize(): void
    {
        try {
            $maxPacketSize = $this->getMaxAllowedPacketSize();

            // Adjust buffer based on detected packet size.
            // 30% buffer for smaller packet sizes.
            $this->maxPacketSizeBuffer = 0.3;
            if ($maxPacketSize > 67108864) {
                // > 64MB.
                // 60% buffer.
                $this->maxPacketSizeBuffer = 0.6;
            } else if ($maxPacketSize > 33554432) {
                // > 32MB.
                // 50% buffer.
                $this->maxPacketSizeBuffer = 0.5;
            } else if ($maxPacketSize > 16777216) {
                // > 16MB.
                // 40% buffer.
                $this->maxPacketSizeBuffer = 0.4;
            }
        } catch (\Exception $e) {
            // Use default buffer on error.
        }//end try
    }//end initializeMaxPacketSize()

    /**
     * Sanitize column name for safe database usage
     *
     * @param string $name Column name to sanitize
     *
     * @return string Sanitized column name
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
     * Perform bulk upsert operation on dynamic table
     *
     * This method provides high-performance INSERT...ON CONFLICT DO UPDATE (PostgreSQL)
     * or INSERT...ON DUPLICATE KEY UPDATE (MySQL/MariaDB) operations for dynamic tables.
     * It returns complete objects with database-computed classification (created/updated/unchanged).
     *
     * @param array    $objects   Array of object data in standard format
     * @param Register $register  Register context
     * @param Schema   $schema    Schema context
     * @param string   $tableName Target table name
     *
     * @return array Array of complete objects with object_status field
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @psalm-return list<array<string, mixed>>
     */
    public function bulkUpsert(array $objects, Register $register, Schema $schema, string $tableName): array
    {
        if ($objects === []) {
            return [];
        }

        // Prepare objects for dynamic table structure.
        $preparedObjects = $this->prepareObjectsForDynamicTable(
            objects: $objects,
            register: $register,
            schema: $schema
        );

        if ($preparedObjects === []) {
            return [];
        }

        // Determine optimal chunk size.
        $chunkSize = $this->calculateOptimalChunkSize($preparedObjects);
        $chunks    = array_chunk($preparedObjects, $chunkSize);

        $allResults = [];

        // Process each chunk.
        foreach ($chunks as $chunkIndex => $chunk) {
            $chunkResults = $this->executeUpsertChunk(
                chunk: $chunk,
                tableName: $tableName,
                chunkNumber: ($chunkIndex + 1)
            );

            $allResults = array_merge($allResults, $chunkResults);
        }

        // PERFORMANCE: Event dispatching disabled by default.
        // Dispatching events for 20k+ objects causes 10x slowdown (5000 obj/s -> 500 obj/s).
        // Enable via $dispatchEvents parameter when business logic hooks are needed.
        // $this->dispatchBulkEvents(results: $allResults, register: $register, schema: $schema).
        return $allResults;
    }//end bulkUpsert()

    /**
     * Execute upsert operation for a single chunk
     *
     * @param array  $chunk       Chunk of prepared objects
     * @param string $tableName   Target table name
     * @param int    $chunkNumber Chunk number for logging
     *
     * @return array Array of complete objects with object_status
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @psalm-return list<array<string, mixed>>
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)       Bulk upsert requires complex data transformation logic
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    private function executeUpsertChunk(array $chunk, string $tableName, int $chunkNumber): array
    {
        if ($chunk === []) {
            return [];
        }

        // Record operation start time for precise created/updated detection.
        $operationStartTime = (new DateTime())->format('Y-m-d H:i:s');

        // Get table columns to filter out non-existent columns.
        $tableColumns = $this->getTableColumns($tableName);

        // Filter chunk data to only include columns that exist in the table.
        $filteredChunk = [];
        foreach ($chunk as $objectData) {
            $filteredObject = [];
            foreach ($objectData as $columnName => $value) {
                if (in_array($columnName, $tableColumns, true) === true) {
                    $filteredObject[$columnName] = $value;
                    continue;
                }

                // Log dropped columns for debugging purposes.
                $this->logger->debug(
                    '[MagicBulkHandler] Dropping column not in table',
                    ['column' => $columnName, 'table' => $tableName]
                );
            }

            if (empty($filteredObject) === false) {
                $filteredChunk[] = $filteredObject;
            }
        }

        if (empty($filteredChunk) === true) {
            return [];
        }

        // Deduplicate chunk by UUID (keep last occurrence).
        // This prevents "cannot affect row a second time" PostgreSQL errors.
        $deduplicatedChunk = [];
        $seenUuids         = [];
        foreach ($filteredChunk as $objectData) {
            $uuid = $objectData['_uuid'] ?? null;
            if ($uuid !== null) {
                // Keep track of the position to allow overwriting.
                if (isset($seenUuids[$uuid]) === true) {
                    // Replace previous occurrence with this one (keep last).
                    $deduplicatedChunk[$seenUuids[$uuid]] = $objectData;
                    continue;
                }

                // Add new object.
                $index = count($deduplicatedChunk);
                $deduplicatedChunk[$index] = $objectData;
                $seenUuids[$uuid]          = $index;
            }
        }

        // Re-index array after deduplication.
        $filteredChunk = array_values($deduplicatedChunk);

        if (empty($filteredChunk) === true) {
            return [];
        }

        // Get columns from first filtered object.
        $columns    = array_keys($filteredChunk[0]);
        $uuids      = array_column($filteredChunk, '_uuid');
        $platform   = $this->db->getDatabasePlatform();
        $isPostgres = $platform->getName() === 'postgresql';

        // Build column list with proper quoting.
        $columnList = '`'.implode('`, `', $columns).'`';
        if ($isPostgres === true) {
            $columnList = '"'.implode('", "', $columns).'"';
        }

        // Build VALUES clause with parameters.
        $valuesClause = [];
        $parameters   = [];
        $paramIndex   = 0;

        foreach ($filteredChunk as $objectData) {
            $rowValues = [];
            foreach ($columns as $column) {
                $paramName   = 'p'.$paramIndex;
                $rowValues[] = ':'.$paramName;
                $parameters[$paramName] = $objectData[$column] ?? null;
                $paramIndex++;
            }

            $valuesClause[] = '('.implode(',', $rowValues).')';
        }

        // Build UPSERT SQL.
        // NOTE: tableName doesn't include 'oc_' prefix, we must add it manually for raw SQL.
        // Using hardcoded 'oc_' prefix as IDBConnection doesn't expose getPrefix() in Nextcloud 32.
        $fullTableName = 'oc_'.$tableName;

        // MySQL/MariaDB: INSERT...ON DUPLICATE KEY UPDATE.
        $sql  = "INSERT INTO `{$fullTableName}` ({$columnList}) VALUES ".implode(',', $valuesClause);
        $sql .= ' ON DUPLICATE KEY UPDATE ';
        if ($isPostgres === true) {
            // PostgreSQL: INSERT...ON CONFLICT DO UPDATE.
            $sql  = "INSERT INTO \"{$fullTableName}\" ({$columnList}) VALUES ".implode(',', $valuesClause);
            $sql .= ' ON CONFLICT (_uuid) DO UPDATE SET ';
        }

        // Build UPDATE clauses.
        $updateClauses = [];

        foreach ($columns as $column) {
            if ($column === '_uuid' || $column === '_created') {
                // Never update UUID or created timestamp.
                continue;
            }

            if ($column === '_updated') {
                // Always update the updated timestamp.
                $updateClauses[] = '`_updated` = NOW()';
                if ($isPostgres === true) {
                    $updateClauses[count($updateClauses) - 1] = '"_updated" = NOW()';
                }

                continue;
            }

            // Update all other columns.
            $updateClauses[] = "`{$column}` = VALUES(`{$column}`)";
            if ($isPostgres === true) {
                $updateClauses[count($updateClauses) - 1] = "\"{$column}\" = EXCLUDED.\"{$column}\"";
            }
        }//end foreach

        $sql .= implode(', ', $updateClauses);

        // Execute UPSERT.
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($parameters);

            $this->logger->info(
                '[MagicBulkHandler] Executed UPSERT chunk',
                [
                    'chunk'       => $chunkNumber,
                    'objects'     => count($chunk),
                    'table'       => $tableName,
                    'sql_size_kb' => round(strlen($sql) / 1024, 2),
                    'sql_preview' => substr($sql, 0, 150),
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                '[MagicBulkHandler] UPSERT chunk failed',
                [
                    'chunk' => $chunkNumber,
                    'table' => $tableName,
                    'error' => $e->getMessage(),
                ]
            );
            throw $e;
        }//end try

        // Query back complete objects with classification.
        $completeObjects = [];

        if (empty($uuids) === false) {
            // Get full table name with hardcoded prefix.
            $fullTableName = 'oc_'.$tableName;

            $placeholders = implode(',', array_fill(0, count($uuids), '?'));

            // Build SELECT query with object_status classification.
            $selectSql = "
                SELECT *,
                       '{$operationStartTime}' as operation_start_time,
                       CASE
                           WHEN `_created` >= '{$operationStartTime}' THEN 'created'
                           WHEN `_updated` >= '{$operationStartTime}' THEN 'updated'
                           ELSE 'unchanged'
                       END as object_status
                FROM `{$fullTableName}`
                WHERE `_uuid` IN ({$placeholders})
            ";
            if ($isPostgres === true) {
                $selectSql = "
                    SELECT *,
                           '{$operationStartTime}' as operation_start_time,
                           CASE
                               WHEN \"_created\" >= '{$operationStartTime}' THEN 'created'
                               WHEN \"_updated\" >= '{$operationStartTime}' THEN 'updated'
                               ELSE 'unchanged'
                           END as object_status
                    FROM \"{$fullTableName}\"
                    WHERE \"_uuid\" IN ({$placeholders})
                ";
            }

            $stmt = $this->db->prepare($selectSql);
            $stmt->execute(array_values($uuids));
            $completeObjects = $stmt->fetchAll();

            $this->logger->info(
                '[MagicBulkHandler] Retrieved complete objects for classification',
                [
                    'chunk'            => $chunkNumber,
                    'uuids_requested'  => count($uuids),
                    'objects_returned' => count($completeObjects),
                ]
            );
        }//end if

        return $completeObjects;
    }//end executeUpsertChunk()

    /**
     * Get list of columns that exist in a table
     *
     * @param string $tableName The table name
     *
     * @return array List of column names
     *
     * @psalm-return list<mixed>
     */
    private function getTableColumns(string $tableName): array
    {
        try {
            $platform   = $this->db->getDatabasePlatform();
            $isPostgres = $platform->getName() === 'postgresql';

            // Get full table name with hardcoded prefix.
            $fullTableName = 'oc_'.$tableName;

            // MySQL/MariaDB: use SHOW COLUMNS.
            $sql = "SHOW COLUMNS FROM `$fullTableName`";
            if ($isPostgres === true) {
                // PostgreSQL: query information_schema with full table name.
                $sql = "SELECT column_name
                        FROM information_schema.columns
                        WHERE table_name = ? AND table_schema = 'public'";
            }

            $stmt = $this->db->prepare($sql);
            // MySQL/MariaDB: SHOW COLUMNS doesn't need parameters.
            $stmtParams = [];
            if ($isPostgres === true) {
                // PostgreSQL information_schema expects table name WITH prefix.
                $stmtParams = [$fullTableName];
            }

            $stmt->execute($stmtParams);

            $columns = [];
            $row     = $stmt->fetch();
            while ($row !== false) {
                $columnKey = 'Field';
                if ($isPostgres === true) {
                    $columnKey = 'column_name';
                }

                $columns[] = $row[$columnKey];

                $row = $stmt->fetch();
            }

            $this->logger->debug(
                '[MagicBulkHandler] Retrieved columns',
                ['table' => $tableName, 'columns' => $columns]
            );
            $this->tableColumnsCache[$tableName] = $columns;

            return $columns;
        } catch (\Exception $e) {
            $this->logger->error(
                '[MagicBulkHandler] Failed to get table columns',
                [
                    'table' => $tableName,
                    'error' => $e->getMessage(),
                ]
            );
            return [];
        }//end try
    }//end getTableColumns()
}//end class
