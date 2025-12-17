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
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Bulk operations handler for MagicMapper dynamic tables
 *
 * This class provides high-performance bulk operations specifically optimized
 * for schema-specific dynamic tables, offering better performance than generic
 * table operations due to schema-aware optimizations.
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
     * Constructor for MagicBulkHandler
     *
     * @param IDBConnection   $db     Database connection for operations
     * @param LoggerInterface $logger Logger for debugging and error reporting
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger
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
        $prepared   = [];
        $properties = $schema->getProperties();
        $now        = new DateTime();

        foreach ($objects as $object) {
            $preparedObject = [];

            // Extract @self metadata.
            $selfData = $object['@self'] ?? [];

            // Map metadata to prefixed columns.
            $preparedObject['_uuid']         = $selfData['id'] ?? $object['id'] ?? Uuid::v4()->toRfc4122();
            $preparedObject['_register']     = $register->getId();
            $preparedObject['_schema']       = $schema->getId();
            $preparedObject['_owner']        = $selfData['owner'] ?? null;
            $preparedObject['_organisation'] = $selfData['organisation'] ?? null;
            $preparedObject['_created']      = $selfData['created'] ?? $now->format('Y-m-d H:i:s');
            $preparedObject['_updated']      = $now->format('Y-m-d H:i:s');
            $preparedObject['_published']    = $selfData['published'] ?? null;
            $preparedObject['_depublished']  = $selfData['depublished'] ?? null;
            $preparedObject['_name']         = $selfData['name'] ?? null;
            $preparedObject['_description']  = $selfData['description'] ?? null;
            $preparedObject['_summary']      = $selfData['summary'] ?? null;
            $preparedObject['_image']        = $selfData['image'] ?? null;
            $preparedObject['_slug']         = $selfData['slug'] ?? null;
            $preparedObject['_uri']          = $selfData['uri'] ?? null;

            // Map schema properties to columns.
            foreach (array_keys($properties) as $propertyName) {
                $columnName = $this->sanitizeColumnName($propertyName);
                $value      = $object[$propertyName] ?? null;

                // Convert complex values for database storage.
                if (is_array($value) === true || is_object($value) === true) {
                    $preparedObject[$columnName] = json_encode($value);
                } else {
                    $preparedObject[$columnName] = $value;
                }
            }

            $prepared[] = $preparedObject;
        }//end foreach

        return $prepared;

    }//end prepareObjectsForDynamicTable()

    /**
     * Categorize objects into insert vs update operations
     *
     * @param array  $objects   Prepared object data
     * @param string $tableName Target table name
     *
     * @return array[] Array containing [insertObjects, updateObjects]
     *
     * @psalm-return list{list<mixed>, list<mixed>}
     */
    private function categorizeObjectsForSave(array $objects, string $tableName): array
    {
        if ($objects === []) {
            return [[], []];
        }

        // Get UUIDs to check for existing objects.
        $uuids = array_column($objects, '_uuid');

        // Find existing objects.
        $qb = $this->db->getQueryBuilder();
        $qb->select('_uuid')
            ->from($tableName, 't')
            ->where($qb->expr()->in('t._uuid', $qb->createNamedParameter($uuids, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY)));

        $result        = $qb->executeQuery();
        $existingUuids = array_column($result->fetchAll(), '_uuid');

        // Categorize objects.
        $insertObjects = [];
        $updateObjects = [];

        foreach ($objects as $object) {
            if (in_array($object['_uuid'], $existingUuids, true) === true) {
                $updateObjects[] = $object;
            } else {
                $insertObjects[] = $object;
            }
        }

        return [$insertObjects, $updateObjects];

    }//end categorizeObjectsForSave()

    /**
     * Perform bulk insert into dynamic table
     *
     * @param array  $objects   Array of prepared object data
     * @param string $tableName Target table name
     *
     * @return array Array of inserted UUIDs
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @psalm-return list<mixed>
     */
    private function bulkInsertToDynamicTable(array $objects, string $tableName): array
    {
        if ($objects === []) {
            return [];
        }

        $insertedUuids = [];
        $chunkSize     = $this->calculateOptimalChunkSize($objects);

        // Process in chunks to prevent packet size issues.
        $chunks = array_chunk($objects, $chunkSize);

        foreach ($chunks as $chunk) {
            $chunkUuids    = $this->executeBulkInsertChunk(chunk: $chunk, tableName: $tableName);
            $insertedUuids = array_merge($insertedUuids, $chunkUuids);
        }

        return $insertedUuids;

    }//end bulkInsertToDynamicTable()

    /**
     * Execute bulk insert for a single chunk
     *
     * @param array  $chunk     Chunk of objects to insert
     * @param string $tableName Target table name
     *
     * @return array Array of inserted UUIDs
     *
     * @psalm-return list<mixed>
     */
    private function executeBulkInsertChunk(array $chunk, string $tableName): array
    {
        if ($chunk === []) {
            return [];
        }

        // Get columns from first object.
        $columns       = array_keys($chunk[0]);
        $columnList    = '`'.implode('`, `', $columns).'`';
        $insertedUuids = [];

        // Build VALUES clause.
        $valuesClause = [];
        $parameters   = [];
        $paramIndex   = 0;

        foreach ($chunk as $objectData) {
            $rowValues = [];
            foreach ($columns as $column) {
                $paramName   = 'p'.$paramIndex;
                $rowValues[] = ':'.$paramName;
                $parameters[$paramName] = $objectData[$column] ?? null;
                $paramIndex++;
            }

            $valuesClause[] = '('.implode(',', $rowValues).')';

            // Collect UUID for return.
            if (($objectData['_uuid'] ?? null) !== null) {
                $insertedUuids[] = $objectData['_uuid'];
            }
        }

        // Execute bulk insert.
        $sql = "INSERT INTO `{$tableName}` ({$columnList}) VALUES ".implode(',', $valuesClause);

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($parameters);
        } catch (\Exception $e) {
            $this->logger->error(
                    'Bulk insert to dynamic table failed',
                    [
                        'tableName' => $tableName,
                        'chunkSize' => count($chunk),
                        'error'     => $e->getMessage(),
                    ]
                    );
            throw $e;
        }

        return $insertedUuids;

    }//end executeBulkInsertChunk()

    /**
     * Perform bulk update on dynamic table
     *
     * @param array  $objects   Array of prepared object data
     * @param string $tableName Target table name
     *
     * @return array Array of updated UUIDs
     *
     * @throws \OCP\DB\Exception If a database error occurs
     *
     * @psalm-return list<mixed>
     */
    private function bulkUpdateDynamicTable(array $objects, string $tableName): array
    {
        if ($objects === []) {
            return [];
        }

        $updatedUuids = [];

        // Process updates individually for now (can be optimized later with CASE statements).
        foreach ($objects as $objectData) {
            $uuid = $objectData['_uuid'] ?? null;
            if ($uuid === null || $uuid === '') {
                continue;
            }

            $qb = $this->db->getQueryBuilder();
            $qb->update($tableName, 't');

            // Set all columns except UUID.
            foreach ($objectData as $column => $value) {
                if ($column !== '_uuid') {
                    $qb->set("t.{$column}", $qb->createNamedParameter($value));
                }
            }

            $qb->where($qb->expr()->eq('t._uuid', $qb->createNamedParameter($uuid)));

            try {
                $affectedRows = $qb->executeStatement();
                if ($affectedRows > 0) {
                    $updatedUuids[] = $uuid;
                }
            } catch (\Exception $e) {
                $this->logger->error(
                        'Failed to update object in dynamic table',
                        [
                            'tableName' => $tableName,
                            'uuid'      => $uuid,
                            'error'     => $e->getMessage(),
                        ]
                        );
                // Continue with other objects.
            }
        }//end foreach

        return $updatedUuids;

    }//end bulkUpdateDynamicTable()

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
            if ($maxPacketSize > 67108864) {
                // > 64MB.
                $this->maxPacketSizeBuffer = 0.6;
                // 60% buffer.
            } else if ($maxPacketSize > 33554432) {
                // > 32MB.
                $this->maxPacketSizeBuffer = 0.5;
                // 50% buffer.
            } else if ($maxPacketSize > 16777216) {
                // > 16MB.
                $this->maxPacketSizeBuffer = 0.4;
                // 40% buffer.
            } else {
                $this->maxPacketSizeBuffer = 0.3;
                // 30% buffer for smaller packet sizes.
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
        // Convert to lowercase and replace non-alphanumeric with underscores.
        $sanitized = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $name));

        // Ensure it starts with a letter or underscore.
        if (preg_match('/^[a-zA-Z_]/', $sanitized) === 0) {
            $sanitized = 'col_'.$sanitized;
        }

        // Limit length to 64 characters (MySQL limit).
        return substr($sanitized, 0, 64);

    }//end sanitizeColumnName()
}//end class
