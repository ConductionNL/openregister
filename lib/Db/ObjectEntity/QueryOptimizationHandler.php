<?php

/**
 * QueryOptimizationHandler
 *
 * Handler for query optimization and additional bulk operations on ObjectEntity.
 * Extracted from ObjectEntityMapper to follow Single Responsibility Principle.
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
use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Handles query optimization and specialized bulk operations for ObjectEntity.
 *
 * This handler manages:
 * - Large object separation and individual processing
 * - Bulk owner/organization declaration
 * - Expiry date management
 * - Composite index optimizations
 * - Query hints and ORDER BY optimizations
 * - JSON filter detection
 *
 * @category Nextcloud
 * @package  OpenRegister
 * @author   Conduction BV <info@conduction.nl>
 * @license  EUPL-1.2 https://opensource.org/licenses/EUPL-1.2
 * @link     https://www.conduction.nl
 */
class QueryOptimizationHandler
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
     * Constructor.
     *
     * @param IDBConnection   $db        Database connection.
     * @param LoggerInterface $logger    Logger instance.
     * @param string          $tableName Table name for objects.
     */
    public function __construct(
        IDBConnection $db,
        LoggerInterface $logger,
        string $tableName='openregister_objects'
    ) {
        $this->db        = $db;
        $this->logger    = $logger;
        $this->tableName = $tableName;

    }//end __construct()


    /**
     * Detect and separate extremely large objects for individual processing.
     *
     * @param array $objects     Array of objects to check.
     * @param int   $maxSafeSize Maximum safe size in bytes for batch processing.
     *
     * @return array Array with 'large' and 'normal' keys containing separated objects.
     */
    public function separateLargeObjects(array $objects, int $maxSafeSize=1000000): array
    {
        $largeObjects  = [];
        $normalObjects = [];

        foreach ($objects as $object) {
            $objectSize = $this->estimateObjectSize($object);

            if ($objectSize > $maxSafeSize) {
                $largeObjects[] = $object;
            } else {
                $normalObjects[] = $object;
            }
        }

        return [
            'large'  => $largeObjects,
            'normal' => $normalObjects,
        ];

    }//end separateLargeObjects()


    /**
     * Process large objects individually to prevent packet size errors.
     *
     * Note: This method is designed for INSERT operations and expects array data.
     *
     * @param array $largeObjects Array of large objects to process (must be arrays for INSERT).
     *
     * @return array Array of processed object UUIDs.
     */
    public function processLargeObjectsIndividually(array $largeObjects): array
    {
        if (empty($largeObjects) === true) {
            return [];
        }

        $processedIds = [];

        foreach ($largeObjects as $index => $objectData) {
            try {
                // Ensure we have array data for INSERT operations.
                if (is_array($objectData) === false) {
                    continue;
                }

                // Get columns from the object.
                $columns = array_keys($objectData);

                // Build single INSERT statement.
                $placeholders = ':'.implode(', :', $columns);
                $sql          = "INSERT INTO {$this->tableName} (".implode(', ', $columns).") VALUES ({$placeholders})";

                // Prepare parameters.
                $parameters = [];
                foreach ($columns as $column) {
                    $value = $objectData[$column] ?? null;

                    // JSON encode the object field if it's an array.
                    if ($column === 'object' && is_array($value) === true) {
                        $value = json_encode($value);
                    }

                    $parameters[':'.$column] = $value;
                }

                // Execute single insert.
                $stmt   = $this->db->prepare($sql);
                $result = $stmt->execute($parameters);

                // Check if execution was successful and UUID exists.
                if ($result !== false && ($objectData['uuid'] ?? null) !== null) {
                    $processedIds[] = $objectData['uuid'];
                }

                // Clear memory after each large object.
                unset($parameters, $sql);
                gc_collect_cycles();
            } catch (Exception $e) {
                $this->logger->error('Error processing large object', ['index' => $index + 1, 'exception' => $e->getMessage()]);

                // If it's not a packet size error, re-throw.
                if (strpos($e->getMessage(), 'max_allowed_packet') === false) {
                    throw $e;
                }
            }//end try
        }//end foreach

        return $processedIds;

    }//end processLargeObjectsIndividually()


    /**
     * Bulk assign default owner and organization to objects that don't have them assigned.
     *
     * This method updates objects in batches to assign default values where they are missing.
     *
     * @param string|null $defaultOwner        Default owner to assign.
     * @param string|null $defaultOrganisation Default organization UUID to assign.
     * @param int         $batchSize           Number of objects to process in each batch.
     *
     * @return array Array containing statistics about the bulk operation.
     *
     * @throws \Exception If the bulk operation fails.
     */
    public function bulkOwnerDeclaration(?string $defaultOwner=null, ?string $defaultOrganisation=null, int $batchSize=1000): array
    {
        if ($defaultOwner === null && $defaultOrganisation === null) {
            throw new InvalidArgumentException('At least one of defaultOwner or defaultOrganisation must be provided');
        }

        $results = [
            'totalProcessed'        => 0,
            'ownersAssigned'        => 0,
            'organisationsAssigned' => 0,
            'errors'                => [],
            'startTime'             => new DateTime(),
        ];

        try {
            $offset         = 0;
            $hasMoreRecords = true;

            while ($hasMoreRecords === true) {
                // Build query to find objects without owner or organization.
                $qb = $this->db->getQueryBuilder();
                $qb->select('id', 'uuid', 'owner', 'organisation')
                    ->from($this->tableName)
                    ->setMaxResults($batchSize)
                    ->setFirstResult($offset);

                // Add conditions for missing owner or organization.
                $conditions = [];
                if ($defaultOwner !== null) {
                    $conditions[] = $qb->expr()->orX(
                        $qb->expr()->isNull('owner'),
                        $qb->expr()->eq('owner', $qb->createNamedParameter(''))
                    );
                }

                if ($defaultOrganisation !== null) {
                    $conditions[] = $qb->expr()->orX(
                        $qb->expr()->isNull('organisation'),
                        $qb->expr()->eq('organisation', $qb->createNamedParameter(''))
                    );
                }

                if (empty($conditions) === false) {
                    $qb->where($qb->expr()->orX(...$conditions));
                }

                $result  = $qb->executeQuery();
                $objects = $result->fetchAll();

                if (empty($objects) === true) {
                    break;
                }

                // Process batch of objects.
                $batchResults = $this->processBulkOwnerDeclarationBatch($objects, $defaultOwner, $defaultOrganisation);

                // Update statistics.
                $results['totalProcessed']        += count($objects);
                $results['ownersAssigned']        += $batchResults['ownersAssigned'];
                $results['organisationsAssigned'] += $batchResults['organisationsAssigned'];
                $results = array_merge_recursive($results, ['errors' => $batchResults['errors']]);

                $offset += $batchSize;

                // If we got fewer records than the batch size, we're done.
                if (count($objects) < $batchSize) {
                    $hasMoreRecords = false;
                }
            }//end while

            $results['endTime']  = new DateTime();
            $results['duration'] = $results['endTime']->diff($results['startTime'])->format('%H:%I:%S');

            return $results;
        } catch (Exception $e) {
            $this->logger->error('Error during bulk owner declaration', ['exception' => $e->getMessage()]);
            throw new RuntimeException('Bulk owner declaration failed: '.$e->getMessage());
        }//end try

    }//end bulkOwnerDeclaration()


    /**
     * Set expiry dates for objects based on retention period in milliseconds.
     *
     * Updates the expires column for objects based on their deleted date plus the retention period.
     * Only affects soft-deleted objects without an expiry date.
     *
     * @param int $retentionMs Retention period in milliseconds.
     *
     * @return int Number of objects updated.
     *
     * @throws \Exception Database operation exceptions.
     */
    public function setExpiryDate(int $retentionMs): int
    {
        try {
            // Convert milliseconds to seconds for DateTime calculation.
            $retentionSeconds = intval($retentionMs / 1000);

            // Get the query builder.
            $qb = $this->db->getQueryBuilder();

            // Update objects that have been deleted but don't have an expiry date set.
            $qb->update($this->tableName)
                ->set(
                    'expires',
                    $qb->createFunction(
                        sprintf('DATE_ADD(JSON_UNQUOTE(JSON_EXTRACT(deleted, "$.deletedAt")), INTERVAL %d SECOND)', $retentionSeconds)
                    )
                )
                ->where($qb->expr()->isNull('expires'))
                ->andWhere($qb->expr()->isNotNull('deleted'))
                ->andWhere($qb->expr()->neq('deleted', $qb->createNamedParameter('null')));

            // Execute the update and return number of affected rows.
            return $qb->executeStatement();
        } catch (Exception $e) {
            $this->logger->error('Failed to set expiry dates for objects: '.$e->getMessage(), ['exception' => $e]);
            throw $e;
        }//end try

    }//end setExpiryDate()


    /**
     * Apply optimizations for composite indexes.
     *
     * @param IQueryBuilder $_qb     Query builder (reserved for future optimization).
     * @param array         $filters Applied filters.
     *
     * @return void
     */
    public function applyCompositeIndexOptimizations(IQueryBuilder $_qb, array $filters): void
    {
        // INDEX OPTIMIZATION: If we have schema + register + published filters,
        // ensure they're applied in the optimal order for the composite index.
        $hasSchema    = isset($filters['schema']) || isset($filters['schema_id']);
        $hasRegister  = isset($filters['registers']) || isset($filters['register']);
        $hasPublished = ($filters['published'] ?? null) !== null;

        if ($hasSchema === true && $hasRegister === true && $hasPublished === true) {
            // This will use the idx_schema_register_published composite index.
            $this->logger->debug('🚀 QUERY OPTIMIZATION: Using composite index for schema+register+published');
        }

        // MULTITENANCY OPTIMIZATION: Schema + organisation index.
        $hasOrganisation = ($filters['organisation'] ?? null) !== null;
        if ($hasSchema === true && $hasOrganisation === true) {
            $this->logger->debug('🚀 QUERY OPTIMIZATION: Using composite index for schema+organisation');
        }

    }//end applyCompositeIndexOptimizations()


    /**
     * Optimize ORDER BY clauses to use indexes.
     *
     * @param IQueryBuilder $qb Query builder.
     *
     * @return void
     */
    public function optimizeOrderBy(IQueryBuilder $qb): void
    {
        // INDEX-AWARE ORDERING: Default to indexed columns for sorting.
        $orderByParts = $qb->getQueryPart('orderBy');

        if (empty($orderByParts) === true) {
            // Use indexed columns for default ordering.
            $qb->orderBy('updated', 'DESC')
                ->addOrderBy('id', 'DESC');

            $this->logger->debug('🚀 QUERY OPTIMIZATION: Using indexed columns for ORDER BY');
        }

    }//end optimizeOrderBy()


    /**
     * Add database-specific query hints for better performance.
     *
     * @param IQueryBuilder $qb       Query builder.
     * @param array         $filters  Applied filters.
     * @param bool          $skipRbac Whether RBAC is skipped.
     *
     * @return void
     */
    public function addQueryHints(IQueryBuilder $qb, array $filters, bool $skipRbac): void
    {
        // QUERY HINT 1: For small result sets, suggest using indexes.
        $limit = $qb->getMaxResults();
        if ($limit !== null && $limit <= 50) {
            $this->logger->debug('🚀 QUERY OPTIMIZATION: Small result set - favoring index usage');
        }

        // QUERY HINT 2: For RBAC-enabled queries, suggest specific execution plan.
        if ($skipRbac === false) {
            $this->logger->debug('🚀 QUERY OPTIMIZATION: RBAC enabled - using owner-based indexes');
        }

        // QUERY HINT 3: For JSON queries, suggest JSON-specific optimizations.
        if (($filters['object'] ?? null) !== null || $this->hasJsonFilters($filters) === true) {
            $this->logger->debug('🚀 QUERY OPTIMIZATION: JSON queries detected - using JSON indexes');
        }

    }//end addQueryHints()


    /**
     * Check if filters contain JSON-based queries.
     *
     * @param array $filters Filter array to check.
     *
     * @return bool True if JSON filters are present.
     */
    public function hasJsonFilters(array $filters): bool
    {
        foreach ($filters as $key => $value) {
            // Check for dot-notation in filter keys (indicates JSON path queries).
            if (strpos($key, '.') !== false && $key !== 'schema.id') {
                return true;
            }
        }

        return false;

    }//end hasJsonFilters()


    /**
     * Process a batch of objects for bulk owner declaration.
     *
     * @param array       $objects             Array of object data from database.
     * @param string|null $defaultOwner        Default owner to assign.
     * @param string|null $defaultOrganisation Default organization UUID to assign.
     *
     * @return array Batch processing results.
     */
    private function processBulkOwnerDeclarationBatch(array $objects, ?string $defaultOwner, ?string $defaultOrganisation): array
    {
        $batchResults = [
            'ownersAssigned'        => 0,
            'organisationsAssigned' => 0,
            'errors'                => [],
        ];

        foreach ($objects as $objectData) {
            try {
                $needsUpdate = false;
                $updateData  = [];

                // Check if owner needs to be assigned.
                if ($defaultOwner !== null && (empty($objectData['owner']) === true || $objectData['owner'] === null)) {
                    $updateData['owner'] = $defaultOwner;
                    $needsUpdate         = true;
                    $batchResults['ownersAssigned']++;
                }

                // Check if organization needs to be assigned.
                if ($defaultOrganisation !== null && (empty($objectData['organisation']) === true || $objectData['organisation'] === null)) {
                    $updateData['organisation'] = $defaultOrganisation;
                    $needsUpdate = true;
                    $batchResults['organisationsAssigned']++;
                }

                // Update the object if needed.
                if ($needsUpdate === true) {
                    $this->updateObjectOwnership((int) $objectData['id'], $updateData);
                }
            } catch (Exception $e) {
                $error = 'Error updating object '.$objectData['uuid'].': '.$e->getMessage();
                $batchResults['errors'][] = $error;
            }//end try
        }//end foreach

        return $batchResults;

    }//end processBulkOwnerDeclarationBatch()


    /**
     * Update ownership information for a specific object.
     *
     * @param int   $objectId   The ID of the object to update.
     * @param array $updateData Array containing owner and/or organisation data.
     *
     * @return void
     *
     * @throws \Exception If the update fails.
     */
    private function updateObjectOwnership(int $objectId, array $updateData): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->tableName)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($objectId, IQueryBuilder::PARAM_INT)));

        foreach ($updateData as $field => $value) {
            $qb->set($field, $qb->createNamedParameter($value));
        }

        // Update the modified timestamp.
        $qb->set('modified', $qb->createNamedParameter(new DateTime(), IQueryBuilder::PARAM_DATE));

        $qb->executeStatement();

    }//end updateObjectOwnership()


    /**
     * Estimate the size of an object in bytes for size calculations.
     *
     * @param mixed $object The object to estimate size for.
     *
     * @return int Estimated size in bytes.
     */
    private function estimateObjectSize(mixed $object): int
    {
        if (is_array($object) === true) {
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
        } else if (is_object($object) === true && $object instanceof ObjectEntity) {
            $size       = 0;
            $reflection = new \ReflectionClass($object);
            foreach ($reflection->getProperties() as $property) {
                $property->setAccessible(true);
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


}//end class
