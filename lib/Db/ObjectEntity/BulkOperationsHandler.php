<?php

/**
 * BulkOperationsHandler
 *
 * Handler for bulk database operations on ObjectEntity.
 * Extracted from ObjectEntityMapper to follow Single Responsibility Principle.
 *
 * This handler contains performance-critical bulk operation logic optimized for:
 * - Large-scale inserts/updates (1000+ objects)
 * - Transaction management
 * - Memory efficiency
 * - max_allowed_packet error prevention
 * - Database connection health monitoring
 *
 * @category Nextcloud
 * @package  OpenRegister
 * @author   Conduction BV <info@conduction.nl>
 * @license  EUPL-1.2 https://opensource.org/licenses/EUPL-1.2
 * @link     https://www.conduction.nl
 */

namespace OCA\OpenRegister\Db\ObjectEntity;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectHandlers\OptimizedBulkOperations;
use OCP\DB\Exception as OcpDbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Handles bulk database operations for ObjectEntity.
 *
 * This handler manages:
 * - Bulk insert/update/delete operations
 * - Chunk size optimization for large datasets
 * - Transaction management and rollback
 * - Publish/depublish bulk operations
 * - Schema/register-based bulk operations
 *
 * @category Nextcloud
 * @package  OpenRegister
 * @author   Conduction BV <info@conduction.nl>
 * @license  EUPL-1.2 https://opensource.org/licenses/EUPL-1.2
 * @link     https://www.conduction.nl
 */
class BulkOperationsHandler
{

    /**
     * Database connection.
     *
     * @var IDBConnection
     */
    private IDBConnection $db;

    /**
     * Logger instance.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Table name for objects.
     *
     * @var string
     */
    private string $tableName;

    /**
     * Max packet size buffer percentage (safety margin).
     *
     * @var float
     */
    private float $maxPacketSizeBuffer = 0.25;
    // Use 25% of max_allowed_packet for safety.

    /**
     * Query builder handler for max_allowed_packet queries.
     *
     * @var QueryBuilderHandler
     */
    private QueryBuilderHandler $queryBuilderHandler;

    /**
     * Event dispatcher for business logic hooks (optional).
     *
     * @var IEventDispatcher|null
     */
    private ?IEventDispatcher $eventDispatcher = null;

    /**
     * Constructor.
     *
     * @param IDBConnection       $db                  Database connection.
     * @param LoggerInterface     $logger              Logger instance.
     * @param QueryBuilderHandler $queryBuilderHandler Query builder handler.
     * @param string              $tableName           Table name for objects.
     * @param IEventDispatcher    $eventDispatcher     Event dispatcher for business logic hooks.
     */
    public function __construct(
        IDBConnection $db,
        LoggerInterface $logger,
        QueryBuilderHandler $queryBuilderHandler,
        string $tableName='openregister_objects',
        IEventDispatcher $eventDispatcher=null
    ) {
        $this->db                  = $db;
        $this->logger              = $logger;
        $this->queryBuilderHandler = $queryBuilderHandler;
        $this->tableName           = $tableName;
        $this->eventDispatcher     = $eventDispatcher;
    }//end __construct()

    /**
     * ULTRA PERFORMANCE: Memory-intensive unified bulk save operation.
     *
     * This method provides maximum performance by delegating to OptimizedBulkOperations.
     * Target Performance: 2000+ objects/second.
     *
     * @param array $insertObjects Array of arrays (insert data).
     * @param array $updateObjects Array of ObjectEntity instances (update data).
     *
     * @return array Array of processed UUIDs.
     */
    public function ultraFastBulkSave(array $insertObjects=[], array $updateObjects=[]): array
    {
        // Only create OptimizedBulkOperations if we have an eventDispatcher.
        // This maintains backward compatibility.
        if ($this->eventDispatcher !== null) {
            $optimizedHandler = new OptimizedBulkOperations(
                db: $this->db,
                logger: $this->logger,
                eventDispatcher: $this->eventDispatcher
            );
        } else {
            // Fallback without event dispatcher (legacy mode).
            $optimizedHandler = new OptimizedBulkOperations(
                db: $this->db,
                logger: $this->logger,
                eventDispatcher: \OC::$server->get(IEventDispatcher::class)
            );
        }

        return $optimizedHandler->ultraFastUnifiedBulkSave(
            insertObjects: $insertObjects,
            updateObjects: $updateObjects
        );
    }//end ultraFastBulkSave()

    /**
     * Perform bulk delete operations on objects by UUID.
     *
     * Handles both soft delete and hard delete based on the hardDelete flag.
     *
     * @param array $uuids      Array of object UUIDs to delete.
     * @param bool  $hardDelete Whether to force hard delete (default: false).
     *
     * @return array Array of UUIDs of deleted objects.
     */
    public function deleteObjects(array $uuids=[], bool $hardDelete=false): array
    {
        if (empty($uuids) === true) {
            return [];
        }

        $deletedObjectIds   = [];
        $transactionStarted = false;

        try {
            // Check if there's already an active transaction.
            if ($this->db->inTransaction() === false) {
                $this->db->beginTransaction();
                $transactionStarted = true;
            }

            // Bulk delete objects with hard delete flag.
            $deletedIds       = $this->bulkDelete(
                uuids: $uuids,
                hardDelete: $hardDelete
            );
            $deletedObjectIds = array_merge($deletedObjectIds, $deletedIds);

            // Commit transaction only if we started it.
            if ($transactionStarted === true) {
                $this->db->commit();
            }
        } catch (Exception $e) {
            // Rollback transaction only if we started it.
            if ($transactionStarted === true) {
                $this->db->rollBack();
            }

            throw $e;
        }//end try

        return $deletedObjectIds;
    }//end deleteObjects()

    /**
     * Perform bulk publish operations on objects by UUID.
     *
     * @param array          $uuids    Array of object UUIDs to publish.
     * @param \DateTime|bool $datetime Optional datetime for publishing (false to unset).
     *
     * @return array Array of UUIDs of published objects.
     */
    public function publishObjects(array $uuids=[], \DateTime|bool $datetime=true): array
    {
        if (empty($uuids) === true) {
            return [];
        }

        $publishedObjectIds = [];
        $transactionStarted = false;

        try {
            if ($this->db->inTransaction() === false) {
                $this->db->beginTransaction();
                $transactionStarted = true;
            }

            $publishedIds       = $this->bulkPublish(
                uuids: $uuids,
                datetime: $datetime
            );
            $publishedObjectIds = array_merge($publishedObjectIds, $publishedIds);

            if ($transactionStarted === true) {
                $this->db->commit();
            }
        } catch (Exception $e) {
            if ($transactionStarted === true) {
                $this->db->rollBack();
            }

            throw $e;
        }//end try

        return $publishedObjectIds;
    }//end publishObjects()

    /**
     * Perform bulk depublish operations on objects by UUID.
     *
     * @param array          $uuids    Array of object UUIDs to depublish.
     * @param \DateTime|bool $datetime Optional datetime for depublishing (false to unset).
     *
     * @return array Array of UUIDs of depublished objects.
     */
    public function depublishObjects(array $uuids=[], \DateTime|bool $datetime=true): array
    {
        if (empty($uuids) === true) {
            return [];
        }

        $depublishedObjectIds = [];
        $transactionStarted   = false;

        try {
            if ($this->db->inTransaction() === false) {
                $this->db->beginTransaction();
                $transactionStarted = true;
            }

            $depublishedIds       = $this->bulkDepublish(
                uuids: $uuids,
                datetime: $datetime
            );
            $depublishedObjectIds = array_merge($depublishedObjectIds, $depublishedIds);

            if ($transactionStarted === true) {
                $this->db->commit();
            }
        } catch (Exception $e) {
            if ($transactionStarted === true) {
                $this->db->rollBack();
            }

            throw $e;
        }//end try

        return $depublishedObjectIds;
    }//end depublishObjects()

    /**
     * Publish all objects belonging to a specific schema.
     *
     * @param int  $schemaId   The ID of the schema whose objects should be published.
     * @param bool $publishAll Whether to publish all objects (default: false).
     *
     * @return (array|int)[] Array containing statistics about the publishing operation.
     *
     * @throws \Exception If the publishing operation fails.
     *
     * @psalm-return array{published_count: int<0, max>,
     *     published_uuids: list<mixed>, schema_id: int}
     */
    public function publishObjectsBySchema(int $schemaId, bool $publishAll=false): array
    {
        // First, get all UUIDs for objects belonging to this schema.
        $qb = $this->db->getQueryBuilder();
        $qb->select('uuid')
            ->from($this->tableName)
            ->where($qb->expr()->eq('schema', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)));

        // When publishAll is false, only include objects that are not published.
        if ($publishAll === false) {
            $qb->andWhere($qb->expr()->isNull('published'));
        }

        $result = $qb->executeQuery();
        $uuids  = [];
        while (($row = $result->fetch()) !== false) {
            $uuids[] = $row['uuid'];
        }

        $result->closeCursor();

        if (empty($uuids) === true) {
            return [
                'published_count' => 0,
                'published_uuids' => [],
                'schema_id'       => $schemaId,
            ];
        }

        return [
            'published_count' => count($uuids),
            'published_uuids' => $uuids,
            'schema_id'       => $schemaId,
        ];
    }//end publishObjectsBySchema()

    /**
     * Delete all objects belonging to a specific schema.
     *
     * @param int  $schemaId   The ID of the schema whose objects should be deleted.
     * @param bool $hardDelete Whether to force hard delete (default: false).
     *
     * @return (array|int)[] Array containing statistics about the deletion operation.
     *
     * @throws \Exception If the deletion operation fails.
     *
     * @psalm-return array{deleted_count: int<0, max>, deleted_uuids: array,
     *     schema_id: int}
     */
    public function deleteObjectsBySchema(int $schemaId, bool $hardDelete=false): array
    {
        // First, get all UUIDs for objects belonging to this schema.
        $qb = $this->db->getQueryBuilder();
        $qb->select('uuid')
            ->from($this->tableName)
            ->where($qb->expr()->eq('schema', $qb->createNamedParameter($schemaId, IQueryBuilder::PARAM_INT)));

        // When hardDelete is false, only include objects that are not soft-deleted.
        if ($hardDelete === false) {
            $qb->andWhere($qb->expr()->isNull('deleted'));
        }

        $result = $qb->executeQuery();
        $uuids  = [];
        while (($row = $result->fetch()) !== false) {
            $uuids[] = $row['uuid'];
        }

        $result->closeCursor();

        if (empty($uuids) === true) {
            return [
                'deleted_count' => 0,
                'deleted_uuids' => [],
                'schema_id'     => $schemaId,
            ];
        }

        // Use the existing bulk delete method with hard delete flag.
        $deletedUuids = $this->deleteObjects(
            uuids: $uuids,
            hardDelete: $hardDelete
        );

        return [
            'deleted_count' => count($deletedUuids),
            'deleted_uuids' => $deletedUuids,
            'schema_id'     => $schemaId,
        ];
    }//end deleteObjectsBySchema()

    /**
     * Delete all objects belonging to a specific register.
     *
     * @param int $registerId The ID of the register whose objects should be deleted.
     *
     * @return (array|int)[] Array containing statistics about the deletion operation.
     *
     * @throws \Exception If the deletion operation fails.
     *
     * @psalm-return array{deleted_count: int<0, max>, deleted_uuids: array,
     *     register_id: int}
     */
    public function deleteObjectsByRegister(int $registerId): array
    {
        // First, get all UUIDs for objects belonging to this register.
        $qb = $this->db->getQueryBuilder();
        $qb->select('uuid')
            ->from($this->tableName)
            ->where($qb->expr()->eq('register', $qb->createNamedParameter($registerId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNull('deleted'));

        $result = $qb->executeQuery();
        $uuids  = [];
        while (($row = $result->fetch()) !== false) {
            $uuids[] = $row['uuid'];
        }

        $result->closeCursor();

        if (empty($uuids) === true) {
            return [
                'deleted_count' => 0,
                'deleted_uuids' => [],
                'register_id'   => $registerId,
            ];
        }

        // Use the existing bulk delete method.
        $deletedUuids = $this->deleteObjects($uuids);

        return [
            'deleted_count' => count($deletedUuids),
            'deleted_uuids' => $deletedUuids,
            'register_id'   => $registerId,
        ];
    }//end deleteObjectsByRegister()

    /**
     * Process a single chunk of insert objects within a transaction.
     *
     * @param array $insertChunk Array of objects to insert.
     *
     * @return array Array of inserted object UUIDs.
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     */
    public function processInsertChunk(array $insertChunk): array
    {
        $transactionStarted = false;

        try {
            // Start a new transaction for this chunk.
            if ($this->db->inTransaction() === false) {
                $this->db->beginTransaction();
                $transactionStarted = true;
            }

            // Process the insert chunk.
            $insertedIds = $this->bulkInsert($insertChunk);

            // Commit transaction if we started it.
            if ($transactionStarted === true) {
                $this->db->commit();
            }

            return $insertedIds;
        } catch (Exception $e) {
            // Rollback transaction if we started it.
            if ($transactionStarted === true) {
                try {
                    $this->db->rollBack();
                } catch (\Exception $rollbackException) {
                    $this->logger->error('Error during rollback', ['exception' => $rollbackException->getMessage()]);
                }
            }

            throw $e;
        }//end try
    }//end processInsertChunk()

    /**
     * Process a single chunk of update objects within a transaction.
     *
     * @param array $updateChunk Array of ObjectEntity instances to update.
     *
     * @return array Array of updated object UUIDs.
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     */
    public function processUpdateChunk(array $updateChunk): array
    {
        $transactionStarted = false;

        try {
            // Start a new transaction for this chunk.
            if ($this->db->inTransaction() === false) {
                $this->db->beginTransaction();
                $transactionStarted = true;
            }

            // Process the update chunk.
            $updatedIds = $this->bulkUpdate($updateChunk);

            // Commit transaction if we started it.
            if ($transactionStarted === true) {
                $this->db->commit();
            }

            return $updatedIds;
        } catch (Exception $e) {
            // Rollback transaction if we started it.
            if ($transactionStarted === true) {
                try {
                    $this->db->rollBack();
                } catch (\Exception $rollbackException) {
                    $this->logger->error('Error during rollback', ['exception' => $rollbackException->getMessage()]);
                }
            }

            throw $e;
        }//end try
    }//end processUpdateChunk()

    /**
     * Calculate optimal chunk size based on actual data size to prevent max_allowed_packet errors.
     *
     * @param array $insertObjects Array of objects to insert.
     * @param array $updateObjects Array of objects to update.
     *
     * @return int Optimal chunk size in number of objects.
     *
     * @psalm-return int<5, 100>
     */
    public function calculateOptimalChunkSize(array $insertObjects, array $updateObjects): int
    {
        // Start with a very conservative chunk size to prevent packet size issues.
        $baseChunkSize = 25;

        // Sample objects to estimate data size.
        $sampleSize    = min(20, max(5, count($insertObjects) + count($updateObjects)));
        $sampleObjects = array_merge(
            array_slice($insertObjects, 0, intval($sampleSize / 2)),
            array_slice($updateObjects, 0, intval($sampleSize / 2))
        );

        if (empty($sampleObjects) === true) {
            return $baseChunkSize;
        }

        // Calculate average object size in bytes.
        $totalSize     = 0;
        $objectCount   = 0;
        $maxObjectSize = 0;

        foreach ($sampleObjects as $object) {
            $objectSize    = $this->estimateObjectSize($object);
            $totalSize    += $objectSize;
            $maxObjectSize = max($maxObjectSize, $objectSize);
            $objectCount++;
        }

        // $objectCount is guaranteed to be > 0 because we check empty($sampleObjects) before the loop
        $averageObjectSize = $totalSize / $objectCount;

        // Use the maximum object size to be extra safe.
        $safetyObjectSize = max($averageObjectSize, $maxObjectSize);

        // Calculate safe chunk size based on actual max_allowed_packet value.
        $maxPacketSize = $this->queryBuilderHandler->getMaxAllowedPacketSize() * $this->maxPacketSizeBuffer;
        $safeChunkSize = intval($maxPacketSize / $safetyObjectSize);

        // Ensure chunk size is within very conservative bounds.
        $optimalChunkSize = max(5, min(100, $safeChunkSize));

        // If we have very large objects, be extra conservative.
        if ($safetyObjectSize > 1000000) {
            // 1MB per object.
            $optimalChunkSize = max(5, min(25, $optimalChunkSize));
        }

        // If we have extremely large objects, be very conservative.
        if ($safetyObjectSize > 5000000) {
            // 5MB per object.
            $optimalChunkSize = max(1, min(10, $optimalChunkSize));
        }

        return $optimalChunkSize;
    }//end calculateOptimalChunkSize()

    /**
     * Estimate the size of an object in bytes for chunk size calculation.
     *
     * @param mixed $object The object to estimate size for.
     *
     * @return int Estimated size in bytes.
     *
     * @psalm-return int<0, max>
     */
    private function estimateObjectSize(mixed $object): int
    {
        if (is_array($object) === true) {
            // For array objects (insert case).
            $size = 0;
            foreach ($object as $key => $value) {
                $size += strlen($key);
                if (is_string($value) === true) {
                    $size += strlen($value);
                } else if (is_array($value) === true) {
                    $size += strlen(json_encode($value));
                } else if (is_numeric($value) === true) {
                    $size += strlen((string) $value);
                }
            }

            return $size;
        } else if (is_object($object) === true) {
            // For ObjectEntity objects (update case).
            $size       = 0;
            $reflection = new ReflectionClass($object);
            foreach ($reflection->getProperties() as $property) {
                // Note: setAccessible() is no longer needed in PHP 8.1+
                $value = $property->getValue($object);

                if (is_string($value) === true) {
                    $size += strlen($value);
                } else if (is_array($value) === true) {
                    $size += strlen(json_encode($value));
                } else if (is_numeric($value) === true) {
                    $size += strlen((string) $value);
                }
            }

            return $size;
        }//end if

        return 0;
    }//end estimateObjectSize()

    /**
     * Calculate optimal batch size for bulk insert operations based on actual data size.
     *
     * @param array $insertObjects Array of objects to insert.
     * @param array $_columns      Array of column names.
     *
     * @return int Optimal batch size in number of objects.
     *
     * @psalm-return int<5, 100>
     */
    private function calculateOptimalBatchSize(array $insertObjects, array $_columns): int
    {
        // Start with a very conservative batch size.
        $baseBatchSize = 25;

        // Sample objects to estimate data size.
        $sampleSize    = min(20, max(5, count($insertObjects)));
        $sampleObjects = array_slice($insertObjects, 0, $sampleSize);

        if (empty($sampleObjects) === true) {
            return $baseBatchSize;
        }

        // Calculate average and maximum object size in bytes.
        $totalSize     = 0;
        $objectCount   = 0;
        $maxObjectSize = 0;

        foreach ($sampleObjects as $object) {
            $objectSize    = $this->estimateObjectSize($object);
            $totalSize    += $objectSize;
            $maxObjectSize = max($maxObjectSize, $objectSize);
            $objectCount++;
        }

        // $objectCount is guaranteed to be > 0 because we check empty($sampleObjects) before the loop
        $averageObjectSize = $totalSize / $objectCount;

        // Use the maximum object size to be extra safe.
        $safetyObjectSize = max($averageObjectSize, $maxObjectSize);

        // Calculate safe batch size based on actual max_allowed_packet value.
        $maxPacketSize = $this->queryBuilderHandler->getMaxAllowedPacketSize() * $this->maxPacketSizeBuffer;
        $safeBatchSize = intval($maxPacketSize / $safetyObjectSize);

        // Ensure batch size is within very conservative bounds.
        $optimalBatchSize = max(5, min(100, $safeBatchSize));

        // If we have very large objects, be extra conservative.
        if ($safetyObjectSize > 1000000) {
            $optimalBatchSize = max(5, min(25, $optimalBatchSize));
        }

        // If we have extremely large objects, be very conservative.
        if ($safetyObjectSize > 5000000) {
            $optimalBatchSize = max(1, min(10, $optimalBatchSize));
        }

        return $optimalBatchSize;
    }//end calculateOptimalBatchSize()

    /**
     * Perform true bulk insert of objects using single SQL statement.
     *
     * This method uses a single INSERT statement with multiple VALUES for optimal performance.
     * It bypasses individual entity creation and event dispatching for maximum speed.
     *
     * @param array $insertObjects Array of objects to insert.
     *
     * @return array Array of inserted object UUIDs.
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     *
     * @psalm-return list<mixed>
     */
    private function bulkInsert(array $insertObjects): array
    {
        if (empty($insertObjects) === true) {
            return [];
        }

        // Get the first object to determine column structure.
        $firstObject = $insertObjects[0];
        $columns     = array_keys($firstObject);

        // Calculate optimal batch size based on actual data size.
        $batchSize   = $this->calculateOptimalBatchSize(
            insertObjects: $insertObjects,
            _columns: $columns
        );
        $insertedIds = [];
        $objectCount = count($insertObjects);

        for ($i = 0; $i < $objectCount; $i += $batchSize) {
            $batch = array_slice($insertObjects, $i, $batchSize);

            // Check database connection health before processing batch.
            try {
                $this->db->executeQuery('SELECT 1');
            } catch (Exception $e) {
                throw new OcpDbException('Database connection lost during bulk insert', 0, $e);
            }

            // Build VALUES clause for this batch.
            $valuesClause = [];
            $parameters   = [];
            $paramIndex   = 0;

            foreach ($batch as $objectData) {
                $rowValues = [];
                foreach ($columns as $column) {
                    /*
                     * @var string $column
                     */

                    $paramName   = 'param_'.$paramIndex.'_'.$column;
                    $rowValues[] = ':'.$paramName;

                    $value = $objectData[$column] ?? null;

                    // JSON encode the object field if it's an array.
                    if (($column === 'object' || $column === 'data') === true && is_array($value) === true) {
                        $value = json_encode($value);
                    }

                    $parameters[$paramName] = $value;
                    $paramIndex++;
                }

                $valuesClause[] = '('.implode(', ', $rowValues).')';
            }//end foreach

            // Build the complete INSERT statement for this batch.
            $batchSql = "INSERT INTO {$this->tableName} (".implode(', ', $columns).") VALUES ".implode(', ', $valuesClause);

            // Execute the batch insert with retry logic.
            $maxBatchRetries = 3;
            $batchRetryCount = 0;
            $batchSuccess    = false;

            while ($batchRetryCount <= $maxBatchRetries && $batchSuccess === false) {
                try {
                    $stmt   = $this->db->prepare($batchSql);
                    $result = $stmt->execute($parameters);

                    if ($result === false) {
                        throw new Exception('Statement execution returned false');
                    }

                    $batchSuccess = true;
                } catch (Exception $e) {
                    $batchRetryCount++;
                    $this->logger->error(
                        'Error executing batch',
                        ['attempt' => $batchRetryCount, 'error' => $e->getMessage()]
                    );

                    if ($batchRetryCount > $maxBatchRetries) {
                        throw $e;
                    }

                    sleep(2);
                }//end try
            }//end while

            // Collect UUIDs from the inserted objects for return.
            foreach ($batch as $objectData) {
                if (($objectData['uuid'] ?? null) !== null) {
                    $insertedIds[] = $objectData['uuid'];
                }
            }

            // Clear batch variables to free memory.
            unset($batch, $valuesClause, $parameters, $batchSql);
            gc_collect_cycles();
        }//end for

        return $insertedIds;
    }//end bulkInsert()

    /**
     * Perform bulk update of objects using optimized SQL.
     *
     * This method processes each object individually for better compatibility.
     *
     * @param array $updateObjects Array of ObjectEntity instances to update.
     *
     * @return string[] Array of updated object UUIDs.
     *
     * @throws \OCP\DB\Exception If a database error occurs.
     *
     * @psalm-return list<string>
     */
    private function bulkUpdate(array $updateObjects): array
    {
        if (empty($updateObjects) === true) {
            return [];
        }

        $updatedIds = [];

        // Process each object individually for better compatibility.
        foreach ($updateObjects as $object) {
            $dbId = $object->getId();
            if ($dbId === null) {
                continue;
            }

            // Get all column names from the object.
            $columns = $this->getEntityColumns($object);

            // Build UPDATE statement for this object.
            $qb = $this->db->getQueryBuilder();
            $qb->update($this->tableName);

            // Set values for each column.
            foreach ($columns as $column) {
                if ($column === 'id') {
                    continue;
                }

                $value = $this->getEntityValue(
                    entity: $object,
                    column: $column
                );
                $qb->set($column, $qb->createNamedParameter($value));
            }

            // Add WHERE clause for this specific ID.
            $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($dbId)));

            // Execute the update for this object.
            $qb->executeStatement();

            // Collect UUID for return.
            $uuid = $object->getUuid();
            if ($uuid !== null) {
                $updatedIds[] = $uuid;
            }
        }//end foreach

        return $updatedIds;
    }//end bulkUpdate()

    /**
     * Perform bulk delete operations on objects by UUID.
     *
     * Handles both soft delete and hard delete.
     *
     * @param array $uuids      Array of object UUIDs to delete.
     * @param bool  $hardDelete Whether to force hard delete.
     *
     * @return array Array of UUIDs of deleted objects.
     *
     * @psalm-return list<mixed>
     */
    private function bulkDelete(array $uuids, bool $hardDelete=false): array
    {
        if (empty($uuids) === true) {
            return [];
        }

        $deletedIds = [];

        // Process deletes in smaller chunks.
        $chunkSize = 500;
        $chunks    = array_chunk($uuids, $chunkSize);

        foreach ($chunks as $uuidChunk) {
            // Check database connection health.
            try {
                $this->db->executeQuery('SELECT 1');
            } catch (Exception $e) {
                throw new OcpDbException('Database connection lost during bulk delete', 0, $e);
            }

            // Get the current state of objects.
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'uuid', 'deleted')
                ->from($this->tableName)
                ->where(
                    $qb->expr()->in(
                        'uuid',
                        $qb->createNamedParameter($uuidChunk, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
                    )
                );

            $objects = $qb->executeQuery()->fetchAll();

            // Separate objects for soft delete and hard delete.
            $softDeleteIds = [];
            $hardDeleteIds = [];

            foreach ($objects as $object) {
                if ($hardDelete === true) {
                    $hardDeleteIds[] = $object['id'];
                }

                if ($hardDelete === false && empty($object['deleted']) === true) {
                    $softDeleteIds[] = $object['id'];
                }

                if ($hardDelete === false && empty($object['deleted']) === false) {
                    $hardDeleteIds[] = $object['id'];
                }

                $deletedIds[] = $object['uuid'];
            }

            // Perform soft deletes (set deleted timestamp).
            if (empty($softDeleteIds) === false) {
                $currentTime = (new DateTime())->format('Y-m-d H:i:s');
                $qb          = $this->db->getQueryBuilder();
                $qb->update($this->tableName)
                    ->set(
                        'deleted',
                        $qb->createNamedParameter(
                            json_encode(
                                [
                                    'timestamp' => $currentTime,
                                    'reason'    => 'bulk_delete',
                                ]
                            )
                        )
                    )
                    ->where(
                        $qb->expr()->in(
                            'id',
                            $qb->createNamedParameter($softDeleteIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)
                        )
                    );

                $qb->executeStatement();
            }//end if

            // Perform hard deletes.
            if (empty($hardDeleteIds) === false) {
                $qb = $this->db->getQueryBuilder();
                $qb->delete($this->tableName)
                    ->where(
                        $qb->expr()->in(
                            'id',
                            $qb->createNamedParameter($hardDeleteIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)
                        )
                    );

                $qb->executeStatement();
            }

            unset($uuidChunk, $objects, $softDeleteIds, $hardDeleteIds);
            gc_collect_cycles();
        }//end foreach

        return $deletedIds;
    }//end bulkDelete()

    /**
     * Perform bulk publish operations on objects by UUID.
     *
     * @param array          $uuids    Array of object UUIDs to publish.
     * @param \DateTime|bool $datetime Optional datetime for publishing (false to unset).
     *
     * @return array Array of UUIDs of published objects.
     *
     * @psalm-return list<mixed>
     */
    private function bulkPublish(array $uuids, \DateTime|bool $datetime=true): array
    {
        if (empty($uuids) === true) {
            return [];
        }

        // Determine the published value.
        $publishedValue = (new DateTime())->format('Y-m-d H:i:s');
        if ($datetime === false) {
            $publishedValue = null;
        }

        if ($datetime instanceof \DateTime) {
            $publishedValue = $datetime->format('Y-m-d H:i:s');
        }

        // Process publishes in smaller chunks.
        $chunkSize    = 500;
        $chunks       = array_chunk($uuids, $chunkSize);
        $publishedIds = [];

        foreach ($chunks as $uuidChunk) {
            try {
                $this->db->executeQuery('SELECT 1');
            } catch (Exception $e) {
                throw new OcpDbException('Database connection lost during bulk publish', 0, $e);
            }

            // Get object IDs for the UUIDs.
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'uuid')
                ->from($this->tableName)
                ->where(
                    $qb->expr()->in(
                        'uuid',
                        $qb->createNamedParameter($uuidChunk, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
                    )
                );

            $objects           = $qb->executeQuery()->fetchAll();
            $objectIds         = array_column($objects, 'id');
            $chunkPublishedIds = array_column($objects, 'uuid');

            if (empty($objectIds) === false) {
                // Update published timestamp.
                $qb = $this->db->getQueryBuilder();
                $qb->update($this->tableName);

                $qb->set('published', $qb->createNamedParameter($publishedValue));
                if ($publishedValue === null) {
                    $qb->set('published', $qb->createNamedParameter(null));
                }

                $qb->where(
                    $qb->expr()->in(
                        'id',
                        $qb->createNamedParameter($objectIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)
                    )
                );

                $qb->executeStatement();
            }

            $publishedIds = array_merge($publishedIds, $chunkPublishedIds);

            unset($uuidChunk, $objects, $objectIds, $chunkPublishedIds);
            gc_collect_cycles();
        }//end foreach

        return $publishedIds;
    }//end bulkPublish()

    /**
     * Perform bulk depublish operations on objects by UUID.
     *
     * @param array          $uuids    Array of object UUIDs to depublish.
     * @param \DateTime|bool $datetime Optional datetime for depublishing (false to unset).
     *
     * @return array Array of UUIDs of depublished objects.
     *
     * @psalm-return list<mixed>
     */
    private function bulkDepublish(array $uuids, \DateTime|bool $datetime=true): array
    {
        if (empty($uuids) === true) {
            return [];
        }

        // Determine the depublished value.
        $depublishedValue = (new DateTime())->format('Y-m-d H:i:s');
        if ($datetime === false) {
            $depublishedValue = null;
        }

        if ($datetime instanceof \DateTime) {
            $depublishedValue = $datetime->format('Y-m-d H:i:s');
        }

        // Process depublishes in smaller chunks.
        $chunkSize      = 500;
        $chunks         = array_chunk($uuids, $chunkSize);
        $depublishedIds = [];

        foreach ($chunks as $uuidChunk) {
            try {
                $this->db->executeQuery('SELECT 1');
            } catch (Exception $e) {
                throw new OcpDbException('Database connection lost during bulk depublish', 0, $e);
            }

            // Get object IDs for the UUIDs.
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'uuid')
                ->from($this->tableName)
                ->where(
                    $qb->expr()->in(
                        'uuid',
                        $qb->createNamedParameter($uuidChunk, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)
                    )
                );

            $objects   = $qb->executeQuery()->fetchAll();
            $objectIds = array_column($objects, 'id');
            $chunkDepublishedIds = array_column($objects, 'uuid');

            if (empty($objectIds) === false) {
                // Update depublished timestamp.
                $qb = $this->db->getQueryBuilder();
                $qb->update($this->tableName);

                $qb->set('depublished', $qb->createNamedParameter($depublishedValue));
                if ($depublishedValue === null) {
                    $qb->set('depublished', $qb->createNamedParameter(null));
                }

                $qb->where(
                    $qb->expr()->in(
                        'id',
                        $qb->createNamedParameter($objectIds, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY)
                    )
                );

                $qb->executeStatement();
            }

            $depublishedIds = array_merge($depublishedIds, $chunkDepublishedIds);

            unset($uuidChunk, $objects, $objectIds, $chunkDepublishedIds);
            gc_collect_cycles();
        }//end foreach

        return $depublishedIds;
    }//end bulkDepublish()

    /**
     * Get all column names from an entity for bulk operations.
     *
     * @param ObjectEntity $entity The entity to extract columns from.
     *
     * @return string[] Array of column names.
     *
     * @psalm-return list<string>
     */
    private function getEntityColumns(ObjectEntity $entity): array
    {
        // Get all field types to determine which fields are database columns.
        $fieldTypes = $entity->getFieldTypes();
        $columns    = [];

        foreach ($fieldTypes as $fieldName => $fieldType) {
            // Skip virtual fields and schemaVersion.
            if ($fieldType !== 'virtual' && $fieldName !== 'schemaVersion') {
                $columns[] = $fieldName;
            }
        }

        return $columns;
    }//end getEntityColumns()

    /**
     * Get the value of a specific column from an entity.
     *
     * Retrieves the raw value and performs necessary transformations for database storage.
     *
     * @param ObjectEntity $entity The entity to get the value from.
     * @param string       $column The column name.
     *
     * @return mixed The column value with proper transformations applied.
     */
    private function getEntityValue(ObjectEntity $entity, string $column): mixed
    {
        // Use reflection to get the value of the property.
        $reflection = new ReflectionClass($entity);

        try {
            $property = $reflection->getProperty($column);
            // Note: setAccessible() is no longer needed in PHP 8.1+
            $value = $property->getValue($entity);
        } catch (\ReflectionException $e) {
            // Try getter method.
            $getterMethod = 'get'.ucfirst($column);
            if (method_exists($entity, $getterMethod) === false) {
                return null;
            }

            $value = $entity->$getterMethod();
        }

        // Handle DateTime objects.
        if ($value instanceof \DateTime) {
            $value = $value->format('Y-m-d H:i:s');
        }

        // Handle boolean values.
        if (is_bool($value) === true) {
            if ($value === true) {
                $value = 1;
            } else {
                $value = 0;
            }
        }

        // Handle null values.
        if ($value === null) {
            return null;
        }

        // JSON encode the object field if it's an array.
        if ($column === 'object' && is_array($value) === true) {
            $value = json_encode($value);
        }

        // Handle other array values that might need JSON encoding.
        if (is_array($value) === true
            && in_array(
                $column,
                [
                    'files',
                    'relations',
                    'locked',
                    'authorization',
                    'deleted',
                    'validation',
                ],
                true
            ) === true
        ) {
            $value = json_encode($value);
        }

        return $value;
    }//end getEntityValue()
}//end class
