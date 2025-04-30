<?php
/**
 * OpenRegister Dashboard Service
 *
 * This file contains the service class for handling dashboard related operations
 * in the OpenRegister application.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Class DashboardService
 *
 * Service for handling dashboard related operations.
 *
 * @package OCA\OpenRegister\Service
 */
class DashboardService
{


    /**
     * Constructor for DashboardService
     *
     * @param RegisterMapper     $registerMapper   The register mapper instance
     * @param SchemaMapper       $schemaMapper     The schema mapper instance
     * @param ObjectEntityMapper $objectMapper     The object entity mapper instance
     * @param AuditTrailMapper   $auditTrailMapper The audit trail mapper instance
     * @param IDBConnection      $db               The database connection instance
     * @param LoggerInterface    $logger           The logger instance
     *
     * @return void
     */
    public function __construct(
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly ObjectEntityMapper $objectMapper,
        private readonly AuditTrailMapper $auditTrailMapper,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    /**
     * Get statistics for a register/schema combination
     *
     * @param int      $registerId The register ID
     * @param int|null $schemaId   The schema ID (optional)
     *
     * @return array Array containing statistics about objects and logs:
     *               - objects: Array containing object statistics
     *                 - total: Total number of objects
     *                 - size: Total size of all objects in bytes
     *                 - invalid: Number of objects with validation errors
     *                 - deleted: Number of deleted objects
     *                 - locked: Number of locked objects
     *                 - published: Number of published objects
     *               - logs: Array containing log statistics
     *                 - total: Total number of log entries
     *                 - size: Total size of all log entries in bytes
     *               - files: Array containing file statistics
     *                 - total: Total number of files
     *                 - size: Total size of all files in bytes
     *
     * @phpstan-return array{
     *     objects: array{total: int, size: int, invalid: int, deleted: int, locked: int, published: int},
     *     logs: array{total: int, size: int},
     *     files: array{total: int, size: int}
     * }
     */
    private function getStats(int $registerId, ?int $schemaId=null): array
    {
        try {
            // Get object statistics.
            $objectStats = $this->objectMapper->getStatistics($registerId, $schemaId);

            // Get audit trail statistics.
            $logStats = $this->auditTrailMapper->getStatistics($registerId, $schemaId);

            return [
                'objects' => [
                    'total'     => $objectStats['total'],
                    'size'      => $objectStats['size'],
                    'invalid'   => $objectStats['invalid'],
                    'deleted'   => $objectStats['deleted'],
                    'locked'    => $objectStats['locked'],
                    'published' => $objectStats['published'],
                ],
                'logs'    => [
                    'total' => $logStats['total'],
                    'size'  => $logStats['size'],
                ],
                'files'   => [
                    'total' => 0,
                    'size'  => 0,
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get statistics: '.$e->getMessage());
            return [
                'objects' => [
                    'total'     => 0,
                    'size'      => 0,
                    'invalid'   => 0,
                    'deleted'   => 0,
                    'locked'    => 0,
                    'published' => 0,
                ],
                'logs'    => [
                    'total' => 0,
                    'size'  => 0,
                ],
                'files'   => [
                    'total' => 0,
                    'size'  => 0,
                ],
            ];
        }//end try

    }//end getStats()


    /**
     * Get statistics for orphaned items
     *
     * @return array The statistics for orphaned items
     */
    private function getOrphanedStats(): array
    {
        try {
            // Get all registers
            $registers = $this->registerMapper->findAll();
            
            // Build array of valid register/schema combinations
            $validCombinations = [];
            foreach ($registers as $register) {
                $schemas = $this->registerMapper->getSchemasByRegisterId($register->getId());
                foreach ($schemas as $schema) {
                    $validCombinations[] = [
                        'register' => $register->getId(),
                        'schema' => $schema->getId()
                    ];
                }
            }

            // Get orphaned object statistics by excluding all valid combinations
            $objectStats = $this->objectMapper->getStatistics(null, null, $validCombinations);

            // Get orphaned audit trail statistics using the same exclusions
            $auditStats = $this->auditTrailMapper->getStatistics(null, null, $validCombinations);

            return [
                'objects' => [
                    'total'     => $objectStats['total'],
                    'size'      => $objectStats['size'],
                    'invalid'   => $objectStats['invalid'],
                    'deleted'   => $objectStats['deleted'],
                    'locked'    => $objectStats['locked'],
                    'published' => $objectStats['published'],
                ],
                'logs'    => [
                    'total' => $auditStats['total'],
                    'size'  => $auditStats['size'],
                ],
                'files'   => [
                    'total' => 0,
                    'size'  => 0,
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get orphaned statistics: '.$e->getMessage());
            return [
                'objects' => [
                    'total'     => 0,
                    'size'      => 0,
                    'invalid'   => 0,
                    'deleted'   => 0,
                    'locked'    => 0,
                    'published' => 0,
                ],
                'logs'    => [
                    'total' => 0,
                    'size'  => 0,
                ],
                'files'   => [
                    'total' => 0,
                    'size'  => 0,
                ],
            ];
        }//end try

    }//end getOrphanedStats()


    /**
     * Get total statistics across all registers
     *
     * @return array The total statistics
     */
    private function getTotalStats(): array
    {
        try {
            // Get total object statistics (passing null for registerId and schemaId to get all)
            $objectStats = $this->objectMapper->getStatistics(null, null);

            // Get total audit trail statistics
            $logStats = $this->auditTrailMapper->getStatistics(null, null);

            return [
                'objects' => [
                    'total'     => $objectStats['total'],
                    'size'      => $objectStats['size'],
                    'invalid'   => $objectStats['invalid'],
                    'deleted'   => $objectStats['deleted'],
                    'locked'    => $objectStats['locked'],
                    'published' => $objectStats['published'],
                ],
                'logs'    => [
                    'total' => $logStats['total'],
                    'size'  => $logStats['size'],
                ],
                'files'   => [
                    'total' => 0,
                    'size'  => 0,
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get total statistics: '.$e->getMessage());
            return [
                'objects' => [
                    'total'     => 0,
                    'size'      => 0,
                    'invalid'   => 0,
                    'deleted'   => 0,
                    'locked'    => 0,
                    'published' => 0,
                ],
                'logs'    => [
                    'total' => 0,
                    'size'  => 0,
                ],
                'files'   => [
                    'total' => 0,
                    'size'  => 0,
                ],
            ];
        }
    }

    /**
     * Get all registers with their schemas and statistics
     *
     * @param int|null   $limit            The number of registers to return
     * @param int|null   $offset           The offset of the registers to return
     * @param array|null $filters          The filters to apply to the registers
     * @param array|null $searchConditions The search conditions to apply to the registers
     * @param array|null $searchParams     The search parameters to apply to the registers
     *
     * @return array Array of registers with their schemas and statistics
     * @throws \Exception If there is an error getting the registers with schemas
     */
    public function getRegistersWithSchemas(
        ?int $limit=null,
        ?int $offset=null,
        ?array $filters=[],
        ?array $searchConditions=[],
        ?array $searchParams=[]
    ): array {
        try {
            // Get all registers.
            $registers = $this->registerMapper->findAll(
                limit: $limit,
                offset: $offset,
                filters: $filters,
                searchConditions: $searchConditions,
                searchParams: $searchParams
            );

            $result = [];

            // Add system totals as the first "register"
            $totalStats = $this->getTotalStats();
            $result[] = [
                'id'          => 'totals',
                'title'       => 'System Totals',
                'description' => 'Total statistics across all registers and schemas',
                'stats'       => $totalStats,
                'schemas'     => [],
            ];

            // For each register, get its schemas and statistics.
            foreach ($registers as $register) {
                $schemas = $this->registerMapper->getSchemasByRegisterId($register->getId());

                // Get register-level statistics.
                $registerStats = $this->getStats($register->getId());

                // Convert register to array and add statistics.
                $registerArray          = $register->jsonSerialize();
                $registerArray['stats'] = $registerStats;

                // Process schemas.
                $schemasArray = [];
                foreach ($schemas as $schema) {
                    // Get schema-level statistics.
                    $schemaStats = $this->getStats($register->getId(), $schema->getId());

                    // Convert schema to array and add statistics.
                    $schemaArray          = $schema->jsonSerialize();
                    $schemaArray['stats'] = $schemaStats;
                    $schemasArray[]       = $schemaArray;
                }

                $registerArray['schemas'] = $schemasArray;
                $result[] = $registerArray;
            }//end foreach

            // Add orphaned items statistics as a special "register".
            $orphanedStats = $this->getOrphanedStats();
            $result[]      = [
                'id'          => 'orphaned',
                'title'       => 'Orphaned Items',
                'description' => 'Items that reference non-existent registers, schemas, or invalid register-schema combinations',
                'stats'       => $orphanedStats,
                'schemas'     => [],
            ];

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get registers with schemas: '.$e->getMessage());
            throw new \Exception('Failed to get registers with schemas: '.$e->getMessage());
        }//end try

    }//end getRegistersWithSchemas()


    /**
     * Recalculate sizes for objects in specified registers and/or schemas
     *
     * @param int|null $registerId The register ID to filter by (optional)
     * @param int|null $schemaId   The schema ID to filter by (optional)
     *
     * @return array Array containing counts of processed and failed objects
     */
    public function recalculateSizes(?int $registerId=null, ?int $schemaId=null): array
    {
        $result = [
            'processed' => 0,
            'failed'    => 0,
        ];

        try {
            // Build filters array based on provided IDs.
            $filters = [];
            if ($registerId !== null) {
                $filters['register'] = $registerId;
            }

            if ($schemaId !== null) {
                $filters['schema'] = $schemaId;
            }

            // Get all relevant objects
            $objects = $this->objectMapper->findAll(filters: $filters);

            // Update each object to trigger size recalculation.
            foreach ($objects as $object) {
                try {
                    $this->objectMapper->update($object);
                    $result['processed']++;
                } catch (\Exception $e) {
                    $this->logger->error('Failed to update object '.$object->getId().': '.$e->getMessage());
                    $result['failed']++;
                }
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Failed to recalculate sizes: '.$e->getMessage());
            throw new \Exception('Failed to recalculate sizes: '.$e->getMessage());
        }//end try

    }//end recalculateSizes()


    /**
     * Recalculate sizes for audit trail logs in specified registers and/or schemas
     *
     * @param int|null $registerId The register ID to filter by (optional)
     * @param int|null $schemaId   The schema ID to filter by (optional)
     *
     * @return array Array containing counts of processed and failed logs
     */
    public function recalculateLogSizes(?int $registerId=null, ?int $schemaId=null): array
    {
        $result = [
            'processed' => 0,
            'failed'    => 0,
        ];

        try {
            // Build filters array based on provided IDs.
            $filters = [];
            if ($registerId !== null) {
                $filters['register'] = $registerId;
            }

            if ($schemaId !== null) {
                $filters['schema'] = $schemaId;
            }

            // Get all relevant logs.
            $logs = $this->auditTrailMapper->findAll(filters: $filters);

            // Update each log to trigger size recalculation.
            foreach ($logs as $log) {
                try {
                    $this->auditTrailMapper->update($log);
                    $result['processed']++;
                } catch (\Exception $e) {
                    $this->logger->error('Failed to update log '.$log->getId().': '.$e->getMessage());
                    $result['failed']++;
                }
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Failed to recalculate log sizes: '.$e->getMessage());
            throw new \Exception('Failed to recalculate log sizes: '.$e->getMessage());
        }//end try

    }//end recalculateLogSizes()


    /**
     * Recalculate sizes for both objects and logs in specified registers and/or schemas
     *
     * @param int|null $registerId The register ID to filter by (optional)
     * @param int|null $schemaId   The schema ID to filter by (optional)
     *
     * @return array Array containing counts of processed and failed items for both objects and logs
     */
    public function recalculateAllSizes(?int $registerId=null, ?int $schemaId=null): array
    {
        try {
            $objectResults = $this->recalculateSizes($registerId, $schemaId);
            $logResults    = $this->recalculateLogSizes($registerId, $schemaId);

            return [
                'objects' => $objectResults,
                'logs'    => $logResults,
                'total'   => [
                    'processed' => $objectResults['processed'] + $logResults['processed'],
                    'failed'    => $objectResults['failed'] + $logResults['failed'],
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to recalculate all sizes: '.$e->getMessage());
            throw new \Exception('Failed to recalculate all sizes: '.$e->getMessage());
        }

    }//end recalculateAllSizes()


    /**
     * Calculate sizes for all entities (objects and logs) in the system
     * Optionally filtered by register and/or schema
     *
     * @param int|null $registerId The register ID to filter by (optional)
     * @param int|null $schemaId   The schema ID to filter by (optional)
     *
     * @return array Array containing detailed statistics about the calculation process
     */
    public function calculate(?int $registerId=null, ?int $schemaId=null): array
    {
        try {
            // Get the register info if registerId is provided.
            $register = null;
            if ($registerId !== null) {
                try {
                    $register = $this->registerMapper->find($registerId);
                } catch (\Exception $e) {
                    throw new \Exception('Register not found: '.$e->getMessage());
                }
            }

            // Get the schema info if schemaId is provided.
            $schema = null;
            if ($schemaId !== null) {
                try {
                    $schema = $this->schemaMapper->find($schemaId);
                    // Verify schema belongs to register if both are provided.
                    if ($register !== null && !in_array($schema->getId(), $register->getSchemas())) {
                        throw new \Exception('Schema does not belong to the specified register');
                    }
                } catch (\Exception $e) {
                    throw new \Exception('Schema not found or invalid: '.$e->getMessage());
                }
            }

            // Perform the calculations.
            $results = $this->recalculateAllSizes($registerId, $schemaId);

            // Build the response.
            $response = [
                'status'    => 'success',
                'timestamp' => (new \DateTime())->format('c'),
                'scope'     => [
                    'register' => $register ? [
                        'id'    => $register->getId(),
                        'title' => $register->getTitle(),
                    ] : null,
                    'schema'   => $schema ? [
                        'id'    => $schema->getId(),
                        'title' => $schema->getTitle(),
                    ] : null,
                ],
                'results'   => $results,
                'summary'   => [
                    'total_processed' => $results['total']['processed'],
                    'total_failed'    => $results['total']['failed'],
                    'success_rate'    => $results['total']['processed'] + $results['total']['failed'] > 0 ? round(($results['total']['processed'] / ($results['total']['processed'] + $results['total']['failed'])) * 100, 2) : 0,
                ],
            ];

            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Size calculation failed: '.$e->getMessage());
            throw new \Exception('Size calculation failed: '.$e->getMessage());
        }//end try

    }//end calculate()


}//end class
