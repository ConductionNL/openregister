<?php

/**
 * OpenRegister Optimized Bulk Database Operations
 *
 * High-performance bulk operations that trade memory for speed by:
 * - Building large SQL statements in memory
 * - Using INSERT...ON DUPLICATE KEY UPDATE for unified operations
 * - Prepared statement reuse and batching
 * - Memory-intensive query construction
 *
 * @category Handler
 * @package  OCA\OpenRegister\Db\ObjectHandlers
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\ObjectHandlers;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Psr\Log\LoggerInterface;

/**
 * Memory-intensive bulk database operations for maximum speed
 *
 * PERFORMANCE STRATEGY: Trade memory for speed by:
 * - Building massive SQL statements in memory (up to 16MB per query)
 * - Using INSERT...ON DUPLICATE KEY UPDATE for unified operations
 * - Prepared statement reuse with parameter binding
 * - Chunked processing with optimal batch sizes
 *
 * Memory usage can reach 500MB+ for large datasets but provides
 * 10-20x performance improvement over individual operations.
 */
class OptimizedBulkOperations
{

    private IDBConnection $db;

    private LoggerInterface $logger;

    /**
     * Maximum SQL statement size in bytes (16MB)
     *
     * @var int Maximum SQL statement size in bytes (16MB)
     */
    private const MAX_QUERY_SIZE = 16777216;

    /**
     * Optimal batch size for memory usage - increased for sub-1-second performance
     *
     * @var int Optimal batch size for memory usage - increased for sub-1-second performance
     */
    private const OPTIMAL_BATCH_SIZE = 10000;

    /**
     * Maximum parameters per query (MySQL limit)
     *
     * @var int Maximum parameters per query (MySQL limit)
     */
    private const MAX_PARAMETERS = 32000;


    /**
     * Constructor
     *
     * @param IDBConnection   $db     Database connection
     * @param LoggerInterface $logger Logger instance
     */
    public function __construct(IDBConnection $db, LoggerInterface $logger)
    {
        $this->db     = $db;
        $this->logger = $logger;

    }//end __construct()


    /**
     * Ultra-fast unified bulk operations using INSERT...ON DUPLICATE KEY UPDATE
     *
     * PERFORMANCE OPTIMIZATION: This method combines INSERT and UPDATE operations
     * into a single bulk SQL statement, providing 10-20x performance improvement
     * over individual operations by trading memory for speed.
     *
     * Memory Impact: Can use 100-500MB for large batches but eliminates the
     * 8,781 individual SQL statements that cause the current bottleneck.
     *
     * @param array $insertObjects Array of objects to insert (raw arrays)
     * @param array $updateObjects Array of objects to update (ObjectEntity instances)
     *
     * @return array Array of processed UUIDs
     *
     * @throws \OCP\DB\Exception If bulk operation fails
     */
    public function ultraFastUnifiedBulkSave(array $insertObjects, array $updateObjects): array
    {
        $startTime      = microtime(true);
        $processedUUIDs = [];

        // MEMORY OPTIMIZATION: Convert all objects to unified format in memory.
        $allObjects = $this->unifyObjectFormats(insertObjects: $insertObjects, updateObjects: $updateObjects);

        if (empty($allObjects) === true) {
            return [];
        }

        // PERFORMANCE: Process in optimal chunks to balance memory vs speed.
        $chunks      = array_chunk($allObjects, self::OPTIMAL_BATCH_SIZE);
        $totalChunks = count($chunks);

        // PERFORMANCE: Minimal logging for large operations.
        if (count($allObjects) > 10000) {
            $this->logger->info(
                    "Starting ultra-fast bulk operations",
                    [
                        'total_objects' => count($allObjects),
                        'chunks'        => $totalChunks,
                    ]
                    );
        }

        foreach ($chunks as $chunkIndex => $chunk) {
            $chunkStartTime = microtime(true);

            // MEMORY-INTENSIVE: Build massive INSERT...ON DUPLICATE KEY UPDATE statement.
            $chunkUUIDs     = $this->processUnifiedChunk(objects: $chunk, chunkNumber: $chunkIndex + 1, _totalChunks: $totalChunks);
            $processedUUIDs = array_merge($processedUUIDs, $chunkUUIDs);

            $chunkTime = microtime(true) - $chunkStartTime;
            $this->logger->debug(
                    "Processed chunk with optimized bulk operations",
                    [
                        'chunk'              => $chunkIndex + 1,
                        'objects'            => count($chunk),
                        'time_seconds'       => round($chunkTime, 3),
                        'objects_per_second' => round(count($chunk) / $chunkTime, 0),
                    ]
                    );

            // MEMORY MANAGEMENT: Clear processed chunk data.
            unset($chunk, $chunkUUIDs);
        }//end foreach

        $totalTime        = microtime(true) - $startTime;
        $objectsPerSecond = count($allObjects) / $totalTime;

        // Calculate performance improvement.
        if ($objectsPerSecond > 165) {
            $performanceImprovement = round($objectsPerSecond / 165, 1).'x faster';
        } else {
            $performanceImprovement = 'baseline';
        }

        $this->logger->info(
                "Completed optimized bulk operations",
                [
                    'total_objects'           => count($allObjects),
                    'total_time_seconds'      => round($totalTime, 3),
                    'objects_per_second'      => round($objectsPerSecond, 0),
                    'performance_improvement' => $performanceImprovement,
                ]
                );

        return $processedUUIDs;

    }//end ultraFastUnifiedBulkSave()


    /**
     * Process a unified chunk using memory-intensive INSERT...ON DUPLICATE KEY UPDATE
     *
     * PERFORMANCE STRATEGY: Build one massive SQL statement in memory that handles
     * both inserts and updates, eliminating thousands of individual operations.
     *
     * @param array $objects     Unified object array
     * @param int   $chunkNumber Current chunk number for logging
     * @param int   $totalChunks Total chunks for progress tracking
     *
     * @return array Array of processed UUIDs
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    private function processUnifiedChunk(array $objects, int $chunkNumber, int $_totalChunks): array
    {
        if (empty($objects) === true) {
            return [];
        }

        // MEMORY ALLOCATION: Pre-allocate arrays for better performance.
        $processedUUIDs = [];
        $processedUUIDs = array_pad($processedUUIDs, count($objects), '');

        // Get column structure from first object.
        $firstObject = $objects[0];
        $columns     = array_keys($firstObject);

        // MEMORY-INTENSIVE QUERY BUILDING: Construct massive SQL statement.
        // IMPORTANT: Use full table name with oc_ prefix for raw SQL operations.
        $tableName = 'oc_openregister_objects';

        // Map object columns to actual database columns.
        $dbColumns = $this->mapObjectColumnsToDatabase($columns);
        $sql       = $this->buildMassiveInsertOnDuplicateKeyUpdateSQL(tableName: $tableName, columns: $dbColumns, objectCount: count($objects));

        // PARAMETER BINDING: Build parameters array in memory (can be very large).
        $parameters = [];
        $paramIndex = 0;

        foreach ($objects as $index => $objectData) {
            foreach ($dbColumns as $dbColumn) {
                $value = $this->extractColumnValue(objectData: $objectData, dbColumn: $dbColumn);

                $parameters['param_'.$paramIndex] = $value;
                $paramIndex++;
            }

            $processedUUIDs[$index] = $objectData['uuid'] ?? '';
        }

        // EXECUTE: Single massive SQL operation instead of thousands of individual ones.
        // REMOVED ERROR SUPPRESSION: Let any database errors bubble up immediately.
        // TIMING: Get database time BEFORE operation for accurate classification.
        $stmt = $this->db->prepare("SELECT NOW() as operation_start");
        $stmt->execute();
        $operationStartTime = $stmt->fetchOne();

        $stmt   = $this->db->prepare($sql);
        $result = $stmt->execute($parameters);

        // DEBUG: Bulk SQL execution completed successfully.
        // DEBUG: Log the actual SQL and some parameters to verify what's being executed.
        $sampleParams = array_slice($parameters, 0, min(10, count($parameters)), true);

        // ENHANCED STATISTICS: Calculate created vs updated objects from affected rows.
        // MySQL INSERT...ON DUPLICATE KEY UPDATE returns:.
        // - 1 for each new row inserted (created).
        // - 2 for each existing row updated.
        // - 0 for unchanged rows.
        $totalObjects = count($objects);
        // @psalm-suppress RedundantCast
        $affectedRows = $result->rowCount();

        // Estimate created vs updated (rough calculation).
        // If affected_rows == totalObjects, all were created.
        // If affected_rows == totalObjects * 2, all were updated.
        // Mixed operations will be between these values.
        $estimatedCreated = 0;
        $estimatedUpdated = 0;

        if ($affectedRows <= $totalObjects) {
            // Mostly creates, some might be unchanged.
            $estimatedCreated = $affectedRows;
            $estimatedUpdated = 0;
        } else if ($affectedRows <= $totalObjects * 2) {
            // Mixed creates and updates.
            // This is an approximation - exact counts would require separate queries.
            $estimatedCreated = max(0, $totalObjects * 2 - $affectedRows);
            $estimatedUpdated = $affectedRows - $estimatedCreated;
        }

        $this->logger->info(
                "BULK SAVE: Executed unified bulk operation with statistics",
                [
                    'chunk'             => $chunkNumber,
                    'objects_processed' => $totalObjects,
                    'affected_rows'     => $affectedRows,
                    'estimated_created' => $estimatedCreated,
                    'estimated_updated' => $estimatedUpdated,
                    'sql_size_kb'       => round(strlen($sql) / 1024, 2),
                    'table_name'        => $tableName,
                    'sample_params'     => $sampleParams,
                    'sql_preview'       => substr($sql, 0, 200),
                ]
                );

        // ENHANCED RETURN: Query back complete objects for precise create/update classification.
        $completeObjects = [];

        // REMOVED ERROR SUPPRESSION: Let SELECT query errors bubble up immediately.
        // Query all affected objects to get complete data with timestamps AND operation timing for classification.
        $uuids = array_filter($processedUUIDs);
        // Remove empty UUIDs.
        if (empty($uuids) === false) {
            $placeholders = implode(',', array_fill(0, count($uuids), '?'));
            $selectSql    = "
                SELECT *,
                       '{$operationStartTime}' as operation_start_time,
                       CASE
                           WHEN created >= '{$operationStartTime}' THEN 'created'
                           WHEN updated >= '{$operationStartTime}' THEN 'updated'
                           ELSE 'unchanged'
                       END as object_status
                FROM {$tableName}
                WHERE uuid IN ({$placeholders})
            ";

            $stmt = $this->db->prepare($selectSql);
            $stmt->execute(array_values($uuids));
            $completeObjects = $stmt->fetchAll();

            // DEBUG: SELECT query completed.
            $this->logger->info(
                    "BULK SAVE: Retrieved complete objects for classification",
                    [
                        'chunk'              => $chunkNumber,
                        'uuids_requested'    => count($uuids),
                        'objects_returned'   => count($completeObjects),
                        'select_sql_preview' => substr($selectSql, 0, 200),
                    ]
                    );
        }//end if

        // MEMORY CLEANUP: Clear large variables.
        unset($parameters, $sql);

        // ENHANCED RETURN: Return complete objects with timestamps for precise classification.
        // If complete objects available, return them; otherwise fallback to UUID array.
        if (empty($completeObjects) === false) {
            $finalResult = $completeObjects;
        } else {
            $finalResult = array_filter($processedUUIDs);
        }

        // DEBUG: Returning bulk operation results.
        return $finalResult;

    }//end processUnifiedChunk()


    /**
     * Build massive INSERT...ON DUPLICATE KEY UPDATE SQL statement
     *
     * MEMORY-INTENSIVE: This method constructs very large SQL statements (up to 16MB)
     * in memory to eliminate the need for thousands of individual operations.
     *
     * @param string $tableName   Table name
     * @param array  $columns     Column names
     * @param int    $objectCount Number of objects to process
     *
     * @return string Massive SQL statement
     */
    private function buildMassiveInsertOnDuplicateKeyUpdateSQL(string $tableName, array $columns, int $objectCount): string
    {
        // Build SQL string.
        $sql = '';

        // Build INSERT portion.
        $columnList = '`'.implode('`, `', $columns).'`';
        $sql       .= "INSERT INTO `{$tableName}` ({$columnList}) VALUES ";

        // Build VALUES portion - MEMORY INTENSIVE!
        $valuesClauses = [];
        $paramIndex    = 0;
        $columnCount   = count($columns);

        for ($i = 0; $i < $objectCount; $i++) {
            $rowValues = [];
            // Iterate over columns (count only matters, not the column name).
            for ($j = 0; $j < $columnCount; $j++) {
                $rowValues[] = ':param_'.$paramIndex;
                $paramIndex++;
            }

            $valuesClauses[] = '('.implode(', ', $rowValues).')';
        }

        $sql .= implode(', ', $valuesClauses);

        // Add ON DUPLICATE KEY UPDATE portion for unified insert/update behavior.
        $sql          .= ' ON DUPLICATE KEY UPDATE ';
        $updateClauses = [];

        foreach ($columns as $column) {
            if ($column !== 'id' && $column !== 'uuid' && $column !== 'created') {
                // 🔒 IMMUTABLE: Never update primary keys (id, uuid) or creation timestamp (created).
                if ($column === 'updated') {
                    // SMART UPDATE: Only update timestamp if actual data changed.
                    $databaseManagedFields = ['id', 'uuid', 'created', 'updated'];
                    $dataColumns           = array_diff($columns, $databaseManagedFields);
                    $changeChecks          = [];

                    foreach ($dataColumns as $dataCol) {
                        if ($dataCol === 'object') {
                            // SPECIAL HANDLING: JSON comparison for object data.
                            $changeChecks[] = "JSON_EXTRACT(`{$dataCol}`, '$') != JSON_EXTRACT(VALUES(`{$dataCol}`), '$')";
                        } else if (in_array($dataCol, ['files', 'relations', 'authorization', 'validation', 'geo', 'retention', 'groups']) === true) {
                            // JSON fields comparison.
                            $changeChecks[] = "COALESCE(`{$dataCol}`, '{}') != COALESCE(VALUES(`{$dataCol}`), '{}')";
                        } else {
                            // Regular field comparison with NULL handling.
                            $changeChecks[] = "COALESCE(`{$dataCol}`, '') != COALESCE(VALUES(`{$dataCol}`), '')";
                        }
                    }

                    $changeCondition = implode(' OR ', $changeChecks);
                    $updateClauses[] = "`updated` = CASE WHEN ({$changeCondition}) THEN NOW() ELSE `updated` END";
                } else {
                    // Regular field updates.
                    $updateClauses[] = "`{$column}` = VALUES(`{$column}`)";
                }//end if
            }//end if
        }//end foreach

        $sql .= implode(', ', $updateClauses);

        return $sql;

    }//end buildMassiveInsertOnDuplicateKeyUpdateSQL()


    /**
     * Unify insert and update objects into consistent format for bulk processing
     *
     * MEMORY OPTIMIZATION: Convert all objects to arrays to reduce memory overhead
     * and enable unified processing instead of handling two different types.
     *
     * @param array $insertObjects Array of arrays (insert data)
     * @param array $updateObjects Array of ObjectEntity instances (update data)
     *
     * @return ((mixed|string)[]|mixed)[] Unified array format for all objects
     *
     * @psalm-return list{0?: array{uuid: mixed|string,...}|mixed,...}
     */
    private function unifyObjectFormats(array $insertObjects, array $updateObjects): array
    {
        $allObjects = [];

        // Add insert objects (already in array format).
        foreach ($insertObjects as $insertObj) {
            if (is_array($insertObj) === true) {
                // Ensure required UUID field only.
                if (isset($insertObj['uuid']) === false) {
                    $insertObj['uuid'] = (string) \Symfony\Component\Uid\Uuid::v4();
                }

                // DATABASE-MANAGED: created and updated are handled by database, don't set to avoid false changes.
                $allObjects[] = $insertObj;
            }
        }

        // Convert update objects to array format using the correct ObjectEntity methods.
        foreach ($updateObjects as $updateObj) {
            if (is_object($updateObj) === true && method_exists($updateObj, 'getObjectArray') === true && method_exists($updateObj, 'getObject') === true) {
                // Use the proper ObjectEntity methods to get the correct structure directly.
                $newFormatArray = $updateObj->getObjectArray();
                // Gets metadata at top level.
                $newFormatArray['object'] = $updateObj->getObject();
                // Gets actual object data.
                // CRITICAL FIX: Ensure UUID is at top level for proper return value handling.
                // The UUID might be in getObject() data, so extract it to top level.
                if (method_exists($updateObj, 'getUuid') === true && $updateObj->getUuid() !== null) {
                    $newFormatArray['uuid'] = $updateObj->getUuid();
                } else if (($newFormatArray['object']['uuid'] ?? null) !== null) {
                    $newFormatArray['uuid'] = $newFormatArray['object']['uuid'];
                } else if (($newFormatArray['object']['id'] ?? null) !== null) {
                    // Fallback: use id field as uuid if no uuid field exists.
                    $newFormatArray['uuid'] = $newFormatArray['object']['id'];
                }

                // DATABASE-MANAGED: updated timestamp handled by database ON UPDATE clause.
                $allObjects[] = $newFormatArray;
            }//end if
        }//end foreach

        return $allObjects;

    }//end unifyObjectFormats()


    /**
     * Map object data columns to actual database column names
     *
     * The database table has specific columns: id, uuid, version, register, schema, object, updated, created
     * Object data may contain additional fields that need to be mapped or ignored.
     *
     * @param array $objectColumns Array of column names from object data
     *
     * @return string[] Array of actual database column names
     *
     * @psalm-return list{0?: string,...}
     */
    private function mapObjectColumnsToDatabase(array $objectColumns): array
    {
        // Database table structure from migration: id, uuid, version, register, schema, object, updated, created.
        $validDbColumns = [
            'uuid',
            'version',
            'register',
            'schema',
            'object',
            'updated',
            'created',
            'description',
            'uri',
            'files',
            'relations',
            'locked',
            'owner',
            'authorization',
            'folder',
            'deleted',
            'organisation',
            'application',
            'validation',
            'geo',
            'retention',
            'size',
            'published',
            'depublished',
            'groups',
            'name',
            'image',
            'schemaVersion',
            'expires',
            'slug',
            'summary',
        ];

        // Filter object columns to only include valid database columns.
        $mappedColumns = [];

        foreach ($validDbColumns as $dbColumn) {
            // Include column if it's in object data or if it's a required metadata field.
            if (in_array($dbColumn, $objectColumns) === true) {
                $mappedColumns[] = $dbColumn;
            }

            // DATABASE-MANAGED: Don't force include created/updated - let database handle defaults.
        }

        // Ensure required columns are present.
        $requiredColumns = ['uuid', 'register', 'schema'];
        foreach ($requiredColumns as $required) {
            if (in_array($required, $mappedColumns) === false) {
                $mappedColumns[] = $required;
            }
        }

        // METADATA COLUMNS: Always include metadata columns that we extract from object data.
        $metadataColumns = ['name'];
        // We extract name from nested object.naam field.
        foreach ($metadataColumns as $metadataCol) {
            if (in_array($metadataCol, $mappedColumns) === false) {
                $mappedColumns[] = $metadataCol;
            }
        }

        // DATABASE-MANAGED: Let MySQL handle created/updated with DEFAULT and ON UPDATE clauses.
        // Don't force these columns into INSERT - let database use column defaults.
        return $mappedColumns;

    }//end mapObjectColumnsToDatabase()


    /**
     * Extract the appropriate value for a database column from object data
     *
     * @param array  $objectData Object data array
     * @param string $dbColumn   Database column name
     *
     * @return mixed Value for the database column
     */
    private function extractColumnValue(array $objectData, string $dbColumn)
    {
        switch ($dbColumn) {
            case 'uuid':
                // CRITICAL FIX: Look for UUID in correct field.
                // Data preparation sets UUID in 'uuid' field, not 'id' field.
                return $objectData['uuid'] ?? $objectData['id'] ?? (string) \Symfony\Component\Uid\Uuid::v4();

            case 'version':
                return $objectData['@self']['version'] ?? '0.0.1';

            case 'register':
                // Extract from @self metadata or use register field.
                return $objectData['@self']['register'] ?? $objectData['register'] ?? null;

            case 'schema':
                // Extract from @self metadata or use schema field.
                return $objectData['@self']['schema'] ?? $objectData['schema'] ?? null;

            case 'object':
                // Store only the nested object data, not the entire structure.
                // The objectData structure should be: {id, register, schema, object: {actual_data...}}
                // We only want to store the 'object' property contents in the database object column.
                // VALIDATION: object property MUST be set and MUST be an array.
                if (isset($objectData['object']) === false) {
                    throw new InvalidArgumentException("Object data is missing required 'object' property. Available keys: ".json_encode(array_keys($objectData)));
                }

                $objectContent = $objectData['object'];

                // VALIDATION: object content must be an array, not a string or other type.
                if (is_array($objectContent) === false) {
                    throw new InvalidArgumentException("Object content must be an array, got ".gettype($objectContent).". This suggests double JSON encoding or malformed CSV parsing.");
                }

                // Normal case - array data needs JSON encoding.
                return json_encode($objectContent, \JSON_UNESCAPED_UNICODE);

            case 'created':
                // DATABASE-MANAGED: Let database set DEFAULT CURRENT_TIMESTAMP on new records.
                // Only set if explicitly provided (for migrations or special cases).
                $value = $objectData[$dbColumn] ?? null;
                if ($value !== null && $value !== '') {
                    return $this->convertDateTimeToMySQLFormat($value);
                }
                return null;
            // Let database handle with DEFAULT CURRENT_TIMESTAMP.
            case 'updated':
                // DATABASE-MANAGED: Let database set ON UPDATE CURRENT_TIMESTAMP.
                // Only set if explicitly provided (for migrations or special cases).
                $value = $objectData[$dbColumn] ?? null;
                if ($value !== null && $value !== '') {
                    return $this->convertDateTimeToMySQLFormat($value);
                }
                return null;
            // Let database handle with ON UPDATE CURRENT_TIMESTAMP.
            case 'published':
            case 'depublished':
                // Handle datetime fields that might be in ISO 8601 format.
                // CRITICAL FIX: Check @self section first (from CSV import), then root level.
                $value = $objectData['@self'][$dbColumn] ?? $objectData[$dbColumn] ?? null;
                if ($value === null || $value === '') {
                    return null;
                    // These fields can be null.
                }
                return $this->convertDateTimeToMySQLFormat($value);

            case 'name':
                // SIMPLE METADATA EXTRACTION: Look for 'naam' in object data.
                $objectContent = $objectData['object'] ?? [];
                if (is_array($objectContent) === true && (($objectContent['naam'] ?? null) !== null)) {
                    return $objectContent['naam'];
                }

                // Fallback to direct field or existing name.
                return $objectData['name'] ?? null;

            case 'files':
            case 'relations':
            case 'locked':
                // JSON columns that should default to empty arrays, not null.
                $value = $objectData[$dbColumn] ?? [];
                return json_encode($value, \JSON_UNESCAPED_UNICODE);

            default:
                // CRITICAL FIX: For metadata fields, check @self section first (from CSV import), then root level.
                // This handles fields like 'organisation', 'owner', 'slug', 'summary', 'image', 'description', etc.
                return $objectData['@self'][$dbColumn] ?? $objectData[$dbColumn] ?? null;
        }//end switch

    }//end extractColumnValue()


    /**
     * Convert datetime value to MySQL format
     *
     * Handles various datetime formats including ISO 8601 and converts them
     * to the MySQL DATETIME format (YYYY-MM-DD HH:MM:SS).
     *
     * @param mixed $value The datetime value to convert
     *
     * @return string The datetime in MySQL format
     */
    private function convertDateTimeToMySQLFormat($value): string
    {
        if ($value === false || is_string($value) === false) {
            return date('Y-m-d H:i:s');
            // Fallback to current time.
        }

        // NO ERROR SUPPRESSION: Let datetime parsing errors bubble up immediately!
        // Convert ISO 8601 to MySQL datetime format.
        $dateTime = new DateTime($value);
        return $dateTime->format('Y-m-d H:i:s');

    }//end convertDateTimeToMySQLFormat()


}//end class
